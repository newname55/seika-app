<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/repo_casts.php';

function store_access_is_super(): bool {
  return is_role('super_user') || is_role('admin') || (function_exists('can_view_all_store_shift') && can_view_all_store_shift());
}

function store_access_allowed_stores(PDO $pdo): array {
  $userId = current_user_id();
  if ($userId === null) {
    return [];
  }

  return repo_allowed_stores($pdo, $userId, store_access_is_super());
}

function store_access_resolve_manageable_store_id(PDO $pdo, ?int $requestedStoreId = null): int {
  $stores = store_access_allowed_stores($pdo);
  if (!$stores) {
    throw new RuntimeException('管理可能な店舗がありません');
  }

  $allowed = [];
  foreach ($stores as $store) {
    $sid = (int)($store['id'] ?? 0);
    if ($sid > 0) {
      $allowed[$sid] = true;
    }
  }

  $requested = (int)($requestedStoreId ?? 0);
  if ($requested > 0 && isset($allowed[$requested])) {
    set_current_store_id($requested);
    return $requested;
  }

  $sessionStoreId = get_current_store_id();
  if ($sessionStoreId > 0 && isset($allowed[$sessionStoreId])) {
    return $sessionStoreId;
  }

  $firstStoreId = (int)($stores[0]['id'] ?? 0);
  if ($firstStoreId <= 0) {
    throw new RuntimeException('有効な店舗がありません');
  }

  set_current_store_id($firstStoreId);
  return $firstStoreId;
}

function store_access_find_store_name(array $stores, int $storeId): string {
  foreach ($stores as $store) {
    if ((int)($store['id'] ?? 0) === $storeId) {
      return (string)($store['name'] ?? '');
    }
  }
  return '';
}
