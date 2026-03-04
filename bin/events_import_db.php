<?php
declare(strict_types=1);

/**
 * bin/events_import_db.php
 *
 * Import JSON (storage/events/okayama_events.json) into haruto_core.events
 * - upsert by (source_id, external_id) unique
 * - event_sources のキー列名が環境差あっても自動対応
 */

date_default_timezone_set('Asia/Tokyo');

$root = dirname(__DIR__); // /var/www/html/seika-app

// db() 読み込み
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

$input = $argv[1] ?? ($root . '/storage/events/okayama_events.json');
if (!is_file($input)) {
  fwrite(STDERR, "input not found: {$input}\n");
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

function map_status(?string $s): string {
  $s = strtolower(trim((string)$s));
  if ($s === '') return 'scheduled';
  if (in_array($s, ['scheduled','cancelled','canceled','postponed','unknown'], true)) {
    return $s === 'canceled' ? 'cancelled' : $s;
  }
  return 'unknown';
}

/**
 * event_sources の「キー列」を自動判別する
 * - まず候補を上から順に探す
 * - 見つからなければ null（= name で代用）
 */
function detect_event_sources_key_col(PDO $pdo): ?string {
  $cands = ['key','source_key','source_code','code','slug','source','identifier'];
  $sql = "SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'event_sources'";
  $cols = [];
  foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $c) {
    $cols[strtolower((string)$c)] = (string)$c; // 元の大小を保持
  }
  foreach ($cands as $want) {
    if (isset($cols[$want])) return $cols[$want];
  }
  return null;
}

/**
 * event_sources に row を確実に用意して source_id を返す
 * - キー列があればキー列で検索/作成
 * - 無ければ name 列で検索/作成（最終手段）
 */
