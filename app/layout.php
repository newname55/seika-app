<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/store.php';

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/** テーマ取得（session優先 → DB → default） */
function current_ui_theme(): string {
  $t = (string)($_SESSION['ui_theme'] ?? '');
  if ($t !== '') return $t;

  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) return 'dark';

  try {
    $pdo = db();
    $st = $pdo->prepare("SELECT ui_theme FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $r = $st->fetch();
    $t = $r ? (string)($r['ui_theme'] ?? '') : '';
    if ($t === '') $t = 'dark';
    $_SESSION['ui_theme'] = $t; // キャッシュ
    return $t;
  } catch (Throwable $e) {
    return 'dark';
  }
}

/** store_id -> 店舗名（失敗しても空文字で返す） */
function layout_store_name(?int $store_id): string {
  if ($store_id === null) return '';
  try {
    $pdo = db();
    $st = $pdo->prepare("SELECT name FROM stores WHERE id = ? LIMIT 1");
    $st->execute([$store_id]);
    $r = $st->fetch();
    return $r ? (string)$r['name'] : '';
  } catch (Throwable $e) {
    return '';
  }
}
function render_user_menu_html(): string {
  $options = [
    'light'         => 'Light（現場）',
    'dark'          => 'Dark（標準）',
    'soft'          => 'Soft（夜）',
    'high_contrast' => 'High Contrast',
    'store_color'   => 'Store Color',
  ];
  $cur = current_ui_theme();

  $isAdmin = function_exists('is_role') && (is_role('super_user') || is_role('admin'));

  $login_id = (string)($_SESSION['login_id'] ?? '');
  $display  = (string)($_SESSION['display_name'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');
  $who = $display !== '' ? $display : $login_id;

  $initial = 'U';
  if ($who !== '') {
    $initial = mb_substr($who, 0, 1);
  }

  ob_start();
  ?>
  <div class="userMenu" id="userMenu">
    <button type="button" class="userMenu__btn" id="userMenuBtn" aria-haspopup="menu" aria-expanded="false">
      <span class="userMenu__avatar"><?= h($initial) ?></span>
      <span class="userMenu__who">
        <span class="userMenu__name"><?= h($who !== '' ? $who : '-') ?></span>
        <span class="userMenu__role"><?= h($role !== '' ? $role : '') ?></span>
      </span>
      <span class="userMenu__chev">▾</span>
    </button>

    <div class="userMenu__panel" id="userMenuPanel" role="menu" hidden>
      <?php if ($isAdmin): ?>
        <a class="userMenu__item" href="/seika-app/public/admin/users/index.php" role="menuitem">👤 ユーザー管理</a>
        <div class="userMenu__sep"></div>
      <?php endif; ?>

      <div class="userMenu__label">🎨 テーマ</div>
      <div class="userMenu__grid">
        <?php foreach ($options as $k => $label): ?>
          <button
            type="button"
            class="userMenu__themeBtn <?= $cur === $k ? 'on' : '' ?>"
            onclick="setTheme('<?= h($k) ?>')"
            role="menuitem"
          ><?= h($label) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="userMenu__sep"></div>

      <a class="userMenu__item danger" href="/seika-app/public/logout.php" role="menuitem">🚪 ログアウト</a>
    </div>
  </div>

  <style>
  .userMenu{ position:relative; display:inline-block; }
  .userMenu__btn{
    display:flex; align-items:center; gap:10px;
    min-height: var(--tap);
    padding:10px 12px;
    border-radius:14px;
    border:1px solid var(--line);
    background: var(--cardA);
    color: var(--txt);
    cursor:pointer;
    user-select:none;
  }
  .userMenu__avatar{
    width:34px; height:34px; border-radius:999px;
    display:flex; align-items:center; justify-content:center;
    font-weight:1000;
    background: color-mix(in srgb, var(--accent) 0%, transparent);
    border:1px solid var(--line);
  }
  .userMenu__who{ display:flex; flex-direction:column; line-height:1.1; text-align:left; }
  .userMenu__name{ font-weight:1000; font-size:13px; }
  .userMenu__role{ font-size:11px; color:var(--muted); }
  .userMenu__chev{ color:var(--muted); font-weight:900; }

/* =========================================
   user menu dropdown (読みやすさ重視)
   - Light/Soft: ふんわり
   - Dark: 不透明 + 高コントラスト
========================================= */

.userMenu__panel{
  position:absolute; right:0; top: calc(100% + 8px);
  min-width: 280px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background: var(--card);
  box-shadow: var(--shadow);
  padding: 10px;
  z-index: 999;
}

.userMenu__item{
  display:block;
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid transparent;
  color: var(--txt);
}
.userMenu__item:hover{
  border-color: var(--line);
  background: color-mix(in srgb, var(--card) 70%, var(--chip));
}
.userMenu__item.danger{ color: var(--ng); font-weight: 1000; }

.userMenu__sep{ height:1px; background: var(--line); margin: 10px 0; }

.userMenu__label{
  font-size:12px;
  color: var(--muted);
  font-weight: 900;
  margin: 4px 2px 8px;
}

.userMenu__grid{ display:grid; grid-template-columns: 1fr; gap:8px; }

.userMenu__themeBtn{
  width:100%;
  text-align:left;
  padding: 12px 12px;
  border-radius: 12px;
  border: 1px solid var(--line);
  background: var(--card);
  color: var(--txt);
  cursor:pointer;
  font-weight: 900;
}
.userMenu__themeBtn:hover{
  border-color: color-mix(in srgb, var(--accent) 28%, var(--line));
  background: color-mix(in srgb, var(--accent) 8%, var(--card));
}
.userMenu__themeBtn.on{
  border-color: color-mix(in srgb, var(--accent) 55%, var(--line));
  background: color-mix(in srgb, var(--accent) 14%, var(--card));
}

/* ---------- Darkだけ：透けゼロ + くっきり ---------- */
body[data-theme="dark"] .userMenu__panel{
  background: #141414;          /* 完全不透明 */
  border-color: rgba(255,255,255,.14);
  box-shadow: 0 12px 30px rgba(0,0,0,.55);
}

body[data-theme="dark"] .userMenu__item:hover{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.14);
}

body[data-theme="dark"] .userMenu__sep{
  background: rgba(255,255,255,.12);
}

body[data-theme="dark"] .userMenu__label{
  color: rgba(255,255,255,.72);
}

body[data-theme="dark"] .userMenu__themeBtn{
  background: #1b1b1b;          /* 透けゼロ */
  border-color: rgba(255,255,255,.14);
}
body[data-theme="dark"] .userMenu__themeBtn:hover{
  background: #232323;
  border-color: color-mix(in srgb, var(--accent) 38%, rgba(255,255,255,.14));
}
body[data-theme="dark"] .userMenu__themeBtn.on{
  background: color-mix(in srgb, var(--accent) 18%, #1b1b1b);
  border-color: color-mix(in srgb, var(--accent) 65%, rgba(255,255,255,.14));
}

  [data-theme="light"] .userMenu__btn{ background:#fff; box-shadow: 0 10px 18px rgba(0,0,0,.06); border:1px solid var(--line); }
  [data-theme="light"] .userMenu__panel{ background:#fff; box-shadow: 0 10px 18px rgba(0,0,0,.10); }
  [data-theme="light"] .userMenu__themeBtn{ background:#fff; box-shadow: 0 10px 18px rgba(0,0,0,.06); border:1px solid var(--line); }
  </style>

  <script>
(function(){
  const btn = document.getElementById('userMenuBtn');
  const panel = document.getElementById('userMenuPanel');
  if(!btn || !panel) return;

  function open(){
    panel.hidden = false;
    btn.setAttribute('aria-expanded','true');
  }
  function close(){
    panel.hidden = true;
    btn.setAttribute('aria-expanded','false');
  }

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (panel.hidden) open(); else close();
  });

  document.addEventListener('click', () => close());
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  window.setTheme = async function(theme){
    try{
      const res = await fetch('/seika-app/public/api/set_theme.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        credentials:'same-origin',
        body: 'theme=' + encodeURIComponent(theme)
      });
      const j = await res.json().catch(()=>({}));
      if (j && j.ok){
        document.body.setAttribute('data-theme', theme);

        // ボタンのon表示を更新
        document.querySelectorAll('.userMenu__themeBtn').forEach(el => el.classList.remove('on'));
        const onBtn = document.querySelector('.userMenu__themeBtn[onclick*="' + theme + '"]');
        if (onBtn) onBtn.classList.add('on');

        close();
        return;
      }
    }catch(e){}
    alert('テーマ変更に失敗しました');
  };
})();
</script>
  <?php
  return (string)ob_get_clean();
}
/** 共通ヘッダ */
function render_header(string $title, array $opts = []): void {
  $back_href  = array_key_exists('back_href', $opts) ? $opts['back_href'] : '/seika-app/public/dashboard.php';
  $back_label = (string)($opts['back_label'] ?? '← 戻る');
  $right_html = (string)($opts['right_html'] ?? '');
  $show_store = (bool)($opts['show_store'] ?? true);
  $show_user  = (bool)($opts['show_user'] ?? true);

  $store_id = null;
  if (function_exists('current_store_id')) {
    $sid = current_store_id();
    $store_id = ($sid === null) ? null : (int)$sid;
  }
  $store_name = layout_store_name($store_id);

  $login_id = (string)($_SESSION['login_id'] ?? '');
  $display  = (string)($_SESSION['display_name'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');
  $who = $display !== '' ? $display : $login_id;

  ?>
  <header class="app-header">
    <div class="app-header__inner">
      <div class="app-header__left">
        <?php if ($back_href !== null && $back_href !== ''): ?>
          <a class="app-back" href="<?= h((string)$back_href) ?>"><?= h($back_label) ?></a>
        <?php endif; ?>
      </div>

      <div class="app-header__center">
        <div class="app-title"><?= h($title) ?></div>
        <div class="app-sub">
          <?php if ($show_store): ?>
            <span class="pill">店舗: <?= h($store_name !== '' ? $store_name : '未選択') ?><?= $store_id !== null ? ' (#'.(int)$store_id.')' : '' ?></span>
          <?php endif; ?>
          <?php if ($show_user): ?>
            <span class="pill">ユーザー: <?= h($who !== '' ? $who : '-') ?><?= $role !== '' ? ' / '.h($role) : '' ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="app-header__right" style="display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;">
        <?= $right_html ?>
        <?= render_user_menu_html() ?>
      </div>
    </div>
  </header>
  <?php
}

/** 共通CSS（テーマ変数方式） */
function render_layout_css(): void {
  ?>
  <style>
    :root{
      --tap: 52px;
      --radius: 18px;
      --shadow: 0 10px 30px rgba(0,0,0,.35);
    }

    /* =========================
       Theme variables (body[data-theme="..."])
       ========================= */

    /* ===== default: dark ===== */
    body[data-theme="dark"]{
      --bg1:#0b1220;
      --bg2:#0f172a;
      --txt:#e8eefc;
      --muted:#a8b4d6;
      --line:rgba(255,255,255,.12);

      --cardA: rgba(255,255,255,.06);
      --cardB: rgba(255,255,255,.03);

      --accent:#60a5fa;
      --ok:#34d399;
      --warn:#fbbf24;
      --ng:#fb7185;

      /* 便利 */
      --primary:#111827;
    }

    /* ===== light (iPad現場CSS寄せ) ===== */
    body[data-theme="light"]{
      --bg1:#f4f6fb;
      --bg2:#f4f6fb;
      --txt:#0f1222;
      --muted:#6b7280;
      --line:#e7e9f2;

      --cardA:#ffffff;
      --cardB:#fbfbff;

      --accent:#2563eb;
      --ok:#16a34a;
      --warn:#f59e0b;
      --ng:#dc2626;

      --radius:18px;
      --shadow:0 10px 30px rgba(15,18,34,.08);

      --primary:#111827;
      --blue:#2563eb;
      --cyan:#0891b2;
      --green:#16a34a;
      --yellow:#f59e0b;
      --orange:#f97316;
      --red:#dc2626;
      --purple:#7c3aed;

      --softBlue:#eef4ff;
      --softGreen:#eaf8ef;
      --softYellow:#fff6e5;
      --softRed:#ffecec;
      --softPurple:#f3edff;
    }
    /* ===== Light mode table fix ===== */
    body.light table {
      border-collapse: collapse;
      background: #ffffff;
    }

    body.light th {
      background: #f1f5f9;          /* ヘッダを1段濃く */
      color: #0f172a;
      font-weight: 600;
      border-bottom: 2px solid #cbd5e1;
      padding: 10px 12px;
    }

    body.light td {
      color: #1e293b;
      border-bottom: 1px solid #e5e7eb;
      padding: 10px 12px;
    }

    /* 行ホバー（Lightでは必須） */
    body.light tbody tr:hover {
      background: #f8fafc;
    }
    /* ===== high contrast ===== */
    body[data-theme="high_contrast"]{
      --bg1:#000000;
      --bg2:#000000;
      --txt:#ffffff;
      --muted:#d1d5db;
      --line:rgba(255,255,255,.35);

      --cardA: rgba(255,255,255,.10);
      --cardB: rgba(255,255,255,.06);

      --accent:#00e5ff;
      --ok:#00ff85;
      --warn:#ffd400;
      --ng:#ff3b30;

      --primary:#000;
    }

    /* ===== soft ===== */
    body[data-theme="soft"]{
      --bg1:#101826;
      --bg2:#0b1320;
      --txt:#e6edf7;
      --muted:#b7c2d8;
      --line:rgba(255,255,255,.10);

      --cardA: rgba(255,255,255,.07);
      --cardB: rgba(255,255,255,.04);

      --accent:#a78bfa;
      --ok:#34d399;
      --warn:#fbbf24;
      --ng:#fb7185;

      --primary:#111827;
    }

    /* ===== store_color ===== */
    body[data-theme="store_color"]{
      --bg1:#0b1220;
      --bg2:#0f172a;
      --txt:#e8eefc;
      --muted:#a8b4d6;
      --line:rgba(255,255,255,.12);

      --cardA: rgba(255,255,255,.06);
      --cardB: rgba(255,255,255,.03);

      --accent:#22c55e; /* ひとまず在庫=緑 */
      --ok:#34d399;
      --warn:#fbbf24;
      --ng:#fb7185;

      --primary:#111827;
    }

    /* =========================
       Base styles (keep the "good old" dark vibe)
       ========================= */
    *{ box-sizing:border-box; }
    body{
      margin:0;
      color:var(--txt);
      font-family:system-ui,-apple-system,"Noto Sans JP",sans-serif;

      /* 以前の “良さ”：ダークはradial + gradient */
      background:
        radial-gradient(1200px 600px at 20% -10%, rgba(96,165,250,.18), transparent 55%),
        radial-gradient(1000px 520px at 90% 10%, rgba(167,139,250,.14), transparent 55%),
        radial-gradient(900px 520px at 50% 120%, rgba(34,197,94,.10), transparent 55%),
        linear-gradient(180deg, var(--bg1), var(--bg2));
      min-height:100vh;
      -webkit-text-size-adjust: 100%;
    }

    /* lightは“現場見やすさ最優先”で単色に寄せる */
    body[data-theme="light"]{
      background: var(--bg1);
    }

    a{ color:inherit; text-decoration:none; }
    .page{ max-width:1100px; margin:0 auto; padding:14px; }

    /* ===== Header ===== */
    .app-header{
      position:sticky; top:0; z-index:50;
      background: rgba(10,16,30,.72);
      backdrop-filter:saturate(160%) blur(12px);
      border-bottom:1px solid var(--line);
    }
    .app-header__inner{
      max-width:1100px; margin:0 auto; padding:12px 14px;
      display:grid; grid-template-columns: 1fr 2fr 1fr; gap:10px; align-items:center;
    }
    @media (max-width:820px){
      .app-header__inner{ grid-template-columns: 1fr; }
      .app-header__left{ order:1; }
      .app-header__center{ order:0; }
      .app-header__right{ order:2; justify-self:start; }
    }
    .app-title{ font-size:18px; font-weight:900; letter-spacing:.3px; }
    .app-sub{ margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; }

    .pill{
      display:inline-flex; align-items:center; gap:8px;
      border:1px solid var(--line);
      background: var(--cardA);
      color:var(--muted);
      padding:6px 10px; border-radius:999px; font-size:12px; line-height:1.2;
    }

    /* ===== Buttons ===== */
    .btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      min-height: var(--tap);
      padding:10px 14px;
      border-radius:14px;
      border:1px solid var(--line);
      background: var(--cardA);
      color:var(--txt);
      cursor:pointer;
      font-size:14px;
      user-select:none;
      -webkit-tap-highlight-color: transparent;
    }
    .btn:hover{ filter: brightness(1.05); }
    .btn:active{ transform: translateY(1px); }

    .btn-primary{
      border-color:transparent;
      background: linear-gradient(135deg, rgba(96,165,250,.95), rgba(167,139,250,.85));
      box-shadow: var(--shadow);
      color: #fff;
    }

    .app-back{
      min-height: var(--tap);
      display:inline-flex; align-items:center; justify-content:center;
      padding:10px 14px;
      border-radius:14px;
      border:1px solid var(--line);
      background: var(--cardA);
      font-size:14px;
    }

    /* ===== Cards ===== */
    .card{
      border:1px solid var(--line);
      border-radius: var(--radius);
      background: linear-gradient(180deg, var(--cardA), var(--cardB));
      box-shadow: var(--shadow);
      padding:14px;
    }
    .muted{ color:var(--muted); font-size:12px; }

    /* =========================
       light theme overrides (効くように body[data-theme="light"] に統一)
       ========================= */
    body[data-theme="light"] .app-header{
      padding: 10px 0 12px;
      background: linear-gradient(180deg, rgba(244,246,251,.98), rgba(244,246,251,.90));
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--line);
    }

    body[data-theme="light"] .app-back{
      background:#fff;
      border:1px solid var(--line);
      box-shadow: 0 10px 18px rgba(0,0,0,.06);
    }

    body[data-theme="light"] .pill{
      background:#fff;
      border:1px solid var(--line);
      color: var(--muted);
      font-weight: 900;
    }

    body[data-theme="light"] .card{
      background: #fff;
      border: 1px solid var(--line);
      box-shadow: var(--shadow);
      border-radius: var(--radius);
    }

    /* ボタン：あなたの iPad CSS の btn 風 */
    body[data-theme="light"] .btn,
    body[data-theme="light"] .btnSmall{
      border: none;
      border-radius: 16px;
      min-height: 58px;
      padding: 14px 14px;
      font-size: 16px;
      font-weight: 1000;
      box-shadow: 0 10px 18px rgba(0,0,0,.08);
      background: #fff;
      color: var(--txt);
    }

    /* 色ボタン互換（必要なら使える） */
    body[data-theme="light"] .b-dark{ background: var(--primary); color:#fff; }
    body[data-theme="light"] .b-blue{ background: var(--blue); color:#fff; }
    body[data-theme="light"] .b-cyan{ background: var(--cyan); color:#fff; }
    body[data-theme="light"] .b-green{ background: var(--green); color:#fff; }
    body[data-theme="light"] .b-yellow{ background: var(--yellow); color:#111; }
    body[data-theme="light"] .b-orange{ background: var(--orange); color:#111; }
    body[data-theme="light"] .b-red{ background: var(--red); color:#fff; }
    body[data-theme="light"] .b-purple{ background: var(--purple); color:#fff; }

    body[data-theme="light"] input,
    body[data-theme="light"] select{
      border-radius: 14px;
      border: 1px solid var(--line);
      background: #fff;
      padding: 14px 12px;
      min-height: 58px;
      font-size: 16px;
      font-weight: 800;
      outline:none;
    }
    body[data-theme="light"] input:focus,
    body[data-theme="light"] select:focus{
      border-color: rgba(37,99,235,.55);
      box-shadow: 0 0 0 4px rgba(37,99,235,.12);
    }
    .prodCard{
      cursor: pointer;
      pointer-events: auto;
    }
    .prodCard *{
      pointer-events: none; /* 中の要素がクリックを奪って死ぬ事故を防ぐ */
    }
    /* ===== スマホ最適化（PCは一切影響なし） ===== */
@media (max-width: 640px) {

  table thead {
    display: none;
  }

  table tbody tr {
    display: block;
    margin-bottom: 14px;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 10px;
    background: #fff;
  }

  table tbody td {
    display: block;
    padding: 6px 4px;
    border: none;
    text-align: left !important;
  }

  /* 商品名 */
  table tbody td:first-child {
    font-weight: 900;
    font-size: 15px;
    margin-bottom: 6px;
  }

  /* 種別・数量 */
  table tbody td:nth-child(2),
  table tbody td:nth-child(4) {
    display: inline-block;
    margin-right: 8px;
  }

  /* カテゴリ・バーコードは省略気味 */
  table tbody td:nth-child(3),
  table tbody td:nth-child(6) {
    font-size: 12px;
    color: var(--muted);
  }

  /* 「この商品を入出庫」ボタン */
  table tbody td a.btn {
    display: block;
    margin-top: 8px;
    text-align: center;
  }
}
@media (max-width:640px){
  .qty-badge{
    font-size:18px;
    font-weight:900;
  }
}
.barcode-detail summary {
  cursor: pointer;
  font-size: 13px;
  color: var(--accent);
  user-select: none;
}

.barcode-detail summary::marker {
  display: none;
}

.barcode-detail summary:before {
  content: "▶ ";
}

.barcode-detail[open] summary:before {
  content: "▼ ";
}

.barcode-value {
  margin-top: 6px;
  font-size: 13px;
  word-break: break-all;
  color: var(--txt);
}
  </style>
  <?php
}

/** ページ開始（PWA meta込み） */
function render_page_start(string $title): void {
  $theme = current_ui_theme();
  ?>
  <!doctype html>
  <html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title>

    <!-- PWA -->
    <link rel="manifest" href="/seika-app/public/manifest.webmanifest">
    <meta name="theme-color" content="#0b1220">

    <!-- iOS PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Seika">
    <link rel="apple-touch-icon" href="/seika-app/public/assets/icon-192.png">

    <?php render_layout_css(); ?>
  </head>
  <body data-theme="<?= h($theme) ?>">
  <?php
}

/** ページ終了 */
function render_page_end(): void {
  ?>
  <script>
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("/seika-app/public/sw.js").catch(()=>{});
    });
  }
  </script>
  </body>
  </html>
  <?php
}