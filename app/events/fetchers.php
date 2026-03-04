<?php
declare(strict_types=1);

/**
 * app/events/fetchers.php
 *
 * Okayama event fetchers (no convex).
 *
 * Normalized item format:
 * [
 *   'source' => string,
 *   'source_id' => string,
 *   'title' => string,
 *   'starts_at' => ?string, // 'Y-m-d H:i:s' JST or null
 *   'ends_at' => ?string,   // 'Y-m-d H:i:s' JST or null
 *   'all_day' => int,       // 0/1
 *   'venue_name' => ?string,
 *   'venue_addr' => ?string,
 *   'organizer_name' => ?string,
 *   'organizer_contact' => ?string,
 *   'source_url' => string,
 *   'notes' => ?string,
 * ]
 */

date_default_timezone_set('Asia/Tokyo');

if (!function_exists('normalize_whitespace')) {
  function normalize_whitespace(string $s): string {
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string)$s);
  }
}

function http_get(string $url, int $timeoutSec = 18): string {
  $ch = curl_init($url);
  if ($ch === false) throw new RuntimeException('curl_init failed');

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => $timeoutSec,
    CURLOPT_TIMEOUT => $timeoutSec,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'haruto-events-sync/1.0',
    CURLOPT_HTTPHEADER => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language: ja,en;q=0.7',
    ],
    // DebianでCAが無い/壊れてる環境の保険（存在すれば使う）
    CURLOPT_CAINFO => file_exists('/etc/ssl/certs/ca-certificates.crt') ? '/etc/ssl/certs/ca-certificates.crt' : null,
  ]);

  $body = curl_exec($ch);
  if ($body === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException("HTTP GET failed: {$err} url={$url}");
  }

  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($code >= 400) throw new RuntimeException("HTTP {$code} for {$url}");
  if (!is_string($body) || $body === '') throw new RuntimeException("empty body url={$url}");
  return (string)$body;
}

/** Reiwa: 令和1 = 2019 */
function reiwa_to_ad(int $reiwaYear): int { return 2018 + $reiwaYear; }

function dom_from_html(string $html): DOMDocument {
  $prev = libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  // 文字化け/パース安定用
  $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  return $dom;
}

function xp_text(DOMNode $node): string {
  return trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
}

function norm_ws(string $s): string {
  $s = preg_replace('/\xC2\xA0/u', ' ', $s); // nbsp
  $s = preg_replace('/[ \t]+/u', ' ', (string)$s);
  $s = preg_replace("/\r\n|\r/u", "\n", $s);
  $s = preg_replace("/\n{3,}/u", "\n\n", $s);
  return trim($s);
}

/**
 * Parse date range strings commonly seen on Okayama sources.
 * Returns [starts_date(Y-m-d)|null, ends_date(Y-m-d)|null]
 *
 * ✅ 年なし (2/14～3/15) も対応
 */
function parse_jp_date_range(?string $s, ?DateTimeImmutable $baseYear = null): array {
  $s = normalize_whitespace((string)$s);
  if ($s === '') return [null, null];

  $baseYear = $baseYear ?: new DateTimeImmutable('now');
  $yBase = (int)$baseYear->format('Y');

  // 例: 2026年1月23日（金）～2026年2月13日（金）
  if (preg_match('/(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日.*?[～〜\-]\s*(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日/u', $s, $m)) {
    $start = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    $end   = sprintf('%04d-%02d-%02d', (int)$m[4], (int)$m[5], (int)$m[6]);
    return [$start, $end];
  }

  // 例: 2026年1月23日（金）～2月13日（金）  ← 年省略（後半）
  if (preg_match('/(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日.*?[～〜\-]\s*(\d{1,2})月\s*(\d{1,2})日/u', $s, $m)) {
    $y  = (int)$m[1];
    $sm = (int)$m[2]; $sd = (int)$m[3];
    $em = (int)$m[4]; $ed = (int)$m[5];

    $start = sprintf('%04d-%02d-%02d', $y, $sm, $sd);
    $endY = $y;
    if ($em < $sm) $endY = $y + 1; // 年またぎ
    $end = sprintf('%04d-%02d-%02d', $endY, $em, $ed);
    return [$start, $end];
  }

  // 単日: 2026年1月23日
  if (preg_match('/(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日/u', $s, $m)) {
    $d = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    return [$d, $d];
  }

  // 令和: 令和8年2月14日
  if (preg_match('/令和\s*(\d{1,2})年\s*(\d{1,2})月\s*(\d{1,2})日/u', $s, $m)) {
    $y = reiwa_to_ad((int)$m[1]);
    $d = sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
    return [$d, $d];
  }

  // ✅ 年なし: 2/14～3/15
  $s2 = str_replace(['〜','～','－','–','—'], '-', $s);
  if (preg_match('/(\d{1,2})\/(\d{1,2}).*?-\s*(\d{1,2})\/(\d{1,2})/u', $s2, $m)) {
    $sm = (int)$m[1]; $sd = (int)$m[2];
    $em = (int)$m[3]; $ed = (int)$m[4];

    $start = sprintf('%04d-%02d-%02d', $yBase, $sm, $sd);
    $endY = $yBase;
    if ($em < $sm) $endY = $yBase + 1; // 12月→1月など
    $end = sprintf('%04d-%02d-%02d', $endY, $em, $ed);
    return [$start, $end];
  }

  // ✅ 年なし単日: 2/14
  if (preg_match('/(\d{1,2})\/(\d{1,2})/u', $s, $m)) {
    $d = sprintf('%04d-%02d-%02d', $yBase, (int)$m[1], (int)$m[2]);
    return [$d, $d];
  }

  return [null, null];
}

