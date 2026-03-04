<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function orders_repo_get_categories_with_menus(PDO $pdo, int $storeId): array {
  $sql = "
    SELECT
      m.id, m.name, m.price_ex, m.image_url, m.description, m.is_sold_out, m.sort_order,
      c.id AS category_id, c.name AS category_name, c.sort_order AS category_sort
    FROM order_menus m
    LEFT JOIN order_menu_categories c
      ON c.id = m.category_id AND c.store_id = m.store_id
    WHERE m.store_id = ?
      AND m.is_active = 1
    ORDER BY c.sort_order ASC, m.sort_order ASC, m.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $cats = [];
  foreach ($rows as $r) {
    $cid = (int)($r['category_id'] ?? 0);
    $cname = (string)($r['category_name'] ?? 'その他');
    $key = $cid > 0 ? (string)$cid : '0';

    if (!isset($cats[$key])) {
      $cats[$key] = [
        'category_id' => $cid,
        'category_name' => $cname,
        'items' => [],
      ];
    }

    $cats[$key]['items'][] = [
      'id' => (int)$r['id'],
      'name' => (string)$r['name'],
      'price_ex' => (int)$r['price_ex'],
      'image_url' => (string)($r['image_url'] ?? ''),
      'description' => (string)($r['description'] ?? ''),
      'is_sold_out' => ((int)$r['is_sold_out'] === 1),
    ];
  }
  return array_values($cats);
}

function orders_repo_resolve_table_id(PDO $pdo, int $storeId, int $tableNo): int {
  // 安全策：卓番号の上限（店に合わせて調整）
  if ($tableNo < 1 || $tableNo > 50) return 0;

  // 既存を探す（store一致 + active）
  $st = $pdo->prepare("SELECT id FROM order_tables WHERE store_id=? AND table_no=? AND is_active=1 LIMIT 1");
  $st->execute([$storeId, $tableNo]);
  $id = (int)($st->fetchColumn() ?: 0);
  if ($id > 0) return $id;

  // 無ければ自動作成（※ order_tables のカラムが違うならここだけ調整）
  $name = '卓' . $tableNo;
  $st = $pdo->prepare("INSERT INTO order_tables (store_id, table_no, name, is_active) VALUES (?, ?, ?, 1)");
  $st->execute([$storeId, $tableNo, $name]);

  return (int)$pdo->lastInsertId();
}

