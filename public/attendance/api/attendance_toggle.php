<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';
require_once __DIR__ . '/../../../app/store_decommission.php';

require_login();
require_role(['admin','manager','super_user']);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: application/json; charset=utf-8');

function jexit(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function csrf_token_local(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}

function now_jst_str(): string {
  return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
}

function actor_user_id(): int {
  if (function_exists('current_user_id')) return (int)current_user_id();
  return (int)($_SESSION['user_id'] ?? 0);
}

function ip_addr(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }
function user_agent(): string { return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255); }

function safe_store_id(): int {
  $sid = 0;
  if (isset($_GET['store_id'])) $sid = (int)$_GET['store_id'];
  if ($sid <= 0 && isset($_POST['store_id'])) $sid = (int)$_POST['store_id'];
  if ($sid <= 0 && isset($_SESSION['store_id'])) $sid = (int)$_SESSION['store_id'];
  return $sid;
}

function compose_dt_for_business_date(string $businessDate, string $timeHHMM, string $businessDayStart = '06:00:00'): string {
  $tz = new DateTimeZone('Asia/Tokyo');

  $cut = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $businessDayStart) ? $businessDayStart : '06:00:00';
  if (strlen($cut) === 5) $cut .= ':00';
  $cutHHMM = substr($cut, 0, 5);

  if (!preg_match('/^\d{2}:\d{2}$/', $timeHHMM)) $timeHHMM = '00:00';

  $base = new DateTimeImmutable($businessDate . ' 00:00:00', $tz);
  if ($timeHHMM < $cutHHMM) $base = $base->modify('+1 day');

  return $base->format('Y-m-d') . ' ' . $timeHHMM . ':00';
}

/** audits: カラム揺れを吸収して INSERT するために列一覧を取る */
function fetch_cols(PDO $pdo, string $table): array {
  $cols = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $cols[(string)$r['Field']] = true;
    }
  } catch (Throwable $e) {
    return [];
  }
  return $cols;
}

