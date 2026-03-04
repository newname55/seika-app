<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/events/fetchers.php';

/**
 * haruto_core互換版 events_sync
 * - event_sources(code) を upsert して source_id(int) を取得
 * - events(source_id, external_id, ...) に upsert
 *
 * CLI: php public/api/events_sync.php
 * Web: require_admin() を通す（必要なら）
 */

function is_cli(): bool { return PHP_SAPI === 'cli'; }

function json_out(array $a, int $code = 200): never {
  if (!is_cli()) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  // CLIは見やすく
  if ($code >= 400) {
    fwrite(STDERR, "ERROR: " . ($a['error'] ?? 'unknown') . "\n");
    exit(1);
  }
  echo "OK: " . json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
  exit(0);
}

function dt_window(): array {
  $today = new DateTimeImmutable('today');
  $fromDt = $today->modify('-7 days');
  $toDt   = $today->modify('+90 days');
  return [$fromDt, $toDt];
}

function upsert_source(PDO $pdo, string $code, string $name, ?string $base_url): int {
  $sql = "INSERT INTO event_sources (code, name, base_url, is_active)
          VALUES (:code,:name,:base_url,1)
          ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            base_url = VALUES(base_url),
            is_active = 1";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':code' => $code,
    ':name' => $name,
    ':base_url' => $base_url,
  ]);

  $st = $pdo->prepare("SELECT source_id FROM event_sources WHERE code = :code");
  $st->execute([':code' => $code]);
  return (int)$st->fetchColumn();
}

function fingerprint(string $title, ?string $starts_at, ?string $venue, ?string $addr): string {
  $t = mb_strtolower(trim($title));
  $s = $starts_at ? substr($starts_at, 0, 16) : '';
  $v = mb_strtolower(trim((string)$venue));
  $a = mb_strtolower(trim((string)$addr));
  return hash('sha256', $t . '|' . $s . '|' . $v . '|' . $a);
}

function upsert_event(PDO $pdo, array $e): void {
  // haruto_core schema
  $sql = "INSERT INTO events (
            store_id, source_id, external_id, source_url, source_list_url,
            title, description, starts_at, ends_at, all_day, timezone,
            venue_name, address, city, prefecture, lat, lng,
            organizer_name, contact_name, contact_tel, contact_email,
            status, fingerprint, fetched_at
          ) VALUES (
            :store_id, :source_id, :external_id, :source_url, :source_list_url,
            :title, :description, :starts_at, :ends_at, :all_day, :timezone,
            :venue_name, :address, :city, :prefecture, :lat, :lng,
            :organizer_name, :contact_name, :contact_tel, :contact_email,
            :status, :fingerprint, NOW()
          )
          ON DUPLICATE KEY UPDATE
            store_id       = VALUES(store_id),
            source_url     = VALUES(source_url),
            source_list_url= VALUES(source_list_url),
            title          = VALUES(title),
            description    = VALUES(description),
            starts_at      = VALUES(starts_at),
            ends_at        = VALUES(ends_at),
            all_day        = VALUES(all_day),
            timezone       = VALUES(timezone),
            venue_name     = VALUES(venue_name),
            address        = VALUES(address),
            city           = VALUES(city),
            prefecture     = VALUES(prefecture),
            lat            = VALUES(lat),
            lng            = VALUES(lng),
            organizer_name = VALUES(organizer_name),
            contact_name   = VALUES(contact_name),
            contact_tel    = VALUES(contact_tel),
            contact_email  = VALUES(contact_email),
            status         = VALUES(status),
            fingerprint    = VALUES(fingerprint),
            fetched_at     = NOW()";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':store_id' => $e['store_id'] ?? null,
    ':source_id' => (int)$e['source_id'],
    ':external_id' => (string)$e['external_id'],
    ':source_url' => (string)$e['source_url'],
    ':source_list_url' => $e['source_list_url'] ?? null,
    ':title' => (string)$e['title'],
    ':description' => $e['description'] ?? null,
    ':starts_at' => $e['starts_at'] ?? null,
    ':ends_at' => $e['ends_at'] ?? null,
    ':all_day' => (int)($e['all_day'] ?? 0),
    ':timezone' => $e['timezone'] ?? 'Asia/Tokyo',
    ':venue_name' => $e['venue_name'] ?? null,
    ':address' => $e['address'] ?? null,
    ':city' => $e['city'] ?? null,
    ':prefecture' => $e['prefecture'] ?? null,
    ':lat' => $e['lat'] ?? null,
    ':lng' => $e['lng'] ?? null,
    ':organizer_name' => $e['organizer_name'] ?? null,
    ':contact_name' => $e['contact_name'] ?? null,
    ':contact_tel' => $e['contact_tel'] ?? null,
    ':contact_email' => $e['contact_email'] ?? null,
    ':status' => $e['status'] ?? 'unknown',
    ':fingerprint' => $e['fingerprint'] ?? null,
  ]);
}