/** Parse time like "13：30～15：00" or "13:30-15:00". Returns [start(H:i)|null, end(H:i)|null] */
function parse_jp_time_range(?string $s): array {
  $s = normalize_whitespace((string)$s);
  if ($s === '') return [null, null];

  $s = str_replace(['：','〜','～','－','–','—'], [':','-','-','-','-','-'], $s);
  $s = preg_replace('/\s+/u', '', $s);

  if (preg_match('/(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})/u', $s, $m)) {
    return [sprintf('%02d:%02d', (int)$m[1], (int)$m[2]), sprintf('%02d:%02d', (int)$m[3], (int)$m[4])];
  }
  if (preg_match('/(\d{1,2}):(\d{2})-/u', $s, $m)) {
    return [sprintf('%02d:%02d', (int)$m[1], (int)$m[2]), null];
  }
  return [null, null];
}

function combine_dt(?string $dateYmd, ?string $timeHi, string $defaultTime = '00:00'): ?string {
  if (!$dateYmd) return null;
  $t = $timeHi ?: $defaultTime;
  return $dateYmd . ' ' . $t . ':00';
}

/** Extract section text from Okayama City article by h2 heading label. */
function oky_city_section_text(DOMXPath $xp, string $label): ?string {
  $nodes = $xp->query(sprintf("//h2[contains(normalize-space(.), '%s')]", $label));
  if (!$nodes || $nodes->length === 0) return null;

  $h2 = $nodes->item(0);
  $buf = [];
  for ($n = $h2->nextSibling; $n !== null; $n = $n->nextSibling) {
    if ($n instanceof DOMElement && strtolower($n->tagName) === 'h2') break;
    $txt = trim($n->textContent ?? '');
    if ($txt !== '') $buf[] = $txt;
  }
  $out = norm_ws(implode("\n", $buf));
  return $out !== '' ? $out : null;
}

/**
 * Fetcher: Okayama City event list (weekly list -> detail pages)
 */
