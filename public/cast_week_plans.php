<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','admin','manager','super_user']);
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* =========================
  helpers
========================= */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function has_role(string $role): bool { return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true); }
function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}
function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }
function ymd(DateTime $d): string { return $d->format('Y-m-d'); }
function dow0(string $ymd): int { return (int)(new DateTime($ymd, new DateTimeZone('Asia/Tokyo')))->format('w'); } // 0=Sun..6=Sat
function jp_dow_label(string $ymd): string {
  static $jp = ['日','月','火','水','木','金','土'];
  return $jp[dow0($ymd)] ?? '';
}
function normalize_date(?string $ymd, ?string $fallback=null): string {
  $s = trim((string)$ymd);
  if ($s === '' && $fallback !== null) $s = $fallback;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return ymd(new DateTime('today', new DateTimeZone('Asia/Tokyo')));
  return $s;
}
function week_start_date(string $anyDateYmd, int $weekStartDow0): string {
  $dt = new DateTime($anyDateYmd, new DateTimeZone('Asia/Tokyo'));
  $curDow = (int)$dt->format('w');
  $diff = ($curDow - $weekStartDow0 + 7) % 7;
  if ($diff > 0) $dt->modify("-{$diff} days");
  return $dt->format('Y-m-d');
}
function week_dates(string $weekStartYmd): array {
  $dt = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) { $out[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }
  return $out;
}

/* =========================
  role / store resolve
========================= */
$userId   = current_user_id_safe();
$isSuper  = has_role('super_user');
$isStaff  = $isSuper || has_role('admin') || has_role('manager');
$isCast   = has_role('cast');
$castOnly = (!$isStaff && $isCast);

function current_staff_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id
    WHERE ur.user_id=?
      AND r.code IN ('admin','manager')
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}
function current_cast_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("SELECT store_id FROM cast_profiles WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $sid = (int)($st->fetchColumn() ?: 0);
  if ($sid > 0) return $sid;

  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=? AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

$storeId = 0;
if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? ($_POST['store_id'] ?? 0));
  if ($storeId <= 0) {
    $storeId = (int)$pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
  }
} elseif ($isStaff) {
  $storeId = current_staff_store_id($pdo, $userId);
} else {
  $storeId = current_cast_store_id($pdo, $userId);
}
if ($storeId <= 0) { http_response_code(400); exit('店舗が特定できません'); }

