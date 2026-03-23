<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/store.php';

ensure_session();

function current_origin(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $scheme = $https ? 'https' : 'http';
  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
  return $host !== '' ? ($scheme . '://' . $host) : '';
}
function normalize_google_redirect_uri(string $uri): string {
  $uri = trim($uri);
  if ($uri !== '') {
    $uri = preg_replace('#/seika-app/public/google_callback\.php$#', '/wbss/public/google_callback.php', $uri) ?? $uri;
  }
  if ($uri === '') {
    $origin = current_origin();
    if ($origin !== '') {
      $uri = $origin . '/wbss/public/google_callback.php';
    }
  }
  return $uri;
}
function redirect_login(string $qs = ''): void {
  $url = '/wbss/public/login.php' . ($qs ? ('?' . $qs) : '');
  header('Location: ' . $url);
  exit;
}
function b64url_decode(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  $out = base64_decode($s, true);
  return $out === false ? '' : $out;
}
function b64url_encode(string $s): string {
  return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function verify_state(string $state, string $secret): ?array {
  $parts = explode('.', $state, 2);
  if (count($parts) !== 2) return null;
  [$p, $sig] = $parts;

  $expect = b64url_encode(hash_hmac('sha256', $p, $secret, true));
  if (!hash_equals($expect, $sig)) return null;

  $json = b64url_decode($p);
  if ($json === '') return null;
  $data = json_decode($json, true);
  if (!is_array($data)) return null;
  return $data;
}
function get_cookie(string $name): string {
  return is_string($_COOKIE[$name] ?? null) ? (string)$_COOKIE[$name] : '';
}
function clear_cookie(string $name): void {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  setcookie($name, '', [
    'expires'  => time() - 3600,
    'path'     => '/wbss/public',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

$clientId     = conf('GOOGLE_CLIENT_ID');
$clientSecret = conf('GOOGLE_CLIENT_SECRET');
$redirectUri  = normalize_google_redirect_uri(conf('GOOGLE_REDIRECT_URI'));
$secret       = conf('OAUTH_STATE_SECRET');

if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || $secret === '') {
  http_response_code(500);
  exit('Google設定が未完了です（GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI / OAUTH_STATE_SECRET）');
}

$stateRaw = (string)($_GET['state'] ?? '');
$code     = (string)($_GET['code'] ?? '');
if ($stateRaw === '' || $code === '') {
  redirect_login('google=error');
}

$st = verify_state($stateRaw, $secret);
if (!$st) redirect_login('google=state');

// 期限
$exp = (int)($st['exp'] ?? 0);
if ($exp <= 0 || $exp < time()) redirect_login('google=state');

// nonce（Cookie）照合：セッションに依存しないCSRF対策
$nonce = (string)($st['nonce'] ?? '');
$cookieNonce = get_cookie('oauth_nonce_google');
clear_cookie('oauth_nonce_google');
if ($nonce === '' || $cookieNonce === '' || !hash_equals($cookieNonce, $nonce)) {
  redirect_login('google=state');
}

$mode   = (string)($st['mode'] ?? 'login');  // login/link
$target = (int)($st['target'] ?? 0);
$ret    = normalize_internal_wbss_path((string)($st['ret'] ?? '/wbss/public/gate.php'), '/wbss/public/gate.php');

$pdo = db();

try {
  // 1) code -> access_token
  $tokenUrl = 'https://oauth2.googleapis.com/token';
  $post = http_build_query([
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
  ], '', '&');

  $ch = curl_init($tokenUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 15,
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($res === false || $err) throw new RuntimeException('token取得失敗: ' . $err);
  $tok = json_decode($res, true);
  if (!is_array($tok) || empty($tok['access_token'])) throw new RuntimeException('token不正 HTTP ' . $http);
  $accessToken = (string)$tok['access_token'];

  // 2) userinfo
  $infoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
  $ch = curl_init($infoUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT => 15,
  ]);
  $res2 = curl_exec($ch);
  $err2 = curl_error($ch);
  $http2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($res2 === false || $err2) throw new RuntimeException('userinfo取得失敗: ' . $err2);
  $info = json_decode($res2, true);
  if (!is_array($info) || empty($info['sub'])) throw new RuntimeException('userinfo不正 HTTP ' . $http2);

  $provider = 'google';
  $providerUserId = (string)$info['sub'];
  $email = isset($info['email']) ? (string)$info['email'] : null;
  $name  = isset($info['name']) ? (string)$info['name'] : null;
  $pic   = isset($info['picture']) ? (string)$info['picture'] : null;

  // 3) linkモード：管理者だけ許可（ここでセッション再チェック）
  if ($mode === 'link') {
    require_login();
    if (!is_role('super_user') && !is_role('admin')) {
      http_response_code(403);
      exit('Forbidden');
    }
    if ($target <= 0) redirect_login('google=error');

    // 既に別ユーザーに紐付いてないかチェック（事故防止）
    $stx = $pdo->prepare("
      SELECT user_id FROM user_identities
      WHERE provider=? AND provider_user_id=? AND is_active=1
      LIMIT 1
    ");
    $stx->execute([$provider, $providerUserId]);
    $exists = (int)($stx->fetchColumn() ?: 0);
    if ($exists > 0 && $exists !== $target) {
      redirect_login('google=already_linked');
    }

    $pdo->beginTransaction();

    // 既存googleを無効化（このユーザー側）
    $pdo->prepare("
      UPDATE user_identities
      SET is_active=0
      WHERE user_id=? AND provider='google'
    ")->execute([$target]);

    // upsert（provider+provider_user_id UNIQUE）
    $ins = $pdo->prepare("
      INSERT INTO user_identities
        (user_id, provider, provider_user_id, provider_email, display_name, picture_url, linked_at, last_login_at, is_active)
      VALUES
        (?, 'google', ?, ?, ?, ?, NOW(), NOW(), 1)
      ON DUPLICATE KEY UPDATE
        user_id=VALUES(user_id),
        provider_email=VALUES(provider_email),
        display_name=VALUES(display_name),
        picture_url=VALUES(picture_url),
        last_login_at=NOW(),
        is_active=1
    ");
    $ins->execute([$target, $providerUserId, $email, $name, $pic]);

    $pdo->commit();

    header('Location: ' . $ret);
    exit;
  }

  // 4) 通常ログイン：紐付いてる user_id を引く
  $st2 = $pdo->prepare("
    SELECT user_id
    FROM user_identities
    WHERE provider='google' AND provider_user_id=? AND is_active=1
    LIMIT 1
  ");
  $st2->execute([$providerUserId]);
  $userId = (int)($st2->fetchColumn() ?: 0);

  if ($userId <= 0) redirect_login('google=unlinked');

  // 5) last_login_at更新（要件）
  $pdo->prepare("
    UPDATE user_identities
    SET last_login_at = NOW()
    WHERE provider='google' AND provider_user_id=? AND is_active=1
    LIMIT 1
  ")->execute([$providerUserId]);

  // 6) ログイン確立（あなたの auth.php の定義に合わせて「void」想定）
  login_user_by_id($userId);

  header('Location: /wbss/public/gate.php');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[google_callback] ' . $e->getMessage());
  redirect_login('google=error');
}
