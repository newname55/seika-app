<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
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
function b64url_encode(string $s): string {
  return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function hmac_state(array $payload, string $secret): string {
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) $json = '{}';
  $p = b64url_encode($json);
  $sig = b64url_encode(hash_hmac('sha256', $p, $secret, true));
  return $p . '.' . $sig;
}
function set_cookie(string $name, string $value, int $ttlSec = 600): void {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  setcookie($name, $value, [
    'expires'  => time() + $ttlSec,
    'path'     => '/wbss/public',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax', // OAuthの戻り(トップレベルGET)で送られる
  ]);
}

$clientId     = conf('GOOGLE_CLIENT_ID');
$redirectUri  = normalize_google_redirect_uri(conf('GOOGLE_REDIRECT_URI'));
$secret       = conf('OAUTH_STATE_SECRET');

if ($clientId === '' || $redirectUri === '' || $secret === '') {
  http_response_code(500);
  exit('Google設定が未完了です（GOOGLE_CLIENT_ID / GOOGLE_REDIRECT_URI / OAUTH_STATE_SECRET）');
}

// linkモード（管理者のみ）
$linkUserId = (int)($_GET['link_user_id'] ?? 0);
$mode = 'login';
$target = 0;

if ($linkUserId > 0) {
  require_login();
  if (!is_role('super_user') && !is_role('admin')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $mode = 'link';
  $target = $linkUserId;
}

// 戻り先（任意）
$return = (string)($_GET['return'] ?? '');
if ($return === '') {
  $return = ($mode === 'link')
    ? ('/wbss/public/admin_users.php?id=' . $target . '#edit')
    : '/wbss/public/gate.php';
}
$return = normalize_internal_wbss_path($return, '/wbss/public/gate.php');

// stateに全部入れて署名（セッション消えても復元可能）
$nonce = bin2hex(random_bytes(16));
$payload = [
  'v' => 1,
  'p' => 'google',
  'mode' => $mode,          // login / link
  'target' => $target,      // link対象 user_id
  'ret' => $return,         // 戻り先
  'nonce' => $nonce,        // リプレイ対策
  'exp' => time() + 600,    // 10分
];
$state = hmac_state($payload, $secret);

// nonceだけCookieに保存（セッション不要）
set_cookie('oauth_nonce_google', $nonce, 600);

$scope = 'openid email profile';

$url = 'https://accounts.google.com/o/oauth2/v2/auth'
  . '?response_type=code'
  . '&client_id=' . urlencode($clientId)
  . '&redirect_uri=' . urlencode($redirectUri)
  . '&scope=' . urlencode($scope)
  . '&state=' . urlencode($state)
  . '&prompt=consent'
  . '&access_type=online';

header('Location: ' . $url);
exit;
