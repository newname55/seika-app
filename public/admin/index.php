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
  <div class="admin-wrap dashboard-shell admin-launcher-shell">
    <section class="admin-launcher-hero">
      <div class="admin-launcher-hero__copy">
        <div class="hero-store-label">Admin Launcher</div>
        <div class="hero-store-name">管理メニュー</div>
        <div class="hero-store-meta">admin / super_user only</div>
        <span class="hero-badge">重要操作</span>
        <h1>設定、解約、採用、運用導線を1か所にまとめています。</h1>
        <p>普段触らない画面だけを切り出した管理者向けランチャーです。迷ったらまず「ユーザー管理」か「店舗解約・データ廃棄」から開けば大丈夫です。</p>
      </div>
      <div class="admin-launcher-hero__aside">
        <div class="dashboard-inline-panel admin-launcher-panel">
          <div class="store-switch-label">権限</div>
          <div class="admin-launcher-panel__value">admin / super_user</div>
          <div class="store-switch-help">一般運用の入口とは分けて、重い操作だけをここへ集約しています。</div>
        </div>
        <div class="dashboard-inline-panel admin-launcher-panel">
          <div class="store-switch-label">おすすめ順</div>
          <div class="admin-launcher-panel__list">
            <span>1. ユーザー管理</span>
            <span>2. 店舗解約・データ廃棄</span>
            <span>3. 面接者一覧</span>
          </div>
        </div>
      </div>
    </section>

    <section class="dash-section admin-launcher-section">
      <div class="dash-section-head">
        <div>
          <h2>主要メニュー</h2>
          <p>毎回使う可能性が高い管理導線です。ユーザー、解約、採用の3つを最上段に置いています。</p>
        </div>
      </div>
      <div class="dash-grid admin-launcher-grid">
        <a class="dash-card is-primary" href="/wbss/public/admin_users.php">
          <div class="dash-card-top">
            <span class="dash-icon">👤</span>
            <span class="dash-tag">最重要</span>
          </div>
          <div class="dash-title">ユーザー管理</div>
          <div class="dash-desc">ユーザー作成、無効化、権限、店舗、LINE 連携をまとめて管理します。</div>
        </a>

        <a class="dash-card is-warn" href="/wbss/public/admin/store_decommission.php">
          <div class="dash-card-top">
            <span class="dash-icon">🗃️</span>
            <span class="dash-tag">解約</span>
          </div>
          <div class="dash-title">店舗解約・データ廃棄</div>
          <div class="dash-desc">停止、件数確認、申請、承認、予約、監査ログを管理します。</div>
        </a>

        <a class="dash-card is-primary" href="/wbss/public/applicants/index.php">
          <div class="dash-card-top">
            <span class="dash-icon">🧾</span>
            <span class="dash-tag">採用台帳</span>
          </div>
          <div class="dash-title">面接者一覧</div>
          <div class="dash-desc">面接、体験入店、在籍、店舗移動の流れをまとめて管理します。</div>
        </a>
      </div>
    </section>

    <section class="dash-section admin-launcher-section">
      <div class="dash-section-head">
        <div>
          <h2>運用と切替</h2>
          <p>店舗切替や現場ランチャー、自分の連携設定など、日々の管理運用を支える入口です。</p>
        </div>
      </div>
      <div class="dash-grid admin-launcher-grid">
        <a class="dash-card" href="/wbss/public/store_select.php">
          <div class="dash-card-top">
            <span class="dash-icon">🏪</span>
            <span class="dash-tag">切替</span>
          </div>
          <div class="dash-title">店舗選択</div>
          <div class="dash-desc">自分の現在店舗を切り替えて、他画面の表示基準を変更します。</div>
        </a>

        <a class="dash-card" href="/wbss/public/stock/index.php">
          <div class="dash-card-top">
            <span class="dash-icon">📦</span>
            <span class="dash-tag">現場</span>
          </div>
          <div class="dash-title">在庫ランチャー</div>
          <div class="dash-desc">現場の入出庫、棚卸、在庫確認の入口をまとめて開きます。</div>
        </a>

        <a class="dash-card is-primary" href="/wbss/public/line_login_start.php?mode=link&return=<?= urlencode('/wbss/public/admin/index.php') ?>">
          <div class="dash-card-top">
            <span class="dash-icon">🔗</span>
            <span class="dash-tag">リンク</span>
          </div>
          <div class="dash-title">自分のLINE連携</div>
          <div class="dash-desc">この管理者アカウントに LINE を紐付けて、通知や認証運用を整えます。</div>
        </a>
      </div>
    </section>

    <section class="dash-section admin-launcher-section">
      <div class="dash-section-head">
        <div>
          <h2>保守メモ</h2>
          <p>本番運用時の考え方や、トラブル時の最短導線をメモとして残しています。</p>
        </div>
      </div>
      <div class="dash-grid admin-launcher-grid">
        <a class="dash-card is-warn" href="/wbss/public/admin/deploy_history.php">
          <div class="dash-card-top">
            <span class="dash-icon">🧯</span>
            <span class="dash-tag">本番</span>
          </div>
          <div class="dash-title">本番 deploy / rollback</div>
          <div class="dash-desc">本番履歴の確認と、直前成功 deploy への rollback 管理 UI を開きます。</div>
        </a>

        <div class="dash-card admin-launcher-note">
          <div class="dash-card-top">
            <span class="dash-icon">✅</span>
            <span class="dash-tag">方針</span>
          </div>
          <div class="dash-title">運用メモ</div>
          <div class="dash-desc">キャストは「LINEログインのみ」に寄せる前提で、ID/PW 運用を増やしすぎない方針です。</div>
        </div>

        <div class="dash-card admin-launcher-note">
          <div class="dash-card-top">
            <span class="dash-icon">🆘</span>
            <span class="dash-tag">手順</span>
          </div>
          <div class="dash-title">トラブル時</div>
          <div class="dash-desc">LINE 未連携やログイン詰まりは、まず「ユーザー管理」から対象ユーザーの連携状態を確認します。</div>
        </div>
      </div>
    </section>
  </div>
