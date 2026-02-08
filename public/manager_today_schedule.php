<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['manager','admin','super_user']);

$pdo = db();

/* =========================
   Utils (fallback)
========================= */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $ok = is_string($token) && $token !== '' && isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
    if (!$ok) {
      http_response_code(403);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok'=>false,'error'=>'csrf'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

function conf(string $key): string {
  if (defined($key)) return (string)constant($key);
  $v = getenv($key);
  return is_string($v) ? $v : '';
}

function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }
function dow0(DateTime $d): int { return (int)$d->format('w'); } // 0=Sun..6=Sat

function normalize_date(string $ymd, ?string $fallback=null): string {
  $ymd = trim($ymd);
  if ($ymd === '') $ymd = (string)$fallback;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
  return $ymd;
}

function week_start_date(string $anyDateYmd, int $weekStartDow0): string {
  $dt = new DateTime($anyDateYmd, new DateTimeZone('Asia/Tokyo'));
  $cur = dow0($dt);
  $diff = ($cur - $weekStartDow0 + 7) % 7;
  if ($diff > 0) $dt->modify("-{$diff} days");
  return $dt->format('Y-m-d');
}
function week_dates(string $weekStartYmd): array {
  $dt = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) { $out[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }
  return $out;
}

function business_date_for_store(array $storeRow, ?DateTime $now=null): string {
  $now = $now ?: jst_now();
  $cut = (string)($storeRow['business_day_start'] ?? '06:00:00');
  $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
  if ($now < $cutDT) $now->modify('-1 day');
  return $now->format('Y-m-d');
}

function current_staff_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.code IN ('admin','manager')
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

/* =========================
   Store resolve
========================= */
$userId = current_user_id_safe();

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? ($_POST['store_id'] ?? 0));
  if ($storeId <= 0) {
    $st = $pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1");
    $storeId = (int)$st->fetchColumn();
  }
} else {
  $storeId = current_staff_store_id($pdo, $userId);
}

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が特定できません（user_roles の admin/manager に store_id を設定してください）');
}

$st = $pdo->prepare("
  SELECT id,name,business_day_start,weekly_holiday_dow,open_time
  FROM stores WHERE id=? LIMIT 1
");
$st->execute([$storeId]);
$store = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$store) exit('店舗が見つかりません');

$now = jst_now();
$bizDate = business_date_for_store($store);

/* =========================
   LINE push helper
========================= */
function line_api_post(string $accessToken, string $path, array $body): array {
  $url = 'https://api.line.me/v2/bot/' . ltrim($path, '/');
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  return [
    'code' => $code,
    'body' => is_string($res) ? $res : '',
    'curl_error' => $err,
  ];
}

function resolve_line_user_id(PDO $pdo, int $castUserId): string {
  $st = $pdo->prepare("
    SELECT provider_user_id
    FROM user_identities
    WHERE user_id=? AND provider='line' AND is_active=1
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$castUserId]);
  return (string)($st->fetchColumn() ?: '');
}