function orders_repo_create_order(PDO $pdo, int $storeId, int $tableId, ?int $ticketId, string $note, array $items): int {
  // tableId は order_tables.id 前提（resolveしない）
  if ($tableId <= 0) throw new RuntimeException('table_id required');

  // テーブル存在確認（store一致）
  $st = $pdo->prepare("SELECT id FROM order_tables WHERE store_id=? AND id=? AND is_active=1 LIMIT 1");
  $st->execute([$storeId, $tableId]);
  if (!(int)($st->fetchColumn() ?: 0)) throw new RuntimeException('table not found');

  // items整形
  $menuIds = [];
  foreach ($items as $it) {
    $mid = (int)($it['menu_id'] ?? 0);
    if ($mid > 0) $menuIds[$mid] = true;
  }
  if (!$menuIds) throw new RuntimeException('invalid items');

  // menuチェック（store一致 + active + soldout）
  $in = implode(',', array_fill(0, count($menuIds), '?'));
  $params = array_merge([$storeId], array_keys($menuIds));
  $st = $pdo->prepare("SELECT id, is_sold_out, is_active FROM order_menus WHERE store_id=? AND id IN ($in)");
  $st->execute($params);

  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $map[(int)$m['id']] = $m;

  foreach (array_keys($menuIds) as $mid) {
    if (!isset($map[$mid]) || (int)$map[$mid]['is_active'] !== 1) throw new RuntimeException("menu not found: {$mid}");
    if ((int)$map[$mid]['is_sold_out'] === 1) throw new RuntimeException("sold out: {$mid}");
  }

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      INSERT INTO order_orders (store_id, table_id, ticket_id, status, note)
      VALUES (?, ?, ?, 'new', ?)
    ");
    $st->execute([
      $storeId,
      $tableId,
      ($ticketId && $ticketId > 0) ? $ticketId : null,
      ($note !== '' ? $note : null),
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $sti = $pdo->prepare("
      INSERT INTO order_order_items (store_id, order_id, menu_id, qty, item_status, note)
      VALUES (?, ?, ?, ?, 'new', ?)
    ");

    foreach ($items as $it) {
      $mid = (int)($it['menu_id'] ?? 0);
      $qty = max(1, (int)($it['qty'] ?? 1));
      $inote = trim((string)($it['note'] ?? ''));
      $sti->execute([$storeId, $orderId, $mid, $qty, ($inote !== '' ? $inote : null)]);
    }

    $pdo->commit();
    return $orderId;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function orders_repo_kitchen_list(PDO $pdo, int $storeId): array {
  $sql = "
    SELECT
      o.id AS order_id, o.table_id, t.name AS table_name,
      o.status AS order_status, o.note AS order_note, o.created_at,
      i.id AS item_id, i.menu_id, i.qty, i.item_status, i.note AS item_note,
      m.name AS menu_name
    FROM order_orders o
    JOIN order_tables t ON t.id = o.table_id
    JOIN order_order_items i ON i.order_id = o.id
    JOIN order_menus m ON m.id = i.menu_id
    WHERE o.store_id = ?
      AND o.status IN ('new','accepted')
      AND i.item_status IN ('new','cooking')
    ORDER BY o.created_at DESC, o.id DESC, i.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $orders = [];
  foreach ($rows as $r) {
    $oid = (int)$r['order_id'];
    if (!isset($orders[$oid])) {
      $orders[$oid] = [
        'order_id' => $oid,
        'table_id' => (int)$r['table_id'],
        'table_name' => (string)$r['table_name'],
        'order_status' => (string)$r['order_status'],
        'order_note' => (string)($r['order_note'] ?? ''),
        'created_at' => (string)$r['created_at'],
        'items' => [],
      ];
    }
    $orders[$oid]['items'][] = [
      'item_id' => (int)$r['item_id'],
      'menu_id' => (int)$r['menu_id'],
      'menu_name' => (string)$r['menu_name'],
      'qty' => (int)$r['qty'],
      'item_status' => (string)$r['item_status'],
      'item_note' => (string)($r['item_note'] ?? ''),
    ];
  }
  return array_values($orders);
}

function orders_repo_update_item_status(PDO $pdo, int $storeId, int $itemId, string $status): array {
  $allowed = ['cooking','served','canceled'];
  if (!in_array($status, $allowed, true)) throw new RuntimeException('invalid status');

  $pdo->beginTransaction();
  try {
    // item の order_id を取得（store一致）
    $st = $pdo->prepare("SELECT order_id FROM order_order_items WHERE id=? AND store_id=? LIMIT 1");
    $st->execute([$itemId, $storeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('item not found');
    $orderId = (int)$row['order_id'];

    // item更新
    $st = $pdo->prepare("UPDATE order_order_items SET item_status=? WHERE id=? AND store_id=?");
    $st->execute([$status, $itemId, $storeId]);

    // 残り(new/cooking)が 0 なら done
    $st = $pdo->prepare("
      SELECT SUM(item_status IN ('new','cooking')) AS remain_cnt
      FROM order_order_items
      WHERE store_id=? AND order_id=?
    ");
    $st->execute([$storeId, $orderId]);
    $remain = (int)($st->fetch(PDO::FETCH_ASSOC)['remain_cnt'] ?? 0);

    $newOrderStatus = ($remain === 0) ? 'done' : 'accepted';
    $st = $pdo->prepare("UPDATE order_orders SET status=? WHERE id=? AND store_id=? AND status<>'canceled'");
    $st->execute([$newOrderStatus, $orderId, $storeId]);

    $pdo->commit();
    return ['order_id' => $orderId, 'order_status' => $newOrderStatus];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}