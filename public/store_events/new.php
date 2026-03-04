<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/db.php';
if (is_file(__DIR__ . '/../../app/auth.php')) require_once __DIR__ . '/../../app/auth.php';
if (is_file(__DIR__ . '/../../app/layout.php')) require_once __DIR__ . '/../../app/layout.php';

if (function_exists('require_login')) require_login();
if (function_exists('require_role')) {
  // 作成は manager/admin に絞るならここを変更
  require_role(['manager','admin','super_user']);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
if ($storeId <= 0) { http_response_code(400); echo "store_id required"; exit; }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================
  CSRF (軽量)
========================= */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

function verify_csrf(string $token): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $ok = isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
  if (!$ok) { http_response_code(400); echo "CSRF token invalid"; exit; }
}

/* =========================
  Load external event (optional)
========================= */
$fromExternalId = (int)($_GET['from_external_id'] ?? $_POST['from_external_id'] ?? 0);
$ext = null;

if ($fromExternalId > 0) {
  $st = $pdo->prepare("SELECT * FROM store_external_events WHERE id=:id AND store_id=:sid LIMIT 1");
  $st->execute(['id'=>$fromExternalId, 'sid'=>$storeId]);
  $ext = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* =========================
  Defaults
========================= */
$title    = '';
$startsAt = '';
$endsAt   = '';
$budget   = '';
$memo     = '';

if ($ext) {
  $title = '【連動】' . (string)$ext['title'];
  $startsAt = (string)($ext['starts_at'] ?? '');
  $endsAt   = (string)($ext['ends_at'] ?? '');

  // ends_at が無い or starts=ends のときは「当日23:59」寄せ
  if ($startsAt !== '' && $endsAt === '') {
    $d = new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Tokyo'));
    $endsAt = $d->format('Y-m-d 23:59:59');
  }

  $venue = trim((string)($ext['venue_name'] ?? ''));
  $addr  = trim((string)($ext['venue_addr'] ?? ''));
  $src   = trim((string)($ext['source'] ?? ''));
  $url   = trim((string)($ext['source_url'] ?? ''));

  $memoParts = [];
  $memoParts[] = "外部イベント連動: {$src}";
  if ($venue !== '') $memoParts[] = "会場: {$venue}";
  if ($addr !== '')  $memoParts[] = "住所: {$addr}";
  if ($url !== '')   $memoParts[] = "元URL: {$url}";
  $memo = implode("\n", $memoParts);
} else {
  // デフォルト：今日の21:00〜（必要なら変えて）
  $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
  $d = $now->format('Y-m-d');
  $startsAt = $d . ' 21:00:00';
  $endsAt   = $d . ' 23:59:59';
}

/* =========================
  POST: create instance
========================= */
$createdId = 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf((string)($_POST['csrf_token'] ?? ''));

  $title    = trim((string)($_POST['title'] ?? ''));
  $startsAt = trim((string)($_POST['starts_at'] ?? ''));
  $endsAt   = trim((string)($_POST['ends_at'] ?? ''));
  $budget   = trim((string)($_POST['budget_yen'] ?? ''));
  $memo     = trim((string)($_POST['memo'] ?? ''));

  if ($title === '' || mb_strlen($title) > 120) $error = 'タイトルは1〜120文字で入力してね';
  if ($error === '' && $startsAt === '') $error = '開始日時を入力してね';
  if ($error === '' && $endsAt === '') $error = '終了日時を入力してね';

  // ざっくり日時パース
  $sa = null; $ea = null;
  if ($error === '') {
    try { $sa = new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Tokyo')); } catch (Throwable $e) { $error='開始日時の形式が不正'; }
  }
  if ($error === '') {
    try { $ea = new DateTimeImmutable($endsAt, new DateTimeZone('Asia/Tokyo')); } catch (Throwable $e) { $error='終了日時の形式が不正'; }
  }
  if ($error === '' && $sa && $ea && $ea < $sa) $error = '終了日時が開始日時より前になってる';

  $budgetVal = null;
  if ($error === '' && $budget !== '') {
    $budgetVal = (int)preg_replace('/[^\d]/', '', $budget);
    if ($budgetVal < 0) $budgetVal = 0;
  }

  if ($error === '') {
    // NOTE: created_by/updated_by は既存の current_user_id() があるなら差し替え
    $createdBy = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("
        INSERT INTO store_event_instances
          (store_id, template_id, title, status, starts_at, ends_at, budget_yen, owner_user_id, memo, created_by, updated_by, created_at, updated_at)
        VALUES
          (:sid, NULL, :title, 'draft', :sa, :ea, :budget, :owner, :memo, :cb, :ub, NOW(), NOW())
      ");
      $st->execute([
        'sid' => $storeId,
        'title' => $title,
        'sa' => $sa->format('Y-m-d H:i:s'),
        'ea' => $ea->format('Y-m-d H:i:s'),
        'budget' => $budgetVal,
        'owner' => $createdBy > 0 ? $createdBy : null,
        'memo' => $memo !== '' ? $memo : null,
        'cb' => $createdBy > 0 ? $createdBy : null,
        'ub' => $createdBy > 0 ? $createdBy : null,
      ]);

      $createdId = (int)$pdo->lastInsertId();

      // audit log（テーブルがある場合のみ）
      try {
        $st2 = $pdo->prepare("
          INSERT INTO store_event_audit_logs
            (store_id, actor_user_id, action, entity_type, entity_id, summary, detail_json, created_at)
          VALUES
            (:sid, :uid, 'instance.create', 'instance', :eid, :sum, :json, NOW())
        ");
        $st2->execute([
          'sid'=>$storeId,
          'uid'=>$createdBy > 0 ? $createdBy : null,
          'eid'=>$createdId,
          'sum'=>"created: {$title}",
          'json'=>json_encode([
            'from_external_id' => $fromExternalId ?: null,
            'starts_at' => $sa->format('Y-m-d H:i:s'),
            'ends_at' => $ea->format('Y-m-d H:i:s'),
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
      } catch (Throwable $e) {
        // ログ失敗は致命にしない
      }

      $pdo->commit();

      header("Location: ./index.php?store_id={$storeId}&tab=internal");
      exit;

    } catch (Throwable $e) {
      $pdo->rollBack();
      $error = '作成に失敗: ' . $e->getMessage();
    }
  }
}

/* =========================
  Layout
========================= */
$pageTitle = "店内イベント作成";
if (function_exists('render_header')) {
  render_header($pageTitle);
} else {
  ?><!doctype html><html lang="ja"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($pageTitle)?></title>
  <style>
    body{font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,"Hiragino Kaku Gothic ProN","Noto Sans JP","Yu Gothic",sans-serif;background:#0b1020;color:#e8ecff;margin:0}
    a{color:#8ab4ff;text-decoration:none}
    .wrap{max-width:900px;margin:0 auto;padding:16px}
    .card{background:#121a33;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px;margin:12px 0}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    input,textarea{width:100%;background:#0f1730;color:#e8ecff;border:1px solid rgba(255,255,255,.18);border-radius:10px;padding:10px}
    label{display:block;margin:10px 0}
    .muted{color:#aab3d6}
    .btn{display:inline-block;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:#0f1730;color:#e8ecff;cursor:pointer}
    .btn.primary{background:#1b2850}
    .err{background:#3b1a1a;border:1px solid rgba(255,120,120,.35);padding:10px;border-radius:10px}
  </style>
  </head><body><div class="wrap"><?php
}

?>
<div class="row" style="justify-content:space-between">
  <div>
    <h1 style="margin:8px 0"><?=h($pageTitle)?></h1>
    <div class="muted"><a href="./index.php?store_id=<?=$storeId?>&tab=external">← 一覧へ</a></div>
  </div>
</div>

<?php if ($ext): ?>
  <div class="card">
    <div class="muted">外部イベントからプリセット</div>
    <div style="font-weight:700;margin-top:6px"><?=h((string)$ext['title'])?></div>
    <div class="muted" style="margin-top:6px">
      source: <?=h((string)$ext['source'])?> / source_id: <?=h((string)$ext['source_id'])?>
      <?php if (!empty($ext['source_url'])): ?>
        / <a href="<?=h((string)$ext['source_url'])?>" target="_blank" rel="noopener">元ページ</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="card err"><?=h($error)?></div>
<?php endif; ?>

<div class="card">
  <form method="post">
    <input type="hidden" name="store_id" value="<?=$storeId?>">
    <input type="hidden" name="from_external_id" value="<?=$fromExternalId?>">
    <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">

    <label>
      <div class="muted">タイトル</div>
      <input name="title" value="<?=h($title)?>" maxlength="120" required>
    </label>

    <div class="row">
      <label style="flex:1;min-width:260px">
        <div class="muted">開始（YYYY-mm-dd HH:ii:ss）</div>
        <input name="starts_at" value="<?=h($startsAt)?>" required>
      </label>
      <label style="flex:1;min-width:260px">
        <div class="muted">終了（YYYY-mm-dd HH:ii:ss）</div>
        <input name="ends_at" value="<?=h($endsAt)?>" required>
      </label>
    </div>

    <label>
      <div class="muted">予算（任意）</div>
      <input name="budget_yen" value="<?=h($budget)?>" placeholder="例: 20000">
    </label>

    <label>
      <div class="muted">メモ（運用・元URL・キャスト用ひとことなど）</div>
      <textarea name="memo" rows="6"><?=h($memo)?></textarea>
    </label>

    <div class="row" style="justify-content:flex-end">
      <a class="btn" href="./index.php?store_id=<?=$storeId?>&tab=external">キャンセル</a>
      <button class="btn primary" type="submit">draftで作成</button>
    </div>
  </form>
</div>

<?php
if (function_exists('render_footer')) {
  render_footer();
} else {
  echo "</div></body></html>";
}