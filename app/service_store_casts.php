<?php
declare(strict_types=1);

require_once __DIR__ . '/repo_casts.php';
require_once __DIR__ . '/repo_invites.php';

function service_generate_cast_login_id(PDO $pdo, int $storeId): string {
  for ($i = 0; $i < 12; $i++) {
    $suffix = bin2hex(random_bytes(3));
    $base = 'cast_' . $storeId . '_' . date('ymdHis') . '_' . $suffix;
    $loginId = substr($base, 0, 50);

    $st = $pdo->prepare("SELECT 1 FROM users WHERE login_id=? LIMIT 1");
    $st->execute([$loginId]);
    if ($st->fetchColumn() === false) {
      return $loginId;
    }
  }

  return substr('cast_' . $storeId . '_' . bin2hex(random_bytes(10)), 0, 50);
}

function service_find_cast_role_id(PDO $pdo): ?int {
  try {
    $st = $pdo->prepare("SELECT id FROM roles WHERE code='cast' LIMIT 1");
    $st->execute();
    $roleId = $st->fetchColumn();
    if ($roleId === false) {
      return null;
    }
    return (int)$roleId;
  } catch (Throwable $e) {
    return null;
  }
}

function service_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

function service_ensure_store_cast_role(PDO $pdo, int $userId, int $storeId): void {
  if ($userId <= 0 || $storeId <= 0) return;

  $castRoleId = service_find_cast_role_id($pdo);
  if ($castRoleId === null) return;

  $st = $pdo->prepare("
    SELECT 1
    FROM user_roles
    WHERE user_id = ?
      AND role_id = ?
      AND store_id = ?
    LIMIT 1
  ");
  $st->execute([$userId, $castRoleId, $storeId]);
  if ($st->fetchColumn()) return;

  if (service_has_column($pdo, 'user_roles', 'created_at')) {
    $sql = "INSERT INTO user_roles (user_id, role_id, store_id, created_at) VALUES (?, ?, ?, NOW())";
  } else {
    $sql = "INSERT INTO user_roles (user_id, role_id, store_id) VALUES (?, ?, ?)";
  }
  $pdo->prepare($sql)->execute([$userId, $castRoleId, $storeId]);
}

function service_sync_store_cast_assignments(PDO $pdo, int $storeId): int {
  if ($storeId <= 0) return 0;

  $castRoleId = service_find_cast_role_id($pdo);
  if ($castRoleId === null) {
    return 0;
  }

  $st = $pdo->prepare("
    SELECT
      su.user_id,
      su.staff_code,
      su.employment_type
    FROM store_users su
    JOIN user_roles ur
      ON ur.user_id = su.user_id
     AND ur.store_id = su.store_id
     AND ur.role_id = ?
    WHERE su.store_id = ?
      AND su.status = 'active'
  ");
  $st->execute([$castRoleId, $storeId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $updated = 0;
  foreach ($rows as $row) {
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId <= 0) continue;

    try {
      $employmentType = trim((string)($row['employment_type'] ?? 'part'));
      if ($employmentType === '') $employmentType = 'part';
      $shopTag = trim((string)($row['staff_code'] ?? ''));

      $stProfile = $pdo->prepare("
        INSERT INTO cast_profiles (user_id, store_id, employment_type, shop_tag, updated_at, created_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          employment_type=VALUES(employment_type),
          shop_tag=VALUES(shop_tag),
          updated_at=NOW()
      ");
      $stProfile->execute([$userId, $storeId, $employmentType, $shopTag]);
    } catch (Throwable $e) {
      // cast_profiles が無い環境は無視
    }

    $updated++;
  }

  return $updated;
}

function service_fetch_store_cast_index(PDO $pdo, int $storeId): array {
  $casts = repo_fetch_casts($pdo, $storeId, 'all');
  $notes = repo_fetch_cast_notes($pdo, $storeId, array_map(
    static fn(array $cast): int => (int)($cast['id'] ?? 0),
    $casts
  ));

  return [
    'casts' => $casts,
    'note_map' => $notes,
  ];
}

function service_fetch_store_cast_index_with_retired(PDO $pdo, int $storeId): array {
  $casts = repo_fetch_casts($pdo, $storeId, 'with_retired');
  $notes = repo_fetch_cast_notes($pdo, $storeId, array_map(
    static fn(array $cast): int => (int)($cast['id'] ?? 0),
    $casts
  ));

  return [
    'casts' => $casts,
    'note_map' => $notes,
  ];
}

function service_create_cast_invite(PDO $pdo, int $storeId, int $createdByUserId, string $expiresInput = ''): string {
  $expiresInput = trim($expiresInput);
  if ($expiresInput === '') {
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
  } else {
    $ts = strtotime($expiresInput);
    if ($ts === false) {
      throw new InvalidArgumentException('期限の形式が不正です');
    }
    $expiresAt = date('Y-m-d H:i:s', $ts);
  }

  $rawToken = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
  $tokenHash = hash('sha256', $rawToken);

  repo_insert_cast_invite($pdo, $rawToken, $tokenHash, $storeId, $createdByUserId, $expiresAt);
  return $rawToken;
}

function service_get_or_create_cast_invite_token(PDO $pdo, int $storeId, int $createdByUserId): string {
  $invite = repo_find_latest_reusable_cast_invite($pdo, $storeId);
  if ($invite && !empty($invite['token'])) {
    return (string)$invite['token'];
  }

  return service_create_cast_invite($pdo, $storeId, $createdByUserId);
}

function service_add_store_cast(PDO $pdo, int $storeId, string $displayName, string $employmentType, string $staffCode = ''): void {
  $pdo->beginTransaction();
  try {
    service_add_store_cast_core($pdo, $storeId, $displayName, $employmentType, $staffCode);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function service_add_store_cast_core(PDO $pdo, int $storeId, string $displayName, string $employmentType, string $staffCode = ''): void {
  $displayName = trim($displayName);
  $employmentType = trim($employmentType);
  $staffCode = trim($staffCode);

  if ($displayName === '') {
    throw new InvalidArgumentException('名前が空です');
  }
  if (!in_array($employmentType, ['regular', 'part', 'trial', 'support'], true)) {
    throw new InvalidArgumentException('雇用区分が不正です');
  }

  if ($staffCode !== '') {
    $st = $pdo->prepare("
      SELECT 1
      FROM store_users
      WHERE store_id = ?
        AND staff_code = ?
        AND status = 'active'
      LIMIT 1
    ");
    $st->execute([$storeId, $staffCode]);
    if ($st->fetchColumn()) {
      throw new InvalidArgumentException('店番 ' . $staffCode . ' はこの店舗ですでに使われています');
    }
  }

  $loginId = service_generate_cast_login_id($pdo, $storeId);
  $plain = bin2hex(random_bytes(16));
  $hash = password_hash($plain, PASSWORD_DEFAULT);
  $userEmployment = ($employmentType === 'regular') ? 'regular' : 'part';

  $st = $pdo->prepare("
    INSERT INTO users (login_id, password_hash, display_name, employment_type, is_active, created_at, updated_at)
    VALUES (?, ?, ?, ?, 1, NOW(), NOW())
  ");
  $st->execute([$loginId, $hash, $displayName, $userEmployment]);
  $userId = (int)$pdo->lastInsertId();

  $st = $pdo->prepare("
    INSERT INTO store_users (store_id, user_id, staff_code, employment_type, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
  ");
  $st->execute([$storeId, $userId, $staffCode === '' ? null : $staffCode, $employmentType]);

  service_ensure_store_cast_role($pdo, $userId, $storeId);

  try {
    $st = $pdo->prepare("
      INSERT INTO cast_profiles (user_id, store_id, employment_type, shop_tag, updated_at, created_at)
      VALUES (?, ?, ?, ?, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        employment_type=VALUES(employment_type),
        shop_tag=VALUES(shop_tag),
        updated_at=NOW()
    ");
    $st->execute([$userId, $storeId, $employmentType, $staffCode]);
  } catch (Throwable $e) {
  }
}

function service_add_store_casts_bulk(PDO $pdo, array $rows): int {
  $normalized = [];
  $seenStaffCodes = [];

  foreach ($rows as $index => $row) {
    if (!is_array($row)) continue;

    $storeId = (int)($row['store_id'] ?? 0);
    $displayName = trim((string)($row['display_name'] ?? ''));
    $employmentType = trim((string)($row['employment_type'] ?? 'part'));
    $staffCode = trim((string)($row['staff_code'] ?? ''));

    $isIncompleteRow = ($displayName === '' || $staffCode === '');
    if ($storeId <= 0 && $isIncompleteRow) {
      continue;
    }
    if ($isIncompleteRow) {
      continue;
    }
    if ($storeId <= 0) {
      throw new InvalidArgumentException(($index + 1) . '行目: 店舗を選択してください');
    }
    if (!in_array($employmentType, ['regular', 'part'], true)) {
      throw new InvalidArgumentException(($index + 1) . '行目: 雇用区分が不正です');
    }

    $staffKey = $storeId . ':' . $staffCode;
    if ($staffCode !== '' && isset($seenStaffCodes[$staffKey])) {
      throw new InvalidArgumentException(($index + 1) . '行目: 同じ店舗で店番 ' . $staffCode . ' が重複しています');
    }
    if ($staffCode !== '') {
      $seenStaffCodes[$staffKey] = true;
    }

    $normalized[] = [
      'store_id' => $storeId,
      'display_name' => $displayName,
      'employment_type' => $employmentType,
      'staff_code' => $staffCode,
    ];
  }

  if ($normalized === []) {
    throw new InvalidArgumentException('登録する行がありません');
  }

  $pdo->beginTransaction();
  try {
    foreach ($normalized as $row) {
      service_add_store_cast_core(
        $pdo,
        (int)$row['store_id'],
        (string)$row['display_name'],
        (string)$row['employment_type'],
        (string)$row['staff_code']
      );
    }
    $pdo->commit();
    return count($normalized);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function service_retire_store_cast(PDO $pdo, int $storeId, int $storeUserId, string $reason = ''): void {
  $reason = trim($reason);
  if ($storeUserId <= 0) {
    throw new InvalidArgumentException('IDが不正です');
  }

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      SELECT user_id
      FROM store_users
      WHERE id = ?
        AND store_id = ?
      LIMIT 1
    ");
    $st->execute([$storeUserId, $storeId]);
    $userId = (int)($st->fetchColumn() ?: 0);
    if ($userId <= 0) {
      throw new InvalidArgumentException('対象キャストが見つかりません');
    }

    $st = $pdo->prepare("
      UPDATE store_users
      SET status='retired',
          retired_at=COALESCE(retired_at, NOW()),
          retired_reason=?,
          updated_at=NOW()
      WHERE store_id = ?
        AND user_id = ?
        AND status = 'active'
    ");
    $st->execute([$reason === '' ? null : $reason, $storeId, $userId]);

    $castRoleId = service_find_cast_role_id($pdo);
    if ($castRoleId !== null) {
      $st = $pdo->prepare("
        DELETE FROM user_roles
        WHERE user_id = ?
          AND role_id = ?
          AND store_id = ?
      ");
      $st->execute([$userId, $castRoleId, $storeId]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function service_restore_store_cast(PDO $pdo, int $storeId, int $storeUserId): void {
  if ($storeUserId <= 0) {
    throw new InvalidArgumentException('IDが不正です');
  }

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      SELECT user_id, staff_code, employment_type
      FROM store_users
      WHERE id = ?
        AND store_id = ?
      LIMIT 1
    ");
    $st->execute([$storeUserId, $storeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
      throw new InvalidArgumentException('対象キャストが見つかりません');
    }

    $userId = (int)($row['user_id'] ?? 0);
    $staffCode = trim((string)($row['staff_code'] ?? ''));
    if ($staffCode !== '') {
      $st = $pdo->prepare("
        SELECT 1
        FROM store_users
        WHERE store_id = ?
          AND staff_code = ?
          AND status = 'active'
          AND id <> ?
        LIMIT 1
      ");
      $st->execute([$storeId, $staffCode, $storeUserId]);
      if ($st->fetchColumn()) {
        throw new InvalidArgumentException('同じ店番がすでに在籍中に存在するため復帰できません');
      }
    }

    $st = $pdo->prepare("
      UPDATE store_users
      SET status = 'active',
          retired_at = NULL,
          retired_reason = NULL,
          updated_at = NOW()
      WHERE id = ?
        AND store_id = ?
      LIMIT 1
    ");
    $st->execute([$storeUserId, $storeId]);

    service_ensure_store_cast_role($pdo, $userId, $storeId);

    try {
      $employmentType = trim((string)($row['employment_type'] ?? 'part'));
      if ($employmentType === '') $employmentType = 'part';
      $st = $pdo->prepare("
        INSERT INTO cast_profiles (user_id, store_id, employment_type, shop_tag, updated_at, created_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          employment_type=VALUES(employment_type),
          shop_tag=VALUES(shop_tag),
          updated_at=NOW()
      ");
      $st->execute([$userId, $storeId, $employmentType, $staffCode]);
    } catch (Throwable $e) {
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function service_hard_delete_store_cast(PDO $pdo, int $storeId, int $storeUserId): void {
  if ($storeUserId <= 0) {
    throw new InvalidArgumentException('IDが不正です');
  }

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      SELECT user_id
      FROM store_users
      WHERE id = ?
        AND store_id = ?
      LIMIT 1
    ");
    $st->execute([$storeUserId, $storeId]);
    $userId = (int)($st->fetchColumn() ?: 0);
    if ($userId <= 0) {
      throw new InvalidArgumentException('対象キャストが見つかりません');
    }

    $pdo->prepare("DELETE FROM store_users WHERE store_id=? AND user_id=?")->execute([$storeId, $userId]);

    $castRoleId = service_find_cast_role_id($pdo);
    if ($castRoleId !== null) {
      $pdo->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=? AND store_id=?")->execute([$userId, $castRoleId, $storeId]);
    }

    try {
      $pdo->prepare("DELETE FROM cast_profiles WHERE user_id=? AND store_id=?")->execute([$userId, $storeId]);
    } catch (Throwable $e) {
    }

    $remainingStoreUsers = 0;
    try {
      $st = $pdo->prepare("SELECT COUNT(*) FROM store_users WHERE user_id=?");
      $st->execute([$userId]);
      $remainingStoreUsers = (int)$st->fetchColumn();
    } catch (Throwable $e) {
    }

    if ($remainingStoreUsers === 0) {
      try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id=?");
        $st->execute([$userId]);
        $remainingRoles = (int)$st->fetchColumn();
        if ($remainingRoles === 0) {
          $pdo->prepare("DELETE FROM users WHERE id=? LIMIT 1")->execute([$userId]);
        }
      } catch (Throwable $e) {
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
