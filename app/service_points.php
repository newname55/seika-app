<?php
declare(strict_types=1);

require_once __DIR__ . '/repo_points.php';

if (!function_exists('service_points_normalize_number')) {
  function service_points_normalize_number(string $raw): float {
    $raw = trim($raw);
    if ($raw === '') return 0.0;
    // 全角→半角っぽい入力対策（最低限）
    $raw = str_replace(['，','．','ー','−'], [',','.','-','-'], $raw);

    if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) return 0.0;
    $v = (float)$raw;

    // マイナスは0に丸め（現場運用の事故防止）
    if ($v < 0) $v = 0.0;

    // 2桁丸め（DBはDECIMAL(8,2)）
    return round($v, 2);
  }
}

if (!function_exists('service_points_save_day')) {
  /**
   * @param array $casts list from repo_points_casts_for_store()
   * @param array $input array structure:
   *   [
   *     'douhan' => [user_id => raw_string],
   *     'shimei' => [user_id => raw_string],
   *     'douhan_note' => [user_id => string],
   *     'shimei_note' => [user_id => string],
   *   ]
   */
  function service_points_save_day(PDO $pdo, int $storeId, string $businessDate, int $enteredByUserId, array $casts, array $input): void {
    $douhan = $input['douhan'] ?? [];
    $shimei = $input['shimei'] ?? [];
    $douhanNote = $input['douhan_note'] ?? [];
    $shimeiNote = $input['shimei_note'] ?? [];

    $pdo->beginTransaction();
    try {
      foreach ($casts as $c) {
        $uid = (int)$c['user_id'];

        // 既存：$d = service_points_normalize_number(...)
        //       $s = service_points_normalize_number(...)

        $d = (int)round(service_points_normalize_number((string)($douhan[$uid] ?? '')), 0); // ✅ 同伴は整数
        if ($d < 0) $d = 0;

        $s = service_points_normalize_number((string)($shimei[$uid] ?? '')); // 指名は小数OK（2桁丸め）

        $dn = (string)($douhanNote[$uid] ?? '');
        $sn = (string)($shimeiNote[$uid] ?? '');

        // noteは最大255（超えたら切る）
        if (mb_strlen($dn, 'UTF-8') > 255) $dn = mb_substr($dn, 0, 255, 'UTF-8');
        if (mb_strlen($sn, 'UTF-8') > 255) $sn = mb_substr($sn, 0, 255, 'UTF-8');

        repo_points_upsert($pdo, $storeId, $uid, $businessDate, 'douhan', $d, $dn, $enteredByUserId);
        repo_points_upsert($pdo, $storeId, $uid, $businessDate, 'shimei', $s, $sn, $enteredByUserId);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('service_points_term_range')) {
  /**
   * half-month: 1-15 / 16-end
   * @return array [fromYmd, toYmd]
   */
  function service_points_term_range(string $ym, string $term): array {
    // $ym: "YYYY-MM"
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
      $ym = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
    }
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2);

    $from = new DateTime(sprintf('%04d-%02d-01', $y, $m), new DateTimeZone('Asia/Tokyo'));
    $to = clone $from;

    if ($term === 'second') {
      $from->setDate($y, $m, 16);
      $to->modify('last day of this month');
    } else {
      $from->setDate($y, $m, 1);
      $to->setDate($y, $m, 15);
    }

    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
  }
}