</div>

<style>
  .admin-launcher-shell{
    max-width:1320px;
    padding-bottom:28px;
  }
  .admin-launcher-hero,
  .admin-launcher-section{
    border:1px solid var(--line);
    border-radius:22px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
      var(--cardA);
    box-shadow:0 16px 40px rgba(0,0,0,.14);
  }
  .admin-launcher-hero{
    display:grid;
    grid-template-columns:minmax(0, 1.55fr) minmax(250px, .7fr);
    gap:18px;
    padding:20px 22px;
    margin-top:10px;
    margin-bottom:14px;
  }
  .admin-launcher-hero__copy{
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:8px;
  }
  .admin-launcher-hero__copy h1{
    margin:0;
    font-size:25px;
    line-height:1.2;
  }
  .admin-launcher-hero__copy p{
    margin:0;
    color:var(--muted);
    font-size:13px;
    line-height:1.55;
  }
  .admin-launcher-hero__aside{
    display:grid;
    gap:12px;
    align-content:start;
  }
  .admin-launcher-panel{
    padding:12px;
  }
  .admin-launcher-panel__value{
    margin:4px 0 6px;
    font-size:18px;
    font-weight:1000;
  }
  .admin-launcher-panel__list{
    display:grid;
    gap:6px;
    margin-top:6px;
    font-size:13px;
    color:var(--muted);
  }
  .admin-launcher-section{
    padding:18px 18px 16px;
    margin-top:14px;
  }
  .admin-launcher-grid{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap:14px;
  }
  .admin-launcher-note{
    cursor:default;
  }
  .hero-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    color:#0f172a;
    background:linear-gradient(135deg, #facc15, #fb923c);
    width:max-content;
  }
  .hero-store-name{
    font-size:34px;
    font-weight:1000;
    color:var(--txt);
    line-height:1.05;
    letter-spacing:.01em;
  }
  .hero-store-label{
    font-size:12px;
    font-weight:900;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.08em;
  }
  .hero-store-meta{
    font-size:12px;
    font-weight:900;
    color:var(--muted);
  }
  .dashboard-inline-panel{
    border:1px solid var(--line);
    border-radius:16px;
    background:rgba(255,255,255,.03);
  }
  .store-switch-label{
    font-size:13px;
    font-weight:900;
  }
  .store-switch-help{
    margin:0;
    font-size:11px;
    color:var(--muted);
  }
  .dash-section-head{
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:flex-start;
    margin-bottom:12px;
  }
  .dash-section-head h2{
    margin:0;
    font-size:20px;
    line-height:1.2;
  }
  .dash-section-head p{
    margin:6px 0 0;
    color:var(--muted);
    font-size:13px;
    line-height:1.55;
  }
  .dash-card{
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:16px;
    min-height:168px;
    border-radius:18px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.03);
    text-decoration:none;
    color:inherit;
    transition:transform .14s ease, border-color .14s ease, background .14s ease;
  }
  .dash-card:hover{
    transform:translateY(-1px);
    border-color:color-mix(in srgb, var(--accent) 35%, var(--line));
    background:rgba(255,255,255,.05);
  }
  .dash-card.is-primary{
    border-color:color-mix(in srgb, var(--accent) 35%, var(--line));
    background:linear-gradient(180deg, color-mix(in srgb, var(--accent) 12%, transparent), rgba(255,255,255,.03));
  }
  .dash-card.is-warn{
    border-color:color-mix(in srgb, var(--warn) 35%, var(--line));
    background:linear-gradient(180deg, color-mix(in srgb, var(--warn) 12%, transparent), rgba(255,255,255,.03));
  }
  .dash-card-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
  }
  .dash-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:50px;
    height:50px;
    border-radius:16px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.04);
    font-size:24px;
  }
  .dash-tag{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid var(--line);
    color:var(--muted);
    font-size:11px;
    font-weight:900;
    background:rgba(255,255,255,.04);
  }
  .dash-title{
    font-size:18px;
    font-weight:1000;
    line-height:1.25;
  }
  .dash-desc{
    color:var(--muted);
    font-size:13px;
    line-height:1.6;
  }
  @media (max-width: 920px){
    .admin-launcher-hero{
      grid-template-columns:1fr;
    }
  }
</style>

<?php render_page_end(); ?>
