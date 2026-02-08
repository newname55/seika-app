<?php
declare(strict_types=1);

/**
 * see: app/bootstrap.php
 * - 共通の conf / CSRF / 日付関数を提供（既存関数があれば上書きしない）
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

/** HTML escape */
if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

/** env/const getter */
if (!function_exists('conf')) {
  function conf(string $key): string {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return is_string($v) ? $v : '';
  }
}

/** JST now */
if (!function_exists('jst_now')) {
  function jst_now(): DateTime {
    return new DateTime('now', new DateTimeZone('Asia/Tokyo'));
  }
}

/** business_date calc */
if (!function_exists('business_date_for_store')) {
  function business_date_for_store(array $storeRow, ?DateTime $now = null): string {
    $now = $now ?: jst_now();
    $cut = (string)($storeRow['business_day_start'] ?? '06:00:00'); // TIME
    $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
    if ($now < $cutDT) $now->modify('-1 day');
    return $now->format('Y-m-d');
  }
}

/** CSRF token */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['_csrf'])) {
      $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf'];
  }
}

/** CSRF verify */
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $ok = is_string($token) && $token !== ''
      && isset($_SESSION['_csrf'])
      && hash_equals((string)$_SESSION['_csrf'], $token);

    if (!$ok) {
      http_response_code(403);
      exit('CSRF blocked');
    }
  }
}