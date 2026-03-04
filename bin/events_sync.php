#!/usr/bin/env php
<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

/**
 * seika-app: Okayama events fetcher runner (cron-ready)
 *
 * - fetch_all_okayama_events($from,$to) を実行
 * - JSONへ書き出し（atomic write）
 * - ログ出力（実行時間 / 件数 / source別件数 / エラー）
 * - 多重起動防止（flock）
 *
 * Usage:
 *   php /var/www/html/seika-app/bin/events_sync.php
 *   php .../events_sync.php --from="-30 days" --to="+180 days"
 *   php .../events_sync.php --out="/var/www/html/seika-app/storage/events/okayama_events.json"
 *   php .../events_sync.php --log="/var/log/seika-app/events-sync.log"
 *   php .../events_sync.php --quiet
 */

$ROOT = '/var/www/html/seika-app';
$DEFAULT_OUT = $ROOT . '/storage/events/okayama_events.json';
$DEFAULT_LOG = '/var/log/seika-app/events-sync.log';
$DEFAULT_LOCK = '/tmp/seika-events-sync.lock';

function eprintln(string $s): void { fwrite(STDERR, $s . PHP_EOL); }
function now_iso(): string { return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'); }

function ensure_dir(string $path): void {
  $dir = is_dir($path) ? $path : dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException("mkdir failed: {$dir}");
    }
  }
}

function parse_args(array $argv): array {
  $out = [
    'from' => '-30 days',
    'to' => '+180 days',
    'out' => null,
    'log' => null,
    'lock' => null,
    'quiet' => false,
  ];

  for ($i = 1; $i < count($argv); $i++) {
    $a = $argv[$i];

    if ($a === '--quiet') { $out['quiet'] = true; continue; }

    if (str_starts_with($a, '--from=')) { $out['from'] = substr($a, 7); continue; }
    if (str_starts_with($a, '--to='))   { $out['to']   = substr($a, 5); continue; }
    if (str_starts_with($a, '--out='))  { $out['out']  = substr($a, 6); continue; }
    if (str_starts_with($a, '--log='))  { $out['log']  = substr($a, 6); continue; }
    if (str_starts_with($a, '--lock=')) { $out['lock'] = substr($a, 7); continue; }

    // space-separated
    if ($a === '--from' && isset($argv[$i+1])) { $out['from'] = $argv[++$i]; continue; }
    if ($a === '--to'   && isset($argv[$i+1])) { $out['to']   = $argv[++$i]; continue; }
    if ($a === '--out'  && isset($argv[$i+1])) { $out['out']  = $argv[++$i]; continue; }
    if ($a === '--log'  && isset($argv[$i+1])) { $out['log']  = $argv[++$i]; continue; }
    if ($a === '--lock' && isset($argv[$i+1])) { $out['lock'] = $argv[++$i]; continue; }

    if ($a === '--help' || $a === '-h') {
      echo "Usage: events_sync.php [--from=\"-30 days\"] [--to=\"+180 days\"] [--out=PATH] [--log=PATH] [--lock=PATH] [--quiet]\n";
      exit(0);
    }
  }

  return $out;
}