$st = $pdo->prepare("SELECT id,name,weekly_holiday_dow FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$storeRow) { http_response_code(404); exit('店舗が見つかりません'); }

$holidayDow = $storeRow['weekly_holiday_dow'];
$holidayDow = ($holidayDow === null) ? null : (int)$holidayDow;

/* 星華：日曜店休(=0)なら日曜列を非表示 */
$hideHolidayColumn = ($holidayDow === 0);

/* =========================
  week
========================= */
$weekStartDow0 = ($holidayDow === null) ? 1 : (($holidayDow + 1) % 7); // 店休日の翌日を週開始に寄せる
$baseDate  = normalize_date((string)($_GET['date'] ?? $_POST['date'] ?? ''), ymd(jst_now()));
$weekStart = week_start_date($baseDate, $weekStartDow0);
$datesAll  = week_dates($weekStart);

$dates = [];
foreach ($datesAll as $d) {
  if ($hideHolidayColumn && $holidayDow !== null && dow0($d) === $holidayDow) continue;
  $dates[] = $d;
}

/* =========================
  store list (super)
========================= */
$stores = [];
if ($isSuper) {
  $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* =========================
  cast list
========================= */
$st = $pdo->prepare("
  SELECT
    u.id,
    u.display_name,
    u.is_active,

    -- 店番：store_users.staff_code 優先（なければ cast_profiles.shop_tag）
    COALESCE(
      NULLIF(su.staff_code, _utf8mb4'' COLLATE utf8mb4_bin),
      NULLIF(cp.shop_tag,   _utf8mb4'' COLLATE utf8mb4_bin),
      ''
    ) AS staff_code,

    COALESCE(
      NULLIF(su.employment_type, _utf8mb4'' COLLATE utf8mb4_bin),
      cp.employment_type,
      'part'
    ) AS employment_type,

    -- セルのデフォルト開始（あれば優先）
    cp.default_start_time

  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id

  LEFT JOIN store_users su
    ON su.store_id = ur.store_id AND su.user_id = u.id

  LEFT JOIN cast_profiles cp ON cp.user_id = u.id

  WHERE ur.store_id = ?
    -- 退店キャストは除外（store_usersが無い古い人は通す）
    AND (su.status IS NULL OR su.status = 'active')

ORDER BY
  -- 空は最後
  CASE
    WHEN staff_code IS NULL OR staff_code = _utf8mb4'' COLLATE utf8mb4_bin THEN 2
    ELSE 0
  END,
  -- 数字っぽい店番を先に（CASTして 0 になるものは後ろへ）
  CASE
    WHEN CAST(staff_code AS UNSIGNED) > 0 OR staff_code = _utf8mb4'0' COLLATE utf8mb4_bin THEN 0
    ELSE 1
  END,
  CAST(staff_code AS UNSIGNED),
  staff_code,
  u.display_name,
  u.id
");
$st->execute([$storeId]);
$castRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
  load plans
  - note: #douhan タグだけを使う（メモ入力はUIに出さない）
  - 休み：レコード無し（現状テーブルに is_off が無いので）
  - LAST：DBは 23:59:00（UIは LAST 表示）
========================= */
$plans = []; // [uid][ymd] => ['start'=>'HH:MM','end'=>'LAST|HH:MM','douhan'=>bool]
if ($dates) {
  $minD = $dates[0];
  $maxD = $dates[count($dates)-1];

  $st = $pdo->prepare("
    SELECT user_id, business_date, start_time, is_off, note
    FROM cast_shift_plans
    WHERE store_id=?
      AND business_date BETWEEN ? AND ?
      AND status='planned'
  ");
  $st->execute([$storeId, $minD, $maxD]);

  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $uid = (int)$r['user_id'];
    $d   = (string)$r['business_date'];

    $note = (string)($r['note'] ?? '');
    $douhan = (strpos($note, '#douhan') !== false);

    // end は note から読む（#end=HH:MM or #end=LAST）
    $end = 'LAST';
    if (preg_match('/#end=(\d{2}:\d{2}|LAST)\b/u', $note, $m)) {
      $end = strtoupper((string)$m[1]);
    }

    $off = ((int)$r['is_off'] === 1);
    $start = (!$off && $r['start_time'] !== null) ? substr((string)$r['start_time'], 0, 5) : '';

    $plans[$uid][$d] = [
      'start' => $start,
      'end'   => $end,
      'douhan'=> $douhan,
    ];
  }
}



/* =========================
  POST save
========================= */
$err = '';
$ok  = (string)($_GET['ok'] ?? '') === '1';

function normalize_time_hm(?string $hm): ?string {
  $hm = trim((string)$hm);
  if ($hm === '' || $hm === '--') return null;
  if (!preg_match('/^\d{2}:\d{2}$/', $hm)) return null;
  return $hm . ':00';
}
function normalize_end_time_for_db(?string $end): string {
  $end = trim((string)$end);
  if ($end === '' || strtoupper($end) === 'LAST') return '23:59:00';
  if (preg_match('/^\d{2}:\d{2}$/', $end)) return $end . ':00';
  return '23:59:00';
}
function note_from_flags(bool $douhan, string $endHm): string {
  $parts = [];
  if ($douhan) $parts[] = '#douhan';

  $endHm = trim($endHm);
  // LAST は保存しない（デフォルト扱い）
  if ($endHm !== '' && strtoupper($endHm) !== 'LAST' && preg_match('/^\d{2}:\d{2}$/', $endHm)) {
    $parts[] = '#end=' . $endHm;
  }
  return implode(' ', $parts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($isSuper) $storeId = (int)($_POST['store_id'] ?? $storeId);

    $weekStart = normalize_date((string)($_POST['week_start'] ?? $weekStart), $weekStart);
    $datesAll  = week_dates($weekStart);
    $dates = [];
    foreach ($datesAll as $d) {
      if ($hideHolidayColumn && $holidayDow !== null && dow0($d) === $holidayDow) continue;
      $dates[] = $d;
    }

    $pdo->beginTransaction();

    // cast_profiles（形態/基本開始）: castOnly は自分だけ
    $upProf = $pdo->prepare("
      INSERT INTO cast_profiles (user_id, store_id, employment_type, default_start_time, updated_at)
      VALUES (?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        store_id=VALUES(store_id),
        employment_type=VALUES(employment_type),
        default_start_time=VALUES(default_start_time),
        updated_at=NOW()
    ");

    foreach ($castRows as $c) {
      $uid = (int)$c['id'];
      if ($castOnly && $uid !== $userId) continue;

      $etype = (string)($_POST["etype_{$uid}"] ?? 'part');
      if (!in_array($etype, ['regular','part'], true)) $etype = 'part';

      $dst = trim((string)($_POST["dst_{$uid}"] ?? ''));
      $dstTime = null;
      if ($dst !== '' && preg_match('/^\d{2}:\d{2}$/', $dst)) $dstTime = $dst . ':00';

      $upProf->execute([$uid, $storeId, $etype, $dstTime]);
    }

    // cast_shift_plans（WBSS本体）
    // end_time列が無いので、end は note に #end=HH:MM で保持（LASTは省略）
    $upPlan = $pdo->prepare("
      INSERT INTO cast_shift_plans
        (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
      VALUES
        (?, ?, ?, ?, ?, 'planned', ?, ?)
      ON DUPLICATE KEY UPDATE
        start_time=VALUES(start_time),
        is_off=VALUES(is_off),
        status='planned',
        note=VALUES(note),
        created_by_user_id=VALUES(created_by_user_id),
        updated_at=NOW()
    ");
    $delPlan = $pdo->prepare("DELETE FROM cast_week_plans WHERE store_id=? AND user_id=? AND work_date=?");

    foreach ($castRows as $c) {
      $uid = (int)$c['id'];
      if ($castOnly && $uid !== $userId) continue;

      foreach ($dates as $d) {
        $off    = (int)($_POST["off_{$uid}_{$d}"] ?? 0) === 1;
        $douhan = (int)($_POST["douhan_{$uid}_{$d}"] ?? 0) === 1;

        $startHm = trim((string)($_POST["start_{$uid}_{$d}"] ?? ''));
        $endHm   = trim((string)($_POST["end_{$uid}_{$d}"] ?? 'LAST'));

        $startTime = normalize_time_hm($startHm); // 'HH:MM:00' or null

        if ($off) {
          // OFFはレコードを残して is_off=1（連動のため）
          $note = note_from_flags(false, 'LAST'); // OFFはnote不要
          $upPlan->execute([$storeId, $uid, $d, null, 1, $note, $userId ?: null]);
          continue;
        }

        if ($startTime === null) {
          // start未指定なら OFF 扱い（part運用のまま）
          $note = note_from_flags(false, 'LAST');
          $upPlan->execute([$storeId, $uid, $d, null, 1, $note, $userId ?: null]);
          continue;
        }

        $note = note_from_flags($douhan, $endHm);
        $upPlan->execute([$storeId, $uid, $d, $startTime, 0, $note, $userId ?: null]);
      }
    }

    $pdo->commit();
    header('Location: /seika-app/public/cast_week_plans.php?store_id='.$storeId.'&date='.urlencode($weekStart).'&ok=1');
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

/* =========================
  UI settings
========================= */
$timeOptions = ['19:00','20:00','21:00','22:00'];
$endOptions  = ['LAST','23:00','23:30','00:00','00:30','01:00','01:30','02:00'];

render_page_start('出勤予定（週）');
render_header('出勤予定（週）', [
  'back_href'  => $castOnly ? '/seika-app/public/dashboard_cast.php' : '/seika-app/public/dashboard.php',
  'back_label' => '← 戻る',
]);
?>
<div class="page">
  <div class="admin-wrap">

    <?php if ($ok): ?>
      <div class="notice ok">✅ 保存しました</div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="notice ng"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="card head">
      <div class="headRow">
        <div>
          <div class="ttl">出勤予定（週）</div>
          <div class="sub">
            店舗：<b><?= h((string)$storeRow['name']) ?></b>
            <?php if ($hideHolidayColumn): ?>
              / 店休日の列は非表示
            <?php endif; ?>
            <?php if ($castOnly): ?>
              / <b>※自分の行だけ編集</b>
            <?php endif; ?>
          </div>
          <div class="chips">
            <span class="chip">休み：ボタンON/OFF</span>
            <span class="chip">同伴：ボタンON/OFF</span>
            <span class="chip">終了：基本LAST（=23:59保存）</span>
            <span class="chip">メモ：非表示</span>
          </div>
        </div>

        <form method="get" class="ctrl">
          <?php if ($isSuper): ?>
            <select name="store_id" class="sel">
              <?php foreach ($stores as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===(int)$storeId)?'selected':'' ?>>
                  <?= h((string)$s['name']) ?><?= ((int)$s['is_active']===1)?'':'（無効）' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <?php endif; ?>

          <div class="dateBox">
            <div class="muted">基準日</div>
            <input type="date" name="date" value="<?= h($baseDate) ?>" class="sel">
          </div>

          <button class="btn primary" type="submit">表示</button>
        </form>
      </div>

      <div class="weekLine">
        <b>週：</b><?= h(substr($weekStart,5)) ?>（<?= h(jp_dow_label($weekStart)) ?>）〜
        <?= h(substr($datesAll[6],5)) ?>（<?= h(jp_dow_label($datesAll[6])) ?>）
      </div>
    </div>

    <form method="post">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="week_start" value="<?= h($weekStart) ?>">

      <!-- 日付ヘッダ（横スクロールなし：auto-fit で折り返し） -->
      <div class="dateHeader">
        <div class="dateHeaderLeft">キャスト</div>
        <div class="dateHeaderGrid">
          <?php foreach ($dates as $d): ?>
            <div class="dhCell">
              <div class="dhTop"><?= h(substr($d,5)) ?></div>
              <div class="dhSub"><?= h(jp_dow_label($d)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php foreach ($castRows as $c): ?>
        <?php
          $uid = (int)$c['id'];
          $inactive = ((int)$c['is_active'] !== 1);
          $etype = (string)($c['employment_type'] ?? 'part');
          $dst = $c['default_start_time'] ? substr((string)$c['default_start_time'], 0, 5) : '';
          $readonlyRow = ($castOnly && $uid !== $userId);
        ?>
        <div class="rowCard <?= $readonlyRow ? 'rowRO' : '' ?>">
          <div class="left">
              <?php
              $code = trim((string)($c['staff_code'] ?? ''));
              ?>
              <div class="castName">
                <span class="castCode"><?= h($code !== '' ? $code : '--') ?></span>
                <span class="castDisplay"><?= h((string)$c['display_name']) ?></span>
                <?php if (($c['employment_type'] ?? '') === 'regular'): ?>
                  <button type="button" class="btnMini" onclick="weekRegularOn(<?= (int)$uid ?>)">
                    週を出勤
                  </button>
                <?php endif; ?>
                <?php if ($uid === $userId): ?>
                  <span class="tag me">自分</span>
                <?php endif; ?>

                <?php if ($inactive): ?>
                  <span class="tag ng">無効</span>
                <?php endif; ?>
              </div>

          </div>

          <div class="grid">
            <?php foreach ($dates as $d): ?>
              <?php
                $p = $plans[$uid][$d] ?? ['start'=>'','end'=>'LAST','douhan'=>false];
                $start = (string)$p['start'];
                $end   = (string)$p['end'];
                $douhan= (bool)$p['douhan'];

                // 休み判定：start空＝休み（DBにレコードが無い＝休みも同じ）
                $isOff = ($start === '');

                $nameStart = "start_{$uid}_{$d}";
                $nameEnd   = "end_{$uid}_{$d}";
                $nameOff   = "off_{$uid}_{$d}";
                $nameDou   = "douhan_{$uid}_{$d}";
              ?>
              <div class="cell"
                data-uid="<?= (int)$uid ?>"
                data-default-start="<?= h($dst !== '' ? $dst : '20:00') ?>"
                data-readonly="<?= $readonlyRow ? '1' : '0' ?>">
                <input type="hidden" name="<?= h($nameOff) ?>" value="<?= $isOff ? '1' : '0' ?>" class="hidOff">
                <input type="hidden" name="<?= h($nameDou) ?>" value="<?= $douhan ? '1' : '0' ?>" class="hidDou">

                <div class="toggles">
                  <button type="button" class="tgl tglOff <?= $isOff?'on':'' ?>" <?= $readonlyRow?'disabled':'' ?>>
                    <?= $isOff ? '休み' : '出勤' ?>
                  </button>

                  <!-- 同伴：押せない問題を潰す（disabledを「休み or readonly」だけで決める） -->
                  <button type="button" class="tgl tglDou <?= $douhan?'on':'' ?>"
                          <?= ($readonlyRow || $isOff)?'disabled':'' ?>>
                    同伴
                  </button>
                </div>

                <div class="times">
                  <select class="sel mini startSel" name="<?= h($nameStart) ?>" <?= ($readonlyRow || $isOff)?'disabled':'' ?>>
                    <option value="">--</option>
                    <?php foreach ($timeOptions as $opt): ?>
                      <option value="<?= h($opt) ?>" <?= ($opt===$start)?'selected':'' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>

                  <select class="sel mini endSel" name="<?= h($nameEnd) ?>" <?= ($readonlyRow || $isOff)?'disabled':'' ?>>
                    <?php foreach ($endOptions as $opt): ?>
                      <option value="<?= h($opt) ?>" <?= ($opt===$end)?'selected':'' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="foot">
        <button class="btn primary" type="submit">保存</button>
      </div>
    </form>

  </div>
</div>

<style>
/* =========================================================
  cast_week_plans 見やすさ改善（Atlasでも崩れにくい版）
  - 左: キャスト欄固定
  - 上: 曜日ヘッダ固定
  - Dark時の境界/コントラストを強化
========================================================= */

/* ---------- base tokens (Light default) ---------- */
:root{
  --bg:   #f3f6fb;
  --card: #ffffff;
  --chip: #f8fafc;

  --line:  #e2e8f0;
  --line2: #cbd5e1;

  --txt:  #0f172a;
  --mut:  #475569;

  --pri:  #2563eb;
  --priBg:#dbeafe;

  --ok:   #16a34a;
  --okBg: #dcfce7;

  --ng:   #ef4444;
  --ngBg: #fee2e2;

  --shadow: 0 10px 22px rgba(15,23,42,.08);

  --r: 16px;
  --gap: 10px;
  --tap: 36px;
}

/* body側で確実に反映（Atlasで安全） */
body{
  color-scheme: light;
  background: var(--bg);
  color: var(--txt);
}

/* App Theme: Dark（標準） */
body[data-theme="dark"]{
  color-scheme: dark;

  --bg:   #070c16;     /* 背景は暗めに固定 */
  --card: #0f172a;     /* カード */
  --chip: #111c33;     /* ヘッダ/左固定に使う */

  --line:  #22314f;    /* 枠 */
  --line2: #32466f;    /* 強め枠 */

  --txt:  #eaf0ff;
  --mut:  #b3c0dd;

  --pri:  #7bb1ff;
  --priBg: rgba(123,177,255,.18);

  --ok:   #35d46a;
  --okBg: rgba(53,212,106,.20);

  --ng:   #ff6b7a;
  --ngBg: rgba(255,107,122,.20);

  --shadow: 0 14px 30px rgba(0,0,0,.45);

  background: var(--bg);
  color: var(--txt);
}

/* ---------- “カードっぽい”共通箱 ---------- */
.card, .rowCard, .dateHeaderLeft, .dhCell, .cell, .left, .notice{
  background: var(--card);
  color: var(--txt);
  border: 1px solid var(--line);
  border-radius: var(--r);
  box-shadow: var(--shadow);
}

/* Darkは境界が溶けやすいので枠を強める */
body[data-theme="dark"] .card,
body[data-theme="dark"] .rowCard,
body[data-theme="dark"] .dateHeaderLeft,
body[data-theme="dark"] .dhCell,
body[data-theme="dark"] .cell,
body[data-theme="dark"] .left,
body[data-theme="dark"] .notice{
  border-color: var(--line2);
}

/* chip */
.chip{
  background: var(--chip);
  border: 1px solid var(--line);
  color: var(--txt);
  border-radius: 999px;
}

/* =========================================================
   レイアウト：上ヘッダ固定 + 左固定
========================================================= */

/* 上の曜日ヘッダ：透明/ぼかしはやめて不透明に（読みやすさ優先） */
.dateHeader{
  margin-top: 12px;
  display:grid;
  grid-template-columns: 180px 1fr;
  gap: var(--gap);

  position: sticky;
  top: 0;
  z-index: 50;

  padding: 10px 0 10px;
  background: var(--bg);            /* ← 不透明 */
  border-bottom: 1px solid var(--line);
}

/* 左ヘッダ */
.dateHeaderLeft{
  padding: 10px 12px;
  font-weight: 1000;
  background: var(--chip);
}

/* 曜日ヘッダグリッド */
.dateHeaderGrid{
  display:grid;
  grid-template-columns: repeat(7, minmax(132px, 1fr));
  gap: 8px;
}

/* 1日分ヘッダセル：chipトーンにして視認性UP */
.dhCell{
  padding: 10px 10px;
  text-align:center;
  font-weight: 1000;
  line-height: 1.15;
  background: var(--chip);
}

/* 各キャスト行 */
.rowCard{
  margin-top: 10px;
  padding: 10px;
  display:grid;
  grid-template-columns: 180px 1fr;
  gap: var(--gap);
  align-items: start;
}

/* 左のキャスト欄：sticky + 不透明背景必須 */
.left{
  position: sticky;
  left: 0;
  z-index: 40;                 /* ヘッダ(50)より下、セルより上 */
  background: var(--chip);
  padding: 10px;
  border-color: var(--line2);
}

/* キャスト名表示 */
.castName{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  line-height: 1.15;
}
.castCode{
  min-width:36px;
  text-align:right;
  color: var(--mut);
  font-weight: 1000;
}
.castDisplay{
  font-weight: 1000;
  letter-spacing: .2px;
}

/* 「週を出勤」ボタン */
.btnMini{
  margin-left:auto;
  height: 28px;
  padding: 0 12px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--priBg);
  color: var(--txt);
  font-size: 12px;
  font-weight: 1000;
  cursor: pointer;
}
.btnMini:hover{ filter: brightness(1.06); }
.btnMini:active{ transform: translateY(1px); }

/* 右の7日グリッド */
.grid{
  display:grid;
  grid-template-columns: repeat(7, minmax(132px, 1fr));
  gap: 8px;
}

/* 1日分セル */
.cell{
  padding: 10px;
  border-radius: 14px;
}

/* =========================================================
   ボタン/トグル/セレクトの整形（Darkでも見える）
========================================================= */

.toggles{
  display:flex;
  gap:6px;
  align-items:center;
  flex-wrap:wrap;
  margin-bottom: 8px;
}

/* トグル（.tgl が付く前提） */
.tgl{
  height: 28px;
  font-size: 12px;
  padding: 0 10px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--chip);
  color: var(--txt);
  font-weight: 1000;
  cursor:pointer;
  user-select:none;
}
.tgl.on{
  border-color: var(--ok);
  background: var(--okBg);
}
.tgl.ng{
  border-color: var(--ng);
  background: var(--ngBg);
}
.tgl:disabled{ opacity:.45; cursor:not-allowed; }

/* 時刻selectを縦並び */
.times{
  display:grid;
  grid-template-columns: 1fr;
  gap: 6px;
}

/* select/input：Darkで沈む問題を避けるため “常に少し明るい面” */
select,
.times select,
input[type="date"],
select.sel, .sel, .sel.mini{
  width: 100%;
  min-width: 0;
  height: var(--tap);
  padding: 0 10px;
  border-radius: 12px;
  border: 1px solid var(--line2);
  background: var(--card);
  color: var(--txt);
  font-weight: 900;
  outline: none;
}
body[data-theme="dark"] select,
body[data-theme="dark"] input[type="date"]{
  background: #142349;          /* ← cardより少し明るくして文字が沈まない */
  border-color: var(--line2);
}
select:focus,
input[type="date"]:focus{
  border-color: var(--pri);
  box-shadow: 0 0 0 4px rgba(37,99,235,.18);
}
body[data-theme="dark"] select:focus,
body[data-theme="dark"] input[type="date"]:focus{
  box-shadow: 0 0 0 4px rgba(123,177,255,.22);
}

/* セル内のボタン（class無くても最低限読みやすく） */
.cell button{
  height: 30px;
  padding: 0 10px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--chip);
  color: var(--txt);
  font-weight: 1000;
  cursor:pointer;
}
.cell button:hover{ filter: brightness(1.06); }
.cell button:active{ transform: translateY(1px); }

/* muted系 */
.muted, .small{ color: var(--mut); }
body[data-theme="dark"] .muted,
body[data-theme="dark"] .small{ color: var(--mut); }

/* =========================================================
   レスポンシブ
========================================================= */
@media (max-width: 1100px){
  .dateHeader, .rowCard{ grid-template-columns: 1fr; }
  .left{ position: relative; left: auto; }
  .dateHeaderLeft{ display:none; }

  .dateHeaderGrid{ grid-template-columns: repeat(4, minmax(132px, 1fr)); }
  .grid{ grid-template-columns: repeat(4, minmax(132px, 1fr)); }
}

@media (max-width: 680px){
  .dateHeaderGrid{ grid-template-columns: repeat(3, minmax(132px, 1fr)); }
  .grid{ grid-template-columns: repeat(3, minmax(132px, 1fr)); }
}
</style>

<script>
(() => {
  function isReadonly(cell){
    return (cell?.dataset?.readonly === '1');
  }

  function setOff(cell, off){
    const hidOff = cell.querySelector('.hidOff');
    const hidDou = cell.querySelector('.hidDou');
    const offBtn = cell.querySelector('.tglOff');
    const douBtn = cell.querySelector('.tglDou');
    const startSel = cell.querySelector('.startSel');
    const endSel = cell.querySelector('.endSel');

    if (!hidOff || !offBtn) return;

    hidOff.value = off ? '1' : '0';
    offBtn.classList.toggle('on', off);
    offBtn.textContent = off ? '休み' : '出勤';

    const ro = isReadonly(cell);

    if (startSel){
      if (off) startSel.value = '';
      startSel.disabled = off || ro;
    }
        // ✅ 出勤に切り替えた瞬間：開始が未選択ならデフォを入れる
    if (!off && startSel && !startSel.disabled) {
      if (!startSel.value || startSel.value === '--') {
        const def = (cell.dataset.defaultStart || '20:00').trim();
        startSel.value = def;
        startSel.dispatchEvent(new Event('change', { bubbles:true }));
      }
    }
    if (endSel) endSel.disabled = off || ro;

    // 同伴：disabledを「休み or readonly」だけで決める（押せない問題の原因を排除）
    if (douBtn){
      if (off) {
        if (hidDou) hidDou.value = '0';
        douBtn.classList.remove('on');
      }
      douBtn.disabled = off || ro;
    }
  }

  function toggleDou(cell){
    const ro = isReadonly(cell);
    const hidOff = cell.querySelector('.hidOff');
    const hidDou = cell.querySelector('.hidDou');
    const douBtn = cell.querySelector('.tglDou');

    if (!hidDou || !douBtn) return;
    if (ro) return;
    if (hidOff && hidOff.value === '1') return; // 休み中は不可

    const on = (hidDou.value === '1');
    hidDou.value = on ? '0' : '1';
    douBtn.classList.toggle('on', !on);
  }

  document.querySelectorAll('.cell').forEach(cell => {
    const hidOff = cell.querySelector('.hidOff');
    const offBtn = cell.querySelector('.tglOff');
    const douBtn = cell.querySelector('.tglDou');

    // 初期反映
    if (hidOff) setOff(cell, hidOff.value === '1');

    if (offBtn && !offBtn.disabled){
      offBtn.addEventListener('click', () => {
        const cur = (cell.querySelector('.hidOff')?.value === '1');
        setOff(cell, !cur);
      });
    }
    if (douBtn){
      douBtn.addEventListener('click', () => toggleDou(cell));
    }
  });
})();
function weekRegularOn(uid){
  const cells = document.querySelectorAll(`.cell[data-uid="${uid}"]`);
  cells.forEach(cell => {
    if (cell.dataset.readonly === '1') return;

    const hidOff = cell.querySelector('.hidOff');
    const isOff = hidOff && hidOff.value === '1';

    if (isOff) {
      const btn = cell.querySelector('.tglOff');
      if (btn && !btn.disabled) btn.click();
    }

    // クリック後に取り直す（disabledが外れた前提）
    const sel = cell.querySelector('select.startSel');
    if (!sel || sel.disabled) return;

    if (!sel.value || sel.value === '--') {
      const def = (cell.dataset.defaultStart || '20:00').trim();
      sel.value = def;
      sel.dispatchEvent(new Event('change', { bubbles:true }));
    }
  });
}
</script>

<?php render_page_end(); ?>