function resolve_source_id(PDO $pdo, string $sourceKey, ?string $keyCol): int {
  // name列はほぼ確実にある前提
  $name = match ($sourceKey) {
    'okayama_city' => '岡山市',
    'okayama_pref' => '岡山県',
    'mamakari' => 'ママカリフォーラム',
    'okayama_kanko' => '岡山観光WEB',
    default => $sourceKey,
  };

  if ($keyCol !== null) {
    // 1) keyCol で探す
    $st = $pdo->prepare("SELECT source_id FROM event_sources WHERE `{$keyCol}`=:k LIMIT 1");
    $st->execute(['k'=>$sourceKey]);
    $id = $st->fetchColumn();
    if ($id !== false) return (int)$id;

    // 2) 無ければ作る（created_at/updated_at が無い環境もあるのでカラム存在チェック）
    $colSql = "SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE()
                  AND TABLE_NAME='event_sources'";
    $cols = array_map('strtolower', $pdo->query($colSql)->fetchAll(PDO::FETCH_COLUMN));
    $hasCreated = in_array('created_at', $cols, true);
    $hasUpdated = in_array('updated_at', $cols, true);

    $fields = ["`{$keyCol}`", "`name`"];
    $vals   = [":k", ":n"];
    if ($hasCreated) { $fields[] = "`created_at`"; $vals[] = "NOW()"; }
    if ($hasUpdated) { $fields[] = "`updated_at`"; $vals[] = "NOW()"; }

    $ins = $pdo->prepare("INSERT INTO event_sources (".implode(',', $fields).") VALUES (".implode(',', $vals).")");
    $ins->execute(['k'=>$sourceKey,'n'=>$name]);

    $st2 = $pdo->prepare("SELECT source_id FROM event_sources WHERE `{$keyCol}`=:k LIMIT 1");
    $st2->execute(['k'=>$sourceKey]);
    $id2 = $st2->fetchColumn();
    if ($id2 === false) throw new RuntimeException("failed to create event_sources for {$sourceKey}");
    return (int)$id2;
  }

  // --- 最終手段: name で探す/作る ---
  $st = $pdo->prepare("SELECT source_id FROM event_sources WHERE name=:n LIMIT 1");
  $st->execute(['n'=>$name]);
  $id = $st->fetchColumn();
  if ($id !== false) return (int)$id;

  $colSql = "SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE()
                AND TABLE_NAME='event_sources'";
  $cols = array_map('strtolower', $pdo->query($colSql)->fetchAll(PDO::FETCH_COLUMN));
  $hasCreated = in_array('created_at', $cols, true);
  $hasUpdated = in_array('updated_at', $cols, true);

  $fields = ["`name`"];
  $vals   = [":n"];
  if ($hasCreated) { $fields[] = "`created_at`"; $vals[] = "NOW()"; }
  if ($hasUpdated) { $fields[] = "`updated_at`"; $vals[] = "NOW()"; }

  $ins = $pdo->prepare("INSERT INTO event_sources (".implode(',', $fields).") VALUES (".implode(',', $vals).")");
  $ins->execute(['n'=>$name]);

  $st2 = $pdo->prepare("SELECT source_id FROM event_sources WHERE name=:n LIMIT 1");
  $st2->execute(['n'=>$name]);
  $id2 = $st2->fetchColumn();
  if ($id2 === false) throw new RuntimeException("failed to create event_sources by name={$name}");
  return (int)$id2;
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

echo "[".now_str()."] load items=" . count($items) . "\n";

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// event_sources のキー列を自動判別
$keyCol = detect_event_sources_key_col($pdo);
echo "[".now_str()."] event_sources key_col=" . ($keyCol ?? '(none)') . "\n";

// upsert SQL（events の実カラムに合わせる）
$sql = <<<SQL
INSERT INTO events
(
  source_id,
  external_id,
  title,
  source_url,
  created_at,
  updated_at,
  starts_at,
  ends_at,
  all_day,
  venue_name,
  address,
  organizer_name,
  contact_name,
  status,
  prefecture,
  city
)
VALUES
(
  :source_id,
  :external_id,
  :title,
  :source_url,
  NOW(),
  NOW(),
  :starts_at,
  :ends_at,
  :all_day,
  :venue_name,
  :address,
  :organizer_name,
  :contact_name,
  :status,
  :prefecture,
  :city
)
ON DUPLICATE KEY UPDATE
  title=VALUES(title),
  source_url=VALUES(source_url),
  updated_at=NOW(),
  starts_at=VALUES(starts_at),
  ends_at=VALUES(ends_at),
  all_day=VALUES(all_day),
  venue_name=VALUES(venue_name),
  address=VALUES(address),
  organizer_name=VALUES(organizer_name),
  contact_name=VALUES(contact_name),
  status=VALUES(status),
  prefecture=VALUES(prefecture),
  city=VALUES(city)
SQL;

$st = $pdo->prepare($sql);

// source_id cache
$srcIdCache = [];

$ok=0; $skip=0; $fail=0;

$pdo->beginTransaction();
try {
  foreach ($items as $it) {
    if (!is_array($it)) { $skip++; continue; }

    $sourceKey  = (string)($it['source'] ?? '');
    $externalId = (string)($it['source_id'] ?? '');
    $title      = norm((string)($it['title'] ?? ''), 300);
    $sourceUrl  = norm((string)($it['source_url'] ?? ''), 500);

    $startsAt   = norm($it['starts_at'] ?? null, 19);
    $endsAt     = norm($it['ends_at'] ?? null, 19);
    $allDay     = (int)($it['all_day'] ?? 0);

    // ✅ list.php が starts_at 必須なので、starts_at 無しは入れない
    if ($sourceKey === '' || $externalId === '' || !$title || !$sourceUrl || !$startsAt) {
      $skip++;
      continue;
    }

    if (!isset($srcIdCache[$sourceKey])) {
      $srcIdCache[$sourceKey] = resolve_source_id($pdo, $sourceKey, $keyCol);
    }
    $sourceId = (int)$srcIdCache[$sourceKey];

    $venueName = norm($it['venue_name'] ?? null, 255);
    $address   = norm($it['venue_addr'] ?? null, 255);
    $orgName   = norm($it['organizer_name'] ?? null, 255);
    $contact   = norm($it['organizer_contact'] ?? null, 255);

    // 住所から都道府県/市をざっくり
    $prefecture = '岡山県';
    $city = null;
    $addrAll = (string)($address ?? '');
    if ($addrAll !== '') {
      if (preg_match('/(岡山市[^区市町村]*)/u', $addrAll, $m)) $city = $m[1];
    }

    $status = map_status($it['status'] ?? null);

    $params = [
      'source_id' => $sourceId,
      'external_id' => $externalId,
      'title' => $title,
      'source_url' => $sourceUrl,
      'starts_at' => $startsAt,
      'ends_at' => $endsAt ?: $startsAt,
      'all_day' => $allDay ? 1 : 0,
      'venue_name' => $venueName,
      'address' => $address,
      'organizer_name' => $orgName,
      'contact_name' => $contact,
      'status' => $status,
      'prefecture' => $prefecture,
      'city' => $city,
    ];

    $st->execute($params);
    $ok++;
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "[".now_str()."] FAIL: ".$e->getMessage()."\n");
  exit(1);
}

echo "[".now_str()."] done ok={$ok} skip={$skip} fail={$fail} sources=".count($srcIdCache)."\n";
exit(0);
