<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

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

function has_role(string $role): bool {
  return isset($_SESSION['roles']) && is_array($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}
function can_view_staff_notes(): bool {
  return has_role('admin') || has_role('manager') || has_role('super_user');
}
function csrf_token_local(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}
function flash_set(string $msg): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $_SESSION['_flash'] = $msg;
}
function flash_get(): ?string {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (!isset($_SESSION['_flash'])) return null;
  $m = (string)$_SESSION['_flash'];
  unset($_SESSION['_flash']);
  return $m;
}

/* =========================
  input
========================= */
$storeId = (int)($_GET['store_id'] ?? ($_SESSION['store_id'] ?? 0));
$customerId = (int)($_GET['id'] ?? 0);

if ($storeId <= 0) {
  http_response_code(400);
  echo "store_id が必要です";
  exit;
}

/**
 * ★ここが今回の修正
 * id無しで来たら、一覧へ戻す（画面で「store_id と id が必要です」を出さない）
 */
if ($customerId <= 0) {
  flash_set('顧客を選んでください（一覧から開く）');
  header("Location: /seika-app/public/customer/?store_id={$storeId}");
  exit;
}

$me = current_user_id_safe();
$isStaff = can_view_staff_notes();
$csrf = csrf_token_local();

$notesWhereVis = $isStaff
  ? "(visibility IN ('public','staff') OR (visibility='private' AND author_user_id = :me))"
  : "(visibility = 'public' OR (visibility='private' AND author_user_id = :me))";

/* =========================
  customers
========================= */
$st = $pdo->prepare("
  SELECT *
  FROM customers
  WHERE store_id = :store_id
    AND id = :id
  LIMIT 1
");
$st->execute([':store_id'=>$storeId, ':id'=>$customerId]);
$customer = $st->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
  flash_set('顧客が見つかりませんでした');
  header("Location: /seika-app/public/customer/?store_id={$storeId}");
  exit;
}

if (!empty($customer['merged_into_customer_id'])) {
  $to = (int)$customer['merged_into_customer_id'];
  header("Location: /seika-app/public/customer/detail.php?store_id={$storeId}&id={$to}");
  exit;
}

