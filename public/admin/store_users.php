<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
require_role(['super_user','admin','manager']);

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
$pdo = db();

function fetch_stores(PDO $pdo): array {
  $st = $pdo->query("SELECT id, name, is_active FROM stores ORDER BY is_active DESC, id ASC");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_store_users(PDO $pdo, int $store_id, int $only_active = 1): array {
  $sql = "
    SELECT
      su.id AS store_user_id,
      su.store_id,
      su.user_id,
      su.staff_code,
      su.employment_type,
      su.status,
      su.retired_at,
      su.retired_reason,
      su.created_at AS joined_at,     -- joined相当
      su.updated_at AS su_updated_at,

      u.login_id,
      u.display_name,
      u.is_active AS user_active

    FROM store_users su
    JOIN users u ON u.id = su.user_id
    WHERE su.store_id = :store_id
      AND (:only_active = 0 OR su.status = 'active')
    ORDER BY (su.status='active') DESC, u.display_name ASC, u.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':store_id' => $store_id,
    ':only_active' => $only_active,
  ]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_users_for_add(PDO $pdo, int $store_id, string $q = ''): array {
  $q = trim($q);
  $sql = "
    SELECT u.id, u.login_id, u.display_name, u.is_active
    FROM users u
    WHERE NOT EXISTS (
      SELECT 1 FROM store_users su
      WHERE su.store_id = :store_id AND su.user_id = u.id
    )
    AND (:q = '' OR u.login_id LIKE :like1 OR u.display_name LIKE :like2)
    ORDER BY u.is_active DESC, u.display_name ASC, u.id ASC
    LIMIT 200
  ";
  $st = $pdo->prepare($sql);
  $like = '%' . $q . '%';
  $st->execute([
    ':store_id' => $store_id,
    ':q' => $q,
    ':like1' => $like,
    ':like2' => $like,
  ]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_roles_badges(PDO $pdo, int $store_id): array {
  $sql = "
    SELECT ur.user_id, r.code, r.name
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.store_id = :store_id
    ORDER BY ur.user_id ASC, r.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':store_id' => $store_id]);

  $map = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $uid = (int)$row['user_id'];
    if (!isset($map[$uid])) $map[$uid] = [];
    $map[$uid][] = ['code' => (string)$row['code'], 'name' => (string)$row['name']];
  }
  return $map;
}

$msg = '';
$err = '';

$stores = fetch_stores($pdo);

$store_id = (int)($_GET['store_id'] ?? 0);
if ($store_id <= 0 && $stores) $store_id = (int)$stores[0]['id'];

