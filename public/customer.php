<?php
declare(strict_types=1);

/* =====================================
   requires
===================================== */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_customers.php';
require_once __DIR__ . '/../app/service_customer.php';

require_login();
require_role(['cast','admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* =====================================
   helpers
===================================== */
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
function current_user_id_safe(): int {
  return function_exists('current_user_id')
    ? (int)current_user_id()
    : (int)($_SESSION['user_id'] ?? 0);
}
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['_csrf'];
}
function csrf_verify(?string $token): void {
  if (!$token || !isset($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
    http_response_code(403);
    exit('csrf error');
  }
}

/* =====================================
   role / user
===================================== */
$userId   = current_user_id_safe();
$isSuper  = has_role('super_user');
$isStaff  = $isSuper || has_role('admin') || has_role('manager');
$isCast   = has_role('cast');
$castOnly = (!$isStaff && $isCast);

/* =====================================
   store resolve
===================================== */
$stores  = [];
$storeId = 0;

if ($isStaff) {
  if ($isSuper) {
    $stores = $pdo->query(
      "SELECT id,name FROM stores WHERE is_active=1 ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $pdo->prepare("
      SELECT DISTINCT s.id,s.name
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code IN ('admin','manager')
      JOIN stores s ON s.id=ur.store_id AND s.is_active=1
      WHERE ur.user_id=?
    ");
    $st->execute([$userId]);
    $stores = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  $storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
  if ($storeId <= 0) $storeId = (int)($stores[0]['id'] ?? 0);

  $allowed = array_map(fn($s)=>(int)$s['id'], $stores);
  if (!in_array($storeId, $allowed, true)) {
    $storeId = (int)($stores[0]['id'] ?? 0);
  }
}

if ($castOnly) {
  // cast は自分の store に固定
  $st = $pdo->prepare("
    SELECT store_id
    FROM cast_profiles
    WHERE user_id=?
    LIMIT 1
  ");
  $st->execute([$userId]);
  $storeId = (int)($st->fetchColumn() ?: 0);

  if ($storeId <= 0) {
    $st = $pdo->prepare("
      SELECT ur.store_id
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code='cast'
      WHERE ur.user_id=?
      LIMIT 1
    ");
    $st->execute([$userId]);
    $storeId = (int)($st->fetchColumn() ?: 0);
  }
}

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が特定できません');
}

/* =====================================
   input
===================================== */
$action = (string)($_POST['action'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$viewId = (int)($_GET['id'] ?? 0);

$msg = '';
$err = '';

$forceAssignedUserId = $castOnly ? $userId : null;

/* =====================================
   POST actions
===================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);

    if ($action === 'create_customer') {
      $name        = trim((string)$_POST['name']);
      $feature     = trim((string)($_POST['feature'] ?? ''));
      $notePublic  = trim((string)($_POST['note_public'] ?? ''));

      $assignedId = $castOnly
        ? $userId
        : ((string)($_POST['assigned_user_id'] ?? '') !== '' ? (int)$_POST['assigned_user_id'] : null);

      $newId = repo_customer_create(
        $pdo,
        $storeId,
        $name,
        $feature,
        $notePublic,
        $assignedId,
        $userId
      );

      header("Location: customer.php?store_id={$storeId}&id={$newId}");
      exit;
    }

    if ($action === 'update_customer') {
      repo_customer_update(
        $pdo,
        $storeId,
        (int)$_POST['customer_id'],
        trim((string)$_POST['name']),
        trim((string)$_POST['feature']),
        trim((string)$_POST['note_public']),
        (string)$_POST['status'],
        $castOnly ? $userId : ((string)($_POST['assigned_user_id'] ?? '') !== '' ? (int)$_POST['assigned_user_id'] : null)
      );
      $msg = '更新しました';
    }

    if ($action === 'add_note') {
      repo_customer_add_note(
        $pdo,
        $storeId,
        (int)$_POST['customer_id'],
        $userId,
        trim((string)$_POST['note_text'])
      );
      header("Location: customer.php?store_id={$storeId}&id=".$_POST['customer_id']);
      exit;
    }

    if ($action === 'merge') {
      service_customer_merge(
        $pdo,
        $storeId,
        (int)$_POST['from_id'],
        (int)$_POST['to_id'],
        $userId
      );
      header("Location: customer.php?store_id={$storeId}&id=".$_POST['to_id']);
      exit;
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

/* =====================================
   load
===================================== */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=?");
$st->execute([$storeId]);
$storeName = (string)$st->fetchColumn();

$list = repo_customers_search($pdo, $storeId, $q, 80, $forceAssignedUserId);

$customer = null;
$notes = [];
$dupes = repo_customer_possible_duplicates(
  $pdo,
  $storeId,
  $viewId,
  10,
  $forceAssignedUserId
);
if ($viewId > 0) {
  $customer = repo_customer_get($pdo, $storeId, $viewId, $forceAssignedUserId);
  if ($castOnly && !$customer) {
    http_response_code(403);
    exit('forbidden');
  }
if ($customer) {
  $notes = repo_customer_notes($pdo, $storeId, $viewId, 50);
  $dupes = repo_customer_possible_duplicates($pdo, $storeId, $viewId, 10);
}
}

/* =====================================
   render
===================================== */
render_page_start('顧客管理');
render_header('顧客管理',[
  'back_href'=> $castOnly ? '/seika-app/public/dashboard_cast.php' : '/seika-app/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);
?>
<!-- ここからHTML（前と同じUIでOK） -->
<div class="page"><div class="admin-wrap">

  <div class="topRow">
    <div>
      <div class="title">🗒 顧客管理（営業ノート）</div>
      <div class="muted">「名前＋特徴」でまず登録 → メモを積み上げる → あとで統合（重複マージ）</div>
    </div>
    <div class="muted">
      店舗：<b><?= h($storeName) ?></b> (#<?= (int)$storeId ?>)
    </div>
  </div>

  <?php if ($err): ?><div class="card" style="border-color:#ef4444"><?= h($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="card" style="border-color:#22c55e"><?= h($msg) ?></div><?php endif; ?>

  <?php if ($isStaff && $stores): ?>
    <form method="get" class="searchRow">
      <label class="muted">店舗</label>
      <select name="store_id" class="sel">
        <?php foreach ($stores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
            <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <label class="muted">検索</label>
      <input class="sel" name="q" value="<?= h($q) ?>" placeholder="名前 / 特徴 / メモ / ID">
      <button class="btn">表示</button>
    </form>
  <?php else: ?>
    <form method="get" class="searchRow">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <label class="muted">検索</label>
      <input class="sel" name="q" value="<?= h($q) ?>" placeholder="名前 / 特徴 / メモ / ID">
      <button class="btn">表示</button>
    </form>
  <?php endif; ?>

  <div class="grid2">

    <!-- LEFT: list -->
    <div class="card">
      <div class="cardTitle">一覧（<?= count($list) ?>件）</div>
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:80px;">ID</th>
            <th>名前</th>
            <th>特徴</th>
            <th style="width:90px;">状態</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $r): ?>
            <?php
              $status = (string)($r['status'] ?? 'active');
              $statusLabel = ($status === 'inactive') ? '停止' : (($status === 'merged') ? '統合済' : '在籍');
              $statusCls = ($status === 'inactive' || $status === 'merged') ? 'off' : 'ok';
            ?>
            <tr class="<?= ((int)$r['id']===$viewId)?'activeRow':'' ?>">
              <td class="mono"><?= (int)$r['id'] ?></td>
              <td>
                <a class="link" href="/seika-app/public/customer.php?store_id=<?= (int)$storeId ?>&id=<?= (int)$r['id'] ?>">
                  <b><?= h((string)$r['name']) ?></b>
                </a>
                <?php if (!empty($r['assigned_name'])): ?>
                  <div class="muted">担当：<?= h((string)$r['assigned_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="muted"><?= h((string)$r['feature']) ?></td>
              <td><?= '<span class="badge '.$statusCls.'">'.h($statusLabel).'</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <hr class="hr">

      <div class="cardTitle">➕ 新規登録（名前＋特徴）</div>
      <form method="post" class="formCol">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <input type="hidden" name="action" value="create_customer">

        <label class="muted">名前（仮名OK）</label>
        <input class="sel" name="name" required placeholder="例：短髪メガネの人 / たけし？">

        <label class="muted">特徴（見分けポイント）</label>
        <input class="sel" name="feature" placeholder="例：左手に指輪 / 角ハイ / よく笑う">

        <label class="muted">店全体メモ（みんなが見てOK）</label>
        <input class="sel" name="note_public" placeholder="例：初回は静か。褒めると伸びるタイプ。">

        <?php if (!$castOnly): ?>
          <label class="muted">担当キャスト（user_id / 空なら店全体）</label>
          <input class="sel" name="assigned_user_id" inputmode="numeric" placeholder="例：10（かな）">
        <?php else: ?>
          <input type="hidden" name="assigned_user_id" value="<?= (int)$userId ?>">
        <?php endif; ?>

        <button class="btn primary">登録</button>
      </form>
    </div>

    <!-- RIGHT: detail -->
    <div class="card">
      <div class="cardTitle">詳細</div>

      <?php if (!$customer): ?>
        <div class="muted">左の一覧から選ぶか、新規登録してください。</div>
      <?php else: ?>
        <?php
          $status = (string)($customer['status'] ?? 'active');
          $statusLabel = ($status === 'inactive') ? '停止' : (($status === 'merged') ? '統合済' : '在籍');
          $statusCls = ($status === 'inactive' || $status === 'merged') ? 'off' : 'ok';
        ?>
        <div class="detailHead">
          <div>
            <div class="mono muted">#<?= (int)$customer['id'] ?></div>
            <div class="big"><b><?= h((string)$customer['display_name']) ?></b></div>
            <div class="muted"><?= h((string)$customer['features']) ?></div>
          </div>
          <div><?= '<span class="badge '.$statusCls.'">'.h($statusLabel).'</span>' ?></div>
        </div>

        <hr class="hr">

        <div class="cardTitle">編集</div>
        <form method="post" class="formCol">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="action" value="update_customer">
          <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">

          <label class="muted">名前</label>
          <input class="sel" name="name" value="<?= h((string)$customer['display_name']) ?>" required>

          <label class="muted">特徴</label>
          <input class="sel" name="feature" value="<?= h((string)$customer['features']) ?>">

          <label class="muted">店全体メモ（みんなが見てOK）</label>
          <input class="sel" name="note_public" value="<?= h((string)($customer['note_public'] ?? '')) ?>">

          <?php if (!$castOnly): ?>
            <label class="muted">担当キャスト（user_id / 空なら店全体）</label>
            <input class="sel" name="assigned_user_id" inputmode="numeric" placeholder="例：10（かな）">
          <?php else: ?>
            <input type="hidden" name="assigned_user_id" value="<?= (int)$userId ?>">
          <?php endif; ?>

          <label class="muted">状態</label>
          <select class="sel" name="status">
            <option value="active" <?= ($status==='active')?'selected':'' ?>>在籍</option>
            <option value="inactive" <?= ($status==='inactive')?'selected':'' ?>>停止</option>
          </select>

          <button class="btn primary">更新</button>
        </form>

        <hr class="hr">

        <div class="cardTitle">営業メモ（次につなげる）</div>
        <form method="post" class="formCol">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="action" value="add_note">
          <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">

          <textarea class="ta" name="note_text" rows="5" required
            placeholder="例：今日は機嫌よかった。次は前回の話題を覚えてると言うと喜ぶ。好み：角ハイ。"></textarea>
          <button class="btn">メモ追加</button>
        </form>

        <div class="notes">
          <?php foreach ($notes as $n): ?>
            <div class="note">
              <div class="muted">
                <?= h((string)($n['author_name'] ?? '')) ?>
                / <?= h((string)$n['created_at']) ?>
              </div>
              <div class="noteText"><?= nl2br(h((string)$n['note_text'])) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (!$notes): ?><div class="muted">（メモなし）</div><?php endif; ?>
        </div>

        <hr class="hr">

        <div class="cardTitle">重複っぽい候補（あとで統合できる）</div>
        <?php if ($dupes): ?>
          <div class="muted" style="margin-bottom:8px;">「名前が似てる」候補。統合は下でID指定。</div>
          <table class="tbl">
            <tr><th style="width:80px;">ID</th><th>名前</th><th>特徴</th></tr>
            <?php foreach ($dupes as $d): ?>
              <tr>
                <td class="mono"><?= (int)$d['id'] ?></td>
                <td><b><?= h((string)$d['name']) ?></b></td>
                <td class="muted"><?= h((string)$d['feature']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php else: ?>
          <div class="muted">（候補なし）</div>
        <?php endif; ?>

        <div class="mergeBox">
          <div class="muted" style="margin-bottom:6px;">
            例：<b>#123</b> と <b>#456</b> が同一人物なら、<b>統合元=123 / 統合先=456</b>
          </div>
          <form method="post" class="searchRow">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
            <input type="hidden" name="action" value="merge">
            <input class="sel" name="from_id" inputmode="numeric" value="<?= (int)$customer['id'] ?>" style="width:140px;" placeholder="統合元ID">
            <span class="muted">→</span>
            <input class="sel" name="to_id" inputmode="numeric" style="width:140px;" placeholder="統合先ID">
            <button class="btn" onclick="return confirm('統合します。戻せません。OK？')">統合</button>
          </form>
        </div>

      <?php endif; ?>
    </div>

  </div>
</div></div>

<style>
.topRow{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap}
.title{font-weight:1000;font-size:20px}
.grid2{display:grid;grid-template-columns:1.1fr 1fr;gap:12px;margin-top:12px}
@media (max-width: 980px){.grid2{grid-template-columns:1fr}}
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.cardTitle{font-weight:900;margin-bottom:10px}
.searchRow{margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.formCol{display:flex;flex-direction:column;gap:8px}
.sel{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit}
.ta{width:100%;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.04);color:inherit;resize:vertical}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:top}
.activeRow{outline:1px solid rgba(59,130,246,.4)}
.link{color:inherit;text-decoration:none}
.link:hover{text-decoration:underline}
.muted{opacity:.75;font-size:12px}
.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;font-size:12px}
.badge{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.18)}
.badge.ok{background:rgba(34,197,94,.18);border-color:rgba(34,197,94,.35)}
.badge.off{background:rgba(148,163,184,.14);border-color:rgba(148,163,184,.25)}
.detailHead{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.big{font-size:18px}
.hr{border:none;border-top:1px solid rgba(255,255,255,.10);margin:12px 0}
.notes{margin-top:10px;display:flex;flex-direction:column;gap:10px}
.note{padding:10px 12px;border:1px solid rgba(255,255,255,.10);border-radius:12px;background:rgba(255,255,255,.03)}
.noteText{margin-top:6px;white-space:normal;line-height:1.4}
.mergeBox{margin-top:10px;padding:10px 12px;border:1px dashed rgba(255,255,255,.18);border-radius:12px}
</style>
<?php render_page_end(); ?>
