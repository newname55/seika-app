<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

ensure_session();

/* =========================
   util
========================= */
function current_origin(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $scheme = $https ? 'https' : 'http';
  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
  return $host !== '' ? ($scheme . '://' . $host) : '';
}
function normalize_line_redirect_uri(string $uri): string {
  $uri = trim($uri);
  if ($uri !== '') {
    $uri = preg_replace('#/seika-app/public/line_callback\.php$#', '/wbss/public/line_callback.php', $uri) ?? $uri;
  }
  if ($uri === '') {
    $origin = current_origin();
    if ($origin !== '') {
      $uri = $origin . '/wbss/public/line_callback.php';
    }
  }
  return $uri;
}
if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}
function redirect_login(string $qs=''): never {
  header('Location: /wbss/public/login.php' . ($qs ? ('?' . $qs) : ''));
  exit;
}

/* =========================
   OAuth callback params
========================= */
$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

if ($code === '') {
  http_response_code(400);
  exit('Invalid callback');
}

/* state check（厳密に止めない：ログのみ） */
$expect = (string)($_SESSION['line_oauth_state'] ?? '');
if ($expect !== '' && $state !== '' && !hash_equals($expect, $state)) {
  error_log('[line_callback] state mismatch');
}
unset($_SESSION['line_oauth_state']);

/* =========================
   LINE config
========================= */
$channelId     = conf('LINE_CHANNEL_ID');
$channelSecret = conf('LINE_CHANNEL_SECRET');
$redirectUri   = normalize_line_redirect_uri(conf('LINE_REDIRECT_URI'));

if ($channelId === '' || $channelSecret === '' || $redirectUri === '') {
  http_response_code(500);
  exit('LINE設定が未完了です');
}

/* =========================
   token exchange
========================= */
$tokenRes = @file_get_contents(
  'https://api.line.me/oauth2/v2.1/token',
  false,
  stream_context_create([
    'http' => [
      'method'  => 'POST',
      'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
      'content' => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'client_id'     => $channelId,
        'client_secret' => $channelSecret,
      ]),
      'timeout' => 15,
    ]
  ])
);

$data = json_decode((string)$tokenRes, true);
$idToken = (string)($data['id_token'] ?? '');
if ($idToken === '') {
  error_log('[line_callback] token error');
  redirect_login('line=error');
}

/* =========================
   decode id_token
========================= */
$parts = explode('.', $idToken);
if (count($parts) < 2) redirect_login('line=error');
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

$lineUserId  = (string)($payload['sub'] ?? '');
$displayName = (string)($payload['name'] ?? '');
$pictureUrl  = (string)($payload['picture'] ?? '');

if ($lineUserId === '') redirect_login('line=error');

$pdo = db();

/* =========================
   招待トークン（startで保存済み）
========================= */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$invite = trim((string)($_GET['invite'] ?? ''));
if ($invite === '') {
  $invite = trim((string)($_SESSION['invite'] ?? ''));
}
$linkTargetUserId = (int)($_SESSION['line_link_target_user_id'] ?? 0);

