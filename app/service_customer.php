<?php
declare(strict_types=1);

function service_customer_merge(PDO $pdo, int $storeId, int $fromId, int $toId, int $byUserId): void {
  if ($fromId <= 0 || $toId <= 0) throw new RuntimeException('IDが不正です');
  if ($fromId === $toId) throw new RuntimeException('同じIDは統合できません');

  $pdo->beginTransaction();
  try {
    // 両方存在チェック
    $st = $pdo->prepare("SELECT id FROM customers WHERE store_id=? AND id=? LIMIT 1");
    $st->execute([$storeId, $fromId]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('統合元が見つかりません');

    $st->execute([$storeId, $toId]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('統合先が見つかりません');

    // notes を統合先へ寄せる
    $st = $pdo->prepare("
      UPDATE customer_notes
      SET customer_id=?
      WHERE store_id=? AND customer_id=?
    ");
    $st->execute([$toId, $storeId, $fromId]);

    // 統合元を merged 扱いにする
    $st = $pdo->prepare("
      UPDATE customers
      SET
        merged_into_customer_id=?,
        merged_at=NOW(),
        status='merged',
        updated_at=NOW()
      WHERE store_id=? AND id=?
      LIMIT 1
    ");
    $st->execute([$toId, $storeId, $fromId]);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}