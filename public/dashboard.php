<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_casts.php';
require_once __DIR__ . '/../app/store.php';

require_login();
$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');
$isCast    = has_role('cast');

// cast専用へ
if ($isCast && !$isAdmin && !$isManager && !$isSuper) {
  header('Location: /seika-app/public/dashboard_cast.php');
  exit;
}

// ✅ super_user は「未選択なら店舗選択を挟む」
require_store_selected_for_super($isSuper, '/seika-app/public/dashboard.php');

/**
 * ✅ 店舗一覧の方針
 * - super_user: 全店
 * - admin: 全店（切り替えたい）
 * - manager: 自分に紐づく店だけ
 */
$stores = [];
if ($isSuper || $isAdmin) {
  $stores = $pdo->query("SELECT id,name FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($isManager) {
  $stores = repo_allowed_stores($pdo, $userId, false);
}

$storeId = 0;
if ($stores) {
  // GET優先 → セッション（統合キー） → 先頭
  $candidate = (int)($_GET['store_id'] ?? 0);
  if ($candidate <= 0) $candidate = get_current_store_id();
  if ($candidate <= 0) $candidate = (int)$stores[0]['id'];

  $allowedIds = array_map(fn($s)=>(int)$s['id'], $stores);
  if (!in_array($candidate, $allowedIds, true)) {
    $candidate = (int)$stores[0]['id'];
  }

  $storeId = $candidate;
  set_current_store_id($storeId); // ✅ 統一して保存
}

// 店舗名
$storeName = '';
foreach ($stores as $s) {
  if ((int)$s['id'] === $storeId) { $storeName = (string)$s['name']; break; }
}
// ===== dashboard safe defaults =====
$lineUnlinkedCount = 0;
$staffStores       = $staffStores ?? [];
/* =========================
   表示
========================= */
render_page_start('ダッシュボード');
render_header('ダッシュボード');
?>

<div class="page">
<div class="admin-wrap">

<?php if (($isSuper || $isAdmin || $isManager) && !empty($stores)): ?>
<form method="get" class="searchRow" style="margin-top:10px;">
  <label class="muted">表示店舗</label>
  <select name="store_id" class="sel">
    <?php foreach ($stores as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
        <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
      </option>
    <?php endforeach; ?>
  </select>
  <button class="btn">切替</button>
</form>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <b>店舗：</b><?= h($storeName ?: ('#'.$storeId)) ?>
  <div class="muted" style="margin-top:6px;">
    ※この店舗IDはセッションに保存され、他ページ（在庫/顧客/出勤など）でも共通で使われます
  </div>
</div>
<div class="dash-wrap">

  <!-- ========= 今日の業務 ========= -->
  <section class="dash-block">
    <h2>📋 今日の業務</h2>
    <div class="dash-grid">
      <a class="dash-card" href="/seika-app/public/attendance/index.php">
        <div class="icon">🕒</div>
        <div class="title">出勤管理</div>
        <div class="desc">今日来る人・来た人を見る</div>
      </a>

      <a class="dash-card" href="/seika-app/public/cashier/index.php">
        <div class="icon">💰</div>
        <div class="title">会計・伝票</div>
        <div class="desc">お会計を作る</div>
      </a>

      <a class="dash-card" href="/seika-app/public/stock/index.php">
        <div class="icon">📦</div>
        <div class="title">在庫</div>
        <div class="desc">お酒の残りを見る</div>
      </a>

      <a class="dash-card" href="/seika-app/public/events/list.php">
        <div class="icon">🎉</div>
        <div class="title">イベント</div>
        <div class="desc">岡山県のイベント一覧毎週月曜日に観光サイトから自動収集</div>
      </a>

      <a class="dash-card" href="/seika-app/public/orders/dashboard_orders.php">
        <div class="icon">🍺</div>
        <div class="title">注文</div>
        <div class="desc">注文システム</div>
      </a>

    </div>
  </section>


  <!-- =========================
       admin / super_user
  ========================= -->
  <?php if ($isAdmin || $isSuper): ?>
      <!-- ========= 人の管理 ========= -->
  <section class="dash-block">
    <h2>👩‍💼 お店の設定</h2>
    <div class="dash-grid">
      <a class="dash-card" href="/seika-app/public/cast_week_plans.php">
        <div class="icon">📆</div>
        <div class="title">出勤予定</div>
        <div class="desc">シフトを見る・決める</div>
      </a>

      <a class="dash-card" href="/seika-app/public/admin/cast_edit.php">
        <div class="icon">✏️</div>
        <div class="title">キャスト編集</div>
        <div class="desc">名前・雇用・店番を変える</div>
      </a>

      <a class="dash-card" href="/seika-app/public/store_select.php">
        <div class="icon">🏷️</div>
        <div class="title">店舗選択</div>
        <div class="desc">今使うお店を切り替える</div>
      </a>

      <a class="dash-card" href="/seika-app/public/admin/index.php">
        <div class="icon">⚙️</div>
        <div class="title">管理画面</div>
        <div class="desc">設定・管理用</div>
      </a>
    </div>
  </section>

  <!-- ========= お店の設定 ========= -->
  <!-- <section class="dash-block">
    <h2>🏪 管理画面</h2>
      <div class="card-grid" style="margin-top:16px;">
      <a class="dash-card" href="/seika-app/public/store_select.php">
        <div class="icon">🏷️</div>
        <div class="title">店舗選択</div>
        <div class="desc">今使うお店を切り替える</div>
      </a>

      <a class="dash-card" href="/seika-app/public/admin/index.php">
        <div class="icon">⚙️</div>
        <div class="title">管理画面</div>
        <div class="desc">設定・管理用</div>
      </a>
    </div>
  </section> -->

</div>

  <div style="font-weight:1000; font-size:20px;">
    <H2>🏠 ダッシュボード</H2>
  </div>
  <div class="card-grid" style="margin-top:20px;">

  <a class="card" href="/seika-app/public/points_day.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">⭐</div>
      <b>ポイント（日別入力）</b>
      <div class="muted">同伴/指名を日ごとに入力</div>
    </a>

    <a class="card" href="/seika-app/public/points_terms.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">📊</div>
      <b>ポイント（半月集計）</b>
      <div class="muted">1–15 / 16–末</div>
    </a>

    <a class="card" href="/seika-app/public/store_casts_list.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">📄</div>
      <b>キャスト一覧（閲覧）</b>
      <div class="muted">店舗選択して一覧だけ見る</div>
    </a>

    <a class="card" href="/seika-app/public/manager_today_schedule.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">📋</div>
      <b>本日の予定</b>
      <div class="muted">遅刻/欠勤LINE・返信反映</div>
    </a>

    <a class="card" href="/seika-app/public/store_casts.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">👥</div>
      <b>店別キャスト管理</b>
      <div class="muted">招待リンク / 所属 / LINE確認</div>
    </a>

    <a class="card" href="/seika-app/public/store_casts_list.php?store_id=<?= (int)$storeId ?>&filter=line_unlinked">
      <div class="icon">⚠</div>
      <b>
        LINE未連携（一覧）
        <?php if ($lineUnlinkedCount > 0): ?>
          <span class="badge-red"><?= (int)$lineUnlinkedCount ?></span>
        <?php endif; ?>
      </b>
      <div class="muted">要対応キャストのみ</div>
    </a>

    <a class="card" href="/seika-app/public/admin_users.php">
      <div class="icon">👤</div>
      <b>ユーザー管理</b>
      <div class="muted">権限・連携</div>
    </a>

    <a class="card" href="/seika-app/public/admin/index.php">
      <div class="icon">👤</div>
      <b>管理ランチャー</b>
      <div class="muted">管理ランチャー</div>
    </a>

    <a class="card" href="/seika-app/public/cast_week_plans.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">🗓</div>
      <b>週予定入力</b>
      <div class="muted">キャスト×7日</div>
    </a>

    <a class="card" href="/seika-app/public/attendance_reports.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">⏱</div>
      <b>出勤レポート</b>
      <div class="muted">日別/スタッフ別</div>
    </a>

    <a class="card" href="/seika-app/public/stock/index.php">
      <div class="icon">📦</div>
      <b>酒在庫管理</b>
      <div class="muted">商品 / 移動 / 棚卸</div>
    </a>

    <a class="card" href="/seika-app/public/customer/?store_id=<?= (int)$storeId ?>">
      <div class="icon">👥</div>
      <b>顧客カルテ</b>
      <div class="muted">要約 / NG / 来店履歴</div>
    </a>

    <a class="card" href="/seika-app/public/customer.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">🗒</div>
      <b>顧客管理（営業ノート）</b>
      <div class="muted">名前＋特徴 → メモ → 重複は後で統合</div>
    </a>

  </div>
  <?php endif; ?>

  <!-- =========================
       店長（manager専用）
  ========================= -->
  <?php if ($isManager && !$isAdmin && !$isSuper): ?>
  <div class="card-grid" style="margin-top:16px;">

    <a class="card" href="/seika-app/public/manager_today_schedule.php">
      <div class="icon">📋</div>
      <b>本日の予定</b>
      <div class="muted">遅刻/欠勤LINE・返信反映</div>
    </a>
    <a class="card" href="/seika-app/public/cast_week_plans.php">
      <div class="icon">🗓</div>
      <b>週予定入力</b>
      <div class="muted">キャスト×7日</div>
    </a>

    <a class="card" href="/seika-app/public/points_day.php">
      <div class="icon">⭐</div>
      <b>ポイント（日別入力）</b>
      <div class="muted">同伴/指名を日ごとに入力</div>
    </a>

    <a class="card" href="/seika-app/public/points_terms.php">
      <div class="icon">📊</div>
      <b>ポイント（半月集計）</b>
      <div class="muted">1–15 / 16–末</div>
    </a>
    <a class="card" href="/seika-app/public/attendance_reports.php">
      <div class="icon">⏱</div>
      <b>出勤レポート</b>
      <div class="muted">日別/スタッフ別</div>
    </a>

    <a class="card" href="/seika-app/public/stock/list.php">
      <div class="icon">📦</div>
      <b>在庫</b>
      <div class="muted">商品 / 移動 / 棚卸</div>
    </a>

   <a class="card" href="/seika-app/public/customer/?store_id=<?= (int)$storeId ?>">
      <div class="icon">👥</div>
      <b>顧客カルテ</b>
      <div class="muted">要約 / NG / 来店履歴</div>
    </a>
    <a class="card" href="/seika-app/public/customer.php?store_id=<?= (int)$storeId ?>">
      <div class="icon">🗒</div>
      <b>顧客管理（営業ノート）</b>
      <div class="muted">名前＋特徴 → メモ → 重複は後で統合</div>
    </a>

     <a class="card" href="/seika-app/public/store_casts.php#invites">
      <div class="icon">➕</div>
      <b>新人招待</b>
      <div class="muted">招待リンク発行</div>
    </a>
  </div>
  <?php endif; ?>

</div>
</div>

<style>
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
}
.card:hover{
  transform:translateY(-2px);
  box-shadow:0 10px 24px rgba(0,0,0,.18);
}
.card .icon{
  font-size:22px;
  margin-bottom:6px;
}
.card-warn{
  border-color:#f59e0b;
}
.note-list{
  margin:8px 0 0;
  padding-left:18px;
  font-size:13px;
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
.dash-wrap{
  display:flex;
  flex-direction:column;
  gap:28px;
}

.dash-block h2{
  font-size:18px;
  margin-bottom:10px;
}

.dash-grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap:14px;
}

.dash-card{
  display:block;
  padding:16px;
  border-radius:14px;
  border:1px solid var(--line);
  background:var(--cardA);
  text-decoration:none;
  color:inherit;
}

.dash-card:hover{
  background:rgba(255,255,255,.06);
}

.dash-card .icon{
  font-size:26px;
}

.dash-card .title{
  font-weight:900;
  margin-top:6px;
}

.dash-card .desc{
  font-size:12px;
  opacity:.75;
  margin-top:4px;
}

<?php render_page_end(); ?>