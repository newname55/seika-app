<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['manager','admin','super_user']);

$pdo = db();

/* =========================
   Utils (safe)
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

if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}
if (!function_exists('current_user_id')) {
  function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
  }
}

function conf(string $key): string {
  if (defined($key)) return (string)constant($key);
  $v = getenv($key);
  return is_string($v) ? $v : '';
}

function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }
function dow0(DateTime $d): int { return (int)$d->format('w'); } // 0=Sun..6=Sat

function normalize_date(string $ymd, ?string $fallback=null): string {
  $ymd = trim($ymd);
  if ($ymd === '') $ymd = (string)$fallback;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
    return (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
  }
  return $ymd;
}

function week_dates(string $weekStartYmd): array {
  $dt = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) { $out[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }
  return $out;
}

function week_start_by_dow(string $baseYmd, int $weekStartDow0): string {
  $dt = new DateTime($baseYmd, new DateTimeZone('Asia/Tokyo'));
  $cur = (int)$dt->format('w');
  $diff = ($cur - $weekStartDow0 + 7) % 7;
  if ($diff > 0) $dt->modify("-{$diff} days");
  return $dt->format('Y-m-d');
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
      AND r.code IN ('admin','manager','super_user')
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
$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');

$userId = (int)current_user_id();

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? ($_POST['store_id'] ?? 0));
  if ($storeId <= 0) {
    $storeId = (int)($pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
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

/* =========================
   Business date (GET優先)
========================= */
$bizDate = (string)($_GET['business_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) {
  $bizDate = business_date_for_store($store);
}
$now = jst_now();

/* =========================
   LINE helpers
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
   AJAX: send_notice
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
   Week range (holiday翌日を週開始)
========================= */
$holidayDow = $store['weekly_holiday_dow'];
$holidayDow = ($holidayDow === null) ? null : (int)$holidayDow; // 0=Sun..6=Sat
$weekStartDow0 = ($holidayDow === null) ? 1 : (($holidayDow + 1) % 7);

$calDate  = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$weekStart = week_start_by_dow($calDate, $weekStartDow0);
$dates = week_dates($weekStart);

/* =========================
   今日（予定×実績） + 店番(shop_tag)
   - has_plan を作る
   - 母集団は v_store_casts_active（store_users.status='active' + users.is_active）
========================= */
$st = $pdo->prepare("
  SELECT
    c.user_id,
    c.display_name,
    c.user_is_active AS is_active,
    c.employment_type,
    c.shop_tag,

    sp.start_time AS plan_start_time,
    sp.is_off     AS plan_is_off,
    (sp.user_id IS NOT NULL) AS has_plan,

    a.clock_in,
    a.clock_out
  FROM v_store_casts_active c

  LEFT JOIN cast_shift_plans sp
    ON sp.store_id = c.store_id
   AND sp.user_id  = c.user_id
   AND sp.business_date = ?

  LEFT JOIN attendances a
    ON a.store_id = c.store_id
   AND a.user_id  = c.user_id
   AND a.business_date = ?

  WHERE c.store_id = ?

  ORDER BY
    CASE WHEN c.shop_tag='' THEN 999999 ELSE CAST(c.shop_tag AS UNSIGNED) END ASC,
    c.display_name ASC,
    c.user_id ASC
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
   今週予定（7日）
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
   KPI（予定行がある人だけ）
========================= */
$cnt = [
  'planned'  => 0,
  'not_in'   => 0,
  'working'  => 0,
  'finished' => 0,
  'off'      => 0,
  'late'     => 0,
];

foreach ($rows as $r) {
  $hasPlan = ((int)($r['has_plan'] ?? 0) === 1);
  if (!$hasPlan) continue; // 予定が無い人は計算対象外

  $isOff = ((int)($r['plan_is_off'] ?? 0) === 1);
  if ($isOff) { $cnt['off']++; continue; }

  $cnt['planned']++;

  $clockIn  = $r['clock_in'] ?? null;
  $clockOut = $r['clock_out'] ?? null;

  if ($clockOut) { $cnt['finished']++; continue; }
  if ($clockIn)  { $cnt['working']++;  continue; }

  $cnt['not_in']++;

  $pst = (string)($r['plan_start_time'] ?? '');
  if ($pst !== '') {
    $planDT = new DateTime($bizDate.' '.substr($pst,0,5).':00', new DateTimeZone('Asia/Tokyo'));
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

    <div class="subInfo">
      <div class="muted">
        店舗：<b><?= h((string)$store['name']) ?> (#<?= (int)$storeId ?>)</b>
        / 営業日：<b><?= h($bizDate) ?></b>
        <span class="muted">（現在 <?= h($now->format('Y-m-d H:i')) ?>）</span>
      </div>
    </div>

    <?php if ($isSuper): ?>
      <?php $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; ?>
      <form method="get" class="searchRow">
        <label class="muted">店舗</label>
        <select name="store_id" class="sel">
          <?php foreach ($stores as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
              <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>

        <label class="muted">営業日</label>
        <input class="sel" type="date" name="business_date" value="<?= h($bizDate) ?>">

        <button class="btn">表示</button>
      </form>
    <?php endif; ?>

    <!-- KPI（後でボタン化/フィルタ化する前提で class を付けておく） -->
    <div class="kpi kpiBtns" id="kpi">
      <div class="k" data-filter="planned">出勤予定<br><b><?= (int)$cnt['planned'] ?></b></div>
      <div class="k" data-filter="wait">未出勤<br><b><?= (int)$cnt['not_in'] ?></b></div>
      <div class="k" data-filter="in">出勤中<br><b><?= (int)$cnt['working'] ?></b></div>
      <div class="k" data-filter="done">退勤済<br><b><?= (int)$cnt['finished'] ?></b></div>
      <div class="k" data-filter="off">休み<br><b><?= (int)$cnt['off'] ?></b></div>
      <div class="k" data-filter="late">遅刻<br><b><?= (int)$cnt['late'] ?></b></div>
    </div>

    <!-- 今日 -->
    <div class="card" style="margin-top:14px;">
      <div class="cardTitle">今日（予定と実績＋返信）</div>

      <div class="tblWrap" aria-label="今日の予定と実績">
        <table class="tbl tblToday">
          <thead>
            <tr>
              <th class="col-status">状態</th>
              <th class="col-cast">キャスト</th>
              <th class="col-plan">予定</th>
              <th class="col-actual">実績</th>
              <th class="col-late">遅刻</th>
              <th class="col-reply">返信</th>
              <th class="col-action">連絡</th>
              <th class="col-sent">送信</th>
            </tr>
          </thead>

          <tbody>
          <?php foreach ($rows as $r):
            $uid  = (int)$r['user_id'];
            $name = (string)$r['display_name'];

            $shopTag  = trim((string)($r['shop_tag'] ?? ''));
            $tagLabel = ($shopTag !== '') ? $shopTag : (string)$uid; // 空の間の保険

            $hasPlan   = ((int)($r['has_plan'] ?? 0) === 1);
            $planOff   = $hasPlan && ((int)($r['plan_is_off'] ?? 0) === 1);
            $planStart = $hasPlan ? (string)($r['plan_start_time'] ?? '') : '';

            $clockIn  = $r['clock_in'] ? substr((string)$r['clock_in'], 11, 5) : '';
            $clockOut = $r['clock_out'] ? substr((string)$r['clock_out'], 11, 5) : '';

            // 状態ラベル
            $statusLabel = '未出勤';
            if (!$hasPlan) $statusLabel = '予定なし';
            else if ($planOff) $statusLabel = '休み';
            else if ($r['clock_out']) $statusLabel = '退勤済';
            else if ($r['clock_in']) $statusLabel = '出勤中';

            // 遅刻判定
            $isLate = false;
            if ($hasPlan && !$planOff && $planStart !== '' && empty($r['clock_in'])) {
              $planDT = new DateTime($bizDate . ' ' . substr($planStart, 0, 5) . ':00', new DateTimeZone('Asia/Tokyo'));
              if ($now > $planDT) $isLate = true;
            }

            // 状態キー（CSS/JS用）
            $state = 'wait';
            if (!$hasPlan) $state = 'noplan';
            else if ($planOff) $state = 'off';
            else if ($r['clock_out']) $state = 'done';
            else if ($r['clock_in']) $state = 'in';
            else $state = ($isLate ? 'late' : 'wait');

            $lateNotice = $noticeMap[$uid]['late'] ?? null;
            $absNotice  = $noticeMap[$uid]['absent'] ?? null;

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

            // 直近送信
            $lastSent = null;
            if ($lateNotice) $lastSent = $lateNotice;
            if ($absNotice && (!$lastSent || (string)$absNotice['sent_at'] > (string)$lastSent['sent_at'])) $lastSent = $absNotice;

          ?>
            <tr class="row row-state-<?= h($state) ?>" data-state="<?= h($state) ?>" data-user-id="<?= (int)$uid ?>">
              <td class="col-status">
                <span class="badgeState s-<?= h($state) ?>"><?= h($statusLabel) ?></span>
              </td>

              <td class="col-cast">
                <div class="castMain">
                  <b class="castName">【<?= h($tagLabel) ?>】<?= h($name) ?></b>
                </div>
                <div class="castSub muted"><?= h((string)($r['employment_type'] ?? 'part')) ?></div>
              </td>

              <td class="col-plan">
                <?php if (!$hasPlan): ?>
                  <span class="muted">（予定なし）</span>
                <?php elseif ($planOff): ?>
                  <span class="muted">OFF</span>
                <?php else: ?>
                  <span class="timePlan"><b><?= h(substr($planStart,0,5)) ?></b></span>
                <?php endif; ?>
              </td>

              <td class="col-actual">
                <span class="timeActual">
                  <?= h($clockIn ?: '--:--') ?>
                  <span class="muted">→</span>
                  <?= h($clockOut ?: '--:--') ?>
                </span>
              </td>

              <td class="col-late">
                <?php if ($hasPlan && !$planOff && $isLate): ?>
                  <span class="badgeLate">遅刻</span>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>

              <td class="col-reply">
                <?php if ($replyText !== ''): ?>
                  <div class="replyBox">
                    <div class="replyText"><?= nl2br(h(mb_strimwidth($replyText, 0, 160, '…', 'UTF-8'))) ?></div>
                    <div class="muted">返信: <?= h(substr($replyWhen, 11, 5)) ?></div>
                  </div>
                <?php else: ?>
                  <span class="muted">（返信なし）</span>
                <?php endif; ?>
              </td>

              <td class="col-action">
                <?php if ($hasPlan && !$planOff): ?>
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
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>

              <td class="col-sent">
                <?php if ($lastSent): ?>
                  <div class="sentWhen"><?= h(substr((string)$lastSent['sent_at'], 11, 5)) ?></div>
                  <div class="muted">by <?= h((string)($lastSent['sender_login'] ?? '')) ?></div>
                  <?php if (($lastSent['status'] ?? '') === 'failed'): ?>
                    <div class="badgeErr">送信失敗</div>
                    <div class="muted"><?= h((string)($lastSent['error_message'] ?? '')) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
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
              <th style="min-width:220px;">キャスト</th>
              <?php foreach ($dates as $d): ?>
                <?php $dt = new DateTime($d, new DateTimeZone('Asia/Tokyo')); ?>
                <?php $w = ['日','月','火','水','木','金','土'][(int)$dt->format('w')]; ?>
                <th><?= h(substr($d,5)) ?><div class="muted"><?= h($w) ?></div></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): $uid=(int)$r['user_id']; ?>
              <?php
                $shopTag = trim((string)($r['shop_tag'] ?? ''));
                $tagLabel = ($shopTag !== '') ? $shopTag : (string)$uid;
              ?>
              <tr>
                <td><b>【<?= h($tagLabel) ?>】<?= h((string)$r['display_name']) ?></b></td>
                <?php foreach ($dates as $d): ?>
                  <?php
                    $p = $weekPlans[$uid][$d] ?? null;
                    $a = $weekAtt[$uid][$d] ?? ['in'=>'','out'=>''];

                    if ($p === null) {
                      $cell = '<span class="muted">--</span>';
                    } else if (!empty($p['is_off'])) {
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
.rowTop{ display:flex; align-items:center; gap:12px; justify-content:space-between; }
.title{ font-weight:1000; font-size:18px; }
.btn{ display:inline-flex; align-items:center; gap:6px; padding:10px 14px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; text-decoration:none; cursor:pointer; }
.btn.primary{ background:rgba(59,130,246,.18); border-color:rgba(59,130,246,.35); }
.btn.ghost{ background:transparent; }
.searchRow{ margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
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

.modalBg[hidden]{ display:none !important; }
.modalBg{ position:fixed; inset:0; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; padding:16px; z-index:1000; }
.modal{ width:min(720px, 96vw); border:1px solid rgba(255,255,255,.14); border-radius:16px; background:#0f1730; padding:14px; }
.modalHead{ display:flex; justify-content:space-between; align-items:center; }
.modalTitle{ font-weight:1000; font-size:16px; }
.ta{ width:100%; margin-top:10px; padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,.16); background:rgba(255,255,255,.04); color:#e8ecff; resize:vertical; }
.modalFoot{ display:flex; justify-content:flex-end; gap:10px; margin-top:10px; }

.weekWrap{ overflow:auto; }
.weekTbl th{ position:sticky; top:0; background:var(--cardA); }
/* =========================================================
   manager_today_schedule: Lightでも読める強制コントラスト
   - 既存CSSの上書き用（末尾に追加）
========================================================= */

/* 0) ベース：カード/テーブルの文字色を「確実に濃く」 */
.card, .tblWrap, .tbl, .tbl th, .tbl td,
.k, .btn, .sel, .replyBox{
  color: #0f172a; /* slate-900 */
}

/* muted は薄くしすぎない（Lightで消えるのを防ぐ） */
.muted{
  opacity: 0.82;
  color: #334155; /* slate-700 */
}

/* 1) KPI：押せる雰囲気（後でJSでフィルタにする前提） */
.kpiBtns .k{
  cursor: pointer;
  user-select: none;
  transition: transform .05s ease, background .15s ease, border-color .15s ease;
}
.kpiBtns .k:hover{ transform: translateY(-1px); border-color: rgba(59,130,246,.35); }
.kpiBtns .k.active{ outline: 2px solid rgba(59,130,246,.35); }

/* 2) テーブルコンテナ（白背景に寄せてLightで見えるように） */
.tblWrap{
  overflow:auto;
  border: 1px solid rgba(15,23,42,.14);
  border-radius: 14px;
  background: #ffffff;
}

/* 3) 今日テーブル：見出しを濃い背景＋白文字で固定 */
.tblToday{
  width:100%;
  border-collapse: separate;
  border-spacing: 0;
  min-width: 980px; /* ボタン列があるので最低幅を確保 */
}

.tblToday thead th{
  position: sticky;
  top: 0;
  z-index: 5;
  background: #0f172a;  /* 濃紺 */
  color: #ffffff;
  font-weight: 900;
  border-bottom: 1px solid rgba(255,255,255,.18);
  white-space: nowrap;
}

/* 行：白ベース＋ホバー */
.tblToday tbody td{
  background:#ffffff;
  border-bottom: 1px solid rgba(15,23,42,.08);
  vertical-align: middle;
}
.tblToday tbody tr:hover td{
  background: #f8fafc; /* very light */
}

/* 4) 列の横幅（見やすく） */
.tblToday .col-status{ width: 110px; }
.tblToday .col-cast{ min-width: 240px; }
.tblToday .col-plan{ width: 90px; text-align:center; }
.tblToday .col-actual{ width: 140px; text-align:center; }
.tblToday .col-late{ width: 80px; text-align:center; }
.tblToday .col-reply{ min-width: 320px; }
.tblToday .col-action{ width: 190px; }
.tblToday .col-sent{ width: 150px; white-space: nowrap; }

/* 5) 状態バッジ（Lightで見える配色） */
.badgeState{
  display:inline-flex;
  align-items:center;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 900;
  border: 1px solid rgba(15,23,42,.18);
  background: #f1f5f9;
  color: #0f172a;
  white-space: nowrap;
}

/* 状態別 */
.badgeState.s-noplan{ background:#f1f5f9; color:#475569; }
.badgeState.s-off   { background:#eef2ff; color:#3730a3; border-color: rgba(99,102,241,.25); }
.badgeState.s-wait  { background: rgba(251,191,36,.18); color:#92400e; border-color: rgba(251,191,36,.35); }
.badgeState.s-late  { background: rgba(239,68,68,.14); color:#991b1b; border-color: rgba(239,68,68,.35); }
.badgeState.s-in    { background: rgba(34,197,94,.16); color:#166534; border-color: rgba(34,197,94,.35); }
.badgeState.s-done  { background: rgba(59,130,246,.14); color:#1d4ed8; border-color: rgba(59,130,246,.30); }

/* 6) 行の左ラインで “今見るべき” を強調 */
.row{ position: relative; }
.row-state-wait td{ box-shadow: inset 4px 0 0 rgba(251,191,36,.65); }
.row-state-late td{ box-shadow: inset 4px 0 0 rgba(239,68,68,.80); }
.row-state-in   td{ box-shadow: inset 4px 0 0 rgba(34,197,94,.70); }
.row-state-done td{ box-shadow: inset 4px 0 0 rgba(59,130,246,.65); }

/* 7) 遅刻バッジ（既存 badge-red と分離） */
.badgeLate{
  display:inline-block;
  font-size: 11px;
  font-weight: 900;
  padding: 3px 10px;
  border-radius: 999px;
  background: rgba(239,68,68,.14);
  color: #991b1b;
  border: 1px solid rgba(239,68,68,.35);
}

/* 8) 返信ボックス：Lightでも見える */
.replyBox{
  border: 1px solid rgba(15,23,42,.14);
  background: #f8fafc;
}
.replyText{
  color:#0f172a;
}

/* 9) 送信失敗の表示 */
.badgeErr{
  display:inline-block;
  margin-top:6px;
  font-size:11px;
  font-weight:900;
  padding:3px 10px;
  border-radius:999px;
  background: rgba(239,68,68,.14);
  color:#991b1b;
  border: 1px solid rgba(239,68,68,.35);
}
.sentWhen{ font-weight: 900; }

/* 10) ボタン：Lightで“薄すぎ”を防ぐ */
.btn{
  border-color: rgba(15,23,42,.14);
  background: #ffffff;
}
.btn.ghost{
  background: #ffffff;
}
.btn.primary{
  background: rgba(59,130,246,.12);
  border-color: rgba(59,130,246,.30);
}
.btn:disabled{
  opacity: .45;
  cursor: not-allowed;
}

/* 11) 週テーブル：見出しを固定しつつ見やすく */
.weekTbl th{
  background: #0f172a;
  color: #ffffff;
  border-bottom: 1px solid rgba(255,255,255,.18);
}

/* 12) スマホ最適化：返信列を少し縮める */
@media (max-width: 900px){
  .tblToday{ min-width: 860px; }
  .tblToday .col-reply{ min-width: 260px; }
}
/* 状態セルを強調 */
.col-status{
  font-weight:900;
}

/* 状態バッジ */
.badgeState{
  min-width:72px;
  justify-content:center;
}

/* 状態別アイコン風 */
.badgeState.s-wait::before{ content:"⏳ "; }
.badgeState.s-in::before{ content:"🟢 "; }
.badgeState.s-done::before{ content:"✔ "; }
.badgeState.s-off::before{ content:"🌙 "; }
.badgeState.s-noplan::before{ content:"— "; }
.badgeState.s-late::before{ content:"⚠ "; }

/* 要対応行 */
.row-state-wait td{
  background: linear-gradient(
    to right,
    rgba(251,191,36,.12),
    #ffffff 40%
  );
}

.row-state-late td{
  background: linear-gradient(
    to right,
    rgba(239,68,68,.12),
    #ffffff 40%
  );
}
/* 休み行は少し引く */
.row-state-off{
  opacity: .65;
}

.row-state-off td{
  background:#fafafa;
}
/* LINE系ボタン */
.btn.line-late{
  border-color: rgba(239,68,68,.35);
  background: rgba(239,68,68,.10);
  color:#991b1b;
  font-weight:800;
}

.btn.line-abs{
  border-color: rgba(251,191,36,.40);
  background: rgba(251,191,36,.14);
  color:#92400e;
  font-weight:800;
}
.tblToday thead th{
  text-align:center;
}

.tblToday thead th:first-child{
  text-align:left;
}

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