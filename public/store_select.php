<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';

require_login();
require_role(['admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function has_role(string $role): bool { return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true); }

$userId  = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$isSuper = has_role('super_user');
$isAdmin = has_role('admin');
$isMgr   = has_role('manager');
$loginId = (string)($_SESSION['login_id'] ?? '');
$displayName = (string)($_SESSION['display_name'] ?? '');
$roleLabel = (string)($_SESSION['role'] ?? '');
$who = $displayName !== '' ? $displayName : $loginId;

$returnRaw = (string)($_GET['return'] ?? $_GET['next'] ?? '/wbss/public/dashboard.php');
$returnTo = normalize_internal_wbss_path($returnRaw, '/wbss/public/dashboard.php');

$stores = [];
if ($isSuper || $isAdmin) {
  $stores = $pdo->query("SELECT id,name FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
  // manager: 紐づく店だけ
  $st = $pdo->prepare("
    SELECT DISTINCT s.id, s.name
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='manager'
    JOIN stores s ON s.id=ur.store_id AND s.is_active=1
    WHERE ur.user_id=? AND ur.store_id IS NOT NULL
    ORDER BY s.id ASC
  ");
  $st->execute([$userId]);
  $stores = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (!$stores) {
  http_response_code(403);
  exit('店舗に紐付いていません');
}

$selected = get_current_store_id();
if ($selected <= 0) $selected = (int)$stores[0]['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && count($stores) === 1) {
  $onlyStoreId = (int)$stores[0]['id'];
  if ($selected !== $onlyStoreId) {
    set_current_store_id($onlyStoreId);
  }
  header('Location: ' . $returnTo);
  exit;
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $sid = (int)($_POST['store_id'] ?? 0);
  $allowed = array_map(fn($s)=>(int)$s['id'], $stores);

  if ($sid <= 0 || !in_array($sid, $allowed, true)) {
    $err = '店舗が不正です';
  } else {
    set_current_store_id($sid);
    header('Location: ' . $returnTo);
    exit;
  }
}

$storeCount = count($stores);
$selectedStore = null;
foreach ($stores as $store) {
  if ((int)$store['id'] === $selected) {
    $selectedStore = $store;
    break;
  }
}

render_page_start('店舗選択');
render_header('店舗選択', [
  'back_href'  => $returnTo,
  'back_label' => '戻る',
  'show_store' => false,
  'show_user'  => false,
]);
?>
<div class="page">
  <div class="store-select-shell">
    <?php if ($err): ?>
      <div class="store-select-flash is-error"><?= h($err) ?></div>
    <?php endif; ?>

    <section class="store-select-overview" aria-label="現在の選択情報">
      <div class="store-select-overview__grid">
        <?php if ($selectedStore): ?>
          <div class="store-select-overview__item">
            <span class="store-select-overview__label">現在の店舗</span>
            <strong class="store-select-overview__value"><?= h((string)$selectedStore['name']) ?> (#<?= (int)$selectedStore['id'] ?>)</strong>
          </div>
        <?php endif; ?>
        <div class="store-select-overview__item">
          <span class="store-select-overview__label">ユーザー</span>
          <strong class="store-select-overview__value"><?= h($who !== '' ? $who : '-') ?></strong>
        </div>
        <div class="store-select-overview__item">
          <span class="store-select-overview__label">権限</span>
          <strong class="store-select-overview__value"><?= h($roleLabel !== '' ? $roleLabel : '-') ?></strong>
        </div>
      </div>
      <div class="store-select-overview__actions">
        <a class="btn btn-primary" href="<?= h($returnTo) ?>">戻る</a>
        <a class="btn" href="/wbss/public/logout.php">ログアウト</a>
      </div>
    </section>

    <section class="store-select-hero">
      <div class="store-select-hero__main">
        <div class="store-select-kicker">Store Access</div>
        <h1>作業する店舗を選択</h1>
        <p>選んだ店舗はこのセッションに保持されます。日報、勤怠、管理画面はこの店舗を基準に表示されます。</p>

        <div class="store-select-summary">
          <span class="store-select-chip">アクセス可能 <?= number_format($storeCount) ?> 店舗</span>
          <?php if ($selectedStore): ?>
            <span class="store-select-chip is-current">現在 <?= h((string)$selectedStore['name']) ?></span>
          <?php endif; ?>
          <?php if ($isSuper): ?>
            <span class="store-select-chip">権限 super_user</span>
          <?php elseif ($isAdmin): ?>
            <span class="store-select-chip">権限 admin</span>
          <?php elseif ($isMgr): ?>
            <span class="store-select-chip">権限 manager</span>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="store-select-card">
      <div class="store-select-head">
        <div>
          <h2>店舗一覧</h2>
          <p>カードを押すだけで切り替えられます。選択済みの店舗は強調表示されます。</p>
        </div>
      </div>

      <div class="store-grid">
        <?php foreach ($stores as $s): ?>
          <?php
            $storeId = (int)$s['id'];
            $isCurrent = ($storeId === $selected);
          ?>
          <form method="post" class="store-card-form">
            <input type="hidden" name="store_id" value="<?= $storeId ?>">
            <button type="submit" class="store-card<?= $isCurrent ? ' is-current' : '' ?>">
              <span class="store-card__meta">
                <span class="store-card__id">#<?= $storeId ?></span>
                <?php if ($isCurrent): ?>
                  <span class="store-card__badge">選択中</span>
                <?php endif; ?>
              </span>
              <span class="store-card__name"><?= h((string)$s['name']) ?></span>
              <span class="store-card__action"><?= $isCurrent ? 'この店舗で継続' : 'この店舗に切替' ?></span>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</div>

<style>
.store-select-shell{
  max-width:980px;
  margin:0 auto;
  padding-bottom:28px;
}
.store-select-flash,
.store-select-overview,
.store-select-hero,
.store-select-card{
  border:1px solid var(--line);
  border-radius:22px;
  background:linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)), var(--cardA);
  box-shadow:0 16px 40px rgba(0,0,0,.12);
}
.store-select-flash{
  padding:12px 14px;
  margin-bottom:12px;
}
.store-select-overview{
  display:grid;
  grid-template-columns:minmax(0, 1fr) auto;
  gap:14px;
  padding:16px 18px;
  margin-bottom:14px;
}
.store-select-overview__grid{
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:10px;
}
.store-select-overview__item{
  min-width:0;
  padding:12px 14px;
  border:1px solid var(--line);
  border-radius:16px;
  background:rgba(255,255,255,.04);
  display:grid;
  gap:6px;
}
.store-select-overview__label{
  font-size:12px;
  font-weight:800;
  color:var(--muted);
}
.store-select-overview__value{
  min-width:0;
  font-size:16px;
  font-weight:1000;
  line-height:1.35;
  word-break:break-word;
}
.store-select-overview__actions{
  min-width:180px;
  display:grid;
  gap:10px;
}
.store-select-flash.is-error{
  border-color:rgba(239,68,68,.40);
}
.store-select-hero{
  padding:20px;
}
.store-select-kicker{
  font-size:12px;
  font-weight:900;
  color:var(--muted);
  text-transform:uppercase;
  letter-spacing:.08em;
}
.store-select-hero h1{
  margin:10px 0 8px;
  font-size:30px;
  line-height:1.1;
}
.store-select-hero p{
  margin:0;
  color:var(--muted);
  line-height:1.6;
}
.store-select-summary{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:16px;
}
.store-select-chip{
  display:inline-flex;
  align-items:center;
  min-height:34px;
  padding:0 12px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.05);
  font-size:12px;
  font-weight:800;
}
.store-select-chip.is-current{
  border-color:color-mix(in srgb, var(--accent) 40%, var(--line));
  background:color-mix(in srgb, var(--accent) 12%, transparent);
}
.store-select-hero__side{
  display:grid;
  gap:10px;
  min-width:180px;
}
.store-select-card{
  margin-top:14px;
  padding:18px;
}
.store-select-head{
  margin-bottom:14px;
}
.store-select-head h2{
  margin:0;
  font-size:20px;
}
.store-select-head p{
  margin:6px 0 0;
  color:var(--muted);
  font-size:13px;
  line-height:1.5;
}
.store-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
  gap:12px;
}
.store-card-form{
  margin:0;
}
.store-card{
  width:100%;
  min-height:144px;
  padding:16px;
  border-radius:18px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  color:var(--txt);
  display:flex;
  flex-direction:column;
  gap:12px;
  text-align:left;
  cursor:pointer;
  transition:transform .08s ease, border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.store-card:hover{
  transform:translateY(-1px);
  border-color:color-mix(in srgb, var(--accent) 35%, var(--line));
}
.store-card.is-current{
  border-color:color-mix(in srgb, var(--accent) 50%, var(--line));
  background:color-mix(in srgb, var(--accent) 10%, var(--cardA));
  box-shadow:0 14px 24px rgba(0,0,0,.10);
}
.store-card__meta{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.store-card__id,
.store-card__badge{
  display:inline-flex;
  align-items:center;
  min-height:28px;
  padding:0 10px;
  border-radius:999px;
  border:1px solid var(--line);
  font-size:11px;
  font-weight:900;
  background:rgba(255,255,255,.05);
}
.store-card__badge{
  border-color:rgba(34,197,94,.35);
  background:rgba(34,197,94,.14);
  color:var(--ok);
}
.store-card__name{
  display:block;
  font-size:22px;
  font-weight:1000;
  line-height:1.2;
}
.store-card__action{
  display:block;
  margin-top:auto;
  color:var(--muted);
  font-size:13px;
  font-weight:800;
}
body[data-theme="light"] .store-select-flash,
body[data-theme="light"] .store-select-overview,
body[data-theme="light"] .store-select-hero,
body[data-theme="light"] .store-select-card,
body[data-theme="light"] .store-card{
  background:#fff;
  border-color:var(--line);
  box-shadow:0 12px 24px rgba(15,18,34,.08);
}
body[data-theme="light"] .store-select-chip,
body[data-theme="light"] .store-card__id,
body[data-theme="light"] .store-select-overview__item{
  background:#fff;
  border-color:var(--line);
}
body[data-theme="light"] .store-card.is-current{
  background:var(--softBlue);
}
@media (max-width: 720px){
  .store-select-overview{
    grid-template-columns:1fr;
    padding:14px;
  }
  .store-select-overview__grid{
    grid-template-columns:1fr;
  }
  .store-select-overview__actions{
    min-width:0;
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
  .store-select-hero{
    padding:16px;
  }
  .store-select-hero h1{
    font-size:24px;
  }
  .store-grid{
    grid-template-columns:1fr;
  }
  .store-card{
    min-height:0;
    padding:14px;
    gap:10px;
  }
  .store-card__name{
    font-size:19px;
  }
}
@media (max-width: 480px){
  .store-select-shell{
    padding-bottom:20px;
  }
  .store-select-overview__actions{
    grid-template-columns:1fr;
  }
  .store-select-hero h1{
    font-size:22px;
  }
  .store-select-hero p{
    font-size:14px;
  }
  .store-select-summary{
    gap:6px;
  }
  .store-select-chip{
    width:100%;
    justify-content:center;
  }
}
</style>
<?php render_page_end(); ?>
