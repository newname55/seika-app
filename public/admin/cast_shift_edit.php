<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** CSRF */
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  return (string)$_SESSION['_csrf'];
}
function csrf_verify(?string $token): void {
  if (!$token || empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], (string)$token)) {
    http_response_code(403);
    exit('csrf');
  }
}

/** store_id 解決（admin/cast_edit.php と同系統） */
function resolve_store_id(PDO $pdo): int {
  if (function_exists('current_store_id')) {
    $sid = (int)current_store_id();
    if ($sid > 0) return $sid;
  }
  if (function_exists('require_store_selected')) {
    try {
      $rf = new ReflectionFunction('require_store_selected');
      $need = $rf->getNumberOfRequiredParameters();
      $sid = ($need >= 1) ? (int)require_store_selected($pdo) : (int)require_store_selected();
      if ($sid > 0) return $sid;
    } catch (Throwable $e) {}
  }
  $sid = (int)($_GET['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);
  if ($sid <= 0) {
    header('Location: /seika-app/public/store_select.php?next=' . urlencode('/seika-app/public/admin/cast_edit.php'));
    exit;
  }
  $_SESSION['store_id'] = $sid;
  return $sid;
}

/** 週計算（月曜起点） */
function week_start_ymd(string $ymd): string {
  $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  $dow = (int)$d->format('N'); // 1=Mon..7=Sun
  $d->modify('-' . ($dow - 1) . ' days');
  return $d->format('Y-m-d');
}
function week_dates(string $weekStartYmd): array {
  $d = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) {
    $out[] = $d->format('Y-m-d');
    $d->modify('+1 day');
  }
  return $out;
}
function now_jst_ymd(): string {
  return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

$store_id = resolve_store_id($pdo);
$user_id  = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$week     = (string)($_GET['week'] ?? $_POST['week'] ?? now_jst_ymd());
$weekStart = week_start_ymd($week);
$dates = week_dates($weekStart);

if ($user_id <= 0) { http_response_code(400); exit('user_id required'); }

/** store name */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$store_id]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$store_id));

