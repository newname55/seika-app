<?php
declare(strict_types=1);

/**
 * Points Repo (haruto_core.cast_points)
 * - publicは薄く、SQLはここへ
 */

if (!function_exists('repo_points_allowed_stores')) {
  function repo_points_allowed_stores(PDO $pdo, int $userId, bool $isSuper): array {
    if ($isSuper) {
      return $pdo->query("
        SELECT id, name
        FROM stores
        WHERE is_active=1
        ORDER BY id ASC
      ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $st = $pdo->prepare("
      SELECT DISTINCT s.id, s.name
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code IN ('admin','manager')
      JOIN stores s ON s.id=ur.store_id
      WHERE ur.user_id=?
        AND ur.store_id IS NOT NULL
        AND s.is_active=1
      ORDER BY s.id ASC
    ");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('repo_points_casts_for_store')) {
function repo_points_casts_for_store(PDO $pdo, int $storeId): array {
  $st = $pdo->prepare("
    SELECT
      user_id,
      display_name,
      user_is_active AS is_active,
      employment_type,
      default_start_time,
      shop_tag
    FROM v_store_casts_active
    WHERE store_id=?
    ORDER BY
      CASE WHEN shop_tag='' THEN 999999 ELSE CAST(shop_tag AS UNSIGNED) END ASC,
      display_name ASC,
      user_id ASC
  ");
  $st->execute([$storeId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

if (!function_exists('repo_points_day_map')) {
  /**
   * @return array [cast_user_id => ['douhan'=>float,'shimei'=>float,'douhan_note'=>string,'shimei_note'=>string]]
   */
  function repo_points_day_map(PDO $pdo, int $storeId, string $businessDate): array {
    $st = $pdo->prepare("
      SELECT cast_user_id, point_type, point_value, note
      FROM cast_points
      WHERE store_id=? AND business_date=?
    ");
    $st->execute([$storeId, $businessDate]);

    $map = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
      $uid = (int)$r['cast_user_id'];
      $type = (string)$r['point_type'];
      $val = (float)$r['point_value'];
      $note = (string)($r['note'] ?? '');

      if (!isset($map[$uid])) {
        $map[$uid] = ['douhan'=>0.0,'shimei'=>0.0,'douhan_note'=>'','shimei_note'=>''];
      }
      if ($type === 'douhan') { $map[$uid]['douhan'] = $val; $map[$uid]['douhan_note'] = $note; }
      if ($type === 'shimei') { $map[$uid]['shimei'] = $val; $map[$uid]['shimei_note'] = $note; }
    }
    return $map;
  }
}

if (!function_exists('repo_points_upsert')) {
  function repo_points_upsert(
    PDO $pdo,
    int $storeId,
    int $castUserId,
    string $businessDate,
    string $pointType,           // 'douhan'|'shimei'
    float $pointValue,
    string $note,
    int $enteredByUserId
  ): void {
    $st = $pdo->prepare("
      INSERT INTO cast_points
        (store_id, cast_user_id, business_date, point_type, point_value, note, entered_by_user_id, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        point_value = VALUES(point_value),
        note = VALUES(note),
        entered_by_user_id = VALUES(entered_by_user_id),
        updated_at = NOW()
    ");
    $st->execute([
      $storeId,
      $castUserId,
      $businessDate,
      $pointType,
      $pointValue,
      $note,
      $enteredByUserId,
    ]);
  }
}

if (!function_exists('repo_points_term_summary')) {
  /**
   * @return array rows: user_id, display_name, shop_tag, douhan_sum, shimei_sum
   */
  function repo_points_term_summary(PDO $pdo, int $storeId, string $fromYmd, string $toYmd): array {
    $st = $pdo->prepare("
      SELECT
        u.id AS user_id,
        u.display_name,
        COALESCE(NULLIF(TRIM(cp.shop_tag), ''), '') AS shop_tag,
        SUM(CASE WHEN p.point_type='douhan' THEN p.point_value ELSE 0 END) AS douhan_sum,
        SUM(CASE WHEN p.point_type='shimei' THEN p.point_value ELSE 0 END) AS shimei_sum
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code='cast'
      JOIN users u ON u.id=ur.user_id
      LEFT JOIN cast_profiles cp ON cp.user_id=u.id
      LEFT JOIN cast_points p
        ON p.store_id=ur.store_id
       AND p.cast_user_id=u.id
       AND p.business_date BETWEEN ? AND ?
      WHERE ur.store_id=?
      GROUP BY u.id, u.display_name, shop_tag
      ORDER BY
        u.is_active DESC,
        CAST(NULLIF(cp.shop_tag,'') AS UNSIGNED) ASC,
        u.id ASC
    ");
    $st->execute([$fromYmd, $toYmd, $storeId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}