function fetch_okayama_city_events(DateTimeImmutable $from, DateTimeImmutable $to): array {
  $base = 'https://www.city.okayama.jp';
  $items = [];
  $seen = [];

  $cursor = $from->modify('monday this week');
  while ($cursor <= $to) {
    $url = sprintf(
      '%s/ft4/event4_list.php?event4_day=%d&event4_month=%d&event4_range=w&event4_year=%d',
      $base,
      (int)$cursor->format('j'),
      (int)$cursor->format('n'),
      (int)$cursor->format('Y')
    );

    try {
      $html = http_get($url);
    } catch (Throwable $e) {
      $cursor = $cursor->modify('+7 days');
      continue;
    }

    $dom = dom_from_html($html);
    $xp = new DOMXPath($dom);

    $as = $xp->query("//a[contains(@href, '.html')]");
    if ($as) {
      foreach ($as as $a) {
        $href = (string)$a->getAttribute('href');
        $title = xp_text($a);
        if ($title === '' || $href === '') continue;

        $detailUrl = str_starts_with($href, 'http')
          ? $href
          : ($base . (str_starts_with($href, '/') ? $href : '/' . $href));

        if (!str_contains($detailUrl, 'city.okayama.jp')) continue;
        if (str_contains($detailUrl, 'zoomsight.social.or.jp')) continue;

        $key = sha1($detailUrl);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        try {
          $dhtml = http_get($detailUrl);
        } catch (Throwable $e) {
          continue;
        }
        $ddom = dom_from_html($dhtml);
        $dxp = new DOMXPath($ddom);

        $h1 = $dxp->query('//h1')->item(0);
        $dtTitle = $h1 ? xp_text($h1) : $title;

        $when = oky_city_section_text($dxp, '開催日時');
        $where = oky_city_section_text($dxp, '開催場所');
        $org = oky_city_section_text($dxp, '主催者') ?? oky_city_section_text($dxp, '主催者・共催者');
        $contact = oky_city_section_text($dxp, '連絡先') ?? oky_city_section_text($dxp, '連絡先・お問い合わせ');

        [$sd, $ed] = $when ? parse_jp_date_range($when, $from) : [null, null];

        $allDay = 1;
        $startsAt = null;
        $endsAt = null;

        if ($sd) {
          [$st, $et] = $when ? parse_jp_time_range($when) : [null, null];
          if ($st) {
            $allDay = 0;
            $startsAt = combine_dt($sd, $st, '00:00');
            $endsAt = combine_dt($ed ?? $sd, $et, $st);
          } else {
            $startsAt = combine_dt($sd, null, '00:00');
            $endsAt = combine_dt($ed ?? $sd, null, '23:59');
          }

          $sdDt = new DateTimeImmutable($sd);
          $edDt = new DateTimeImmutable($ed ?? $sd);
          if ($edDt < new DateTimeImmutable($from->format('Y-m-d')) || $sdDt > new DateTimeImmutable($to->format('Y-m-d'))) {
            continue;
          }
        } else {
          $allDay = 0; // 日付が取れない場合は終日扱いにせず
        }

        $items[] = [
          'source' => 'okayama_city',
          'source_id' => (string)preg_replace('/\D+/', '', (string)parse_url($detailUrl, PHP_URL_PATH)) ?: $detailUrl,
          'title' => $dtTitle,
          'starts_at' => $startsAt,
          'ends_at' => $endsAt,
          'all_day' => $allDay,
          'venue_name' => $where,
          'venue_addr' => null,
          'organizer_name' => $org,
          'organizer_contact' => $contact,
          'source_url' => $detailUrl,
          'notes' => null,
        ];
      }
    }

    $cursor = $cursor->modify('+7 days');
  }

  return $items;
}

/**
 * Fetcher: Okayama Prefecture calendar by month param (?month=2&year=YYYY)
 *
 * ✅ 修正点:
 * - 県カレンダーはタイトルに "(2/14～3/15)" が入ることが多い
 * - ctxだけでなく title+ctx を日付ソースとして解析
 * - parse_jp_date_range に baseYear(from) を渡して年推定
 */
