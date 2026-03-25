<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/store_decommission.php';

require_login();

header('Content-Type: application/json; charset=UTF-8');

$pdo = db();

if (!function_exists('current_user_id_safe')) {
  function current_user_id_safe(): int {
    return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $ok = is_string($token) && $token !== '' && isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
    if (!$ok) {
      http_response_code(403);
      echo json_encode(['ok'=>false,'error'=>'csrf'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }

function resolve_cast_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("SELECT store_id FROM cast_profiles WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $sid = (int)($st->fetchColumn() ?: 0);
  if ($sid > 0) return $sid;

  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=? AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  csrf_verify($_POST['csrf_token'] ?? null);

  $userId = current_user_id_safe();
  if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'not_logged_in'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $storeId = resolve_cast_store_id($pdo, $userId);
  if ($storeId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'store_id_invalid'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  store_decommission_assert_store_writable($pdo, $storeId, '停止中の店舗では打刻できません');

  $st = $pdo->prepare("SELECT id,name,business_day_start FROM stores WHERE id=? LIMIT 1");
  $st->execute([$storeId]);
  $store = $st->fetch(PDO::FETCH_ASSOC);
  if (!$store) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'store_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $now = jst_now();
  $bizDate = (string)($_POST['business_date'] ?? '');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) {
    $bizDate = business_date_for_store($store, $now);
  }

  $lat = trim((string)($_POST['lat'] ?? ''));
  $lon = trim((string)($_POST['lon'] ?? ''));
  $acc = trim((string)($_POST['accuracy_m'] ?? ''));

  $st = $pdo->prepare("
    SELECT clock_in, clock_out
    FROM attendances
    WHERE store_id=? AND user_id=? AND business_date=?
    LIMIT 1
  ");
  $st->execute([$storeId, $userId, $bizDate]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row || empty($row['clock_in'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'no_clock_in'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!empty($row['clock_out'])) {
    echo json_encode([
      'ok'=>true,
      'store_id'=>$storeId,
      'business_date'=>$bizDate,
      'clock_out'=>substr((string)$row['clock_out'], 11, 5),
      'already'=>true
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $up = $pdo->prepare("
    UPDATE attendances
    SET clock_out = ?, updated_at = NOW()
    WHERE store_id=? AND user_id=? AND business_date=?
    LIMIT 1
  ");
  $up->execute([$now->format('Y-m-d H:i:s'), $storeId, $userId, $bizDate]);

  echo json_encode([
    'ok'=>true,
    'store_id'=>$storeId,
    'business_date'=>$bizDate,
    'clock_out'=>$now->format('H:i'),
    'geo'=>($lat!=='' && $lon!=='') ? ['lat'=>$lat,'lon'=>$lon,'accuracy_m'=>$acc] : null
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