/** audits テーブルを無ければ作る（最小互換）。既存がある場合は “追加” だけする */
function ensure_attendance_audits(PDO $pdo): void {
  $exists = true;
  try {
    $pdo->query("SELECT 1 FROM attendance_audits LIMIT 1");
  } catch (Throwable $e) {
    $exists = false;
  }

  if (!$exists) {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS attendance_audits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id INT UNSIGNED NOT NULL,
        business_date DATE NOT NULL,
        attendance_id BIGINT UNSIGNED NULL,
        cast_user_id INT UNSIGNED NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        action VARCHAR(32) NOT NULL,
        field VARCHAR(32) NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_store_date (store_id, business_date),
        KEY idx_cast (cast_user_id, created_at),
        KEY idx_actor (actor_user_id, created_at),
        KEY idx_attendance (attendance_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return;
  }

  $cols = fetch_cols($pdo, 'attendance_audits');
  if (!$cols) return;

  $adds = [
    'store_id' => "ALTER TABLE attendance_audits ADD COLUMN store_id INT UNSIGNED NOT NULL DEFAULT 0",
    'business_date' => "ALTER TABLE attendance_audits ADD COLUMN business_date DATE NOT NULL DEFAULT '1970-01-01'",
    'attendance_id' => "ALTER TABLE attendance_audits ADD COLUMN attendance_id BIGINT UNSIGNED NULL",
    'cast_user_id' => "ALTER TABLE attendance_audits ADD COLUMN cast_user_id INT UNSIGNED NOT NULL DEFAULT 0",
    'subject_user_id' => "ALTER TABLE attendance_audits ADD COLUMN subject_user_id INT UNSIGNED NOT NULL DEFAULT 0",
    'target_user_id' => "ALTER TABLE attendance_audits ADD COLUMN target_user_id INT UNSIGNED NOT NULL DEFAULT 0",
    'actor_user_id' => "ALTER TABLE attendance_audits ADD COLUMN actor_user_id INT UNSIGNED NOT NULL DEFAULT 0",
    'admin_user_id' => "ALTER TABLE attendance_audits ADD COLUMN admin_user_id INT UNSIGNED NOT NULL DEFAULT 0",
    'action' => "ALTER TABLE attendance_audits ADD COLUMN action VARCHAR(32) NOT NULL DEFAULT ''",
    'field' => "ALTER TABLE attendance_audits ADD COLUMN field VARCHAR(32) NULL",
    'old_value' => "ALTER TABLE attendance_audits ADD COLUMN old_value TEXT NULL",
    'new_value' => "ALTER TABLE attendance_audits ADD COLUMN new_value TEXT NULL",
    'ip' => "ALTER TABLE attendance_audits ADD COLUMN ip VARCHAR(64) NULL",
    'user_agent' => "ALTER TABLE attendance_audits ADD COLUMN user_agent VARCHAR(255) NULL",
    'created_at' => "ALTER TABLE attendance_audits ADD COLUMN created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'",
  ];

  foreach ($adds as $col => $sql) {
    if (!isset($cols[$col])) {
      try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
  }

  try { $pdo->exec("ALTER TABLE attendance_audits ADD INDEX idx_store_date (store_id, business_date)"); } catch (Throwable $e) {}
}

/** ✅ audits INSERT（存在するカラム名に合わせて組み立て） */
function insert_audit(PDO $pdo, array $cols, array $data): void {
  $subjectCol = null;
  foreach (['cast_user_id','subject_user_id','target_user_id'] as $c) {
    if (isset($cols[$c])) { $subjectCol = $c; break; }
  }
  if ($subjectCol === null) $subjectCol = 'cast_user_id';

  $actorCol = null;
  foreach (['actor_user_id','admin_user_id'] as $c) {
    if (isset($cols[$c])) { $actorCol = $c; break; }
  }
  if ($actorCol === null) $actorCol = 'actor_user_id';

  $map = [
    'store_id' => $data['store_id'],
    'business_date' => $data['business_date'],
    'attendance_id' => $data['attendance_id'],
    $subjectCol => $data['subject_user_id'],
    $actorCol => $data['actor_user_id'],
    'action' => $data['action'],
    'field' => $data['field'],
    'old_value' => $data['old_value'],
    'new_value' => $data['new_value'],
    'ip' => $data['ip'],
    'user_agent' => $data['user_agent'],
    'created_at' => $data['created_at'],
  ];

  $fields = [];
  $place = [];
  $vals = [];

  foreach ($map as $k => $v) {
    if (!isset($cols[$k])) continue;
    $fields[] = "`{$k}`";
    $place[] = "?";
    $vals[] = $v;
  }

  if (!$fields) return;
  $sql = "INSERT INTO attendance_audits (" . implode(',', $fields) . ") VALUES (" . implode(',', $place) . ")";
  $st = $pdo->prepare($sql);
  $st->execute($vals);
}

$pdo = db();

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jexit(405, ['ok' => false, 'error' => 'method not allowed']);
  }

  $csrf = (string)($_POST['csrf'] ?? '');
  if ($csrf === '' || !hash_equals(csrf_token_local(), $csrf)) {
    jexit(400, ['ok' => false, 'error' => 'csrf']);
  }

  $storeId = safe_store_id();
  if ($storeId <= 0) jexit(400, ['ok' => false, 'error' => 'store_id missing']);
  store_decommission_assert_store_writable($pdo, $storeId, '停止中の店舗では出勤編集できません');

  $mode = (string)($_POST['mode'] ?? '');
  if (!in_array($mode, ['in','out','late','absent','memo','set_time'], true)) {
    jexit(400, ['ok' => false, 'error' => 'mode invalid']);
  }

  $subjectUserId = (int)($_POST['cast_id'] ?? 0);
  if ($subjectUserId <= 0) jexit(400, ['ok' => false, 'error' => 'target invalid']);

  $businessDate = (string)($_POST['date'] ?? '');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
    jexit(400, ['ok' => false, 'error' => 'date invalid']);
  }

  $st = $pdo->prepare("SELECT business_day_start FROM stores WHERE id=? LIMIT 1");
  $st->execute([$storeId]);
  $businessDayStart = (string)($st->fetchColumn() ?: '06:00:00');

  ensure_attendance_audits($pdo);
  $auditCols = fetch_cols($pdo, 'attendance_audits');

  $pdo->beginTransaction();

  // ✅ 正：user_id + store_id + business_date で引く
  $st = $pdo->prepare("
    SELECT id, clock_in, clock_out, status, note, is_late
    FROM attendances
    WHERE user_id=? AND store_id=? AND business_date=?
    LIMIT 1
  ");
  $st->execute([$subjectUserId, $storeId, $businessDate]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  $now = now_jst_str();

  if (!$row) {
    $ins = $pdo->prepare("
      INSERT INTO attendances
        (user_id, store_id, business_date, clock_in, clock_out, status, source_in, source_out, note, is_late, created_at, updated_at)
      VALUES
        (?, ?, ?, NULL, NULL, 'scheduled', NULL, NULL, '', 0, ?, ?)
    ");
    $ins->execute([$subjectUserId, $storeId, $businessDate, $now, $now]);

    $st->execute([$subjectUserId, $storeId, $businessDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }

  if (!$row) throw new RuntimeException('attendance row create failed');

  $attId = (int)$row['id'];

  $action = '';
  $field  = '';
  $oldVal = '';
  $newVal = '';

  if ($mode === 'in') {
    $action = 'toggle_in';
    $field  = 'clock_in';

    if (!empty($row['clock_in'])) {
      $oldVal = (string)$row['clock_in'];
      $newVal = '';
      $newStatus = (!empty($row['clock_out']) ? 'finished' : 'scheduled');
      $upd = $pdo->prepare("UPDATE attendances SET clock_in=NULL, status=?, source_in=NULL, updated_at=? WHERE id=? LIMIT 1");
      $upd->execute([$newStatus, $now, $attId]);
    } else {
      $oldVal = '';
      $newVal = $now;
      $newStatus = (!empty($row['clock_out']) ? 'finished' : 'working');
      $upd = $pdo->prepare("UPDATE attendances SET clock_in=?, status=?, source_in='admin', updated_at=? WHERE id=? LIMIT 1");
      $upd->execute([$now, $newStatus, $now, $attId]);
    }

  } elseif ($mode === 'out') {
    $action = 'toggle_out';
    $field  = 'clock_out';

    if (!empty($row['clock_out'])) {
      $oldVal = (string)$row['clock_out'];
      $newVal = '';
      $newStatus = (!empty($row['clock_in']) ? 'working' : 'scheduled');
      $upd = $pdo->prepare("UPDATE attendances SET clock_out=NULL, status=?, source_out=NULL, updated_at=? WHERE id=? LIMIT 1");
      $upd->execute([$newStatus, $now, $attId]);
    } else {
      $oldVal = '';
      $newVal = $now;
      $upd = $pdo->prepare("UPDATE attendances SET clock_out=?, status='finished', source_out='admin', updated_at=? WHERE id=? LIMIT 1");
      $upd->execute([$now, $now, $attId]);
    }

  } elseif ($mode === 'late') {
    $action = 'toggle_late';
    $field  = 'is_late';

    $cur = (int)($row['is_late'] ?? 0);
    $new = $cur ? 0 : 1;

    $oldVal = (string)$cur;
    $newVal = (string)$new;

    $upd = $pdo->prepare("UPDATE attendances SET is_late=?, updated_at=? WHERE id=? LIMIT 1");
    $upd->execute([$new, $now, $attId]);

  } elseif ($mode === 'absent') {
    $action = 'toggle_absent';
    $field  = 'status';

    $curStatus = (string)($row['status'] ?? '');
    $oldVal = $curStatus;

    if ($curStatus === 'absent') {
      $newVal = 'scheduled';
      $upd = $pdo->prepare("
        UPDATE attendances
        SET status='scheduled',
            updated_at=?
        WHERE id=?
        LIMIT 1
      ");
      $upd->execute([$now, $attId]);
    } else {
      $newVal = 'absent';
      $upd = $pdo->prepare("
        UPDATE attendances
        SET clock_in=NULL,
            clock_out=NULL,
            status='absent',
            source_in=NULL,
            source_out=NULL,
            is_late=0,
            updated_at=?
        WHERE id=?
        LIMIT 1
      ");
      $upd->execute([$now, $attId]);
    }

  } elseif ($mode === 'memo') {
    $action = 'set_memo';
    $field  = 'note';

    $memo = trim((string)($_POST['memo'] ?? ''));

    $oldVal = (string)($row['note'] ?? '');
    $newVal = $memo;

    $upd = $pdo->prepare("UPDATE attendances SET note=?, updated_at=? WHERE id=? LIMIT 1");
    $upd->execute([$memo, $now, $attId]);

  } else { // set_time
    $action = 'set_time';

    $target = (string)($_POST['target'] ?? '');
    $time   = (string)($_POST['time'] ?? '');
    if (!in_array($target, ['in','out'], true)) { $pdo->rollBack(); jexit(400, ['ok'=>false,'error'=>'target invalid']); }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) { $pdo->rollBack(); jexit(400, ['ok'=>false,'error'=>'time invalid']); }

    $dt = compose_dt_for_business_date($businessDate, $time, $businessDayStart);

    if ($target === 'in') {
      $field  = 'clock_in';
      $oldVal = (string)($row['clock_in'] ?? '');
      $newVal = $dt;

      $newStatus = (!empty($row['clock_out']) ? 'finished' : 'working');
      $upd = $pdo->prepare("UPDATE attendances SET clock_in=?, status=?, source_in='admin', updated_at=? WHERE id=? LIMIT 1");
      $upd->execute([$dt, $newStatus, $now, $attId]);
    } else {
      $field  = 'clock_out';
      $oldVal = (string)($row['clock_out'] ?? '');
      $newVal = $dt;

      $upd = $pdo->prepare("UPDATE attendances SET clock_out=?, status='finished', source_out='admin', updated_at=? WHERE id=? LIMIT 1");
      $upd->execute([$dt, $now, $attId]);
    }
  }

  // 監査ログ
  insert_audit($pdo, $auditCols, [
    'store_id' => $storeId,
    'business_date' => $businessDate,
    'attendance_id' => $attId,
    'subject_user_id' => $subjectUserId,
    'actor_user_id' => actor_user_id(),
    'action' => $action,
    'field' => $field,
    'old_value' => $oldVal,
    'new_value' => $newVal,
    'ip' => ip_addr(),
    'user_agent' => user_agent(),
    'created_at' => $now,
  ]);

  // fresh
  $st = $pdo->prepare("SELECT clock_in, clock_out, status, note, is_late FROM attendances WHERE id=? LIMIT 1");
  $st->execute([$attId]);
  $fresh = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $pdo->commit();

  jexit(200, [
    'ok' => true,
    'in_at' => $fresh['clock_in'] ?? null,
    'out_at' => $fresh['clock_out'] ?? null,
    'status' => (string)($fresh['status'] ?? ''),
    'is_late' => (int)($fresh['is_late'] ?? 0),
    // ✅ noteはメモ文字列として返す（JSONは禁止）
    'memo' => (string)($fresh['note'] ?? ''),
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[attendance_toggle] ' . $e->getMessage());
  jexit(500, ['ok' => false, 'error' => 'server error']);
}
