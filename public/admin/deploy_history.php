<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/deploy.php';

require_login();
require_role(['super_user', 'admin']);

$pdo = db();
$logs = deploy_enrich_log_rows(deploy_fetch_logs($pdo, 200));
$prodSuccessLogs = deploy_fetch_prod_success_logs($pdo, 200);
$prodHead = null;
$prodHeadError = '';
$eligibleTarget = null;
$eligibleTargetHash = '';

try {
  $prodHead = deploy_get_current_prod_head();
  $eligibleTarget = deploy_find_eligible_rollback_target($prodSuccessLogs, (string)$prodHead['full']);
  $eligibleTargetHash = strtolower((string)($eligibleTarget['after_hash'] ?? ''));
} catch (Throwable $e) {
  $prodHeadError = $e->getMessage();
}

render_page_start('本番 deploy / rollback');
render_header('本番 deploy / rollback', [
  'back_href' => '/wbss/public/admin/index.php',
  'back_label' => '← 管理ランチャー',
  'show_store' => false,
]);
?>
<div class="page">
  <div class="card">
    <div class="deploy-head">
      <div>
        <div class="deploy-title">本番 deploy 履歴</div>
        <div class="muted deploy-lead">prod success の中から「現在の本番の1つ前」だけ rollback できます。</div>
      </div>
      <div class="deploy-meta">
        <span class="pill"><span class="pill__label">権限</span><span class="pill__value">admin / super_user</span></span>
        <?php if ($prodHead !== null): ?>
          <span class="pill"><span class="pill__label">prod HEAD</span><span class="pill__value"><?= h((string)$prodHead['short']) ?></span></span>
        <?php else: ?>
          <span class="pill"><span class="pill__label">prod HEAD</span><span class="pill__value">取得失敗</span></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($prodHeadError !== ''): ?>
      <div class="notice notice-error">本番HEADを取得できなかったため rollback は無効です。<?= h($prodHeadError) ?></div>
    <?php elseif ($eligibleTargetHash === ''): ?>
      <div class="notice">rollback 可能な直前履歴が見つかりませんでした。</div>
    <?php else: ?>
      <div class="notice notice-ok">
        rollback 対象: <?= h(substr($eligibleTargetHash, 0, 12)) ?>
        <?php if (!empty($eligibleTarget['created_at'])): ?>
          / <?= h((string)$eligibleTarget['created_at']) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="deploy-table-wrap">
      <table class="deploy-table">
        <thead>
          <tr>
            <th>日時</th>
            <th>env</th>
            <th>before</th>
            <th>after</th>
            <th>status</th>
            <th>executed_by</th>
            <th>detail</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$logs): ?>
            <tr>
              <td colspan="8" class="muted">deploy_logs はまだありません。</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($logs as $row): ?>
            <?php
              $environment = (string)($row['environment'] ?? '');
              $status = (string)($row['status'] ?? '');
              $afterHash = strtolower((string)($row['after_hash'] ?? ''));
              $isProdSuccess = ($environment === 'prod' && $status === 'success' && $afterHash !== '');
              $isCurrentHead = $prodHead !== null && deploy_hash_matches($afterHash, (string)$prodHead['full']);
              $isEligible = $eligibleTargetHash !== '' && deploy_hash_matches($afterHash, $eligibleTargetHash);
            ?>
            <tr>
              <td><?= h((string)($row['created_at'] ?? '')) ?></td>
              <td><span class="env-pill env-<?= h($environment !== '' ? $environment : 'other') ?>"><?= h($environment !== '' ? $environment : '-') ?></span></td>
              <td class="mono"><?= h((string)($row['before_commit'] ?? '')) ?></td>
              <td class="mono"><?= h((string)($row['after_commit'] ?? '')) ?></td>
              <td><?= h($status !== '' ? $status : '-') ?></td>
              <td><?= h((string)($row['executed_by'] ?? '')) ?></td>
              <td><?= nl2br(h((string)($row['detail_text'] ?? ''))) ?></td>
              <td>
                <?php if (!$isProdSuccess): ?>
                  <span class="muted">-</span>
                <?php elseif ($prodHead === null): ?>
                  <span class="muted">HEAD取得不可</span>
                <?php elseif ($isCurrentHead): ?>
                  <button type="button" class="btn" disabled>現在HEAD</button>
                <?php elseif ($isEligible): ?>
                  <form class="js-rollback-form" method="post" action="/wbss/public/api/deploy_rollback.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="target_commit" value="<?= h($afterHash) ?>">
                    <button type="submit" class="btn btn-danger">rollback</button>
                  </form>
                <?php else: ?>
                  <button type="button" class="btn" disabled>直前のみ</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.js-rollback-form').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fd = new FormData(form);
    const target = String(fd.get('target_commit') || '');
    if (!window.confirm('本番を ' + target + ' へ rollback します。実行してよいですか？')) {
      return;
    }

    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || 'rollback に失敗しました');
      }
      alert(json.message || 'rollback を開始しました');
      window.location.reload();
    } catch (error) {
      alert((error && error.message) ? error.message : 'rollback に失敗しました');
      if (btn) btn.disabled = false;
    }
  });
});
</script>

<style>
.deploy-head{
  display:flex;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  align-items:flex-start;
}
.deploy-title{
  font-size:18px;
  font-weight:1000;
}
.deploy-lead{
  margin-top:4px;
}
.deploy-meta{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.deploy-table-wrap{
  margin-top:14px;
  overflow:auto;
}
.deploy-table{
  width:100%;
  border-collapse:collapse;
  font-size:13px;
}
.deploy-table th,
.deploy-table td{
  padding:10px 8px;
  border-bottom:1px solid var(--line);
  vertical-align:top;
}
.deploy-table th{
  text-align:left;
  white-space:nowrap;
}
.mono{
  font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  word-break:break-all;
}
.env-pill{
  display:inline-flex;
  align-items:center;
  padding:4px 8px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  font-size:12px;
  font-weight:800;
}
.env-prod{
  border-color:color-mix(in srgb, var(--accent) 40%, var(--line));
}
.env-rollback{
  border-color:color-mix(in srgb, var(--warn) 45%, var(--line));
}
.notice{
  margin-top:12px;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
}
.notice-ok{
  border-color:color-mix(in srgb, var(--ok) 40%, var(--line));
}
.notice-error{
  border-color:color-mix(in srgb, var(--ng) 45%, var(--line));
}
.btn-danger{
  background:color-mix(in srgb, var(--ng) 16%, var(--cardA));
  border-color:color-mix(in srgb, var(--ng) 40%, var(--line));
}
@media (max-width: 900px){
  .deploy-table{
    min-width:980px;
  }
}
</style>

<?php render_page_end(); ?>
