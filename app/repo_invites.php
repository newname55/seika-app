<?php
declare(strict_types=1);

function repo_insert_cast_invite(
  PDO $pdo,
  string $rawToken,
  string $tokenHash,
  int $storeId,
  int $createdByUserId,
  string $expiresAt
): void {
  $st = $pdo->prepare("
    INSERT INTO invite_tokens
      (token, token_hash, store_id, invite_type, created_by_user_id, created_at, expires_at, is_active)
    VALUES
      (?, ?, ?, 'cast', ?, NOW(), ?, 1)
  ");
  $st->execute([$rawToken, $tokenHash, $storeId, $createdByUserId, $expiresAt]);
}

function repo_find_latest_reusable_cast_invite(PDO $pdo, int $storeId): ?array {
  $st = $pdo->prepare("
    SELECT *
    FROM invite_tokens
    WHERE store_id = ?
      AND CONVERT(invite_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = _utf8mb4'cast' COLLATE utf8mb4_unicode_ci
      AND is_active = 1
      AND expires_at > NOW()
    ORDER BY created_at DESC, id DESC
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
