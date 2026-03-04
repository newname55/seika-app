<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!function_exists('att_h')) {
  function att_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * CSRF（プロジェクト側がcsrf_token/csrf_verifyを持っていればそれを優先）
 */
function att_csrf_token(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}
function att_csrf_verify(?string $token): bool {
  if (function_exists('csrf_verify')) {
    try {
      $r = csrf_verify($token);
      return ($r === null) ? true : (bool)$r;
    } catch (Throwable $e) {
      // fallthrough
    }
  }
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $t = (string)($token ?? '');
  $s = (string)($_SESSION['csrf_token'] ?? '');
  return ($t !== '' && $s !== '' && hash_equals($s, $t));
}

/**
 * store_id を安全に決める（store.php の変更に巻き込まれない）
 */
function att_safe_store_id(): int {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $sid = 0;

  // プロジェクト側が current_store_id() を提供していれば優先
  if (function_exists('current_store_id')) {
    try { $sid = (int)current_store_id(); } catch (Throwable $e) { /* ignore */ }
  }
  if ($sid <= 0) $sid = (int)($_GET['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);

  if ($sid > 0) {
    // よく参照されがちなセッションキーを全埋め
    $_SESSION['store_id'] = $sid;
    $_SESSION['current_store_id'] = $sid;
    $_SESSION['store_selected'] = 1;
  }
  return $sid;
}

function att_fetch_store(PDO $pdo, int $store_id): array {
  if ($store_id <= 0) return [];
  $st = $pdo->prepare("SELECT id, name, business_day_start FROM stores WHERE id=? LIMIT 1");
  $st->execute([$store_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : [];
}

function att_business_date_for_store(string $businessDayStart, ?DateTimeImmutable $now=null): string {
  $tz = new DateTimeZone('Asia/Tokyo');
  $now = $now ?: new DateTimeImmutable('now', $tz);

  $cut = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $businessDayStart) ? $businessDayStart : '06:00:00';
  if (strlen($cut) === 5) $cut .= ':00';

  $cutDT = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $cut, $tz);
  $biz = ($now < $cutDT) ? $now->modify('-1 day') : $now;
  return $biz->format('Y-m-d');
}
function att_has_is_late(PDO $pdo): bool {
  try {
    $st = $pdo->query("SHOW COLUMNS FROM attendances LIKE 'is_late'");
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}
/**
 * 一覧：その日の cast を並べて、attendance を left join
 * 返すキー（index.phpが期待している）
 *  - cast_id, name, employment, shop_tag, planned_start
 *  - in_at, out_at, is_late, memo
 */
function att_get_daily_rows(PDO $pdo, int $store_id, string $date): array {
  if ($store_id <= 0) return [];

  // users.shop_tag が無い環境があるので「存在チェック」して安全にSELECTを組む
  $hasShopTag = false;
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'shop_tag'");
    $st->execute();
    $hasShopTag = (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $hasShopTag = false;
  }

  // shop_tag SELECT句 & ORDER句を可変にする
  $shopSelect = $hasShopTag ? "u.shop_tag AS shop_tag" : "'' AS shop_tag";
  $shopOrder  = $hasShopTag
    ? "(CASE WHEN u.shop_tag REGEXP '^[0-9]+$' THEN LPAD(u.shop_tag, 6, '0') ELSE u.shop_tag END) ASC,"
    : "";

  // cast_week が無い環境でも動くように try/catch で fallback
  $sql = "
    SELECT
      u.id AS cast_id,
      u.display_name AS name,
      u.employment_type AS employment,
      {$shopSelect},
      ws.start_time AS planned_start,
      a.clock_in AS in_at,
      a.clock_out AS out_at,
      COALESCE(a.is_late, 0) AS is_late,
      a.note AS memo
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id AND r.code='cast'
    JOIN users u ON u.id = ur.user_id AND u.is_active=1
    LEFT JOIN attendances a
      ON a.user_id=u.id AND a.store_id=ur.store_id AND a.business_date=?
    LEFT JOIN cast_week ws
      ON ws.user_id=u.id AND ws.store_id=ur.store_id AND ws.work_date=?
    WHERE ur.store_id=?
    ORDER BY
      {$shopOrder}
      u.display_name ASC
  ";

  try {
    $st = $pdo->prepare($sql);
    $st->execute([$date, $date, $store_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  } catch (Throwable $e) {
    // cast_week が無い場合の簡易版
    $sql2 = "
      SELECT
        u.id AS cast_id,
        u.display_name AS name,
        u.employment_type AS employment,
        {$shopSelect},
        NULL AS planned_start,
        a.clock_in AS in_at,
        a.clock_out AS out_at,
        COALESCE(a.is_late, 0) AS is_late,
        a.note AS memo
      FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id AND r.code='cast'
      JOIN users u ON u.id = ur.user_id AND u.is_active=1
      LEFT JOIN attendances a
        ON a.user_id=u.id AND a.store_id=ur.store_id AND a.business_date=?
      WHERE ur.store_id=?
      ORDER BY
        {$shopOrder}
        u.display_name ASC
    ";
    $st = $pdo->prepare($sql2);
    $st->execute([$date, $store_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }
}

/**
 * toggle IN/OUT/late + memo
 * 返す：index.phpが renderRow() で読む形式に合わせる
 */
function att_toggle_in(PDO $pdo, int $store_id, int $user_id, string $date): array {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, clock_in, status, source_in, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), 'working', 'admin', NOW(), NOW())
      ")->execute([$user_id, $store_id, $date]);
    } else {
      // もうIN済みでOUT無し → IN取り消し
      if (!empty($row['clock_in']) && empty($row['clock_out'])) {
        $pdo->prepare("
          UPDATE attendances
          SET clock_in=NULL, status='scheduled', source_in=NULL, updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      } else {
        // IN無し → IN付与（OUTがあっても、やり直し扱いでOUT消す）
        $pdo->prepare("
          UPDATE attendances
          SET clock_in=NOW(), clock_out=NULL, status='working', source_in='admin', source_out=NULL, updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      }
    }

    $hasLate = att_has_is_late($pdo);
    $selLate = $hasLate ? "COALESCE(is_late,0) AS is_late" : "0 AS is_late";

    $st = $pdo->prepare("
      SELECT clock_in AS in_at, clock_out AS out_at, note AS memo, {$selLate}
      FROM attendances
      WHERE store_id=? AND user_id=? AND business_date=?
      LIMIT 1
    ");
    $st->execute([$store_id, $user_id, $date]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: ['in_at'=>null,'out_at'=>null,'memo'=>null];

    $pdo->commit();
    return [
      'ok' => true,
      'in_at' => $a['in_at'] ?? null,
      'out_at' => $a['out_at'] ?? null,
      'is_late' => (int)($a['is_late'] ?? 0),
      'memo' => $a['memo'] ?? null,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function att_toggle_out(PDO $pdo, int $store_id, int $user_id, string $date): array {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      // OUTだけ押された → OUT登録（status finished）
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, clock_out, status, source_out, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), 'finished', 'admin', NOW(), NOW())
      ")->execute([$user_id, $store_id, $date]);
    } else {
      if (!empty($row['clock_out'])) {
        // もうOUT済み → 取り消し
        $pdo->prepare("
          UPDATE attendances
          SET clock_out=NULL, status=IF(clock_in IS NULL,'scheduled','working'), source_out=NULL, updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      } else {
        // OUT付与（INが無い場合でも付ける）
        $pdo->prepare("
          UPDATE attendances
          SET clock_out=NOW(), status='finished', source_out='admin', updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      }
    }

    $hasLate = att_has_is_late($pdo);
    $selLate = $hasLate ? "COALESCE(is_late,0) AS is_late" : "0 AS is_late";

    $st = $pdo->prepare("
      SELECT clock_in AS in_at, clock_out AS out_at, note AS memo, {$selLate}
      FROM attendances
      WHERE store_id=? AND user_id=? AND business_date=?
      LIMIT 1
    ");
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: ['in_at'=>null,'out_at'=>null,'memo'=>null];

    $pdo->commit();
    return [
      'ok' => true,
      'in_at' => $a['in_at'] ?? null,
      'out_at' => $a['out_at'] ?? null,
      'is_late' => (int)($a['is_late'] ?? 0),
      'memo' => $a['memo'] ?? null,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function att_toggle_late(PDO $pdo, int $store_id, int $user_id, string $date): array {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      // レコードが無ければ作って is_late=1
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, status, is_late, created_at, updated_at)
        VALUES (?, ?, ?, 'scheduled', 1, NOW(), NOW())
      ")->execute([$user_id, $store_id, $date]);
      $isLate = 1;
    } else {
      $cur = (int)($row['is_late'] ?? 0);
      $isLate = $cur ? 0 : 1;
      $pdo->prepare("UPDATE attendances SET is_late=?, updated_at=NOW() WHERE id=? LIMIT 1")
          ->execute([$isLate, (int)$row['id']]);
    }

    $st = $pdo->prepare("
      SELECT clock_in AS in_at, clock_out AS out_at, note AS memo, is_late AS is_late
      FROM attendances
      WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1
    ");
    $st->execute([$store_id, $user_id, $date]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: ['in_at'=>null,'out_at'=>null,'memo'=>null,'is_late'=>0];

    $pdo->commit();
    return [
      'ok' => true,
      'in_at' => $a['in_at'] ?? null,
      'out_at' => $a['out_at'] ?? null,
      'is_late' => (int)($a['is_late'] ?? 0),
      'memo' => $a['memo'] ?? null,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function att_save_memo(PDO $pdo, int $store_id, int $user_id, string $date, string $memo): array {
  $memo = mb_substr($memo, 0, 255);

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT id FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $id = (int)($st->fetchColumn() ?: 0);

    if ($id <= 0) {
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, status, note, created_at, updated_at)
        VALUES (?, ?, ?, 'scheduled', ?, NOW(), NOW())
      ")->execute([$user_id, $store_id, $date, $memo]);
    } else {
      $pdo->prepare("UPDATE attendances SET note=?, updated_at=NOW() WHERE id=? LIMIT 1")
          ->execute([$memo, $id]);
    }

    $pdo->commit();
    return ['ok'=>true];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}