/** user + default_start_time を取る（cast_profiles は無くても落ちない） */
$display_name = '';
$default_start = '21:00';
try {
  $st = $pdo->prepare("SELECT display_name FROM users WHERE id=? LIMIT 1");
  $st->execute([$user_id]);
  $display_name = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {}

try {
  $st = $pdo->prepare("SELECT default_start_time FROM cast_profiles WHERE user_id=? AND store_id=? LIMIT 1");
  $st->execute([$user_id, $store_id]);
  $v = $st->fetchColumn();
  if ($v !== false && $v !== null) $default_start = substr((string)$v, 0, 5);
} catch (Throwable $e) {}

$msg = '';
$err = '';

/** 保存（週7日まとめて） */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_verify($_POST['csrf_token'] ?? null);

  $pdo->beginTransaction();
  try {
    $up = $pdo->prepare("
      INSERT INTO cast_shift_plans
        (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
      VALUES
        (:store_id, :user_id, :business_date, :start_time, :is_off, 'planned', :note, :actor)
      ON DUPLICATE KEY UPDATE
        start_time = VALUES(start_time),
        is_off = VALUES(is_off),
        status = 'planned',
        note = VALUES(note),
        created_by_user_id = VALUES(created_by_user_id),
        updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($dates as $ymd) {
      $onKey = 'on_' . $ymd;
      $tmKey = 'time_' . $ymd;

      $isOn = isset($_POST[$onKey]) && (string)$_POST[$onKey] === '1';
      $t = trim((string)($_POST[$tmKey] ?? ''));

      if ($isOn) {
        if (!preg_match('/^\d{2}:\d{2}$/', $t)) $t = $default_start;
        $up->execute([
          ':store_id' => $store_id,
          ':user_id' => $user_id,
          ':business_date' => $ymd,
          ':start_time' => $t . ':00',
          ':is_off' => 0,
          ':note' => null,
          ':actor' => current_user_id_safe() ?: null,
        ]);
      } else {
        // OFF：行を残す運用（履歴が残る）
        $up->execute([
          ':store_id' => $store_id,
          ':user_id' => $user_id,
          ':business_date' => $ymd,
          ':start_time' => null,
          ':is_off' => 1,
          ':note' => null,
          ':actor' => current_user_id_safe() ?: null,
        ]);
      }
    }

    // 軽ログ（テーブル無くても落ちないように try）
    try {
      $lg = $pdo->prepare("
        INSERT INTO cast_shift_logs (store_id, user_id, action, payload_json, created_by_user_id)
        VALUES (?, ?, 'shift.week_save', ?, ?)
      ");
      $lg->execute([$store_id, $user_id, json_encode(['weekStart'=>$weekStart], JSON_UNESCAPED_UNICODE), current_user_id_safe() ?: null]);
    } catch (Throwable $e) {}

    $pdo->commit();
    $msg = '保存しました';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = '保存失敗: ' . $e->getMessage();
  }
}

/** 週の予定を読む */
$plan = []; // [ymd] => ['on'=>bool,'time'=>'HH:MM']
if ($dates) {
  $minD = $dates[0];
  $maxD = $dates[count($dates)-1];
  $st = $pdo->prepare("
    SELECT business_date, start_time, is_off
    FROM cast_shift_plans
    WHERE store_id=? AND user_id=? AND business_date BETWEEN ? AND ?
  ");
  $st->execute([$store_id, $user_id, $minD, $maxD]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $ymd = (string)$r['business_date'];
    $off = ((int)$r['is_off'] === 1);
    $t = $r['start_time'] !== null ? substr((string)$r['start_time'], 0, 5) : '';
    $plan[$ymd] = ['on' => !$off, 'time' => $t];
  }
}

/** prev/next */
$ws = new DateTime($weekStart, new DateTimeZone('Asia/Tokyo'));
$prev = (clone $ws)->modify('-7 day')->format('Y-m-d');
$next = (clone $ws)->modify('+7 day')->format('Y-m-d');

render_page_start('出勤編集');
render_header('出勤編集', [
  'back_href' => '/seika-app/public/admin/cast_edit.php?store_id='.(int)$store_id,
  'back_label' => '← キャスト一覧',
  'right_html' => '
    <a class="btn" href="/seika-app/public/cast_week_plans.php?store_id='.(int)$store_id.'&date='.h($weekStart).'">週表示へ</a>
  ',
]);

$dowJp = ['','月','火','水','木','金','土','日'];
?>
<div class="page">
  <div class="admin-wrap">

    <div class="card">
      <div class="headRow" style="justify-content:space-between">
        <div>
          <div class="ttl">📅 出勤編集</div>
          <div class="sub">店舗：<b><?= h($storeName) ?></b> / <?= h($display_name ?: ('user#'.$user_id)) ?></div>
        </div>
        <div class="row" style="gap:10px;flex-wrap:wrap">
          <a class="btn" href="?store_id=<?= (int)$store_id ?>&user_id=<?= (int)$user_id ?>&week=<?= h($prev) ?>">← 前週</a>
          <span class="btn" style="cursor:default;opacity:.9">週: <?= h($weekStart) ?></span>
          <a class="btn" href="?store_id=<?= (int)$store_id ?>&user_id=<?= (int)$user_id ?>&week=<?= h($next) ?>">次週 →</a>
        </div>
      </div>

      <?php if ($msg): ?><div class="notice ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="notice ng"><?= h($err) ?></div><?php endif; ?>

      <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
        <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
        <input type="hidden" name="week" value="<?= h($weekStart) ?>">

        <div class="muted" style="margin-bottom:10px;">
          ONで開始時刻を入力。OFFは is_off=1 として保存します（履歴が残る）。
        </div>

        <div class="tblWrap">
          <table class="tbl" style="min-width:720px">
            <thead>
              <tr>
                <th style="width:140px">日付</th>
                <th style="width:90px">曜日</th>
                <th style="width:120px">ON/OFF</th>
                <th>開始</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dates as $ymd): ?>
                <?php
                  $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
                  $dow = (int)$d->format('N');
                  $p = $plan[$ymd] ?? ['on'=>false,'time'=>''];
                  $isOn = (bool)$p['on'];
                  $t = $p['time'] !== '' ? $p['time'] : $default_start;
                ?>
                <tr>
                  <td class="mono"><?= h($ymd) ?></td>
                  <td><?= h($dowJp[$dow]) ?></td>
                  <td>
                    <label style="display:inline-flex;gap:8px;align-items:center">
                      <input type="checkbox" name="on_<?= h($ymd) ?>" value="1" <?= $isOn?'checked':'' ?>
                        onchange="toggleRow('<?= h($ymd) ?>', this.checked)">
                      <span class="<?= $isOn?'okTxt':'ngTxt' ?>"><?= $isOn?'ON':'OFF' ?></span>
                    </label>
                  </td>
                  <td>
                    <input class="in mono" id="time_<?= h($ymd) ?>" name="time_<?= h($ymd) ?>"
                      value="<?= h($t) ?>" <?= $isOn?'':'disabled' ?> placeholder="21:00">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="row" style="justify-content:flex-end;margin-top:12px;gap:10px">
          <button type="button" class="btn" onclick="fillDefault()">基本開始で埋める</button>
          <button type="button" class="btn" onclick="allOn()">全部ON</button>
          <button type="button" class="btn" onclick="allOff()">全部OFF</button>
          <button class="btn primary" type="submit">保存</button>
        </div>
      </form>
    </div>

  </div>
</div>

<style>
.headRow{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.ttl{font-weight:1000;font-size:18px}
.sub{margin-top:4px;font-size:12px;opacity:.75}
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.notice{margin-top:10px;padding:10px 12px;border-radius:12px;border:1px solid var(--line)}
.notice.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
.notice.ng{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)}
.muted{opacity:.75;font-size:12px}
.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace}
.tblWrap{overflow:auto}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.10);vertical-align:middle}
.tbl th{text-align:left;white-space:nowrap;opacity:.9}
.in{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;min-height:40px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
.okTxt{color:rgba(34,197,94,.95);font-weight:800}
.ngTxt{color:rgba(239,68,68,.95);font-weight:800}
.row{display:flex;align-items:center}
</style>

<script>
function toggleRow(ymd, isOn){
  const el = document.getElementById('time_'+ymd);
  if (!el) return;
  el.disabled = !isOn;
}
function allOn(){
  document.querySelectorAll('input[type="checkbox"][name^="on_"]').forEach(cb=>{
    cb.checked = true;
    toggleRow(cb.name.substring(3), true);
  });
}
function allOff(){
  document.querySelectorAll('input[type="checkbox"][name^="on_"]').forEach(cb=>{
    cb.checked = false;
    toggleRow(cb.name.substring(3), false);
  });
}
function fillDefault(){
  const base = <?= json_encode($default_start, JSON_UNESCAPED_UNICODE) ?> || '21:00';
  document.querySelectorAll('input[id^="time_"]').forEach(inp=>{ inp.value = base; });
}
</script>

<?php render_page_end(); ?>