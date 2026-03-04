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

$returnTo = (string)($_GET['return'] ?? '/seika-app/public/dashboard.php');
if ($returnTo === '') $returnTo = '/seika-app/public/dashboard.php';

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

$selected = get_current_store_id();
if ($selected <= 0) $selected = (int)$stores[0]['id'];

render_page_start('店舗選択');
render_header('店舗選択', [
  'back_href'  => '/seika-app/public/logout.php',
  'back_label' => 'ログアウト',
]);
?>
<div class="page"><div class="admin-wrap">
  <div class="card">
    <div style="font-weight:900;font-size:18px;">🏪 店舗を選択</div>
    <div class="muted" style="margin-top:6px;">この選択はセッションに保持されます。</div>

    <?php if ($err): ?>
      <div class="card" style="margin-top:10px;border-color:#ef4444;"><?= h($err) ?></div>
    <?php endif; ?>

    <form method="post" style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <select name="store_id" class="sel">
        <?php foreach ($stores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$selected)?'selected':'' ?>>
            <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn primary">この店舗で入る</button>
    </form>

    <div class="muted" style="margin-top:10px;">戻り先：<?= h($returnTo) ?></div>
  </div>
</div></div>

<style>
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.muted{opacity:.75;font-size:12px}
.sel{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
</style>
<?php render_page_end(); ?>