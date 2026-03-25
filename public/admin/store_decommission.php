<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/store_decommission.php';
require_once __DIR__ . '/../../app/layout.php';

store_decommission_require_manage_role();

$pdo = db();
$stores = store_decommission_manageable_stores($pdo);
$requestedStoreId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
$storeId = store_decommission_resolve_store_id($pdo, $requestedStoreId);
$flash = '';
$error = '';
$actorUserId = (int)(current_user_id() ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    csrf_verify((string)($_POST['csrf_token'] ?? ''));
    $uiAction = (string)($_POST['ui_action'] ?? '');

    if ($uiAction === 'suspend') {
      store_decommission_suspend($pdo, $storeId, $actorUserId, (string)($_POST['password'] ?? ''), (string)($_POST['confirm_text'] ?? ''), trim((string)($_POST['reason'] ?? '')));
      $flash = '店舗を停止しました。';
    } elseif ($uiAction === 'unsuspend') {
      store_decommission_unsuspend($pdo, $storeId, $actorUserId, (string)($_POST['password'] ?? ''));
      $flash = '停止を解除しました。';
    } elseif ($uiAction === 'request') {
      store_decommission_request(
        $pdo,
        $storeId,
        $actorUserId,
        (string)($_POST['password'] ?? ''),
        (string)($_POST['confirm_text'] ?? ''),
        trim((string)($_POST['reason'] ?? '')),
        trim((string)($_POST['requested_schedule_at'] ?? '')) !== '' ? trim((string)$_POST['requested_schedule_at']) : null
      );
      $flash = '廃棄申請を作成しました。';
    } elseif ($uiAction === 'approve') {
      store_decommission_approve($pdo, (int)($_POST['job_id'] ?? 0), $actorUserId, (string)($_POST['password'] ?? ''), true, trim((string)($_POST['comment'] ?? '')));
      $flash = '廃棄申請を承認しました。';
    } elseif ($uiAction === 'schedule') {
      store_decommission_schedule($pdo, (int)($_POST['job_id'] ?? 0), (string)($_POST['scheduled_at'] ?? ''), $actorUserId);
      $flash = '実行予約を更新しました。';
    } elseif ($uiAction === 'cancel') {
      store_decommission_cancel($pdo, (int)($_POST['job_id'] ?? 0), $actorUserId, (string)($_POST['password'] ?? ''), (string)($_POST['confirm_text'] ?? ''), trim((string)($_POST['reason'] ?? '')));
      $flash = '廃棄ジョブをキャンセルしました。';
    } elseif ($uiAction === 'export') {
      store_decommission_export($pdo, (int)($_POST['job_id'] ?? 0), $actorUserId);
      $flash = '最終エクスポートを作成しました。';
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$status = store_decommission_status($pdo, $storeId);
$store = $status['store'];
$job = $status['job'];
$summary = $status['summary'];
$logs = $status['logs'];
$storeName = (string)($store['name'] ?? ('#' . $storeId));
$lifecycle = (string)($store['lifecycle_status'] ?? 'active');

render_page_start('店舗解約・データ廃棄');
render_header('店舗解約・データ廃棄', [
  'back_href' => '/wbss/public/admin/index.php',
  'back_label' => '← 管理ランチャー',
]);
?>
<div class="page">
  <?php if ($flash !== ''): ?>
    <div class="notice notice-ok"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="notice notice-error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="get" class="store-dec__row">
      <div>
        <div class="h2">対象店舗</div>
        <div class="muted">停止、申請、承認、予約、ログ確認をここで管理します。</div>
      </div>
      <div class="store-dec__picker">
        <select name="store_id" class="input">
          <?php foreach ($stores as $selectStore): ?>
            <?php $sid = (int)($selectStore['id'] ?? 0); ?>
            <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
              <?= h((string)($selectStore['name'] ?? ('#' . $sid))) ?> (#<?= $sid ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">切替</button>
      </div>
    </form>
  </div>

  <div class="store-dec__grid">
    <div class="card">
      <div class="h2"><?= h($storeName) ?> <span class="muted">#<?= (int)$storeId ?></span></div>
      <table class="kv">
        <tr><th>lifecycle_status</th><td><span class="pill"><?= h($lifecycle) ?></span></td></tr>
        <tr><th>最終ログイン</th><td><?= h((string)($store['last_login_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>更新日時</th><td><?= h((string)($store['updated_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>申請日時</th><td><?= h((string)($store['decommission_requested_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>承認日時</th><td><?= h((string)($store['decommission_approved_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>廃棄予定日時</th><td><?= h((string)($store['decommission_scheduled_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>完了日時</th><td><?= h((string)($store['decommission_completed_at'] ?? '')) ?: '—' ?></td></tr>
      </table>
    </div>

    <div class="card">
      <div class="h2">対象件数プレビュー</div>
      <table class="kv">
        <tr><th>顧客</th><td><?= (int)$summary['customers'] ?></td></tr>
        <tr><th>会計伝票</th><td><?= (int)$summary['tickets'] ?></td></tr>
        <tr><th>注文</th><td><?= (int)$summary['orders'] ?></td></tr>
        <tr><th>出勤</th><td><?= (int)$summary['attendances'] ?></td></tr>
        <tr><th>指名履歴</th><td><?= (int)$summary['nominations'] ?></td></tr>
        <tr><th>面接</th><td><?= (int)$summary['interviews'] ?></td></tr>
        <tr><th>添付件数</th><td><?= (int)$summary['attachments'] ?></td></tr>
        <tr><th>添付総容量</th><td><?= number_format((int)$summary['attachments_bytes']) ?> bytes</td></tr>
      </table>
    </div>
  </div>

  <div class="store-dec__grid">
    <div class="card">
      <div class="h2">停止 / 申請</div>
      <?php if ($lifecycle === 'active'): ?>
        <form method="post" class="store-dec__form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="ui_action" value="suspend">
          <label>確認文字列
            <input class="input" name="confirm_text" value="<?= h(store_decommission_expected_confirm_text('suspend', $storeId)) ?>">
          </label>
          <label>パスワード再入力
            <input class="input" type="password" name="password" autocomplete="current-password">
          </label>
          <label>理由
            <textarea class="input" name="reason" rows="3">契約終了準備のため</textarea>
          </label>
          <button type="submit" class="btn btn-danger">利用停止</button>
        </form>
      <?php else: ?>
        <form method="post" class="store-dec__form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="ui_action" value="unsuspend">
          <label>パスワード再入力
            <input class="input" type="password" name="password" autocomplete="current-password">
          </label>
          <button type="submit" class="btn">停止解除</button>
        </form>
      <?php endif; ?>

      <hr>

      <form method="post" class="store-dec__form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <input type="hidden" name="ui_action" value="request">
        <label>確認文字列
          <input class="input" name="confirm_text" value="<?= h(store_decommission_expected_confirm_text('request', $storeId)) ?>">
        </label>
        <label>パスワード再入力
          <input class="input" type="password" name="password" autocomplete="current-password">
        </label>
        <label>理由
          <textarea class="input" name="reason" rows="3">閉店のため</textarea>
        </label>
        <label>希望予約時刻
          <input class="input" name="requested_schedule_at" value="<?= h((string)($job['scheduled_at'] ?? '')) ?>" placeholder="2026-03-28 03:00:00">
        </label>
        <button type="submit" class="btn btn-danger" <?= $lifecycle === 'active' ? 'disabled' : '' ?>>廃棄申請</button>
      </form>
    </div>

    <div class="card">
      <div class="h2">承認 / 予約 / キャンセル</div>
      <?php if ($job): ?>
        <div class="muted">job #<?= (int)$job['id'] ?> / status: <b><?= h((string)$job['status']) ?></b></div>

        <form method="post" class="store-dec__form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="ui_action" value="approve">
          <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
          <label>承認者パスワード
            <input class="input" type="password" name="password" autocomplete="current-password">
          </label>
          <label>コメント
            <textarea class="input" name="comment" rows="2">内容確認済み</textarea>
          </label>
          <button type="submit" class="btn">廃棄承認</button>
        </form>

        <form method="post" class="store-dec__form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="ui_action" value="schedule">
          <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
          <label>scheduled_at
            <input class="input" name="scheduled_at" value="<?= h((string)($job['scheduled_at'] ?? '')) ?>" placeholder="2026-03-28 03:00:00">
          </label>
          <button type="submit" class="btn">廃棄実行予約</button>
        </form>

        <form method="post" class="store-dec__form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="ui_action" value="export">
          <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
          <button type="submit" class="btn">最終エクスポート作成</button>
          <?php if (!empty($job['export_path'])): ?>
            <a class="btn" href="<?= h((string)$job['export_path']) ?>" target="_blank" rel="noopener noreferrer">ダウンロード</a>
          <?php endif; ?>
        </form>

        <form method="post" class="store-dec__form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="ui_action" value="cancel">
          <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
          <label>確認文字列
            <input class="input" name="confirm_text" value="<?= h(store_decommission_expected_confirm_text('cancel', $storeId)) ?>">
          </label>
          <label>パスワード再入力
            <input class="input" type="password" name="password" autocomplete="current-password">
          </label>
          <label>理由
            <textarea class="input" name="reason" rows="2">運用判断により中止</textarea>
          </label>
          <button type="submit" class="btn">廃棄キャンセル</button>
        </form>
      <?php else: ?>
        <div class="muted">まだ解約ジョブはありません。</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="h2">実行ログ</div>
    <?php if (!$logs): ?>
      <div class="muted">ログはまだありません。</div>
    <?php else: ?>
      <table class="list">
        <thead>
          <tr>
            <th>日時</th>
            <th>step</th>
            <th>status</th>
            <th>message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><?= h((string)($log['created_at'] ?? '')) ?></td>
              <td><?= h((string)($log['step_label'] ?? $log['step_key'] ?? '')) ?></td>
              <td><?= h((string)($log['status'] ?? '')) ?></td>
              <td><?= h((string)($log['message'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<style>
  .store-dec__grid{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap:14px;
    margin-top:14px;
  }
  .store-dec__row{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-end;
    flex-wrap:wrap;
  }
  .store-dec__picker{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
  }
  .store-dec__form{
    display:grid;
    gap:10px;
    margin-top:10px;
  }
  .store-dec__form label{
    display:grid;
    gap:6px;
    font-size:13px;
  }
  .kv{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
  }
  .kv th,
  .kv td{
    text-align:left;
    border-bottom:1px solid var(--line);
    padding:8px 0;
    vertical-align:top;
  }
  .kv th{
    width:160px;
    color:var(--muted);
    font-weight:700;
  }
  .list{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
  }
  .list th,
  .list td{
    border-bottom:1px solid var(--line);
    padding:8px 6px;
    text-align:left;
    vertical-align:top;
  }
  .pill{
    display:inline-flex;
    padding:4px 10px;
    border-radius:999px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.05);
    font-weight:700;
  }
</style>
<?php render_page_end(); ?>
