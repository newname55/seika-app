<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
require_role(['super_user','admin']);

render_page_start('管理ランチャー');
render_header('管理ランチャー', [
  'back_href'  => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);

?>
<div class="page">

  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <div style="font-weight:1000; font-size:18px;">🛠 管理メニュー</div>
        <div class="muted" style="margin-top:4px; line-height:1.6;">
          管理者だけが触る画面。<br>
          迷ったら「ユーザー管理」→「LINE連携」でOK。
        </div>
      </div>
      <div class="muted" style="text-align:right;">
        権限：admin / super_user のみ
      </div>
    </div>

    <div class="grid3" style="margin-top:14px;">
      <a class="tile tile-primary" href="/wbss/public/admin_users.php">
        <div class="tile__icon">👤</div>
        <div class="tile__main">
          <div class="tile__title">ユーザー管理</div>
          <div class="tile__desc">ユーザー作成/無効化/権限/店舗/LINE連携</div>
        </div>
        <div class="tile__hint">最重要</div>
      </a>

      <a class="tile" href="/wbss/public/store_select.php">
        <div class="tile__icon">🏪</div>
        <div class="tile__main">
          <div class="tile__title">店舗選択</div>
          <div class="tile__desc">自分の現在店舗を切り替える</div>
        </div>
        <div class="tile__hint">切替</div>
      </a>

      <a class="tile" href="/wbss/public/stock/index.php">
        <div class="tile__icon">📦</div>
        <div class="tile__main">
          <div class="tile__title">在庫ランチャー</div>
          <div class="tile__desc">現場の入出庫・棚卸へ</div>
        </div>
        <div class="tile__hint">現場</div>
      </a>
    </div>

    <div class="grid3" style="margin-top:12px;">
      <a class="tile tile-warn" href="/wbss/public/admin/deploy_history.php">
        <div class="tile__icon">🧯</div>
        <div class="tile__main">
          <div class="tile__title">本番 deploy / rollback</div>
          <div class="tile__desc">本番履歴の確認と直前成功 deploy への rollback</div>
        </div>
        <div class="tile__hint">本番</div>
      </a>

      <a class="tile tile-primary" href="/wbss/public/applicants/index.php">
        <div class="tile__icon">🧾</div>
        <div class="tile__main">
          <div class="tile__title">面接者一覧</div>
          <div class="tile__desc">面接台帳、体験入店、在籍、店舗移動を管理する</div>
        </div>
        <div class="tile__hint">採用</div>
      </a>

      <a class="tile tile-good" href="/wbss/public/line_login_start.php?mode=link&return=<?= urlencode('/wbss/public/admin/index.php') ?>">
        <div class="tile__icon">🔗</div>
        <div class="tile__main">
          <div class="tile__title">自分のLINE連携</div>
          <div class="tile__desc">この管理者アカウントにLINEを紐付ける</div>
        </div>
        <div class="tile__hint">リンク</div>
      </a>

      <div class="tile tile-ghost" style="pointer-events:none;">
        <div class="tile__icon">✅</div>
        <div class="tile__main">
          <div class="tile__title">運用メモ</div>
          <div class="tile__desc">キャストは「LINEログインのみ」に寄せる</div>
        </div>
        <div class="tile__hint">方針</div>
      </div>

      <div class="tile tile-ghost" style="pointer-events:none;">
        <div class="tile__icon">🧯</div>
        <div class="tile__main">
          <div class="tile__title">トラブル時</div>
          <div class="tile__desc">LINE未連携→ admin_users で連携</div>
        </div>
        <div class="tile__hint">手順</div>
      </div>
    </div>

  </div>

</div>

<style>
  .grid3{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap:14px;
  }

  .tile{
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px;
    border-radius:18px;
    border:1px solid var(--line);
    background: linear-gradient(180deg, var(--cardA), var(--cardB));
    box-shadow: var(--shadow);
    min-height: 84px;
    text-decoration:none;
    color:inherit;
  }
  .tile:hover{ filter: brightness(1.06); }
  .tile:active{ transform: translateY(1px); }

  .tile__icon{
    width:54px; height:54px;
    border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    font-size:26px;
    border:1px solid var(--line);
    background: rgba(255,255,255,.04);
    flex: 0 0 auto;
  }
  .tile__main{ flex:1; min-width: 0; }
  .tile__title{ font-weight:1000; font-size:16px; letter-spacing:.2px; }
  .tile__desc{ margin-top:4px; color:var(--muted); font-size:12px; line-height:1.4; }
  .tile__hint{
    flex:0 0 auto;
    font-size:12px;
    font-weight:900;
    color: var(--muted);
    border:1px solid var(--line);
    border-radius:999px;
    padding:6px 10px;
    background: rgba(255,255,255,.03);
  }

  .tile-primary{
    border-color: color-mix(in srgb, var(--accent) 35%, var(--line));
    background: linear-gradient(180deg,
      color-mix(in srgb, var(--accent) 18%, var(--cardA)),
      var(--cardB)
    );
  }
  .tile-good{
    border-color: color-mix(in srgb, var(--ok) 35%, var(--line));
    background: linear-gradient(180deg,
      color-mix(in srgb, var(--ok) 14%, var(--cardA)),
      var(--cardB)
    );
  }
  .tile-warn{
    border-color: color-mix(in srgb, var(--warn) 35%, var(--line));
    background: linear-gradient(180deg,
      color-mix(in srgb, var(--warn) 14%, var(--cardA)),
      var(--cardB)
    );
  }
  .tile-ghost{
    opacity:.85;
    background: rgba(255,255,255,.02);
  }

  body[data-theme="light"] .tile{
    background:#fff;
    border:1px solid var(--line);
    box-shadow: 0 10px 18px rgba(0,0,0,.06);
  }
  body[data-theme="light"] .tile__icon{
    background: var(--softBlue);
    border: 1px solid var(--line);
  }
  body[data-theme="light"] .tile__hint{
    background:#fff;
    box-shadow: 0 10px 18px rgba(0,0,0,.05);
    border: 1px solid var(--line);
  }
</style>

<?php render_page_end(); ?>