// ログ
error_log('[line_callback] invite=' . ($invite !== '' ? 'SET' : 'EMPTY'));
/* =========================
   既存の LINE 紐付けを取得
   （is_active=0 も含めて取得）
========================= */
$st = $pdo->prepare("
  SELECT id, user_id, is_active
  FROM user_identities
  WHERE provider='line' AND provider_user_id=?
  ORDER BY is_active DESC, linked_at DESC
  LIMIT 1
");
$st->execute([$lineUserId]);
$identity = $st->fetch(PDO::FETCH_ASSOC) ?: null;

$userId = $identity ? (int)$identity['user_id'] : 0;

/* =========================
   招待トークン検証
========================= */
$inviteRow = null;
if ($invite !== '') {
  $st = $pdo->prepare("
    SELECT *
    FROM invite_tokens
    WHERE token=?
    LIMIT 1
  ");
  $st->execute([$invite]);
  $inviteRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;

  if (!$inviteRow) {
    unset($_SESSION['line_invite_token']);
    http_response_code(400);
    exit('招待トークンが無効です');
  }
  if ((int)$inviteRow['is_active'] !== 1) {
    unset($_SESSION['line_invite_token']);
    http_response_code(400);
    exit('この招待は無効です');
  }
  if (!empty($inviteRow['used_at'])) {
    unset($_SESSION['line_invite_token']);
    http_response_code(400);
    exit('この招待は使用済みです');
  }
  if (!empty($inviteRow['expires_at']) && $inviteRow['expires_at'] < date('Y-m-d H:i:s')) {
    unset($_SESSION['line_invite_token']);
    http_response_code(400);
    exit('この招待は期限切れです');
  }
}

/* =========================
   メイン処理
========================= */
try {

  /* 既存 identity があれば有効化＋更新 */
  if ($identity) {
    $pdo->prepare("
      UPDATE user_identities
      SET is_active=1,
          display_name=?,
          picture_url=?,
          last_login_at=NOW()
      WHERE id=?
    ")->execute([$displayName, $pictureUrl, (int)$identity['id']]);
  }

  /* ===== 招待登録 ===== */
  if ($inviteRow) {
    $storeId = (int)$inviteRow['store_id'];

    /* 管理者が誤って登録される事故防止 */
    if ($userId > 0) {
      $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM user_roles ur
        JOIN roles r ON r.id=ur.role_id
        WHERE ur.user_id=? AND r.code IN ('admin','super_user')
      ");
      $st->execute([$userId]);
      if ((int)$st->fetchColumn() > 0) {
        unset($_SESSION['line_invite_token']);
        http_response_code(400);
        exit('このLINEは管理者に連携済みです。キャスト本人のLINEで読み取ってください。');
      }
    }

    $pdo->beginTransaction();

    /* users が無ければ作成 */
    if ($userId <= 0) {
      $loginId = 'line-' . substr($lineUserId, 0, 24);
      $randPw  = bin2hex(random_bytes(16));
      $pwHash  = password_hash($randPw, PASSWORD_DEFAULT);

      $st = $pdo->prepare("SELECT 1 FROM users WHERE login_id=? LIMIT 1");
      $st->execute([$loginId]);
      if ($st->fetchColumn()) {
        $loginId .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
      }

      $pdo->prepare("
        INSERT INTO users
          (login_id, password_hash, display_name, is_active, created_at, updated_at, last_login_at)
        VALUES
          (?, ?, ?, 1, NOW(), NOW(), NOW())
      ")->execute([$loginId, $pwHash, ($displayName ?: 'LINEユーザー')]);

      $userId = (int)$pdo->lastInsertId();

      $pdo->prepare("
        INSERT INTO user_identities
          (user_id, provider, provider_user_id, display_name, picture_url, linked_at, last_login_at, is_active)
        VALUES
          (?, 'line', ?, ?, ?, NOW(), NOW(), 1)
      ")->execute([$userId, $lineUserId, $displayName, $pictureUrl]);
    }

    /* cast ロール付与 */
    $castRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code='cast' LIMIT 1")->fetchColumn();
    if ($castRoleId <= 0) throw new RuntimeException('roles.cast が存在しません');

    $st = $pdo->prepare("
      SELECT 1 FROM user_roles WHERE user_id=? AND role_id=? AND store_id=? LIMIT 1
    ");
    $st->execute([$userId, $castRoleId, $storeId]);
    if (!$st->fetchColumn()) {
      $pdo->prepare("
        INSERT INTO user_roles (user_id, role_id, store_id)
        VALUES (?, ?, ?)
      ")->execute([$userId, $castRoleId, $storeId]);
    }

    /* 履歴 */
    $pdo->prepare("
      INSERT INTO store_cast_history
        (user_id, store_id, action, source, created_at, created_by_user_id)
      VALUES
        (?, ?, 'joined', 'invite', NOW(), ?)
    ")->execute([$userId, $storeId, (int)$inviteRow['created_by_user_id']]);

    /* 招待使用済み */
    $pdo->prepare("
      UPDATE invite_tokens
      SET used_at=NOW(), used_by_user_id=?, is_active=0
      WHERE id=?
    ")->execute([$userId, (int)$inviteRow['id']]);

    $pdo->commit();
    unset($_SESSION['line_invite_token']);

    /* ログイン確立 */
    login_user_by_id($userId);

    /* 完了画面 */
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登録完了</title>
<style>
body{margin:0;background:#0b1020;color:#e8ecff;font-family:system-ui;}
.wrap{max-width:520px;margin:0 auto;padding:22px;}
.card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:18px;}
.ok{font-weight:900;font-size:18px;}
.muted{opacity:.75;margin-top:8px;}
.btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.16);color:#e8ecff;text-decoration:none;}
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="ok">✅ 登録が完了しました</div>
      <div class="muted">このまま画面を閉じてOKです。</div>
      <a class="btn" href="/wbss/public/dashboard.php">ダッシュボードへ</a>
    </div>
  </div>
</body>
</html>
<?php
    exit;
  }

  /* ===== 管理画面からの LINE 連携 ===== */
  if ($linkTargetUserId > 0) {
    $st = $pdo->prepare("
      SELECT id, display_name, is_active
      FROM users
      WHERE id = ?
      LIMIT 1
    ");
    $st->execute([$linkTargetUserId]);
    $targetUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$targetUser) {
      unset($_SESSION['line_link_target_user_id']);
      http_response_code(404);
      exit('連携対象ユーザーが見つかりません');
    }

    if ($userId > 0 && $userId !== $linkTargetUserId) {
      unset($_SESSION['line_link_target_user_id']);
      http_response_code(400);
      exit('このLINEは既に別ユーザーに連携されています');
    }

    $pdo->beginTransaction();

    $pdo->prepare("
      UPDATE user_identities
      SET is_active = 0
      WHERE user_id = ?
        AND provider = 'line'
    ")->execute([$linkTargetUserId]);

    $pdo->prepare("
      INSERT INTO user_identities
        (user_id, provider, provider_user_id, display_name, picture_url, linked_at, last_login_at, is_active)
      VALUES
        (?, 'line', ?, ?, ?, NOW(), NOW(), 1)
      ON DUPLICATE KEY UPDATE
        user_id = VALUES(user_id),
        display_name = VALUES(display_name),
        picture_url = VALUES(picture_url),
        last_login_at = NOW(),
        is_active = 1
    ")->execute([$linkTargetUserId, $lineUserId, $displayName, $pictureUrl]);

    $pdo->commit();
    unset($_SESSION['line_link_target_user_id']);
    unset($_SESSION['invite']);
    $_SESSION['line_link_complete'] = [
      'target_user_name' => (string)($targetUser['display_name'] ?? ('#' . $linkTargetUserId)),
      'line_user_id' => $lineUserId,
    ];
    header('Location: /wbss/public/line_link_complete.php');
    exit;
  }

  /* ===== 招待なし：通常ログイン ===== */
  if ($userId <= 0) {
    $_SESSION['line_pending'] = [
      'provider' => 'line',
      'provider_user_id' => $lineUserId,
      'display_name' => $displayName,
      'picture_url' => $pictureUrl,
    ];
    redirect_login('line=unlinked');
  }

  login_user_by_id($userId);
  header('Location: /wbss/public/gate.php');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[line_callback invite] ' . $e->getMessage());
  unset($_SESSION['line_invite_token']);
  http_response_code(500);
  exit('登録に失敗しました: ' . h($e->getMessage()));
}