function fetch_okayama_pref_events(DateTimeImmutable $from, DateTimeImmutable $to): array {
  $items = [];

  $m = new DateTimeImmutable($from->format('Y-m-01'));
  $endMonth = new DateTimeImmutable($to->format('Y-m-01'));

  while ($m <= $endMonth) {
    $url = sprintf('https://www.pref.okayama.jp/calendar/?month=%d&year=%d', (int)$m->format('n'), (int)$m->format('Y'));

    try {
      $html = http_get($url);
    } catch (Throwable $e) {
      $m = $m->modify('+1 month');
      continue;
    }

    $dom = dom_from_html($html);
    $xp = new DOMXPath($dom);

    $links = $xp->query("//a[contains(@href, '/site/') and (contains(@href, '.html') or contains(@href, '.htm'))]");
    if (!$links) {
      $m = $m->modify('+1 month');
      continue;
    }

    foreach ($links as $a) {
      $title = xp_text($a);
      $href = (string)$a->getAttribute('href');
      if ($title === '' || $href === '') continue;

      $eventUrl = str_starts_with($href, 'http')
        ? $href
        : ('https://www.pref.okayama.jp' . (str_starts_with($href, '/') ? $href : '/' . $href));

      $block = $a->parentNode;
      for ($i=0; $i<4 && $block !== null; $i++) {
        if ($block instanceof DOMElement && in_array(strtolower($block->tagName), ['li','div','section','article'], true)) break;
        $block = $block->parentNode;
      }
      $ctx = $block ? norm_ws($block->textContent ?? '') : '';

      $dateSrc = trim($title . ' ' . $ctx);

      [$sd, $ed] = parse_jp_date_range($dateSrc, $from);

      if ($sd) {
        $sdDt = new DateTimeImmutable($sd);
        $edDt = new DateTimeImmutable($ed ?? $sd);
        if ($edDt < new DateTimeImmutable($from->format('Y-m-d')) || $sdDt > new DateTimeImmutable($to->format('Y-m-d'))) {
          continue;
        }
      } else {
        // 日付が一切取れないのはDBに入れても一覧が出ないのでスキップ
        continue;
      }

      // 時間らしきもの（ctx優先）
      $time = null;
      if (preg_match('/(\d{1,2}[:：]\d{2}.*?[～〜\-].*?\d{1,2}[:：]\d{2})/u', $ctx, $mm)) {
        $time = $mm[1];
      } elseif (preg_match('/(\d{1,2}時\d{0,2}分?.{0,3}\d{1,2}時\d{0,2}分?)/u', $ctx, $mm)) {
        $time = $mm[1];
      }
      [$st, $et] = $time ? parse_jp_time_range($time) : [null, null];

      $startsAt = null; $endsAt = null; $allDay = 1;
      if ($sd) {
        if ($st) {
          $allDay = 0;
          $startsAt = combine_dt($sd, $st, '00:00');
          $endsAt = combine_dt($ed ?? $sd, $et, $st);
        } else {
          $startsAt = combine_dt($sd, null, '00:00');
          $endsAt = combine_dt($ed ?? $sd, null, '23:59');
        }
      }

      $venue = null;
      if (preg_match('/開催場所\s*([^\n]+?)(?:お問い合わせ|$)/u', str_replace("\n", ' ', $ctx), $mm)) {
        $venue = trim($mm[1]);
      }
      if (!$venue) {
        if (preg_match('/（[^）]+）/u', $ctx, $mm)) $venue = trim($mm[0]);
      }

      $org = null;
      if (preg_match('/お問い合わせ\s*([^\n]+)$/u', $ctx, $mm)) $org = trim($mm[1]);

      $items[] = [
        'source' => 'okayama_pref',
        'source_id' => sha1($eventUrl),
        'title' => $title,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'all_day' => $allDay,
        'venue_name' => $venue,
        'venue_addr' => null,
        'organizer_name' => $org,
        'organizer_contact' => null,
        'source_url' => $eventUrl,
        'notes' => null,
      ];
    }

    $m = $m->modify('+1 month');
  }

  return $items;
}

/**
 * Fetcher: Mamakari forum monthly detail page (?month=M&year=Y)
 */
