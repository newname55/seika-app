<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store_decommission.php';

$pdo = db();
$argv = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$limit = 10;

foreach ($argv as $arg) {
  if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
    $limit = max(1, min(100, (int)$m[1]));
  }
}

$jobs = store_decommission_collect_due_jobs($pdo, $limit);
if (!$jobs) {
  fwrite(STDOUT, "no scheduled jobs\n");
  exit(0);
}

foreach ($jobs as $job) {
  $jobId = (int)($job['id'] ?? 0);
  if ($jobId <= 0) {
    continue;
  }

  try {
    $result = store_decommission_execute_job($pdo, $jobId, $dryRun, 0);
    fwrite(STDOUT, sprintf(
      "job=%d status=%s deleted_tables=%d skipped_tables=%d\n",
      $jobId,
      $dryRun ? 'dry-run' : 'completed',
      count($result['deleted'] ?? []),
      count($result['skipped'] ?? [])
    ));
  } catch (Throwable $e) {
    fwrite(STDERR, sprintf("job=%d failed=%s\n", $jobId, $e->getMessage()));
  }
}
