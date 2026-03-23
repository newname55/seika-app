<?php
declare(strict_types=1);

require __DIR__ . '/../app/auth.php';
ensure_session();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$msg = '';
$msgType = 'err'; // err|warn|ok
$login_id = '';

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

/** LINE設定 */
$LINE_CHANNEL_ID     = conf('LINE_CHANNEL_ID');
$LINE_CHANNEL_SECRET = conf('LINE_CHANNEL_SECRET');
$LINE_REDIRECT_URI   = normalize_line_redirect_uri(conf('LINE_REDIRECT_URI'));
$lineReady = ($LINE_CHANNEL_ID !== '' && $LINE_CHANNEL_SECRET !== '' && $LINE_REDIRECT_URI !== '');

/** Google設定 */
$GOOGLE_CLIENT_ID     = conf('GOOGLE_CLIENT_ID');
$GOOGLE_CLIENT_SECRET = conf('GOOGLE_CLIENT_SECRET');
$GOOGLE_REDIRECT_URI  = normalize_google_redirect_uri(conf('GOOGLE_REDIRECT_URI'));
$googleReady = ($GOOGLE_CLIENT_ID !== '' && $GOOGLE_CLIENT_SECRET !== '' && $GOOGLE_REDIRECT_URI !== '');

/** すでにログイン済みならゲートへ（ループ防止） */
if (!empty($_SESSION['user_id'])) {
  header('Location: /wbss/public/gate.php');
  exit;
}

/** クエリ表示（未連携など） */
$lineQ = (string)($_GET['line'] ?? '');
if ($lineQ === 'unlinked') {
  $msg = 'LINE未連携のためログインできません。管理者に連携を依頼してください。';
  $msgType = 'warn';
}

$googleQ = (string)($_GET['google'] ?? '');
if ($googleQ !== '') {
  $msgType = 'warn';
  if ($googleQ === 'unlinked') {
    $msg = 'Google未連携のためログインできません。管理者に連携を依頼してください。';
  } elseif ($googleQ === 'state') {
    $msg = 'Googleログインに失敗しました（state不一致）。もう一度お試しください。';
  } elseif ($googleQ === 'already_linked') {
    $msg = 'このGoogleアカウントは既に別ユーザーに連携されています。管理者に確認してください。';
  } elseif ($googleQ === 'loginfail') {
    $msg = 'Googleログインに失敗しました（ユーザー無効/権限不備）。管理者に確認してください。';
  } elseif ($googleQ === 'error') {
    $msg = 'Googleログインに失敗しました。しばらくしてから再度お試しください。';
  } else {
    $msg = 'Googleログインに失敗しました。';
  }
}

