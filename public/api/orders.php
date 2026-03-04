<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/attendance.php';
require_once __DIR__ . '/../../app/orders_repo.php';

$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------------------------
   JSONヘルパー（API専用）
----------------------------*/
if (!function_exists('json_out')) {
  function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

if (!function_exists('read_json')) {
  function read_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false) return [];
    $raw = trim($raw);
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
  }
}

/* ---------------------------
   store判定
----------------------------*/
$store_id = function_exists('att_safe_store_id')
  ? (int)att_safe_store_id()
  : (int)($_SESSION['store_id'] ?? 0);

if ($store_id <= 0) {
  json_out(['ok'=>false,'error'=>'store not selected'], 400);
}

$action = (string)($_GET['action'] ?? '');

try {

  /* ---------------------------
     メニュー取得
  ----------------------------*/
  if ($action === 'menus') {
    $cats = orders_repo_get_categories_with_menus($pdo, $store_id);
    json_out(['ok'=>true, 'categories'=>$cats]);
  }

  /* ---------------------------
     注文作成
  ----------------------------*/
  if ($action === 'create' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $in = read_json();
error_log('[orders.create] ' . json_encode($in, JSON_UNESCAPED_UNICODE));
    $table_id  = (int)($in['table_id'] ?? 0);
    $ticket_id = (int)($in['ticket_id'] ?? 0);
    $note      = trim((string)($in['note'] ?? ''));
    $items     = $in['items'] ?? [];

    if ($table_id <= 0) {
      json_out(['ok'=>false,'error'=>'table_id required'], 400);
    }

    if (!is_array($items) || count($items) === 0) {
      json_out(['ok'=>false,'error'=>'items required'], 400);
    }

    $order_id = orders_repo_create_order(
      $pdo,
      $store_id,
      $table_id,
      ($ticket_id > 0 ? $ticket_id : null),
      $note,
      $items
    );

    json_out(['ok'=>true, 'order_id'=>$order_id]);
  }

  /* ---------------------------
     キッチン一覧
  ----------------------------*/
  if ($action === 'kitchen_list') {
    require_role(['admin','manager','super_user','staff']);
    $orders = orders_repo_kitchen_list($pdo, $store_id);
    json_out(['ok'=>true, 'orders'=>$orders]);
  }

  /* ---------------------------
     アイテム状態更新
  ----------------------------*/
  if ($action === 'item_status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_role(['admin','manager','super_user','staff']);

    $in = read_json();
    $item_id = (int)($in['item_id'] ?? 0);
    $status  = (string)($in['status'] ?? '');

    if ($item_id <= 0) {
      json_out(['ok'=>false,'error'=>'item_id required'], 400);
    }

    $res = orders_repo_update_item_status($pdo, $store_id, $item_id, $status);
    json_out(['ok'=>true] + $res);
  }

  json_out(['ok'=>false,'error'=>'unknown action'], 404);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}