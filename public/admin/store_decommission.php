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
  <div class="admin-wrap dashboard-shell store-dec-shell">
  <?php if ($flash !== ''): ?>
    <div class="notice notice-ok"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="notice notice-error"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="store-dec-hero">
    <div class="store-dec-hero__copy">
      <div class="hero-store-label">現在の対象店舗</div>
      <div class="hero-store-name"><?= h($storeName) ?></div>
      <div class="hero-store-meta">STORE #<?= (int)$storeId ?></div>
      <span class="hero-badge">解約・廃棄ワークフロー</span>
      <h1>停止、確認、申請、承認、予約を1画面で管理します。</h1>
      <p>即時削除は行わず、店舗単位で安全にクローズするための管理画面です。状態遷移と件数確認を先に行い、実削除はジョブと runner に分離しています。</p>
    </div>

    <form method="get" class="store-dec-hero__tools">
      <div class="dashboard-inline-panel store-dec-switch">
        <div class="store-switch-label">対象店舗</div>
        <div class="store-switch-row">
          <select name="store_id" class="sel store-switch-select">
            <?php foreach ($stores as $selectStore): ?>
              <?php $sid = (int)($selectStore['id'] ?? 0); ?>
              <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
                <?= h((string)($selectStore['name'] ?? ('#' . $sid))) ?> (#<?= $sid ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn">切替</button>
        </div>
        <div class="store-switch-help">停止、申請、承認、ログ確認の対象をここで切り替えます。</div>
      </div>
      <div class="dashboard-inline-panel store-dec-statusbox">
        <div class="store-switch-label">現在状態</div>
        <div class="store-dec-statusbox__value">
          <span class="pill pill-<?= h($lifecycle) ?>"><?= h($lifecycle) ?></span>
        </div>
        <div class="store-switch-help">`active → suspended → decommissioning → decommissioned` の順で進みます。</div>
      </div>
    </form>
  </section>

  <div class="store-dec__grid">
    <section class="dash-section store-dec-panel">
      <div class="dash-section-head">
        <div>
          <h2>店舗状態</h2>
          <p><?= h($storeName) ?> (#<?= (int)$storeId ?>) の現在状態と申請履歴です。</p>
        </div>
      </div>
      <table class="kv">
        <tr><th>lifecycle_status</th><td><span class="pill"><?= h($lifecycle) ?></span></td></tr>
        <tr><th>最終ログイン</th><td><?= h((string)($store['last_login_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>更新日時</th><td><?= h((string)($store['updated_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>申請日時</th><td><?= h((string)($store['decommission_requested_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>承認日時</th><td><?= h((string)($store['decommission_approved_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>廃棄予定日時</th><td><?= h((string)($store['decommission_scheduled_at'] ?? '')) ?: '—' ?></td></tr>
        <tr><th>完了日時</th><td><?= h((string)($store['decommission_completed_at'] ?? '')) ?: '—' ?></td></tr>
      </table>
    </section>

    <section class="dash-section store-dec-panel">
      <div class="dash-section-head">
        <div>
          <h2>対象件数プレビュー</h2>
          <p>現在の店舗に紐づく主要データ件数です。申請前の確認用として使います。</p>
        </div>
      </div>
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
    </section>
  </div>

  <div class="store-dec__grid">
    <section class="dash-section store-dec-panel">
      <div class="dash-section-head">
        <div>
          <h2>停止 / 申請</h2>
          <p>まず店舗を停止し、その後に廃棄申請へ進めます。</p>
        </div>
      </div>
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
    </section>

    <section class="dash-section store-dec-panel">
      <div class="dash-section-head">
        <div>
          <h2>承認 / 予約 / キャンセル</h2>
          <p>ジョブの承認、予約時刻の更新、最終エクスポート、キャンセルを管理します。</p>
        </div>
      </div>
      <?php if ($job): ?>
        <div class="store-dec-jobbar">
          <span>job #<?= (int)$job['id'] ?></span>
          <span>status: <b><?= h((string)$job['status']) ?></b></span>
        </div>

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
    </section>
  </div>

  <section class="dash-section store-dec-panel">
    <div class="dash-section-head">
      <div>
        <h2>実行ログ</h2>
        <p>申請、承認、予約、runner の各ステップを時系列で確認できます。</p>
      </div>
    </div>
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
  </section>
  </div>
</div>

<style>
  .store-dec-shell{
    max-width:1320px;
    padding-bottom:28px;
  }
  .store-dec-hero,
  .store-dec-panel{
    border:1px solid var(--line);
    border-radius:22px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
      var(--cardA);
    box-shadow:0 16px 40px rgba(0,0,0,.14);
  }
  .store-dec-hero{
    display:grid;
    grid-template-columns:minmax(0, 1.5fr) minmax(280px, .82fr);
    gap:18px;
    padding:20px 22px;
    margin-top:10px;
    margin-bottom:14px;
  }
  .store-dec-hero__copy{
    display:flex;
    flex-direction:column;
    gap:8px;
    justify-content:center;
  }
  .store-dec-hero__copy h1{
    margin:0;
    font-size:25px;
    line-height:1.2;
  }
  .store-dec-hero__copy p{
    margin:0;
    color:var(--muted);
    font-size:13px;
    line-height:1.6;
  }
  .store-dec-hero__tools{
    display:grid;
    gap:12px;
    align-content:start;
  }
  .store-dec-switch,
  .store-dec-statusbox{
    padding:12px;
  }
  .store-dec-statusbox__value{
    margin:4px 0 6px;
  }
  .store-dec__grid{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap:14px;
    margin-top:14px;
  }
  .store-dec-panel{
    padding:18px 18px 16px;
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
    margin-top:8px;
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
  .store-dec-jobbar{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:10px;
    padding:10px 12px;
    border:1px solid var(--line);
    border-radius:14px;
    background:rgba(255,255,255,.03);
    color:var(--muted);
    font-size:13px;
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
  .dash-section-head{
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:flex-start;
    margin-bottom:8px;
  }
  .dash-section-head h2{
    margin:0;
    font-size:20px;
    line-height:1.2;
  }
  .dash-section-head p{
    margin:6px 0 0;
    color:var(--muted);
    font-size:13px;
    line-height:1.55;
  }
  .hero-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    color:#0f172a;
    background:linear-gradient(135deg, #facc15, #fb923c);
    width:max-content;
  }
  .hero-store-name{
    font-size:34px;
    font-weight:1000;
    color:var(--txt);
    line-height:1.05;
    letter-spacing:.01em;
  }
  .hero-store-label{
    font-size:12px;
    font-weight:900;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.08em;
  }
  .hero-store-meta{
    font-size:12px;
    font-weight:900;
    color:var(--muted);
  }
  .dashboard-inline-panel{
    border:1px solid var(--line);
    border-radius:16px;
    background:rgba(255,255,255,.03);
  }
  @media (max-width: 920px){
    .store-dec-hero{
      grid-template-columns:1fr;
    }
  }
</style>
<?php render_page_end(); ?>
