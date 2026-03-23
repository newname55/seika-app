<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

ensure_session();

if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

function csrf_token_local(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  }
  return (string) $_SESSION['_csrf'];
}

function csrf_verify_local(?string $token): bool {
  return is_string($token)
    && $token !== ''
    && isset($_SESSION['_csrf'])
    && hash_equals((string) $_SESSION['_csrf'], $token);
}

function app_origin(): string {
  $scheme = 'https';
  $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
  if ($forwarded !== '') {
    $scheme = explode(',', $forwarded)[0] === 'http' ? 'http' : 'https';
  } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
    $scheme = 'https';
  } elseif ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 80) {
    $scheme = 'http';
  }

  $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
  if ($host === '') {
    return '';
  }
  return $scheme . '://' . $host;
}

function login_url_absolute(string $loginPath): string {
  $origin = app_origin();
  if ($origin !== '') {
    return $origin . $loginPath;
  }

  $redirectUri = conf('LINE_REDIRECT_URI');
  if ($redirectUri !== '') {
    $parts = parse_url($redirectUri);
    $scheme = (string) ($parts['scheme'] ?? 'https');
    $host = (string) ($parts['host'] ?? '');
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    if ($host !== '') {
      return $scheme . '://' . $host . $port . $loginPath;
    }
  }

  return $loginPath;
}

function line_push_text_local(string $accessToken, string $toUserId, string $text): array {
  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => json_encode([
      'to' => $toUserId,
      'messages' => [
        ['type' => 'text', 'text' => $text],
      ],
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
  ]);
  $res = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  return ['code' => $code, 'res' => (string) $res, 'err' => (string) $err];
}

$complete = $_SESSION['line_link_complete'] ?? null;
if (!is_array($complete) || empty($complete['line_user_id'])) {
  http_response_code(400);
  exit('連携完了情報が見つかりません');
}

$targetUserName = (string) ($complete['target_user_name'] ?? '');
$lineUserId = (string) ($complete['line_user_id'] ?? '');
$loginPath = '/wbss/public/login.php';
$loginUrl = login_url_absolute($loginPath);
$sendStatus = '';
$sendStatusType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify_local((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('CSRF blocked');
  }

  $accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
  if ($accessToken === '') {
    $sendStatus = 'LINE送信の設定が未完了です。';
    $sendStatusType = 'err';
  } else {
    $message = "WB支援システムのログインURLです。\n以下のリンクからログインしてください。\n" . $loginUrl;
    $result = line_push_text_local($accessToken, $lineUserId, $message);
    if ($result['code'] >= 200 && $result['code'] < 300) {
      $sendStatus = 'LINEにログインURLを送信しました。';
      $sendStatusType = 'ok';
    } else {
      $sendStatus = 'LINEへの送信に失敗しました。時間をおいて再度お試しください。';
      $sendStatusType = 'err';
      error_log('[line_link_complete] push failed: HTTP ' . $result['code'] . ' ' . ($result['res'] ?: $result['err']));
    }
  }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LINE連携完了</title>
<style>
:root{
  --bg:#0b1020;
  --card:rgba(255,255,255,.06);
  --line:rgba(255,255,255,.12);
  --text:#e8ecff;
  --muted:rgba(232,236,255,.78);
  --accent:#5ec7a0;
  --accent-strong:#2fa06e;
  --link:#8ec5ff;
  --ok:#88efc6;
  --err:#ffb4b4;
}
*{box-sizing:border-box;}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at top, rgba(94,199,160,.16), transparent 32%),
    linear-gradient(180deg, #10172b 0%, var(--bg) 100%);
  color:var(--text);
  font-family:system-ui,-apple-system,"Noto Sans JP",sans-serif;
}
.wrap{
  max-width:560px;
  margin:0 auto;
  padding:24px 18px 40px;
}
.card{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:18px;
  padding:20px 18px;
  box-shadow:0 16px 40px rgba(0,0,0,.28);
}
.ok{
  font-size:24px;
  font-weight:900;
  line-height:1.5;
}
.user{
  margin-top:10px;
  font-size:15px;
  color:var(--muted);
}
.lead{
  margin:16px 0 0;
  font-size:16px;
  line-height:1.8;
}
.actions{
  margin-top:22px;
  display:grid;
  gap:14px;
}
.btn{
  display:flex;
  align-items:center;
  justify-content:center;
  width:100%;
  min-height:56px;
  padding:14px 18px;
  border-radius:16px;
  border:none;
  background:linear-gradient(180deg, var(--accent), var(--accent-strong));
  color:#082113;
  text-decoration:none;
  font-size:17px;
  font-weight:900;
}
.linkBtn{
  appearance:none;
  border:none;
  background:none;
  color:var(--link);
  text-decoration:underline;
  font-size:15px;
  padding:0;
  cursor:pointer;
  margin:0 auto;
}
.note{
  margin-top:10px;
  color:var(--muted);
  font-size:13px;
  line-height:1.7;
  text-align:center;
}
.status{
  margin-top:16px;
  border-radius:14px;
  padding:12px 14px;
  font-size:14px;
  font-weight:700;
  line-height:1.6;
}
.status.ok{
  background:rgba(136,239,198,.12);
  color:var(--ok);
  border:1px solid rgba(136,239,198,.24);
  font-size:14px;
}
.status.err{
  background:rgba(255,180,180,.10);
  color:var(--err);
  border:1px solid rgba(255,180,180,.24);
}
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="ok">✅ LINE連携が完了しました</div>
      <?php if ($targetUserName !== ''): ?>
        <div class="user">対象ユーザー: <?= h($targetUserName) ?></div>
      <?php endif; ?>

      <p class="lead">WB支援システムをご利用いただけます。下のボタンからログインしてください。</p>

      <?php if ($sendStatus !== ''): ?>
        <div class="status <?= h($sendStatusType) ?>"><?= h($sendStatus) ?></div>
      <?php endif; ?>

      <div class="actions">
        <a class="btn" href="<?= h($loginPath) ?>">WB支援システムにログイン</a>

        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token_local()) ?>">
          <button type="submit" class="linkBtn">ログインURLをLINEに送る</button>
        </form>
      </div>

      <div class="note">あとで開く場合は、LINEにもログインURLを送れます。</div>
    </div>
  </div>
</body>
</html>