/** PWログイン */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login_id = trim((string)($_POST['login_id'] ?? ''));
  $pw = (string)($_POST['password'] ?? '');

  $res = login_user($login_id, $pw);
  if (!empty($res['ok'])) {
    header('Location: /wbss/public/gate.php');
    exit;
  }
  $msg = (string)($res['error'] ?? 'ログインに失敗しました');
  $msgType = 'err';
}
?>
<!doctype html>
<html lang="ja" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>WBSS ログイン</title>

  <link rel="manifest" href="/wbss/public/manifest.webmanifest">
  <meta name="theme-color" content="#0b1220">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="wbss">
  <link rel="apple-touch-icon" href="/wbss/public/assets/icon-192.png">

  <script>
    (function(){
      const t = localStorage.getItem('ui_theme') || 'dark';
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>

  <style>
    html[data-theme="dark"]{
      --bg1:#0b1220; --bg2:#0f172a;
      --txt:#e8eefc; --muted:#a8b4d6;
      --line:rgba(255,255,255,.12);
      --cardA:rgba(255,255,255,.06);
      --cardB:rgba(255,255,255,.03);
      --shadow:0 16px 40px rgba(0,0,0,.40);
      --accent:#60a5fa;
      --ng:#fb7185;
      --warn:#f59e0b;
      --ok:#34d399;
    }
    html[data-theme="light"]{
      --bg1:#f4f6fb; --bg2:#f4f6fb;
      --txt:#0f1222; --muted:#6b7280;
      --line:#e7e9f2;
      --cardA:#fff; --cardB:#fbfbff;
      --shadow:0 16px 40px rgba(15,18,34,.12);
      --accent:#2563eb;
      --ng:#dc2626;
      --warn:#d97706;
      --ok:#059669;
    }

    *{box-sizing:border-box;}
    body{
      margin:0;
      font-family:system-ui,-apple-system,"Noto Sans JP",sans-serif;
      color:var(--txt);
      min-height:100vh;
      display:grid;
      place-items:center;
      padding:18px;
      background:
        radial-gradient(1200px 700px at 20% -10%, rgba(96,165,250,.20), transparent 55%),
        radial-gradient(1000px 600px at 90% 10%, rgba(167,139,250,.16), transparent 55%),
        linear-gradient(180deg, var(--bg1), var(--bg2));
    }
    html[data-theme="light"] body{ background: var(--bg1); }

    .card{
      width:min(540px, 100%);
      border:1px solid var(--line);
      border-radius:20px;
      background: linear-gradient(180deg, var(--cardA), var(--cardB));
      box-shadow: var(--shadow);
      padding:18px;
    }
    html[data-theme="light"] .card{ background:#fff; }

    .top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
    }
    .logo{ display:flex; align-items:center; gap:12px; }
    .mark{
      width:44px; height:44px; border-radius:16px;
      background: linear-gradient(135deg, rgba(96,165,250,.95), rgba(167,139,250,.85));
      box-shadow: 0 12px 26px rgba(0,0,0,.25);
    }
    .ttl{ margin:0; font-size:18px; font-weight:1000; }
    .sub{ margin-top:6px; font-size:12px; color:var(--muted); }

    .themeBtn{
      border:1px solid var(--line);
      background: var(--cardA);
      color: var(--txt);
      border-radius:14px;
      padding:10px 12px;
      font-weight:900;
      cursor:pointer;
      user-select:none;
      -webkit-tap-highlight-color: transparent;
    }
    html[data-theme="light"] .themeBtn{
      background:#fff;
      border:none;
      box-shadow: 0 10px 18px rgba(0,0,0,.08);
    }
    .themeBtn:active{ transform: translateY(1px); }

    .msg{
      margin-top:12px;
      border-radius:16px;
      padding:12px 14px;
      border:1px solid rgba(251,113,133,.45);
      background: rgba(255,255,255,.06);
      font-weight: 900;
      font-size: 13px;
      color: var(--ng);
    }
    .msg.warn{
      border-color: rgba(245,158,11,.45);
      color: var(--warn);
    }
    .msg.ok{
      border-color: rgba(52,211,153,.45);
      color: var(--ok);
    }
    html[data-theme="light"] .msg{ background:#fff; }

    form{ margin-top:14px; display:grid; gap:12px; }
    label{ font-size:12px; color:var(--muted); font-weight:900; display:block; margin-bottom:6px; }

    input{
      width:100%;
      min-height:58px;
      padding:14px 14px;
      border-radius:16px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.06);
      color: var(--txt);
      font-weight:900;
      font-size:16px;
      outline:none;
    }
    html[data-theme="light"] input{
      background:#fff;
      box-shadow: 0 10px 18px rgba(0,0,0,.06);
    }
    input:focus{
      border-color: rgba(96,165,250,.45);
      box-shadow: 0 0 0 4px rgba(96,165,250,.12);
    }
    html[data-theme="light"] input:focus{
      border-color: rgba(37,99,235,.55);
      box-shadow: 0 0 0 4px rgba(37,99,235,.12);
    }

    .btn{
      min-height:58px;
      border-radius:16px;
      padding:14px 14px;
      font-weight:1000;
      font-size:16px;
      cursor:pointer;
      user-select:none;
      -webkit-tap-highlight-color: transparent;
      border:none;
      background: linear-gradient(135deg, rgba(96,165,250,.95), rgba(167,139,250,.85));
      color:#fff;
      box-shadow: 0 16px 34px rgba(0,0,0,.35);
      display:flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      text-decoration:none;
    }
    html[data-theme="light"] .btn{ box-shadow: 0 16px 34px rgba(15,18,34,.18); }
    .btn:active{ transform: translateY(1px); }

    /* LINEボタン */
    .lineBtn{
      margin-top:14px;
      background: linear-gradient(135deg, rgba(34,197,94,.95), rgba(16,185,129,.85));
    }

    /* Googleボタン（白系でそれっぽく） */
    .googleBtn{
      margin-top:12px;
      background: linear-gradient(180deg, rgba(255,255,255,.95), rgba(255,255,255,.86));
      color:#111827;
      border:1px solid rgba(0,0,0,.10);
      box-shadow: 0 16px 34px rgba(0,0,0,.20);
    }
    html[data-theme="light"] .googleBtn{
      box-shadow: 0 16px 34px rgba(15,18,34,.12);
    }
    .googleG{
      width:22px;height:22px;border-radius:6px;
      display:grid;place-items:center;
      background:#fff;
      border:1px solid rgba(0,0,0,.08);
      font-weight:1000;
    }

    .mini{ font-size:12px; opacity:.9; font-weight:900; }

    .disabled{
      opacity:.45;
      pointer-events:none;
      filter: grayscale(30%);
    }

    .divider{
      margin:14px 0 6px;
      display:flex; align-items:center; gap:10px;
      color:var(--muted);
      font-size:12px;
      font-weight:900;
    }
    .divider:before, .divider:after{
      content:"";
      height:1px;
      background: var(--line);
      flex:1;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="top">
      <div class="logo">
        <div class="mark" aria-hidden="true"></div>
        <div>
          <h1 class="ttl">WBSS システム</h1>
          <div class="sub">現場の入力ミスを減らす。PWA推奨。</div>
        </div>
      </div>
      <button class="themeBtn" type="button" id="themeBtn">🌓</button>
    </div>

    <?php if (!$lineReady): ?>
      <div class="msg warn">LINE設定が未完了です（LINE_CHANNEL_ID / LINE_CHANNEL_SECRET / LINE_REDIRECT_URI）</div>
    <?php endif; ?>

    <?php if (!$googleReady): ?>
      <div class="msg warn" style="margin-top:10px;">Google設定が未完了です（GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI）</div>
    <?php endif; ?>

    <?php if ($msg !== ''): ?>
      <div class="msg <?= h($msgType) ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <!-- ✅ LINEでログイン（リンクなので確実に押せる） -->
    <a class="btn lineBtn <?= $lineReady ? '' : 'disabled' ?>"
       href="/wbss/public/line_login_start.php">
      <span style="font-size:18px;">LINEでログイン</span>
      <span class="mini">（パスワード不要）</span>
    </a>

    <!-- ✅ Googleでログイン -->
    <a class="btn googleBtn <?= $googleReady ? '' : 'disabled' ?>"
       href="/wbss/public/google_login_start.php"
       aria-label="Googleでログイン">
      <span class="googleG">G</span>
      <span style="font-size:18px;">Googleでログイン</span>
      <span class="mini">（連携済のみ）</span>
    </a>

    <div class="divider">または</div>

    <!-- 従来のID/PASS -->
    <form method="post" autocomplete="on">
      <div>
        <label for="login_id">Login ID</label>
        <input id="login_id" name="login_id" value="<?= h($login_id) ?>" autocomplete="username" required>
      </div>
      <div>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button class="btn" type="submit" style="background: linear-gradient(135deg, rgba(96,165,250,.95), rgba(167,139,250,.85)); color:#fff; border:none;">
        ID/PASSでログイン
      </button>
    </form>
  </div>

  <script>
    document.getElementById('themeBtn').addEventListener('click', () => {
      const cur = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = (cur === 'light') ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('ui_theme', next);
    });

    const idEl = document.getElementById('login_id');
    if (idEl && !idEl.value) idEl.focus();
    else document.getElementById('password')?.focus();
  </script>
</body>
</html>