/* =========================
   AJAX: send notice (same file)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'send_notice') {
  header('Content-Type: application/json; charset=UTF-8');

  csrf_verify($_POST['csrf_token'] ?? null);

  $postStoreId = (int)($_POST['store_id'] ?? 0);
  if ($postStoreId !== $storeId && !$isSuper) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'store'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $kind = (string)($_POST['kind'] ?? '');
  if (!in_array($kind, ['late','absent'], true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'kind'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $castUserId = (int)($_POST['cast_user_id'] ?? 0);
  if ($castUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'cast'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $businessDate = normalize_date((string)($_POST['business_date'] ?? ''), $bizDate);

  $text = trim((string)($_POST['text'] ?? ''));
  if ($text === '' || mb_strlen($text, 'UTF-8') > 1000) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'text'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // cast belongs to this store?
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=? AND ur.store_id=?
  ");
  $st->execute([$castUserId, $storeId]);
  if ((int)$st->fetchColumn() <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'cast_store'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $lineTo = resolve_line_user_id($pdo, $castUserId);
  if ($lineTo === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'line_unlinked'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
  if ($accessToken === '') {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'line_token_missing'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // save action first (token is internal)
  $token = bin2hex(random_bytes(12)); // 24 chars
  $templateText = (string)($_POST['template_text'] ?? $text);

  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("
      INSERT INTO line_notice_actions
        (store_id, business_date, cast_user_id, kind, token, template_text, sent_text,
         sent_by_user_id, sent_at, status, error_message, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'sent', NULL, NOW(), NOW())
    ");
    $ins->execute([
      $storeId, $businessDate, $castUserId, $kind, $token,
      $templateText, $text, $userId
    ]);

    // push
    $api = line_api_post($accessToken, 'message/push', [
      'to' => $lineTo,
      'messages' => [
        ['type'=>'text', 'text'=>$text],
      ],
    ]);

    if ($api['code'] >= 300) {
      $errMsg = 'HTTP ' . $api['code'];
      if ($api['curl_error'] !== '') $errMsg .= ' curl=' . $api['curl_error'];

      $upd = $pdo->prepare("
        UPDATE line_notice_actions
        SET status='failed', error_message=?, updated_at=NOW()
        WHERE token=? LIMIT 1
      ");
      $upd->execute([$errMsg, $token]);

      $pdo->commit();
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'line_push_failed','detail'=>$errMsg], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $pdo->commit();
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* =========================
   Week range (today's week)
   - holiday翌日を週開始にする
========================= */
$holidayDow = $store['weekly_holiday_dow'];
$holidayDow = ($holidayDow === null) ? null : (int)$holidayDow; // 0=Sun..6=Sat
$weekStartDow0 = ($holidayDow === null) ? 1 : (($holidayDow + 1) % 7);

$baseDate = normalize_date((string)($_GET['date'] ?? ''), $bizDate);
$weekStart = week_start_date($baseDate, $weekStartDow0);
$dates = week_dates($weekStart);

