<?php
declare(strict_types=1);

/* =========================================================
   customers repository (haruto_core)
   - customer.php 用・動作保証版
========================================================= */

/**
 * 一覧検索
 */
function repo_customers_search(
  PDO $pdo,
  int $storeId,
  string $q,
  int $limit = 80,
  ?int $assignedUserId = null
): array {
  $limit = max(1, min(300, $limit));
  $qLike = '%' . trim($q) . '%';

  $where = "
    c.store_id = ?
    AND (c.merged_into_customer_id IS NULL OR c.merged_into_customer_id = 0)
  ";
  $params = [$storeId];

  if ($q !== '') {
    $where .= " AND (
      c.display_name LIKE ?
      OR c.features LIKE ?
      OR c.note_public LIKE ?
      OR c.id = ?
    )";
    array_push($params, $qLike, $qLike, $qLike, (int)$q);
  }

  if ($assignedUserId !== null) {
    $where .= " AND c.assigned_user_id = ?";
    $params[] = $assignedUserId;
  }

  $sql = "
    SELECT
      c.id,
      c.display_name AS name,
      c.features AS feature,
      c.note_public,
      c.status,
      c.assigned_user_id,
      u.display_name AS assigned_name,
      c.last_visit_at
    FROM customers c
    LEFT JOIN users u ON u.id = c.assigned_user_id
    WHERE {$where}
    ORDER BY c.updated_at DESC, c.id DESC
    LIMIT {$limit}
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * 単体取得
 */
function repo_customer_get(
  PDO $pdo,
  int $storeId,
  int $customerId,
  ?int $assignedUserId = null
): ?array {
  $sql = "
    SELECT
      c.*,
      u.display_name AS assigned_name
    FROM customers c
    LEFT JOIN users u ON u.id = c.assigned_user_id
    WHERE c.store_id = ?
      AND c.id = ?
      AND (c.merged_into_customer_id IS NULL OR c.merged_into_customer_id = 0)
  ";
  $params = [$storeId, $customerId];

  if ($assignedUserId !== null) {
    $sql .= " AND c.assigned_user_id = ?";
    $params[] = $assignedUserId;
  }

  $sql .= " LIMIT 1";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/**
 * 新規作成
 */
function repo_customer_create(
  PDO $pdo,
  int $storeId,
  string $name,
  string $feature,
  string $notePublic,
  ?int $assignedUserId,
  int $createdByUserId
): int {
  $st = $pdo->prepare("
    INSERT INTO customers
      (store_id, display_name, features, note_public, assigned_user_id,
       status, created_by_user_id, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())
  ");
  $st->execute([
    $storeId,
    $name,
    $feature,
    $notePublic,
    $assignedUserId,
    $createdByUserId
  ]);

  return (int)$pdo->lastInsertId();
}

/**
 * 更新
 */
function repo_customer_update(
  PDO $pdo,
  int $storeId,
  int $customerId,
  string $name,
  string $feature,
  string $notePublic,
  string $status,
  ?int $assignedUserId
): void {
  $st = $pdo->prepare("
    UPDATE customers
    SET
      display_name = ?,
      features = ?,
      note_public = ?,
      status = ?,
      assigned_user_id = ?,
      updated_at = NOW()
    WHERE store_id = ?
      AND id = ?
    LIMIT 1
  ");
  $st->execute([
    $name,
    $feature,
    $notePublic,
    $status,
    $assignedUserId,
    $storeId,
    $customerId
  ]);
}

/**
 * メモ一覧
 */
function repo_customer_notes(
  PDO $pdo,
  int $storeId,
  int $customerId,
  int $limit = 50
): array {
  $limit = max(1, min(200, $limit));

  $st = $pdo->prepare("
    SELECT
      n.note_text,
      n.created_at,
      u.display_name AS author_name
    FROM customer_notes n
    LEFT JOIN users u ON u.id = n.author_user_id
    WHERE n.store_id = ?
      AND n.customer_id = ?
    ORDER BY n.id DESC
    LIMIT {$limit}
  ");
  $st->execute([$storeId, $customerId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * メモ追加
 */
function repo_customer_add_note(
  PDO $pdo,
  int $storeId,
  int $customerId,
  int $authorUserId,
  string $noteText
): void {
  $st = $pdo->prepare("
    INSERT INTO customer_notes
      (store_id, customer_id, author_user_id, note_text, created_at)
    VALUES
      (?, ?, ?, ?, NOW())
  ");
  $st->execute([
    $storeId,
    $customerId,
    $authorUserId,
    $noteText
  ]);
}

/**
 * 重複候補（簡易）
 */
function repo_customer_possible_duplicates(
  PDO $pdo,
  int $storeId,
  int $customerId,
  int $limit = 10
): array {
  $st = $pdo->prepare("
    SELECT display_name
    FROM customers
    WHERE store_id = ? AND id = ?
  ");
  $st->execute([$storeId, $customerId]);
  $name = (string)$st->fetchColumn();

  if ($name === '') return [];

  $key = mb_substr($name, 0, 3);

  $st = $pdo->prepare("
    SELECT
      id,
      display_name AS name,
      features AS feature
    FROM customers
    WHERE store_id = ?
      AND id <> ?
      AND (merged_into_customer_id IS NULL OR merged_into_customer_id = 0)
      AND display_name LIKE ?
    ORDER BY id DESC
    LIMIT ?
  ");
  $st->execute([
    $storeId,
    $customerId,
    '%' . $key . '%',
    $limit
  ]);

  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}