/* =========================
  cast links
========================= */
$links = [];
try {
  $st = $pdo->prepare("
    SELECT cast_user_id, link_role, memo, last_seen_at, created_at
    FROM customer_cast_links
    WHERE customer_id = :cid
    ORDER BY (link_role='primary') DESC, COALESCE(last_seen_at, created_at) DESC
  ");
  $st->execute([':cid'=>$customerId]);
  $links = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $links = [];
}

/* =========================
  contacts / profile / dates (任意：テーブル未作成でも落ちない)
========================= */
$contacts = [];
$profile  = [];
$dates    = [];

try {
  $st = $pdo->prepare("
    SELECT id, kind, value, label, is_primary, visibility, verified_at, created_at
    FROM customer_contacts
    WHERE store_id=:s AND customer_id=:c
    ORDER BY (is_primary=1) DESC, kind ASC, created_at DESC
  ");
  $st->execute([':s'=>$storeId, ':c'=>$customerId]);
  $contacts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $contacts = []; }

try {
  $st = $pdo->prepare("
    SELECT *
    FROM customer_profiles
    WHERE store_id=:s AND customer_id=:c
    LIMIT 1
  ");
  $st->execute([':s'=>$storeId, ':c'=>$customerId]);
  $profile = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $profile = []; }

try {
  $st = $pdo->prepare("
    SELECT id, date_kind, the_date, label, visibility, created_at
    FROM customer_dates
    WHERE store_id=:s AND customer_id=:c
    ORDER BY the_date DESC, created_at DESC
    LIMIT 50
  ");
  $st->execute([':s'=>$storeId, ':c'=>$customerId]);
  $dates = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $dates = []; }

/* =========================
  notes
========================= */
$notes = [];
try {
  $sqlNotes = "
    SELECT id, author_user_id, visibility, note_type, note_text, created_at, updated_at
    FROM customer_notes
    WHERE store_id = :store_id
      AND customer_id = :customer_id
      AND {$notesWhereVis}
    ORDER BY created_at DESC
    LIMIT 300
  ";
  $st = $pdo->prepare($sqlNotes);
  $st->execute([
    ':store_id' => $storeId,
    ':customer_id' => $customerId,
    ':me' => $me,
  ]);
  $notes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $notes = [];
}

/* group notes */
$latestSummary = null;
$ngList = [];
$prefList = [];
$timeline = [];
foreach ($notes as $n) {
  $t = (string)($n['note_type'] ?? '');
  if ($t === 'summary' && $latestSummary === null) $latestSummary = $n;
  else if ($t === 'ng') $ngList[] = $n;
  else if ($t === 'preference') $prefList[] = $n;
  else $timeline[] = $n;
}

/* =====================================
   render
===================================== */
render_page_start('顧客管理');
render_header('顧客管理',[
  'back_href'=> $castOnly ?? false
      ? '/seika-app/public/dashboard_cast.php'
      : '/seika-app/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; display:grid; gap:12px; }
  .card{ background:var(--card,#fff); border:1px solid var(--line,#e5e7eb); border-radius:14px; padding:12px; }
  .muted{ color:var(--muted,#6b7280); }
  .row{ display:flex; gap:10px; flex-wrap:wrap; }
  .pill{ display:inline-flex; align-items:center; border:1px solid var(--line,#e5e7eb); border-radius:999px; padding:2px 8px; font-size:12px; }
  .h1{ font-size:18px; font-weight:1000; }
  .h2{ font-size:14px; font-weight:1000; margin-bottom:6px; }
  .note{ border-top:1px solid var(--line,#e5e7eb); padding-top:10px; margin-top:10px; }
  textarea{ width:100%; min-height:90px; padding:10px 12px; border-radius:12px; border:1px solid var(--line,#e5e7eb); background:transparent; }
  select,input{ min-height:44px; padding:10px 12px; border-radius:12px; border:1px solid var(--line,#e5e7eb); background:transparent; }
  button{ min-height:44px; padding:10px 12px; border-radius:12px; border:1px solid var(--line,#e5e7eb); background:transparent; font-weight:1000; cursor:pointer; }
  .grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  @media (max-width: 980px){ .grid2{ grid-template-columns: 1fr; } }
  .badgeType{ font-size:12px; font-weight:1000; }
  .t-summary{ color:#1d4ed8; }
  .t-ng{ color:#b91c1c; }
  .t-pref{ color:#047857; }
  .flash{ border:1px solid var(--line,#e5e7eb); background: color-mix(in srgb, var(--card,#fff) 70%, transparent); border-radius:12px; padding:10px 12px; }
</style>

<div class="page">
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <div style="font-weight:1000;">🎨 テーマ</div>

    <div class="row">
      <form method="post" action="/seika-app/public/api/theme_set.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="theme" value="light">
        <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
        <button type="submit">Light（現場）</button>
      </form>

      <form method="post" action="/seika-app/public/api/theme_set.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="theme" value="dark">
        <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
        <button type="submit">Dark（標準）</button>
      </form>

      <form method="post" action="/seika-app/public/api/theme_set.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="theme" value="soft">
        <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
        <button type="submit">Soft（夜）</button>
      </form>

      <form method="post" action="/seika-app/public/api/theme_set.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="theme" value="hc">
        <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
        <button type="submit">High Contrast</button>
      </form>

      <form method="post" action="/seika-app/public/api/theme_set.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="theme" value="store">
        <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
        <button type="submit">Store Color</button>
      </form>
    </div>
  </div>
</div>
  <?php if ($flash): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <div>
        <div class="h1">顧客カルテ：<?= h((string)$customer['display_name']) ?></div>
        <div class="muted" style="font-size:12px;">
          ID #<?= (int)$customer['id'] ?> / store_id <?= (int)$storeId ?>
        </div>
      </div>
      <div class="row">
        <a class="pill" href="/seika-app/public/customer/?store_id=<?= (int)$storeId ?>">← 台帳一覧へ</a>
      </div>
    </div>

    <div class="row" style="margin-top:10px;">
      <div style="flex:1; min-width:240px;">
        <div class="muted" style="font-size:12px;">特徴（見分けポイント）</div>
        <div style="font-weight:900;"><?= h((string)$customer['features']) ?></div>
      </div>
      <div style="min-width:160px;">
        <div class="muted" style="font-size:12px;">状態</div>
        <div style="font-weight:1000;">
          <?= ((string)$customer['status']==='active') ? '在籍' : '休眠' ?>
        </div>
      </div>
      <div style="min-width:180px;">
        <div class="muted" style="font-size:12px;">最終来店</div>
        <div style="font-weight:900;"><?= $customer['last_visit_at'] ? h((string)$customer['last_visit_at']) : '—' ?></div>
      </div>
    </div>

    <?php if (!empty($customer['note_public'])): ?>
      <div class="note">
        <div class="muted" style="font-size:12px;">店全体メモ（固定）</div>
        <div style="margin-top:6px; font-weight:900;"><?= nl2br(h((string)$customer['note_public'])) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <!-- 基本情報（customers）編集 -->
  <div class="card">
    <div class="h2">基本情報（編集）</div>
    <div class="muted" style="font-size:12px; margin-bottom:8px;">
      名前・特徴・固定メモ・状態・最終来店・担当(user_id)
    </div>

    <form method="post" action="/seika-app/public/api/customer_save.php" class="row">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

      <div style="flex:1; min-width:220px;">
        <div class="muted" style="font-size:12px;">名前</div>
        <input name="display_name" value="<?= h((string)$customer['display_name']) ?>" placeholder="仮名OK">
      </div>

      <div style="flex:1; min-width:220px;">
        <div class="muted" style="font-size:12px;">特徴（見分けポイント）</div>
        <input name="features" value="<?= h((string)$customer['features']) ?>" placeholder="例：よく笑う / 髪色 / 雰囲気">
      </div>

      <div style="min-width:160px;">
        <div class="muted" style="font-size:12px;">状態</div>
        <select name="status">
          <option value="active" <?= ((string)$customer['status']==='active')?'selected':''; ?>>在籍</option>
          <option value="inactive" <?= ((string)$customer['status']==='inactive')?'selected':''; ?>>休眠</option>
        </select>
      </div>

      <div style="min-width:200px;">
        <div class="muted" style="font-size:12px;">最終来店（任意）</div>
        <input name="last_visit_at" value="<?= h((string)($customer['last_visit_at'] ?? '')) ?>" placeholder="2026-03-01 21:00:00">
      </div>

      <div style="min-width:200px;">
        <div class="muted" style="font-size:12px;">担当(user_id / 任意)</div>
        <input name="assigned_user_id" value="<?= h((string)($customer['assigned_user_id'] ?? '')) ?>" placeholder="例：12">
      </div>

      <div style="flex:1; min-width:260px;">
        <div class="muted" style="font-size:12px;">店全体メモ（固定）</div>
        <textarea name="note_public" placeholder="例：誕生日、アレルギー、NG話題、席の好みなど"><?= h((string)($customer['note_public'] ?? '')) ?></textarea>
      </div>

      <div style="min-width:160px; align-self:end;">
        <button type="submit">保存</button>
      </div>
    </form>
  </div>

  <!-- 連絡先 -->
  <div class="card">
    <div class="h2">連絡先（電話 / LINE / メール）</div>

    <form method="post" action="/seika-app/public/api/customer_contact_save.php" class="row">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

      <div style="min-width:160px;">
        <div class="muted" style="font-size:12px;">種別</div>
        <select name="kind">
          <option value="phone">電話</option>
          <option value="line">LINE</option>
          <option value="email">メール</option>
          <option value="other">その他</option>
        </select>
      </div>

      <div style="flex:1; min-width:240px;">
        <div class="muted" style="font-size:12px;">値</div>
        <input name="value" placeholder="電話番号 / LINE userId / メール">
      </div>

      <div style="min-width:200px;">
        <div class="muted" style="font-size:12px;">メモ</div>
        <input name="label" placeholder="例：本人 / サブ / 仕事用">
      </div>

      <div style="min-width:160px;">
        <div class="muted" style="font-size:12px;">公開</div>
        <select name="visibility">
          <option value="staff">スタッフ</option>
          <option value="public">店全体</option>
          <option value="private">自分だけ</option>
        </select>
      </div>

      <div style="min-width:140px;">
        <div class="muted" style="font-size:12px;">主</div>
        <select name="is_primary">
          <option value="0">通常</option>
          <option value="1">主連絡先</option>
        </select>
      </div>

      <div style="min-width:160px; align-self:end;">
        <button type="submit">追加/更新</button>
      </div>
    </form>

    <?php if (!empty($contacts)): ?>
      <div class="note">
        <?php foreach ($contacts as $ct): ?>
          <div class="row" style="justify-content:space-between; align-items:center;">
            <div>
              <span class="pill"><?= h((string)$ct['kind']) ?></span>
              <?php if ((int)$ct['is_primary']===1): ?><span class="pill">primary</span><?php endif; ?>
              <span style="margin-left:8px; font-weight:1000;"><?= h((string)$ct['value']) ?></span>
              <?php if (!empty($ct['label'])): ?><span class="muted">（<?= h((string)$ct['label']) ?>）</span><?php endif; ?>
            </div>
            <div class="muted" style="font-size:12px;"><?= h((string)$ct['visibility']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="muted">（未登録 / またはテーブル未作成）</div>
    <?php endif; ?>
  </div>

  <!-- プロフィール -->
  <div class="card">
    <div class="h2">プロフィール（固定）</div>

    <form method="post" action="/seika-app/public/api/customer_profile_save.php" class="row">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

      <div style="min-width:200px;">
        <div class="muted" style="font-size:12px;">誕生日</div>
        <input name="birthday" value="<?= h((string)($profile['birthday'] ?? '')) ?>" placeholder="YYYY-MM-DD">
      </div>

      <div style="min-width:200px;">
        <div class="muted" style="font-size:12px;">年齢メモ</div>
        <input name="age_note" value="<?= h((string)($profile['age_note'] ?? '')) ?>" placeholder="例：30前後">
      </div>

      <div style="min-width:240px;">
        <div class="muted" style="font-size:12px;">居住エリア</div>
        <input name="residence_area" value="<?= h((string)($profile['residence_area'] ?? '')) ?>">
      </div>

      <div style="min-width:240px;">
        <div class="muted" style="font-size:12px;">職業</div>
        <input name="job_title" value="<?= h((string)($profile['job_title'] ?? '')) ?>">
      </div>

      <div style="min-width:240px;">
        <div class="muted" style="font-size:12px;">お酒</div>
        <input name="alcohol_pref" value="<?= h((string)($profile['alcohol_pref'] ?? '')) ?>">
      </div>

      <div style="min-width:180px;">
        <div class="muted" style="font-size:12px;">喫煙</div>
        <?php $tb = (string)($profile['tobacco'] ?? 'unknown'); ?>
        <select name="tobacco">
          <option value="unknown" <?= $tb==='unknown'?'selected':''; ?>>不明</option>
          <option value="no" <?= $tb==='no'?'selected':''; ?>>吸わない</option>
          <option value="yes" <?= $tb==='yes'?'selected':''; ?>>吸う</option>
          <option value="iqos" <?= $tb==='iqos'?'selected':''; ?>>IQOS等</option>
        </select>
      </div>

      <div style="min-width:180px;">
        <div class="muted" style="font-size:12px;">LINE配信同意</div>
        <?php $opt = (int)($profile['line_opt_in'] ?? 0); ?>
        <select name="line_opt_in">
          <option value="0" <?= $opt===0?'selected':''; ?>>未同意</option>
          <option value="1" <?= $opt===1?'selected':''; ?>>同意</option>
        </select>
      </div>

      <div style="min-width:160px; align-self:end;">
        <button type="submit">保存</button>
      </div>
    </form>
  </div>

  <!-- 記念日 -->
  <div class="card">
    <div class="h2">記念日</div>

    <form method="post" action="/seika-app/public/api/customer_date_save.php" class="row">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

      <div style="min-width:200px;">
        <div class="muted" style="font-size:12px;">種類</div>
        <select name="date_kind">
          <option value="anniversary">記念日</option>
          <option value="wedding">結婚記念日</option>
          <option value="start">初来店記念</option>
          <option value="other">その他</option>
        </select>
      </div>

      <div style="min-width:200px;">
        <div class="muted" style="font-size:12px;">日付</div>
        <input name="the_date" placeholder="YYYY-MM-DD">
      </div>

      <div style="flex:1; min-width:240px;">
        <div class="muted" style="font-size:12px;">メモ</div>
        <input name="label" placeholder="例：推しの誕生日 / 結婚記念日 など">
      </div>

      <div style="min-width:160px;">
        <div class="muted" style="font-size:12px;">公開</div>
        <select name="visibility">
          <option value="staff">スタッフ</option>
          <option value="public">店全体</option>
          <option value="private">自分だけ</option>
        </select>
      </div>

      <div style="min-width:160px; align-self:end;">
        <button type="submit">追加</button>
      </div>
    </form>

    <?php if (!empty($dates)): ?>
      <div class="note">
        <?php foreach ($dates as $d): ?>
          <div class="row" style="justify-content:space-between; align-items:center;">
            <div>
              <span class="pill"><?= h((string)$d['date_kind']) ?></span>
              <span style="margin-left:8px; font-weight:1000;"><?= h((string)$d['the_date']) ?></span>
              <?php if (!empty($d['label'])): ?><span class="muted">（<?= h((string)$d['label']) ?>）</span><?php endif; ?>
            </div>
            <div class="muted" style="font-size:12px;"><?= h((string)$d['visibility']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="muted">（未登録 / またはテーブル未作成）</div>
    <?php endif; ?>
  </div>

  <div class="grid2">
    <div class="card">
      <div class="h2">引き継ぎ要約（summary）</div>
      <?php if ($latestSummary): ?>
        <div class="badgeType t-summary">最新</div>
        <div style="margin-top:6px;"><?= nl2br(h((string)$latestSummary['note_text'])) ?></div>
        <div class="muted" style="font-size:11px; margin-top:6px;"><?= h((string)$latestSummary['created_at']) ?></div>
      <?php else: ?>
        <div class="muted">（要約がまだありません）</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="h2">関係キャスト</div>
      <?php if ($links): ?>
        <?php foreach ($links as $l): ?>
          <div class="note">
            <div class="row" style="justify-content:space-between;">
              <div style="font-weight:1000;">
                cast_user_id: <?= (int)$l['cast_user_id'] ?>
                <span class="pill"><?= h((string)$l['link_role']) ?></span>
              </div>
              <div class="muted" style="font-size:12px;">
                last_seen: <?= $l['last_seen_at'] ? h((string)$l['last_seen_at']) : '—' ?>
              </div>
            </div>
            <?php if (!empty($l['memo'])): ?>
              <div class="muted" style="margin-top:6px;"><?= h((string)$l['memo']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="muted">（紐付けがありません / またはテーブル未作成）</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid2">
    <div class="card">
      <div class="h2">NG（ng）</div>
      <?php if ($ngList): ?>
        <?php foreach ($ngList as $n): ?>
          <div class="note">
            <div class="badgeType t-ng">NG</div>
            <div style="margin-top:6px;"><?= nl2br(h((string)$n['note_text'])) ?></div>
            <div class="muted" style="font-size:11px; margin-top:6px;"><?= h((string)$n['created_at']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="muted">（NG未登録）</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="h2">好み（preference）</div>
      <?php if ($prefList): ?>
        <?php foreach ($prefList as $n): ?>
          <div class="note">
            <div class="badgeType t-pref">好み</div>
            <div style="margin-top:6px;"><?= nl2br(h((string)$n['note_text'])) ?></div>
            <div class="muted" style="font-size:11px; margin-top:6px;"><?= h((string)$n['created_at']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="muted">（好み未登録）</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="h2">メモ追加（新規）</div>

    <form method="post" action="/seika-app/public/api/customer_note_save.php" class="row">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

      <div style="min-width:180px;">
        <div class="muted" style="font-size:12px;">種類</div>
        <select name="note_type">
          <option value="memo">メモ</option>
          <option value="summary">引き継ぎ要約</option>
          <option value="ng">NG</option>
          <option value="preference">好み</option>
          <option value="followup">次回フォロー</option>
          <option value="incident">出来事</option>
        </select>
      </div>

      <div style="min-width:180px;">
        <div class="muted" style="font-size:12px;">公開範囲</div>
        <select name="visibility">
          <option value="public">店全体</option>
          <?php if ($isStaff): ?>
            <option value="staff">スタッフのみ</option>
          <?php endif; ?>
          <option value="private">自分だけ</option>
        </select>
        <?php if (!$isStaff): ?>
          <div class="muted" style="font-size:11px; margin-top:4px;">※castは staff を選べません</div>
        <?php endif; ?>
      </div>

      <div style="flex:1; min-width:260px;">
        <div class="muted" style="font-size:12px;">内容</div>
        <textarea name="note_text" placeholder="例：平成曲が好き。甘い系。焼酎はNG。次回は静かめで。"></textarea>
      </div>

      <div style="min-width:160px; align-self:end;">
        <button type="submit">追加</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="h2">タイムライン（memo/followup/incident…）</div>
    <?php if ($timeline): ?>
      <?php foreach ($timeline as $n): ?>
        <div class="note">
          <div class="row" style="justify-content:space-between; align-items:center;">
            <div class="muted" style="font-size:12px;">
              <span class="pill"><?= h((string)$n['note_type']) ?></span>
              <span class="pill"><?= h((string)$n['visibility']) ?></span>
              <span style="margin-left:6px;">author: <?= (int)$n['author_user_id'] ?></span>
            </div>
            <div class="muted" style="font-size:12px;"><?= h((string)$n['created_at']) ?></div>
          </div>
          <div style="margin-top:6px;"><?= nl2br(h((string)$n['note_text'])) ?></div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="muted">（タイムラインはまだありません）</div>
    <?php endif; ?>
  </div>

</div>

<?php render_page_end(); ?>