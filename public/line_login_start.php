<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

ensure_session();

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

$channelId    = conf('LINE_CHANNEL_ID');
$redirectUri  = normalize_line_redirect_uri(conf('LINE_REDIRECT_URI'));
$state        = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

if ($channelId === '' || $redirectUri === '') {
  http_response_code(500);
  exit('LINE設定が未完了です（ID/REDIRECT_URI）');
}

/**
 * ✅ 招待トークン受け取り（キャスト登録用）
 * line_callback で使うのでセッションに保存
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$invite = trim((string)($_GET['invite'] ?? ''));
if ($invite !== '') {
  $_SESSION['invite'] = $invite; // ★コールバックで拾えるように保持
}

/**
 * 既存仕様：管理者が「このユーザーへ紐付け」するモード
 */
$linkTarget = (int)($_GET['link_user_id'] ?? 0);
if ($linkTarget > 0) {
  // 連携モードでは invite は使わない
  unset($_SESSION['line_invite_token']);
  $_SESSION['line_link_target_user_id'] = $linkTarget;
}

$_SESSION['line_oauth_state'] = $state;

// LINEログインURL
$params = [
  'response_type' => 'code',
  'client_id'     => $channelId,
  'redirect_uri'  => $redirectUri,
  'state'         => $state,
  'scope'         => 'openid profile',
  'prompt'        => 'consent',
];

$authUrl = 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($params);
header('Location: ' . $authUrl);
exit;
