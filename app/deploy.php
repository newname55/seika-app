<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!function_exists('deploy_json_exit')) {
  function deploy_json_exit(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('deploy_extract_commit_hash')) {
  function deploy_extract_commit_hash(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
      return '';
    }
    if (preg_match('/\b([0-9a-f]{7,40})\b/i', $value, $m)) {
      return strtolower((string)$m[1]);
    }
    return '';
  }
}

if (!function_exists('deploy_hash_matches')) {
  function deploy_hash_matches(string $left, string $right): bool {
    $left = strtolower(trim($left));
    $right = strtolower(trim($right));
    if ($left === '' || $right === '') {
      return false;
    }
    return str_starts_with($left, $right) || str_starts_with($right, $left);
  }
}

if (!function_exists('deploy_prod_ssh_target')) {
  function deploy_prod_ssh_target(): string {
    $target = trim((string)conf('WBSS_PROD_SSH_TARGET'));
    if ($target === '') {
      $target = 'raspi5';
    }
    if (!preg_match('/^[A-Za-z0-9_.@:-]+$/', $target)) {
      throw new RuntimeException('prod ssh target config is invalid');
    }
    return $target;
  }
}

if (!function_exists('deploy_prod_app_dir')) {
  function deploy_prod_app_dir(): string {
    $dir = trim((string)conf('WBSS_PROD_APP_DIR'));
    if ($dir === '') {
      $dir = '/var/www/html/wbss';
    }
    if ($dir[0] !== '/') {
      throw new RuntimeException('prod app dir config must be absolute');
    }
    return rtrim($dir, '/');
  }
}

if (!function_exists('deploy_prod_rollback_script')) {
  function deploy_prod_rollback_script(): string {
    $path = trim((string)conf('WBSS_PROD_ROLLBACK_SCRIPT'));
    if ($path === '') {
      $path = deploy_prod_app_dir() . '/rollback.sh';
    }
    if ($path[0] !== '/') {
      throw new RuntimeException('prod rollback script config must be absolute');
    }
    return $path;
  }
}

if (!function_exists('deploy_run_command')) {
  function deploy_run_command(array $command, ?string $cwd = null): array {
    $descriptorSpec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $proc = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($proc)) {
      throw new RuntimeException('command start failed');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    return [
      'exit_code' => (int)$exitCode,
      'stdout' => is_string($stdout) ? trim($stdout) : '',
      'stderr' => is_string($stderr) ? trim($stderr) : '',
    ];
  }
}

if (!function_exists('deploy_run_prod_ssh')) {
  function deploy_run_prod_ssh(array $argv): array {
    $target = deploy_prod_ssh_target();
    $escaped = array_map(static fn(string $part): string => escapeshellarg($part), $argv);
    $remoteCommand = implode(' ', $escaped);
    return deploy_run_command(['ssh', $target, '--', $remoteCommand]);
  }
}

if (!function_exists('deploy_get_current_prod_head')) {
  function deploy_get_current_prod_head(): array {
    $appDir = deploy_prod_app_dir();
    $result = deploy_run_prod_ssh([
      'sh',
      '-lc',
      'cd ' . escapeshellarg($appDir) . ' && git rev-parse HEAD && git rev-parse --short=12 HEAD',
    ]);

    if ((int)$result['exit_code'] !== 0) {
      throw new RuntimeException($result['stderr'] !== '' ? $result['stderr'] : 'prod head fetch failed');
    }

    $lines = preg_split('/\R+/', (string)$result['stdout']) ?: [];
    $full = strtolower(trim((string)($lines[0] ?? '')));
    $short = strtolower(trim((string)($lines[1] ?? '')));

    if (!preg_match('/^[0-9a-f]{40}$/', $full)) {
      throw new RuntimeException('prod full hash is invalid');
    }
    if (!preg_match('/^[0-9a-f]{7,12}$/', $short)) {
      throw new RuntimeException('prod short hash is invalid');
    }

    return ['full' => $full, 'short' => $short];
  }
}

if (!function_exists('deploy_fetch_logs')) {
  function deploy_fetch_logs(PDO $pdo, int $limit = 200): array {
    $limit = max(1, min($limit, 500));
    $st = $pdo->prepare("
      SELECT
        id,
        environment,
        host_name,
        branch_name,
        before_commit,
        after_commit,
        status,
        executed_by,
        detail_text,
        created_at
      FROM deploy_logs
      ORDER BY id DESC
      LIMIT {$limit}
    ");
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('deploy_fetch_prod_success_logs')) {
  function deploy_fetch_prod_success_logs(PDO $pdo, int $limit = 200): array {
    $limit = max(1, min($limit, 500));
    $st = $pdo->prepare("
      SELECT
        id,
        environment,
        host_name,
        branch_name,
        before_commit,
        after_commit,
        status,
        executed_by,
        detail_text,
        created_at
      FROM deploy_logs
      WHERE environment = 'prod'
        AND status = 'success'
      ORDER BY id DESC
      LIMIT {$limit}
    ");
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('deploy_enrich_log_rows')) {
  function deploy_enrich_log_rows(array $rows): array {
    foreach ($rows as &$row) {
      $row['before_hash'] = deploy_extract_commit_hash((string)($row['before_commit'] ?? ''));
      $row['after_hash'] = deploy_extract_commit_hash((string)($row['after_commit'] ?? ''));
    }
    unset($row);
    return $rows;
  }
}

if (!function_exists('deploy_find_eligible_rollback_target')) {
  function deploy_find_eligible_rollback_target(array $prodSuccessRows, string $currentProdHead): ?array {
    $rows = deploy_enrich_log_rows($prodSuccessRows);
    $matchedCurrent = false;

    foreach ($rows as $row) {
      $afterHash = (string)($row['after_hash'] ?? '');
      if ($afterHash === '') {
        continue;
      }

      if (!$matchedCurrent) {
        if (deploy_hash_matches($afterHash, $currentProdHead)) {
          $matchedCurrent = true;
        }
        continue;
      }

      return $row;
    }

    return null;
  }
}
