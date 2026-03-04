<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','admin','manager','super_user']);
}

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

// 互換：store_id / shop_id
$storeId = (int)($_GET['store_id'] ?? ($_GET['shop_id'] ?? 0));

// 互換：business_date / date
$businessDate = (string)($_GET['business_date'] ?? ($_GET['date'] ?? ''));
$businessDate = substr($businessDate, 0, 10);

if ($storeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
  echo json_encode([
    'ok' => false,
    'error' => 'invalid params',
    'got' => ['store_id' => $storeId, 'business_date' => $businessDate],
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/** roles.code='cast' の role_id を取得 */
$roleIdCast = 0;
try {
  $st = $pdo->prepare("SELECT id FROM roles WHERE code='cast' LIMIT 1");
  $st->execute();
  $roleIdCast = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $roleIdCast = 0;
}
if ($roleIdCast <= 0) {
  echo json_encode(['ok'=>false,'error'=>"roles.code='cast' not found"], JSON_UNESCAPED_UNICODE);
  exit;
}

/** store_users のカラム存在チェック（環境差吸収） */
function has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $col]);
  return (int)$st->fetchColumn() > 0;
}

$suHasIsActive  = has_col($pdo, 'store_users', 'is_active');
$suHasStaffCode = has_col($pdo, 'store_users', 'staff_code');
$suHasCastNo    = has_col($pdo, 'store_users', 'cast_no');
$suHasCode      = has_col($pdo, 'store_users', 'code'); // 念のため

// 店番カラムを決定（優先：staff_code → cast_no → code）
$staffCol = $suHasStaffCode ? 'su.staff_code' : ($suHasCastNo ? 'su.cast_no' : ($suHasCode ? 'su.code' : ''));

if ($staffCol === '') {
  echo json_encode([
    'ok'=>false,
    'error'=>'store_users has no staff_code/cast_no/code (cast number column not found)',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// is_active 条件を組み立て
$activeWhere = $suHasIsActive ? " AND su.is_active = 1 " : "";

/** 共通JOIN */
$baseJoin = "
  FROM store_users su
  JOIN users u ON u.id = su.user_id
  JOIN user_roles ur ON ur.user_id = su.user_id AND ur.role_id = :role_id_cast
  WHERE su.store_id = :store_id
  $activeWhere
";

/** attendances テーブルの有無 */
$hasAttendances = false;
try {
  $hasAttendances = (bool)$pdo->query("SHOW TABLES LIKE 'attendances'")->fetchColumn();
} catch (Throwable $e) {
  $hasAttendances = false;
}

/** 1) 当日出勤がいればそれ */
$list = [];
try {
  if ($hasAttendances) {
    $sql = "
      SELECT DISTINCT
        su.user_id AS user_id,
        CAST($staffCol AS UNSIGNED) AS cast_no,
        u.display_name AS name
      $baseJoin
        AND su.user_id IN (
          SELECT a.user_id
          FROM attendances a
          WHERE a.store_id = :store_id2
            AND a.business_date = :business_date
            AND a.status IN ('working','finished')
        )
      ORDER BY cast_no ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':role_id_cast' => $roleIdCast,
      ':store_id' => $storeId,
      ':store_id2' => $storeId,
      ':business_date' => $businessDate,
    ]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $no = (int)($r['cast_no'] ?? 0);
      if ($no <= 0) continue;
      $list[] = [
        'cast_id' => (int)$r['user_id'],
        'cast_no' => $no,
        'name'    => (string)($r['name'] ?? ''),
      ];
    }
  }
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'server error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/** 2) 0人なら在籍キャスト全員（あなたの運用） */
if (!$list) {
  try {
    $sql = "
      SELECT
        su.user_id AS user_id,
        CAST($staffCol AS UNSIGNED) AS cast_no,
        u.display_name AS name
      $baseJoin
      ORDER BY cast_no ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':role_id_cast' => $roleIdCast,
      ':store_id' => $storeId,
    ]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $no = (int)($r['cast_no'] ?? 0);
      if ($no <= 0) continue;
      $list[] = [
        'cast_id' => (int)$r['user_id'],
        'cast_no' => $no,
        'name'    => (string)($r['name'] ?? ''),
      ];
    }
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'server error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

echo json_encode(['ok'=>true,'list'=>$list], JSON_UNESCAPED_UNICODE);