$only_active = ((string)($_GET['only_active'] ?? '1') === '0') ? 0 : 1;
$add_q = trim((string)($_GET['add_q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'add_member') {
      $store_id_post = (int)($_POST['store_id'] ?? 0);
      $user_id = (int)($_POST['user_id'] ?? 0);
      $staff_code = trim((string)($_POST['staff_code'] ?? ''));
      $employment_type = trim((string)($_POST['employment_type'] ?? 'part'));

      if ($store_id_post <= 0 || $user_id <= 0) throw new RuntimeException('不正な指定です');
      if ($employment_type === '') $employment_type = 'part';

      // 既存行があれば復帰、なければINSERT
      $pdo->beginTransaction();

      $st = $pdo->prepare("SELECT id FROM store_users WHERE store_id=? AND user_id=? LIMIT 1");
      $st->execute([$store_id_post, $user_id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        $su_id = (int)$row['id'];
        $pdo->prepare("
          UPDATE store_users
          SET status='active',
              retired_at=NULL,
              retired_reason=NULL,
              staff_code = NULLIF(?, ''),
              employment_type = ?,
              updated_at = CURRENT_TIMESTAMP()
          WHERE id=? LIMIT 1
        ")->execute([$staff_code, $employment_type, $su_id]);
      } else {
        $pdo->prepare("
          INSERT INTO store_users (store_id, user_id, staff_code, employment_type, status, retired_at, retired_reason)
          VALUES (?, ?, NULLIF(?, ''), ?, 'active', NULL, NULL)
        ")->execute([$store_id_post, $user_id, $staff_code, $employment_type]);
      }

      $pdo->commit();

      header('Location: /seika-app/public/admin/store_users.php?store_id=' . $store_id_post . '&ok=1');
      exit;
    }

    if ($action === 'retire_member') {
      $store_id_post = (int)($_POST['store_id'] ?? 0);
      $store_user_id = (int)($_POST['store_user_id'] ?? 0);
      $reason = trim((string)($_POST['retired_reason'] ?? ''));

      if ($store_id_post <= 0 || $store_user_id <= 0) throw new RuntimeException('不正な指定です');

      $pdo->prepare("
        UPDATE store_users
        SET status='retired',
            retired_at=COALESCE(retired_at, CURRENT_TIMESTAMP()),
            retired_reason=NULLIF(?, ''),
            updated_at=CURRENT_TIMESTAMP()
        WHERE id=? AND store_id=?
        LIMIT 1
      ")->execute([$reason, $store_user_id, $store_id_post]);

      header('Location: /seika-app/public/admin/store_users.php?store_id=' . $store_id_post . '&ok=1');
      exit;
    }

    throw new RuntimeException('不明な操作です');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

if ((int)($_GET['ok'] ?? 0) === 1 && $err === '') $msg = '保存しました';

$store_users = $store_id > 0 ? fetch_store_users($pdo, $store_id, $only_active) : [];
$role_badges = $store_id > 0 ? fetch_roles_badges($pdo, $store_id) : [];
$add_candidates = ($store_id > 0) ? fetch_users_for_add($pdo, $store_id, $add_q) : [];

$store_name = '';
foreach ($stores as $s) {
  if ((int)$s['id'] === $store_id) { $store_name = (string)$s['name']; break; }
}

render_page_start('店舗ユーザー管理');
render_header('店舗ユーザー管理', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '<a class="btn" href="/seika-app/public/admin_users.php">全ユーザー</a>',
]);
?>
<style>
  .wrap{max-width:1200px;margin:0 auto;padding:14px}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
  .muted{color:var(--muted)}
  .tbl{width:100%;border-collapse:separate;border-spacing:0 8px}
  .tbl td{background:rgba(255,255,255,.03);padding:10px 10px;vertical-align:middle}
  .tbl tr td:first-child{border-top-left-radius:12px;border-bottom-left-radius:12px}
  .tbl tr td:last-child{border-top-right-radius:12px;border-bottom-right-radius:12px}
  .roles{display:flex;gap:6px;flex-wrap:wrap}
  .pill{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:12px;background:rgba(99,102,241,.14);border:1px solid rgba(99,102,241,.25)}
  .pill.gray{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.10)}
  .badge{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.08);font-size:12px}
  .dot{width:8px;height:8px;border-radius:50%}
  .ops{white-space:nowrap;text-align:right}
  select,input{max-width:420px}
</style>

<div class="wrap">
  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);margin-bottom:12px;"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);margin-bottom:12px;"><?=h($err)?></div><?php endif; ?>

  <div class="card">
    <div style="font-weight:1000;font-size:16px;">🏬 店舗ごとのユーザー（所属）管理</div>
    <div class="muted" style="margin-top:6px;">store_users.status で在籍/退店を管理</div>

    <form method="get" class="row" action="/seika-app/public/admin/store_users.php" style="margin-top:10px;">
      <label class="muted">店舗</label>
      <select class="btn" name="store_id" onchange="this.form.submit()">
        <?php foreach ($stores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $store_id) ? 'selected' : '' ?>>
            <?= h((string)$s['name']) ?><?= ((int)$s['is_active']===1?'':'（停止）') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label class="muted">表示</label>
      <select class="btn" name="only_active" onchange="this.form.submit()">
        <option value="1" <?= $only_active===1?'selected':'' ?>>在籍のみ</option>
        <option value="0" <?= $only_active===0?'selected':'' ?>>退店も含む</option>
      </select>

      <a class="btn" href="/seika-app/public/admin/store_users.php?store_id=<?= (int)$store_id ?>">リセット</a>
    </form>

    <div class="muted" style="margin-top:8px;">
      選択中：<b><?= h($store_name ?: '—') ?></b>
    </div>
  </div>

  <div class="card" style="margin-top:12px;">
    <div style="font-weight:1000;">➕ 所属追加（既存ユーザーを店舗に入れる）</div>

    <form method="get" class="row" action="/seika-app/public/admin/store_users.php" style="margin-top:10px;">
      <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
      <input type="hidden" name="only_active" value="<?= (int)$only_active ?>">
      <input class="btn" type="text" name="add_q" value="<?= h($add_q) ?>" placeholder="login_id / 表示名で検索">
      <button class="btn btn-primary" type="submit">検索</button>
      <a class="btn" href="/seika-app/public/admin/store_users.php?store_id=<?= (int)$store_id ?>&only_active=<?= (int)$only_active ?>">クリア</a>
    </form>

    <form method="post" class="row" style="margin-top:10px;">
      <input type="hidden" name="action" value="add_member">
      <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">

      <select class="btn" name="user_id" required>
        <option value="">追加するユーザーを選択</option>
        <?php foreach ($add_candidates as $u): ?>
          <option value="<?= (int)$u['id'] ?>">
            #<?= (int)$u['id'] ?> <?= h((string)$u['display_name']) ?> (<?= h((string)$u['login_id']) ?>)<?= ((int)$u['is_active']===1?'':'[無効]') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input class="btn" type="text" name="staff_code" placeholder="スタッフコード（任意）">
      <select class="btn" name="employment_type">
        <option value="part">part</option>
        <option value="regular">regular</option>
      </select>

      <button class="btn btn-primary" type="submit">この店舗に所属させる</button>
      <span class="muted">※ 権限（roles）は user_roles（store_id付き）で別管理</span>
    </form>
  </div>

  <div class="card" style="margin-top:12px;">
    <div style="font-weight:1000;">👥 所属ユーザー一覧</div>

    <table class="tbl" style="margin-top:10px;">
      <colgroup>
        <col style="width:360px">
        <col style="width:auto">
        <col style="width:220px">
        <col style="width:160px">
      </colgroup>
      <tbody>
      <?php if (!$store_users): ?>
        <tr><td colspan="4" class="muted">まだ所属ユーザーがいません</td></tr>
      <?php else: ?>
        <?php foreach ($store_users as $r): ?>
          <?php
            $inStore = ((string)$r['status'] === 'active');
            $userActive = ((int)$r['user_active'] === 1);
            $uid = (int)$r['user_id'];
            $roles = $role_badges[$uid] ?? [];
          ?>
          <tr>
            <td>
              <div style="font-weight:900;">
                #<?= (int)$r['user_id'] ?> <?= h((string)$r['display_name']) ?>
              </div>
              <div class="muted" style="font-size:12px;">
                <?= h((string)$r['login_id']) ?>
                / users: <?= $userActive ? '有効' : '無効' ?>
                / code: <?= h((string)($r['staff_code'] ?? '—')) ?>
                / emp: <?= h((string)($r['employment_type'] ?? 'part')) ?>
              </div>
            </td>

            <td>
              <div class="roles">
                <?php if (!$roles): ?>
                  <span class="pill gray">roleなし</span>
                <?php else: ?>
                  <?php foreach ($roles as $x): ?>
                    <span class="pill"><?= h($x['code']) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div class="muted" style="font-size:12px;margin-top:6px;">
                joined: <?= h((string)($r['joined_at'] ?? '—')) ?> /
                retired_at: <?= h((string)($r['retired_at'] ?? '—')) ?>
                <?php if (!empty($r['retired_reason'])): ?>
                  / reason: <?= h((string)$r['retired_reason']) ?>
                <?php endif; ?>
              </div>
            </td>

            <td>
              <span class="badge" style="background:<?= $inStore ? 'rgba(52,211,153,.14)' : 'rgba(251,113,133,.16)' ?>;">
                <span class="dot" style="background:<?= $inStore ? 'var(--ok)' : 'var(--ng)' ?>;"></span>
                <?= $inStore ? '在籍' : '退店' ?>
              </span>
            </td>

            <td class="ops">
              <?php if ($inStore): ?>
                <form method="post" onsubmit="return confirm('このユーザーを退店扱いにしますか？');" style="margin:0;display:flex;gap:8px;justify-content:flex-end;align-items:center;">
                  <input type="hidden" name="action" value="retire_member">
                  <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
                  <input type="hidden" name="store_user_id" value="<?= (int)$r['store_user_id'] ?>">
                  <input class="btn" type="text" name="retired_reason" placeholder="退店理由（任意）" style="max-width:180px;">
                  <button class="btn btn-warn" type="submit">退店</button>
                </form>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_page_end(); ?>