function log_line(string $logPath, string $level, string $msg, array $ctx = []): void {
  $line = [
    'ts' => now_iso(),
    'level' => $level,
    'msg' => $msg,
  ] + $ctx;

  $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) $json = '{"ts":"' . now_iso() . '","level":"ERROR","msg":"json_encode failed"}';

  ensure_dir($logPath);
  file_put_contents($logPath, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** atomic write: write tmp then rename */
function write_json_atomic(string $path, array $data): void {
  ensure_dir($path);
  $tmp = $path . '.tmp.' . getmypid();

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('json_encode failed');

  if (file_put_contents($tmp, $json, LOCK_EX) === false) {
    throw new RuntimeException("write failed: {$tmp}");
  }
  if (!rename($tmp, $path)) {
    @unlink($tmp);
    throw new RuntimeException("rename failed: {$tmp} -> {$path}");
  }
}

/** build summary counts by source */
function count_by_source(array $items): array {
  $src = [];
  foreach ($items as $e) {
    $s = (string)($e['source'] ?? 'unknown');
    $src[$s] = ($src[$s] ?? 0) + 1;
  }
  ksort($src);
  return $src;
}

/** Minimal validate item shape */
function validate_items(array $items): array {
  $ok = [];
  foreach ($items as $it) {
    if (!is_array($it)) continue;

    $title = trim((string)($it['title'] ?? ''));
    $source = trim((string)($it['source'] ?? ''));
    $url = trim((string)($it['source_url'] ?? ''));

    if ($title === '' || $source === '' || $url === '') continue;

    // normalize keys existence
    $it['title'] = $title;
    $it['source'] = $source;
    $it['source_url'] = $url;

    // ensure fields exist
    $it['source_id'] = (string)($it['source_id'] ?? '');
    $it['starts_at'] = $it['starts_at'] ?? null;
    $it['ends_at']   = $it['ends_at'] ?? null;
    $it['all_day']   = (int)($it['all_day'] ?? 0);
    $it['venue_name'] = $it['venue_name'] ?? null;
    $it['venue_addr'] = $it['venue_addr'] ?? null;
    $it['organizer_name'] = $it['organizer_name'] ?? null;
    $it['organizer_contact'] = $it['organizer_contact'] ?? null;
    $it['notes'] = $it['notes'] ?? null;

    $ok[] = $it;
  }
  return $ok;
}

$args = parse_args($argv);

$outPath  = $args['out']  ?? $DEFAULT_OUT;
$logPath  = $args['log']  ?? $DEFAULT_LOG;
$lockPath = __DIR__ . '/../storage/locks/seika-events-sync.lock';
//$lockPath = $args['lock'] ?? $DEFAULT_LOCK;
$quiet    = (bool)$args['quiet'];

$startedAt = microtime(true);
$runId = bin2hex(random_bytes(6));

try {
  // lock
  ensure_dir($lockPath);
  $lockFp = fopen($lockPath, 'c+');
  if ($lockFp === false) throw new RuntimeException("lock open failed: {$lockPath}");
  if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    log_line($logPath, 'INFO', 'skip: already running', ['run_id' => $runId, 'lock' => $lockPath]);
    if (!$quiet) eprintln("skip: already running");
    exit(0);
  }

  // load fetchers
  $fetchersPath = $ROOT . '/app/events/fetchers.php';
  if (!is_file($fetchersPath)) throw new RuntimeException("fetchers not found: {$fetchersPath}");
  require $fetchersPath;

  $from = (new DateTimeImmutable('now'))->modify((string)$args['from']);
  $to   = (new DateTimeImmutable('now'))->modify((string)$args['to']);

  log_line($logPath, 'INFO', 'start', [
    'run_id' => $runId,
    'from' => $from->format(DateTimeInterface::ATOM),
    'to' => $to->format(DateTimeInterface::ATOM),
    'out' => $outPath,
  ]);

  // execute
  $raw = fetch_all_okayama_events($from, $to);
  $items = validate_items($raw);

  // sort stable (starts_at asc, then title)
  usort($items, static function(array $a, array $b): int {
    $sa = (string)($a['starts_at'] ?? '');
    $sb = (string)($b['starts_at'] ?? '');
    if ($sa !== $sb) return $sa <=> $sb;
    return ((string)$a['title']) <=> ((string)$b['title']);
  });

  $by = count_by_source($items);

  $payload = [
    'generated_at' => now_iso(),
    'from' => $from->format('Y-m-d'),
    'to'   => $to->format('Y-m-d'),
    'total' => count($items),
    'by_source' => $by,
    'items' => $items,
  ];

  write_json_atomic($outPath, $payload);

  $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

  log_line($logPath, 'INFO', 'done', [
    'run_id' => $runId,
    'total' => count($items),
    'by_source' => $by,
    'elapsed_ms' => $elapsedMs,
    'out_bytes' => is_file($outPath) ? filesize($outPath) : null,
  ]);

  if (!$quiet) {
    echo "OK run_id={$runId}\n";
    echo "total=" . count($items) . "\n";
    foreach ($by as $k => $v) echo "  {$k}={$v}\n";
    echo "out={$outPath}\n";
    echo "log={$logPath}\n";
    echo "elapsed_ms={$elapsedMs}\n";
  }

  // release lock
  flock($lockFp, LOCK_UN);
  fclose($lockFp);

  exit(0);

} catch (Throwable $e) {
  $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
  log_line($logPath, 'ERROR', 'failed', [
    'run_id' => $runId,
    'error' => $e->getMessage(),
    'elapsed_ms' => $elapsedMs,
    'trace' => substr($e->getTraceAsString(), 0, 4000),
  ]);
  if (!$quiet) {
    eprintln("FAIL run_id={$runId}");
    eprintln($e->getMessage());
  }
  exit(1);
}
