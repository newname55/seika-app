<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/deploy.php';

require_login();

// 本番 rollback は店舗運用ではなくシステム管理操作なので manager には開けず、
// 既存の admin ランチャー方針に合わせて admin / super_user のみに限定する。
require_role(['super_user', 'admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  deploy_json_exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

csrf_verify($_POST['csrf_token'] ?? null);

$targetCommit = strtolower(trim((string)($_POST['target_commit'] ?? '')));
if (!preg_match('/^[0-9a-f]{7,40}$/', $targetCommit)) {
  deploy_json_exit(['ok' => false, 'error' => 'rollback対象commitが不正です'], 422);
}

$pdo = db();
$prodSuccessLogs = deploy_fetch_prod_success_logs($pdo, 200);
$prodSuccessLogs = deploy_enrich_log_rows($prodSuccessLogs);

$targetRow = null;
foreach ($prodSuccessLogs as $row) {
  if (deploy_hash_matches((string)($row['after_hash'] ?? ''), $targetCommit)) {
    $targetRow = $row;
    break;
  }
}

if ($targetRow === null) {
  deploy_json_exit(['ok' => false, 'error' => 'deploy_logs 上の正当な prod success 履歴ではありません'], 422);
}

try {
  $prodHead = deploy_get_current_prod_head();
  $eligibleTarget = deploy_find_eligible_rollback_target($prodSuccessLogs, (string)$prodHead['full']);
  $eligibleTargetHash = strtolower((string)($eligibleTarget['after_hash'] ?? ''));

  if ($eligibleTargetHash === '') {
    deploy_json_exit(['ok' => false, 'error' => 'rollback可能な直前履歴が見つかりません'], 409);
  }
  if (deploy_hash_matches($targetCommit, (string)$prodHead['full'])) {
    deploy_json_exit(['ok' => false, 'error' => '現在の本番HEADには rollback できません'], 409);
  }
  if (!deploy_hash_matches($targetCommit, $eligibleTargetHash)) {
    deploy_json_exit(['ok' => false, 'error' => '現在の本番から戻せるのは直前の success 履歴のみです'], 409);
  }

  $result = deploy_run_prod_ssh([
    'sh',
    '-lc',
    'cd ' . escapeshellarg(deploy_prod_app_dir()) . ' && ' .
    escapeshellarg(deploy_prod_rollback_script()) . ' ' . escapeshellarg($eligibleTargetHash),
  ]);

  if ((int)$result['exit_code'] !== 0) {
    $error = $result['stderr'] !== '' ? $result['stderr'] : ($result['stdout'] !== '' ? $result['stdout'] : 'rollback failed');
    deploy_json_exit(['ok' => false, 'error' => $error], 500);
  }

  deploy_json_exit([
    'ok' => true,
    'message' => 'rollback を実行しました',
    'target_commit' => $eligibleTargetHash,
    'stdout' => $result['stdout'],
  ]);
} catch (Throwable $e) {
  deploy_json_exit(['ok' => false, 'error' => $e->getMessage()], 500);
}