function fetch_mamakari_events(DateTimeImmutable $from, DateTimeImmutable $to): array {
  $items = [];

  $m = new DateTimeImmutable($from->format('Y-m-01'));
  $endMonth = new DateTimeImmutable($to->format('Y-m-01'));

  while ($m <= $endMonth) {
    $url = sprintf('https://www.mamakari.net/event/detail?month=%d&year=%d', (int)$m->format('n'), (int)$m->format('Y'));

    try {
      $html = http_get($url);
    } catch (Throwable $e) {
      $m = $m->modify('+1 month');
      continue;
    }

    $dom = dom_from_html($html);
    $xp = new DOMXPath($dom);

    $h4s = $xp->query('//h4');
    if (!$h4s) {
      $m = $m->modify('+1 month');
      continue;
    }

    foreach ($h4s as $h4) {
      $title = xp_text($h4);
      if ($title === '') continue;

      $buf = [];
      for ($n = $h4->nextSibling; $n !== null; $n = $n->nextSibling) {
        if ($n instanceof DOMElement && strtolower($n->tagName) === 'h4') break;
        $t = trim($n->textContent ?? '');
        if ($t !== '') $buf[] = $t;
      }
      $ctx = norm_ws(implode("\n", $buf));

      $dateLine = null;
      if (preg_match('/開催日\s*([^\n]+)/u', $ctx, $mm)) $dateLine = trim($mm[1]);
      [$sd, $ed] = $dateLine ? parse_jp_date_range($dateLine, $from) : [null, null];

      $timeLine = null;
      if (preg_match('/\n(\d{1,2}[:：]\d{2}.*?)(?:\n|$)/u', "\n".$ctx, $mm)) $timeLine = trim($mm[1]);
      [$st, $et] = $timeLine ? parse_jp_time_range($timeLine) : [null, null];

      $venue = null;
      if (preg_match('/会場\s*([^\n]+)/u', $ctx, $mm)) $venue = trim($mm[1]);

      $org = null;
      if (preg_match('/主催\s*([^\n]+)/u', $ctx, $mm)) $org = trim($mm[1]);

      $contact = null;
      if (preg_match('/連絡先\s*([^\n]+(?:\n[^\n]+){0,6})/u', $ctx, $mm)) $contact = trim($mm[1]);

      $startsAt = null; $endsAt = null; $allDay = 1;
      if ($sd) {
        $sdDt = new DateTimeImmutable($sd);
        $edDt = new DateTimeImmutable($ed ?? $sd);
        if ($edDt < new DateTimeImmutable($from->format('Y-m-d')) || $sdDt > new DateTimeImmutable($to->format('Y-m-d'))) {
          continue;
        }

        if ($st) {
          $allDay = 0;
          $startsAt = combine_dt($sd, $st, '00:00');
          $endsAt = combine_dt($ed ?? $sd, $et, $st);
        } else {
          $startsAt = combine_dt($sd, null, '00:00');
          $endsAt = combine_dt($ed ?? $sd, null, '23:59');
        }
      } else {
        // 日付無しは一覧に出ないのでスキップ
        continue;
      }

      $items[] = [
        'source' => 'mamakari',
        'source_id' => sha1($url . '|' . $title . '|' . ($sd ?? '')),
        'title' => $title,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'all_day' => $allDay,
        'venue_name' => $venue,
        'venue_addr' => '岡山県岡山市北区駅元町14-1（岡山コンベンションセンター）',
        'organizer_name' => $org,
        'organizer_contact' => $contact,
        'source_url' => $url,
        'notes' => null,
      ];
    }

    $m = $m->modify('+1 month');
  }

  return $items;
}

// ===== okayama-kanko.jp fetcher =====

function okayama_kanko_http_get(string $url, int $timeout = 18): string {
  return http_get($url, $timeout);
}

function okayama_kanko_norm(string $s): string {
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim((string)$s);
}

function okayama_kanko_parse_date_range(?string $s, DateTimeImmutable $baseYear): array {
  return parse_jp_date_range($s, $baseYear);
}

function okayama_kanko_parse_time_range(?string $s): array {
  return parse_jp_time_range($s);
}

function okayama_kanko_collect_detail_urls(string $html): array {
  $urls = [];
  if (preg_match_all('#/event/(detail_[0-9A-Za-z_]+\.html)#u', $html, $m)) {
    foreach ($m[1] as $file) $urls[] = 'https://www.okayama-kanko.jp/event/' . $file;
  }
  return array_values(array_unique($urls));
}