/* =========================
   今日（予定×実績）
========================= */
$st = $pdo->prepare("
  SELECT
    u.id AS user_id,
    u.display_name,
    COALESCE(cp.employment_type,'part_time') AS employment_type,
    sp.start_time AS plan_start_time,
    sp.is_off AS plan_is_off,
    a.clock_in,
    a.clock_out
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id
  LEFT JOIN cast_profiles cp ON cp.user_id=u.id
  LEFT JOIN cast_shift_plans sp
    ON sp.store_id=ur.store_id
   AND sp.user_id=u.id
   AND sp.business_date=?
  LEFT JOIN attendances a
    ON a.store_id=ur.store_id
   AND a.user_id=u.id
   AND a.business_date=?
  WHERE ur.store_id=?
  ORDER BY u.is_active DESC, u.id ASC
");
$st->execute([$bizDate, $bizDate, $storeId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
   送信/返信 履歴（営業日分）
========================= */
$noticeMap = []; // [user_id][kind] => latest row
$st = $pdo->prepare("
  SELECT a.*, su.login_id AS sender_login
  FROM line_notice_actions a
  LEFT JOIN users su ON su.id=a.sent_by_user_id
  WHERE a.store_id=? AND a.business_date=?
  ORDER BY a.sent_at DESC
");
$st->execute([$storeId, $bizDate]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $a) {
  $uid = (int)$a['cast_user_id'];
  $k   = (string)$a['kind'];
  if (!isset($noticeMap[$uid][$k])) $noticeMap[$uid][$k] = $a;
}

/* =========================
   今週予定（7日）ロード
========================= */
$weekPlans = []; // [user_id][ymd] => ['start_time'=>'21:00', 'is_off'=>1]
$st = $pdo->prepare("
  SELECT user_id, business_date, start_time, is_off
  FROM cast_shift_plans
  WHERE store_id=?
    AND business_date BETWEEN ? AND ?
");
$st->execute([$storeId, $dates[0], $dates[6]]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $p) {
  $uid = (int)$p['user_id'];
  $d   = (string)$p['business_date'];
  $weekPlans[$uid][$d] = [
    'start_time' => $p['start_time'] ? substr((string)$p['start_time'], 0, 5) : '',
    'is_off' => ((int)$p['is_off'] === 1),
  ];
}

$weekAtt = []; // [user_id][ymd] => ['in'=>'21:05','out'=>'02:10']
$st = $pdo->prepare("
  SELECT user_id, business_date, clock_in, clock_out
  FROM attendances
  WHERE store_id=?
    AND business_date BETWEEN ? AND ?
");
$st->execute([$storeId, $dates[0], $dates[6]]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $a) {
  $uid = (int)$a['user_id'];
  $d   = (string)$a['business_date'];
  $weekAtt[$uid][$d] = [
    'in'  => $a['clock_in'] ? substr((string)$a['clock_in'], 11, 5) : '',
    'out' => $a['clock_out'] ? substr((string)$a['clock_out'], 11, 5) : '',
  ];
}

/* =========================
   KPI
========================= */
$cnt = ['not_in'=>0,'working'=>0,'finished'=>0,'off'=>0,'late'=>0];

foreach ($rows as $r) {
  $planOff = ((int)($r['plan_is_off'] ?? 0) === 1);
  if ($planOff) { $cnt['off']++; continue; }

  $clockIn  = $r['clock_in'] ?? null;
  $clockOut = $r['clock_out'] ?? null;

  if ($clockOut) { $cnt['finished']++; continue; }
  if ($clockIn)  { $cnt['working']++;  continue; }

  $cnt['not_in']++;

  $pst = (string)($r['plan_start_time'] ?? '');
  if ($pst !== '') {
    $planDT = new DateTime($bizDate . ' ' . substr($pst,0,5) . ':00', new DateTimeZone('Asia/Tokyo'));
    if ($now > $planDT) $cnt['late']++;
  }
}

/* =========================
   Render
========================= */
render_page_start('本日の予定');
render_header('本日の予定');
?>
<div class="page">
  <div class="admin-wrap">

    <div class="rowTop">
      <a class="btn" href="/seika-app/public/dashboard.php">← ダッシュボード</a>
      <div class="title">🗓 本日の予定 × 実績</div>
    </div>

    <div class="muted" style="margin-top:6px;">
      店舗：<b><?= h((string)$store['name']) ?> (#<?= (int)$storeId ?>)</b>
      / 営業日：<b><?= h($bizDate) ?></b>
      <span class="muted">（現在 <?= h($now->format('Y-m-d H:i')) ?>）</span>
    </div>

    <?php if ($isSuper): ?>
      <?php $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; ?>
      <form method="get" class="searchRow">
        <select name="store_id" class="sel">
          <?php foreach ($stores as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
              <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn">切替</button>
      </form>
    <?php endif; ?>

    <div class="kpi">
      <div class="k">未出勤<br><b><?= $cnt['not_in'] ?></b></div>
      <div class="k">出勤中<br><b><?= $cnt['working'] ?></b></div>
      <div class="k">退勤済<br><b><?= $cnt['finished'] ?></b></div>
      <div class="k">休み<br><b><?= $cnt['off'] ?></b></div>
      <div class="k">遅刻<br><b><?= $cnt['late'] ?></b></div>
    </div>

    <!-- 今日 -->
    <div class="card" style="margin-top:14px;">
      <div class="cardTitle">今日（予定と実績＋返信）</div>

      <table class="tbl">
        <thead>
          <tr>
            <th>状態</th>
            <th>名前</th>
            <th>予定</th>
            <th>実績</th>
            <th>遅刻</th>
            <th>返信</th>
            <th>連絡</th>
            <th>送信</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $uid = (int)$r['user_id'];
          $name = (string)$r['display_name'];

          $planOff   = ((int)($r['plan_is_off'] ?? 0) === 1);
          $planStart = (string)($r['plan_start_time'] ?? '');

          $clockIn  = $r['clock_in'] ? substr((string)$r['clock_in'], 11, 5) : '';
          $clockOut = $r['clock_out'] ? substr((string)$r['clock_out'], 11, 5) : '';

          $statusLabel = '未出勤';
          if ($planOff) $statusLabel = '休み';
          else if ($r['clock_out']) $statusLabel = '退勤済';
          else if ($r['clock_in']) $statusLabel = '出勤中';

          $isLate = false;
          if (!$planOff && $planStart !== '' && empty($r['clock_in'])) {
            $planDT = new DateTime($bizDate . ' ' . substr($planStart,0,5) . ':00', new DateTimeZone('Asia/Tokyo'));
            if ($now > $planDT) $isLate = true;
          }

          $lateNotice = $noticeMap[$uid]['late'] ?? null;
          $absNotice  = $noticeMap[$uid]['absent'] ?? null;

          // 返信は「最後に来た返信」を表示
          $replyText = '';
          $replyWhen = '';
          $cand = [];
          if ($lateNotice && !empty($lateNotice['last_reply_text'])) $cand[] = $lateNotice;
          if ($absNotice  && !empty($absNotice['last_reply_text']))  $cand[] = $absNotice;
          if ($cand) {
            usort($cand, fn($a,$b)=>strcmp((string)$b['responded_at'], (string)$a['responded_at']));
            $replyText = (string)($cand[0]['last_reply_text'] ?? '');
            $replyWhen = (string)($cand[0]['responded_at'] ?? '');
          }

          $tplLate = "{$name}さん\n遅刻の連絡をお願いします。\n到着予定時刻と理由を返信してください。";
          $tplAbs  = "{$name}さん\n本日欠勤の場合は理由を返信してください。";
        ?>
          <tr>
            <td><?= h($statusLabel) ?></td>
            <td>
              <b><?= h($name) ?></b>
              <div class="muted"><?= h((string)($r['employment_type'] ?? '')) ?></div>
            </td>
            <td>
              <?= $planOff ? '<span class="muted">OFF</span>' : '<b>'.h(substr($planStart,0,5)).'</b>' ?>
            </td>
            <td>
              <?= h($clockIn ?: '--:--') ?> → <?= h($clockOut ?: '--:--') ?>
            </td>
            <td>
              <?= $isLate ? '<span class="badge-red">遅刻</span>' : '<span class="muted">-</span>' ?>
            </td>

            <td style="max-width:360px;">
              <?php if ($replyText !== ''): ?>
                <div class="replyBox">
                  <div class="replyText"><?= nl2br(h(mb_strimwidth($replyText, 0, 160, '…', 'UTF-8'))) ?></div>
                  <div class="muted">返信: <?= h(substr($replyWhen, 11, 5)) ?></div>
                </div>
              <?php else: ?>
                <span class="muted">（返信なし）</span>
              <?php endif; ?>
            </td>

            <td>
              <div class="btnRow">
                <button type="button" class="btn ghost js-open-modal"
                  data-kind="late"
                  data-cast="<?= (int)$uid ?>"
                  data-name="<?= h($name) ?>"
                  data-text="<?= h($tplLate) ?>"
                >遅刻LINE</button>

                <button type="button" class="btn ghost js-open-modal"
                  data-kind="absent"
                  data-cast="<?= (int)$uid ?>"
                  data-name="<?= h($name) ?>"
                  data-text="<?= h($tplAbs) ?>"
                >欠勤LINE</button>
              </div>
            </td>

            <td class="muted" style="white-space:nowrap;">
              <?php
                $lastSent = null;
                if ($lateNotice) $lastSent = $lateNotice;
                if ($absNotice && (!$lastSent || (string)$absNotice['sent_at'] > (string)$lastSent['sent_at'])) $lastSent = $absNotice;
              ?>
              <?php if ($lastSent): ?>
                <?= h(substr((string)$lastSent['sent_at'], 11, 5)) ?>
                <div class="muted">by <?= h((string)($lastSent['sender_login'] ?? '')) ?></div>
                <?php if (($lastSent['status'] ?? '') === 'failed'): ?>
                  <div class="badge-red">送信失敗</div>
                  <div class="muted"><?= h((string)($lastSent['error_message'] ?? '')) ?></div>
                <?php endif; ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- 今週 -->
    <div class="card" style="margin-top:14px;">
      <div class="cardTitle">今週（7日）</div>
      <div class="muted" style="margin-bottom:8px;">
        週開始：<?= h($weekStart) ?>（店休日の翌日スタート）
      </div>

      <div class="weekWrap">
        <table class="tbl weekTbl">
          <thead>
            <tr>
              <th style="min-width:140px;">キャスト</th>
              <?php foreach ($dates as $d): ?>
                <?php
                  $dt = new DateTime($d, new DateTimeZone('Asia/Tokyo'));
                  $w = ['日','月','火','水','木','金','土'][(int)$dt->format('w')];
                ?>
                <th><?= h(substr($d,5)) ?><div class="muted"><?= h($w) ?></div></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): $uid=(int)$r['user_id']; ?>
              <tr>
                <td><b><?= h((string)$r['display_name']) ?></b></td>
                <?php foreach ($dates as $d): ?>
                  <?php
                    $p = $weekPlans[$uid][$d] ?? ['start_time'=>'','is_off'=>false];
                    $a = $weekAtt[$uid][$d] ?? ['in'=>'','out'=>''];
                    $cell = '';
                    if (!empty($p['is_off'])) {
                      $cell = '<span class="muted">OFF</span>';
                    } else {
                      $stt = (string)($p['start_time'] ?? '');
                      $cell = ($stt !== '' ? '<b>'.h($stt).'</b>' : '<span class="muted">--</span>');
                      if ($a['in'] !== '' || $a['out'] !== '') {
                        $cell .= '<div class="muted">'.h($a['in'] ?: '--:--').'→'.h($a['out'] ?: '--:--').'</div>';
                      }
                    }
                  ?>
                  <td><?= $cell ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Modal -->
<div class="modalBg" id="modalBg" hidden>
  <div class="modal">
    <div class="modalHead">
      <div class="modalTitle" id="modalTitle">LINE送信</div>
      <button type="button" class="btn ghost" id="modalClose">✕</button>
    </div>

    <div class="muted" id="modalSub" style="margin-top:4px;"></div>

    <form method="post" action="/seika-app/public/manager_today_schedule.php" id="modalForm">
      <input type="hidden" name="action" value="send_notice">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="business_date" value="<?= h($bizDate) ?>">
      <input type="hidden" name="cast_user_id" id="m_cast_user_id" value="">
      <input type="hidden" name="kind" id="m_kind" value="">
      <input type="hidden" name="template_text" id="m_template_text" value="">

      <textarea name="text" id="m_text" class="ta" rows="7" required></textarea>

      <div class="modalFoot">
        <button type="button" class="btn" id="modalCancel">キャンセル</button>
        <button type="submit" class="btn primary" id="modalSend">送信</button>
      </div>
      <div class="muted" id="modalMsg" style="margin-top:8px;"></div>
    </form>
  </div>
</div>

<style>
.rowTop{ display:flex; align-items:center; gap:12px; }
.title{ font-weight:1000; font-size:18px; }

.btn{ display:inline-flex; align-items:center; gap:6px; padding:10px 14px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; text-decoration:none; cursor:pointer; }
.btn.primary{ background:rgba(59,130,246,.18); border-color:rgba(59,130,246,.35); }
.btn.ghost{ background:transparent; }

.searchRow{ margin-top:10px; display:flex; gap:10px; align-items:center; }
.sel{ padding:10px 12px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; }

.kpi{ margin-top:10px; display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; }
.k{ padding:12px; border:1px solid var(--line); border-radius:14px; background:var(--cardA); text-align:center; }

.card{ padding:14px; border:1px solid var(--line); border-radius:14px; background:var(--cardA); }
.cardTitle{ font-weight:900; margin-bottom:10px; }

.tbl{ width:100%; border-collapse:collapse; }
.tbl th,.tbl td{ padding:10px; border-bottom:1px solid rgba(255,255,255,.08); vertical-align:top; }
.muted{ opacity:.75; font-size:12px; }
.badge-red{ display:inline-block; background:#ef4444; color:#fff; font-size:11px; padding:2px 8px; border-radius:999px; }
.btnRow{ display:flex; gap:8px; flex-wrap:wrap; }

.replyBox{ padding:8px 10px; border:1px solid rgba(255,255,255,.12); border-radius:12px; background:rgba(255,255,255,.04); }
.replyText{ font-size:13px; white-space:normal; line-height:1.35; }

.modalBg[hidden]{ display:none !important; } /* ← これが大事（script内に書かない） */
.modalBg{
  position:fixed; inset:0;
  background:rgba(0,0,0,.55);
  display:flex; align-items:center; justify-content:center;
  padding:16px;
  z-index:1000;
}
.modal{
  width:min(720px, 96vw);
  border:1px solid rgba(255,255,255,.14);
  border-radius:16px;
  background:#0f1730;
  padding:14px;
}
.modalHead{ display:flex; justify-content:space-between; align-items:center; }
.modalTitle{ font-weight:1000; font-size:16px; }
.ta{
  width:100%;
  margin-top:10px;
  padding:12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.16);
  background:rgba(255,255,255,.04);
  color:#e8ecff;
  resize:vertical;
}
.modalFoot{ display:flex; justify-content:flex-end; gap:10px; margin-top:10px; }

.weekWrap{ overflow:auto; }
.weekTbl th{ position:sticky; top:0; background:var(--cardA); }
</style>

<script>
(() => {
  const bg = document.getElementById('modalBg');
  const closeBtn = document.getElementById('modalClose');
  const cancelBtn = document.getElementById('modalCancel');
  const title = document.getElementById('modalTitle');
  const sub = document.getElementById('modalSub');
  const msg = document.getElementById('modalMsg');

  const inCast = document.getElementById('m_cast_user_id');
  const inKind = document.getElementById('m_kind');
  const inTpl  = document.getElementById('m_template_text');
  const ta = document.getElementById('m_text');
  const form = document.getElementById('modalForm');
  const sendBtn = document.getElementById('modalSend');

  function openModal(kind, castId, name, text){
    msg.textContent = '';
    inCast.value = castId;
    inKind.value = kind;
    inTpl.value  = text;

    title.textContent = (kind === 'late') ? '遅刻LINE 送信' : '欠勤LINE 送信';
    sub.textContent = `宛先：${name}（user_id=${castId}）`;
    ta.value = text;

    bg.hidden = false;
    setTimeout(() => ta.focus(), 50);
  }
  function closeModal(){ bg.hidden = true; }

  document.querySelectorAll('.js-open-modal').forEach(btn => {
    btn.addEventListener('click', () => {
      openModal(btn.dataset.kind, btn.dataset.cast, btn.dataset.name, btn.dataset.text);
    });
  });

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  bg.addEventListener('click', (e) => { if (e.target === bg) closeModal(); });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = '送信中…';
    sendBtn.disabled = true;

    try {
      const fd = new FormData(form);
      const res = await fetch('/seika-app/public/manager_today_schedule.php', { method:'POST', body: fd });
      const json = await res.json().catch(() => null);

      if (!res.ok || !json || !json.ok) {
        msg.textContent = '送信失敗：' + (json && json.error ? json.error : ('HTTP ' + res.status));
        sendBtn.disabled = false;
        return;
      }

      msg.textContent = '✅ 送信しました（返信はこの画面に自動反映）';
      setTimeout(() => location.reload(), 600);

    } catch (err) {
      msg.textContent = '送信失敗：通信エラー';
      sendBtn.disabled = false;
    }
  });
})();
</script>

<?php render_page_end(); ?>