try {
  // CLIでは認証不要。Web運用するならここをON
  // if (!is_cli()) require_admin();

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  [$fromDt, $toDt] = dt_window();

  // sources
  $sourceMap = [
    'okayama_city' => ['岡山市イベント', 'https://www.city.okayama.jp/'],
    'okayama_pref' => ['岡山県イベントカレンダー', 'https://www.pref.okayama.jp/calendar/'],
    'mamakari'     => ['岡山コンベンションセンター（ママカリ）', 'https://www.mamakari.net/event/'],
    'convex'       => ['コンベックス岡山', 'https://www.convex-okayama.co.jp/event/'],
  ];

  $sourceIdByCode = [];
  foreach ($sourceMap as $code => [$name, $base]) {
    $sourceIdByCode[$code] = upsert_source($pdo, $code, $name, $base);
  }

  // fetch
  $rawEvents = fetch_all_okayama_events($fromDt, $toDt);

  $pdo->beginTransaction();
  $upserted = 0;

  foreach ($rawEvents as $it) {
    $code = (string)($it['source'] ?? '');
    if ($code === '' || !isset($sourceIdByCode[$code])) continue;

    $title = trim((string)($it['title'] ?? ''));
    if ($title === '') continue;

    $startsAt = $it['starts_at'] ?? null;
    $venue = $it['venue_name'] ?? null;
    $addr  = $it['venue_addr'] ?? null;

    $fp = fingerprint($title, $startsAt ? (string)$startsAt : null, $venue ? (string)$venue : null, $addr ? (string)$addr : null);

    // external_id は「元ソース内で一意」なら何でもOK（fetchersのsource_idを使う）
    $externalId = (string)($it['source_id'] ?? ('fp:' . $fp));

    $row = [
      'store_id' => null,
      'source_id' => $sourceIdByCode[$code],
      'external_id' => $externalId,
      'source_url' => (string)($it['source_url'] ?? ''),
      'source_list_url' => null,
      'title' => $title,
      'description' => null,
      'starts_at' => $startsAt,
      'ends_at' => $it['ends_at'] ?? null,
      'all_day' => (int)($it['all_day'] ?? 0),
      'timezone' => 'Asia/Tokyo',
      'venue_name' => $venue,
      'address' => $addr,
      'city' => '岡山市',
      'prefecture' => '岡山県',
      'lat' => null,
      'lng' => null,
      'organizer_name' => $it['organizer_name'] ?? null,
      'contact_name' => $it['organizer_contact'] ?? null,
      'contact_tel' => null,
      'contact_email' => null,
      'status' => 'scheduled',
      'fingerprint' => $fp,
    ];

    if ($row['source_url'] === '') continue;

    upsert_event($pdo, $row);
    $upserted++;
  }

  $pdo->commit();

  json_out([
    'ok' => true,
    'from' => $fromDt->format('Y-m-d'),
    'to' => $toDt->format('Y-m-d'),
    'fetched' => count($rawEvents),
    'upserted' => $upserted,
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}