function fetch_okayama_kanko_detail(string $url, DateTimeImmutable $baseYear): ?array {
  $html = okayama_kanko_http_get($url);
  $dom  = dom_from_html($html);

  // ✅ title: detail は最初の <h2> がイベント名（ダメなら h1 → title）
  $title = null;

  // 1) h2
  foreach ($dom->getElementsByTagName('h2') as $h2) {
    $t = okayama_kanko_norm($h2->textContent ?? '');
    if ($t !== '') { $title = $t; break; }
  }

  // 2) h1 fallback
  if (!$title) {
    foreach ($dom->getElementsByTagName('h1') as $h1) {
      $t = okayama_kanko_norm($h1->textContent ?? '');
      if ($t !== '') { $title = $t; break; }
    }
  }

  // 3) <title> fallback
  if (!$title) {
    $ts = $dom->getElementsByTagName('title');
    if ($ts && $ts->length > 0) {
      $t = okayama_kanko_norm($ts->item(0)->textContent ?? '');
      if ($t !== '') $title = $t;
    }
  }

  if (!$title) return null;

  $text = okayama_kanko_norm($dom->textContent ?? '');

  $dateText = null;
  if (preg_match('/開催期間\s*(.+?)開催時間/u', $text, $m)) $dateText = trim($m[1]);
  if (!$dateText && preg_match('/開催期間\s*(.+?)開催場所/u', $text, $m)) $dateText = trim($m[1]);

  $timeText = null;
  if (preg_match('/開催時間\s*(.+?)開催場所/u', $text, $m)) $timeText = trim($m[1]);

  $placeText = null;
  if (preg_match('/開催場所\s*(.+?)所在地/u', $text, $m)) $placeText = trim($m[1]);

  $addrText = null;
  if (preg_match('/所在地\s*(.+?)電話番号/u', $text, $m)) $addrText = trim($m[1]);

  $telText = null;
  if (preg_match('/電話番号\s*(.+?)(?:車でのアクセス|公共交通機関でのアクセス|ウェブサイト|$)/u', $text, $m)) $telText = trim($m[1]);

  [$sd, $ed] = okayama_kanko_parse_date_range($dateText, $baseYear);
  [$t1, $t2] = okayama_kanko_parse_time_range($timeText);

  if (!$sd) return null;

  $all_day = (!$t1 && !$t2) ? 1 : 0;

  if ($all_day) {
    $starts_at = $sd . ' 00:00:00';
    $ends_at   = ($ed ?? $sd) . ' 23:59:59';
  } else {
    $starts_at = $sd . ' ' . ($t1 ?? '00:00') . ':00';
    $ends_at   = ($ed ?? $sd) . ' ' . ($t2 ?? ($t1 ?? '23:59')) . ':00';
  }

  $source_id = null;
  if (preg_match('#/event/(detail_[0-9A-Za-z_]+)\.html#', $url, $m)) $source_id = $m[1];
  if (!$source_id) $source_id = 'url:' . $url;

  return [
    'source' => 'okayama_kanko',
    'source_id' => $source_id,
    'title' => $title,
    'starts_at' => $starts_at,
    'ends_at' => $ends_at,
    'all_day' => $all_day,
    'venue_name' => $placeText ? okayama_kanko_norm($placeText) : null,
    'venue_addr' => $addrText ? okayama_kanko_norm($addrText) : null,
    'organizer_name' => null,
    'organizer_contact' => $telText ? okayama_kanko_norm($telText) : null,
    'source_url' => $url,
    'notes' => null,
  ];
}

function fetch_okayama_kanko_events(DateTimeImmutable $fromDt, DateTimeImmutable $toDt, int $max_pages = 10, int $sleep_ms = 250): array {
  $out = [];
  $seen = [];

  for ($page=1; $page<=$max_pages; $page++) {
    $listUrl = ($page===5)
      ? "https://www.okayama-kanko.jp/event/index.html"
      : "https://www.okayama-kanko.jp/event/index_{$page}_1_1___0______.html";

    try {
      $html = okayama_kanko_http_get($listUrl);
    } catch (Throwable $e) {
      break;
    }

    $detailUrls = okayama_kanko_collect_detail_urls($html);
    if (!$detailUrls) break;

    foreach ($detailUrls as $u) {
      if (isset($seen[$u])) continue;
      $seen[$u]=true;

      try {
        $ev = fetch_okayama_kanko_detail($u, $fromDt);
      } catch (Throwable $e) {
        continue;
      }
      if (!$ev) continue;

      $sd = new DateTimeImmutable((string)$ev['starts_at']);
      $ed = !empty($ev['ends_at']) ? new DateTimeImmutable((string)$ev['ends_at']) : $sd;
      if ($ed < $fromDt || $sd > $toDt) continue;

      $out[] = $ev;
      if ($sleep_ms>0) usleep($sleep_ms*1000);
    }
  }
  return $out;
}

/** master */
function fetch_all_okayama_events(DateTimeImmutable $from, DateTimeImmutable $to): array {
  $all = [];
  $fetchers = [
    fn() => fetch_okayama_city_events($from, $to),
    fn() => fetch_okayama_pref_events($from, $to),
    fn() => fetch_mamakari_events($from, $to),
    fn() => fetch_okayama_kanko_events($from, $to, 2, 0), // heavy: cap pages
  ];

  foreach ($fetchers as $fx) {
    try {
      $res = $fx();
      if (is_array($res)) $all = array_merge($all, $res);
    } catch (Throwable $e) {
      continue;
    }
  }

  $out = [];
  foreach ($all as $it) {
    $title = trim((string)($it['title'] ?? ''));
    if ($title === '') continue;
    if (empty($it['starts_at'])) continue; // ✅ DB一覧に出ないので確実に除外
    $it['title'] = $title;
    $out[] = $it;
  }
  return $out;
}