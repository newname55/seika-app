<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();

// ✅ haruto_core 標準：role チェック
if (function_exists('require_role')) {
  require_role(['admin', 'super_user']);
} else {
  // 念のための保険（基本ここは通らない）
  if (!isset($_SESSION['roles']) || !in_array('admin', $_SESSION['roles'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }
}

$pdo = db();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function business_date(): string {
  $now = new DateTime();
  if ((int)$now->format('H') < 5) {
    $now->modify('-1 day');
  }
  return $now->format('Y-m-d');
}

$business_date = business_date();

/* ========= 保存 ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $user_id = (int)$_POST['user_id'];
  $cast_no = (int)$_POST['cast_no'];
  $on      = isset($_POST['confirmed']) ? 1 : 0;

  $st = $pdo->prepare("
    INSERT INTO attendance_daily
      (business_date, user_id, cast_no, confirmed)
    VALUES
      (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      confirmed = VALUES(confirmed)
  ");
  $st->execute([$business_date, $user_id, $cast_no, $on]);

  header('Location: cast_attendance.php');
  exit;
}

/* ========= 一覧 ========= */
$rows = $pdo->query("
  SELECT
    u.id AS user_id,
    u.name,
    u.cast_no,
    u.is_active,
    COALESCE(a.confirmed, 0) AS confirmed
  FROM users u
  LEFT JOIN attendance_daily a
    ON a.user_id = u.id
   AND a.business_date = '{$business_date}'
  WHERE u.role = 'cast'
    AND u.is_active = 1
  ORDER BY u.cast_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

layout_header('出勤管理');
?>

<h1>出勤管理（<?= h($business_date) ?>）</h1>

<table class="table">
  <thead>
    <tr>
      <th>店番</th>
      <th>名前</th>
      <th>出勤</th>
      <th>操作</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
        <input type="hidden" name="cast_no" value="<?= (int)$r['cast_no'] ?>">
        <td><?= (int)$r['cast_no'] ?></td>
        <td><?= h($r['name']) ?></td>
        <td>
          <input type="checkbox" name="confirmed" <?= $r['confirmed'] ? 'checked' : '' ?>>
        </td>
        <td>
          <button class="btn">反映</button>
        </td>
      </form>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>

<p class="muted">
  ※ ここでONになったキャストのみ、会計・指名・FREE候補に表示されます
</p>

<?php layout_footer(); ?>