<?php
declare(strict_types=1);

/**
 * bin/store_events_import_okayama.php
 *
 * Import JSON (storage/events/okayama_events.json) into haruto_core.store_external_events
 * - upsert by UNIQUE(store_id, source, source_id)
 *
 * Usage:
 *   php bin/store_events_import_okayama.php --store_id=1
 *   php bin/store_events_import_okayama.php --store_id=1 --in="/path/to/okayama_events.json"
 */

date_default_timezone_set('Asia/Tokyo');

$root = dirname(__DIR__); // /var/www/html/seika-app

// db() 読み込み（既存と同じ候補）
$db_candidates = [
  $root . '/app/db.php',
  $root . '/db.php',
  $root . '/app/_db.php',
  $root . '/public/api/_db.php',
];
foreach ($db_candidates as $f) {
  if (is_file($f)) { require_once $f; break; }
}
if (!function_exists('db')) {
  fwrite(STDERR, "db() not found. Check app/db.php\n");
  exit(1);
}

function now_str(): string { return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'); }

function norm(?string $s, int $max=255): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  $s = preg_replace('/\s+/u', ' ', $s);
  if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
  return $s;
}

function parse_dt(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  try {
    $d = new DateTimeImmutable($s, new DateTimeZone('Asia/Tokyo'));
    return $d->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

function arg_value(array $argv, string $name): ?string {
  $prefix = $name . '=';
  foreach ($argv as $a) {
    if (str_starts_with($a, $prefix)) return substr($a, strlen($prefix));
  }
  return null;
}

$storeIdStr = arg_value($argv, '--store_id');
$storeId = (int)($storeIdStr ?? 0);
if ($storeId <= 0) {
  fwrite(STDERR, "missing --store_id=...\n");
  exit(1);
}

$input = arg_value($argv, '--in') ?? ($root . '/storage/events/okayama_events.json');
if (!is_file($input)) {
  fwrite(STDERR, "input not found: {$input}\n");
  exit(1);
}

// --- load json ---
$raw = file_get_contents($input);
if ($raw === false || $raw === '') {
  fwrite(STDERR, "failed to read: {$input}\n");
  exit(1);
}
$j = json_decode($raw, true);
if (!is_array($j)) {
  fwrite(STDERR, "json decode failed: {$input}\n");
  exit(1);
}
$items = $j['items'] ?? null;
if (!is_array($items)) {
  fwrite(STDERR, "json missing items[]\n");
  exit(1);
}

echo "[".now_str()."] store_id={$storeId} load items=" . count($items) . " in={$input}\n";

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = <<<SQL
INSERT INTO store_external_events
(
  store_id,
  source,
  source_id,
  title,
  starts_at,
  ends_at,
  all_day,
  venue_name,
  venue_addr,
  organizer_name,
  organizer_contact,
  source_url,
  notes,
  fetched_at
)
VALUES
(
  :store_id,
  :source,
  :source_id,
  :title,
  :starts_at,
  :ends_at,
  :all_day,
  :venue_name,
  :venue_addr,
  :organizer_name,
  :organizer_contact,
  :source_url,
  :notes,
  NOW()
)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  starts_at = VALUES(starts_at),
  ends_at = VALUES(ends_at),
  all_day = VALUES(all_day),
  venue_name = VALUES(venue_name),
  venue_addr = VALUES(venue_addr),
  organizer_name = VALUES(organizer_name),
  organizer_contact = VALUES(organizer_contact),
  source_url = VALUES(source_url),
  notes = VALUES(notes),
  fetched_at = NOW(),
  updated_at = NOW()
SQL;

$st = $pdo->prepare($sql);

$ok = 0;
$fail = 0;

$pdo->beginTransaction();
try {
  foreach ($items as $it) {
    if (!is_array($it)) continue;

    $source = norm($it['source'] ?? null, 30);
    $sourceId = norm($it['source_id'] ?? null, 120);
    $title = norm($it['title'] ?? null, 200);

    if ($source === null || $sourceId === null || $title === null) {
      $fail++;
      continue;
    }

    $startsAt = parse_dt($it['starts_at'] ?? null);
    $endsAt   = parse_dt($it['ends_at'] ?? null);
    $allDay   = (int)($it['all_day'] ?? 0) ? 1 : 0;

    $venueName = norm($it['venue_name'] ?? null, 200);
    $venueAddr = norm($it['venue_addr'] ?? null, 255);

    $orgName = norm($it['organizer_name'] ?? null, 200);

    $orgContact = $it['organizer_contact'] ?? null;
    $orgContact = is_string($orgContact) ? trim($orgContact) : null;
    if ($orgContact !== null && $orgContact !== '') {
      $orgContact = preg_replace('/\s+/u', ' ', $orgContact);
    } else {
      $orgContact = null;
    }

    $sourceUrl = norm($it['source_url'] ?? null, 255);

    $notes = $it['notes'] ?? null;
    $notes = is_string($notes) ? trim($notes) : null;
    if ($notes !== null && $notes !== '') {
      $notes = preg_replace('/\s+/u', ' ', $notes);
    } else {
      $notes = null;
    }

    $st->execute([
      'store_id' => $storeId,
      'source' => $source,
      'source_id' => $sourceId,
      'title' => $title,
      'starts_at' => $startsAt,
      'ends_at' => $endsAt,
      'all_day' => $allDay,
      'venue_name' => $venueName,
      'venue_addr' => $venueAddr,
      'organizer_name' => $orgName,
      'organizer_contact' => $orgContact,
      'source_url' => $sourceUrl,
      'notes' => $notes,
    ]);

    $ok++;
    if (($ok % 200) === 0) echo "[".now_str()."] upserted={$ok}\n";
  }

  $pdo->commit();
  echo "[".now_str()."] done ok={$ok} fail={$fail}\n";
  exit(0);

} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "[".now_str()."] ERROR: ".$e->getMessage()."\n");
  exit(1);
}
