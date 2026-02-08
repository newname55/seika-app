<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();

$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/* =========================
   role 判定
========================= */
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');
$isCast    = has_role('cast');

/* =========================
   振り分け（castは専用へ）
========================= */
if ($isCast && !$isAdmin && !$isManager && !$isSuper) {
  header('Location: /seika-app/public/dashboard_cast.php');
  exit;
}

/* =========================
   admin / manager 用
   LINE未連携キャスト数（全体）
   ※ 店舗別にしたいなら WHERE ur.store_id = ? を足す
========================= */
$lineUnlinkedCount = 0;
if ($isAdmin || $isSuper || $isManager) {
  $st = $pdo->query("
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id AND r.code = 'cast'
    LEFT JOIN user_identities ui
      ON ui.user_id = u.id
      AND ui.provider = 'line'
      AND ui.is_active = 1
    WHERE ui.id IS NULL
      AND u.is_active = 1
  ");
  $lineUnlinkedCount = (int)$st->fetchColumn();
}

/* =========================
   画面
========================= */
render_page_start('WB支援システム');
render_header('WB支援システム');
?>

<div class="page">
<div class="admin-wrap">

  <div class="dashHead">
    <div class="dashTitle">🏠 WB支援システム</div>
    <div class="muted" style="margin-top:4px;">
      <?= $isSuper ? '全店舗管理者'
        : ($isAdmin ? '管理者'
        : ($isManager ? '店長'
        : 'キャスト')) ?> としてログイン中
    </div>
  </div>

  <!-- =========================
       ✅ 共通（admin/manager/super）
  ========================= -->
  <?php if ($isAdmin || $isSuper || $isManager): ?>
    <div class="sectionTitle">運用</div>
    <div class="card-grid">

      <a class="card" href="/seika-app/public/manager_today_schedule.php">
        <div class="icon">📋</div>
        <b>本日の予定</b>
        <div class="muted">今日＋今週（7日）</div>
      </a>

      <a class="card" href="/seika-app/public/cast_week_plans.php">
        <div class="icon">🗓</div>
        <b>週予定入力</b>
        <div class="muted">キャスト×7日</div>
      </a>

      <a class="card" href="/seika-app/public/dashboard_cast.php">
        <div class="icon">📱</div>
        <b>キャスト画面（確認）</b>
        <div class="muted">スマホUI</div>
      </a>

      <a class="card" href="/seika-app/public/stock/index.php">
        <div class="icon">📦</div>
        <b>酒在庫管理</b>
        <div class="muted">商品 / 移動 / 棚卸</div>
      </a>

    </div>
  <?php endif; ?>

  <!-- =========================
       ✅ 管理（admin/super）
  ========================= -->
  <?php if ($isAdmin || $isSuper): ?>
    <div class="sectionTitle" style="margin-top:16px;">管理</div>
    <div class="card-grid">

      <a class="card" href="/seika-app/public/store_casts.php">
        <div class="icon">👥</div>
        <b>店別キャスト管理</b>
        <div class="muted">所属 / 異動 / 招待</div>
      </a>

      <a class="card" href="/seika-app/public/store_casts.php#invites">
        <div class="icon">➕</div>
        <b>新人招待（QR/リンク）</b>
        <div class="muted">履歴・失効</div>
      </a>

      <a class="card" href="/seika-app/public/admin_users.php">
        <div class="icon">👤</div>
        <b>ユーザー管理</b>
        <div class="muted">権限・連携</div>
      </a>

      <a class="card" href="/seika-app/public/store_casts.php#line-alert">
        <div class="icon">⚠</div>
        <b>
          LINE未連携
          <?php if ($lineUnlinkedCount > 0): ?>
            <span class="badge-red"><?= (int)$lineUnlinkedCount ?></span>
          <?php endif; ?>
        </b>
        <div class="muted">要対応キャスト</div>
      </a>

      <!-- ✅ 追加：勤怠（集計）土台ページ（まだ無いなら後で作る） -->
      <a class="card" href="/seika-app/public/attendance_reports.php">
        <div class="icon">🧾</div>
        <b>勤怠集計</b>
        <div class="muted">半期/期間・労働時間</div>
      </a>

    </div>

    <div class="muted" style="margin-top:10px;">
      ※「勤怠集計」はまだ未実装なら 404 になります。作るときはここに繋がります。
    </div>
  <?php endif; ?>

  <!-- =========================
       ✅ 店長（manager専用：adminは上に出る）
  ========================= -->
  <?php if ($isManager && !$isAdmin && !$isSuper): ?>
    <div class="sectionTitle" style="margin-top:16px;">店長</div>
    <div class="card-grid">

      <a class="card" href="/seika-app/public/store_casts.php">
        <div class="icon">👥</div>
        <b>所属キャスト</b>
        <div class="muted">LINE連携確認</div>
      </a>

      <a class="card" href="/seika-app/public/store_casts.php#invites">
        <div class="icon">➕</div>
        <b>新人招待</b>
        <div class="muted">この店のみ</div>
      </a>

      <!-- ✅ 追加：在庫（list.php が動くならこちらでもOK） -->
      <a class="card" href="/seika-app/public/stock/list.php">
        <div class="icon">📦</div>
        <b>在庫（一覧）</b>
        <div class="muted">現場向け</div>
      </a>

    </div>
  <?php endif; ?>

  <!-- =========================
       （保険）キャスト表示（基本ここには来ない）
  ========================= -->
  <?php if ($isCast && !$isAdmin && !$isManager && !$isSuper): ?>
    <div class="sectionTitle" style="margin-top:16px;">キャスト</div>
    <div class="card-grid cast-grid">

      <a class="card big" href="/seika-app/public/cast_today.php">
        <div class="icon">⏰</div>
        <b>本日の出勤</b>
      </a>

      <a class="card big" href="/seika-app/public/cast_week.php">
        <div class="icon">🗓</div>
        <b>出勤予定</b>
      </a>

      <a class="card big" href="/seika-app/public/profile.php">
        <div class="icon">👤</div>
        <b>プロフィール</b>
      </a>

      <a class="card big" href="/seika-app/public/help.php">
        <div class="icon">❓</div>
        <b>ヘルプ</b>
      </a>

    </div>
  <?php endif; ?>

</div>
</div>

<style>
.dashTitle{ font-weight:1000; font-size:20px; }
.sectionTitle{
  margin-top:14px;
  margin-bottom:8px;
  font-weight:900;
  opacity:.9;
}

.card-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:12px;
}
.card{
  padding:14px;
  border:1px solid var(--line);
  border-radius:14px;
  background:var(--cardA);
  text-decoration:none;
  color:inherit;
  transition: transform .12s ease, box-shadow .12s ease;
}
.card:hover{
  transform:translateY(-2px);
  box-shadow:0 10px 24px rgba(0,0,0,.18);
}
.card .icon{
  font-size:22px;
  margin-bottom:6px;
}
.badge-red{
  background:#ef4444;
  color:#fff;
  font-size:11px;
  padding:2px 6px;
  border-radius:999px;
  margin-left:6px;
}
.muted{ opacity:.75; font-size:12px; }

.cast-grid{
  grid-template-columns:1fr 1fr;
}
.cast-grid .card.big{
  padding:22px;
  text-align:center;
}
@media (max-width:420px){
  .cast-grid{ grid-template-columns:1fr; }
}
</style>

<?php render_page_end(); ?>