<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/store.php';

const ROLE_ALL_STORE_SHIFT_VIEW = 'all_store_shift_view';

function ensure_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

function require_login(): void {
  ensure_session();
  if (!isset($_SESSION["user_id"])) {
    header("Location: /wbss/public/login.php");
    exit;
  }
}

function is_role(string $code): bool {
  ensure_session();
  return in_array($code, $_SESSION["roles"] ?? [], true);
}
/**
 * 現在ログイン中の user_id を返す
 * 未ログイン時は null
 */
function current_user_id(): ?int {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}
// ===== 権限ヘルパ（UI/導線の出し分けに使う） =====
function can_edit_master(): bool {
  return is_role('super_user') || is_role('admin');
}
function can_view_master(): bool {
  return is_role('super_user') || is_role('admin') || is_role('manager');
}
function can_view_all_store_shift(): bool {
  return is_role('super_user') || is_role('admin') || is_role(ROLE_ALL_STORE_SHIFT_VIEW);
}
function can_do_stock_ops(): bool {
  return is_role('super_user') || is_role('admin') || is_role('manager') || is_role('staff');
}

function restore_store_context_after_login(array $roles, array $storeIds): void {
  ensure_session();

  if (get_current_store_id() > 0) {
    return;
  }

  $userId = (int)($_SESSION['user_id'] ?? 0);
  if ($userId <= 0) {
    return;
  }

  $normalizedStoreIds = [];
  foreach ($storeIds as $storeId) {
    $normalizedStoreId = (int)$storeId;
    if ($normalizedStoreId > 0) {
      $normalizedStoreIds[$normalizedStoreId] = true;
    }
  }

  $canViewAllStores = in_array('super_user', $roles, true) || in_array('admin', $roles, true);
  $allowedStoreIds = $canViewAllStores ? store_fetch_active_store_ids() : array_keys($normalizedStoreIds);

  $restoredStoreId = store_restore_remembered_store_id_for_user($userId, $allowedStoreIds);
  if ($restoredStoreId > 0) {
    return;
  }

  if (count($allowedStoreIds) === 1) {
    set_current_store_id((int)$allowedStoreIds[0]);
  }
}

function is_super_user(): bool {
  return is_role("super_user");
}

function require_role(array $any_of): void {
  require_login();
  foreach ($any_of as $r) {
    if (is_role((string)$r)) return;
  }
  http_response_code(403);
  echo "Forbidden";
  exit;
}

/**
 * ID/PW ログイン
 * - password_hash が NULL/空 のユーザーはここではログイン不可（LINE限定運用のため）
 * - 成功時 users.last_login_at を更新（PW最終ログイン表示用）
 */
function login_user(string $login_id, string $password): array {
  $pdo = db();

  $st = $pdo->prepare("
    SELECT id, login_id, password_hash, display_name, is_active
    FROM users
    WHERE login_id = ?
    LIMIT 1
  ");
  $st->execute([$login_id]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if (!$u || (int)$u["is_active"] !== 1) {
    return ["ok" => false, "error" => "IDまたはパスワードが違います"];
  }

  $hash = $u["password_hash"];

  // ✅ LINE限定キャストなど password_hash が無い場合はPWログイン不可
  if ($hash === null || (string)$hash === '') {
    return ["ok" => false, "error" => "このユーザーはパスワード未設定です（LINEログインを使用してください）"];
  }

  $hash = (string)$hash;
  $ok = false;

  if (str_starts_with($hash, "sha2:")) {
    $expect = substr($hash, 5);
    $ok = hash_equals($expect, hash("sha256", $password));
    if ($ok) {
      $newHash = password_hash($password, PASSWORD_DEFAULT);
      $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
      $up->execute([$newHash, (int)$u["id"]]);
    }
  } else {
    $ok = password_verify($password, $hash);
  }

  if (!$ok) {
    return ["ok" => false, "error" => "IDまたはパスワードが違います"];
  }

  // ロール取得
  $st2 = $pdo->prepare("
    SELECT r.code, ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
  ");
  $st2->execute([(int)$u["id"]]);
  $rows = $st2->fetchAll(PDO::FETCH_ASSOC);

  $roles = [];
  $storeIds = [];
  foreach ($rows as $r) {
    $roles[] = (string)$r["code"];
    if ($r["store_id"] !== null) $storeIds[] = (int)$r["store_id"];
  }
  $roles = array_values(array_unique($roles));
  $storeIds = array_values(array_unique($storeIds));

  // ✅ セッション確立
  ensure_session();
  $_SESSION["user_id"] = (int)$u["id"];
  $_SESSION["login_id"] = (string)$u["login_id"];
  $_SESSION["display_name"] = (string)$u["display_name"];
  $_SESSION["roles"] = $roles;
  $_SESSION["store_ids"] = $storeIds;
  restore_store_context_after_login($roles, $storeIds);

  // ✅ PWログインの最終ログイン時刻（users.last_login_at）
  try {
    $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=? LIMIT 1")
        ->execute([(int)$u["id"]]);
  } catch (Throwable $e) {
    // 列が無い等でもログインは続行
  }

  return ["ok" => true];
}

/* =========================================================
 * 代理操作（impersonation）
 * ========================================================= */

function can_impersonate(): bool {
  return is_role('super_user') || is_role('admin') || is_role('manager');
}

function is_impersonating(): bool {
  ensure_session();
  return isset($_SESSION['impersonate']);
}

/**
 * 代理開始
 */
function start_impersonation(int $target_user_id, int $store_id): void {
  ensure_session();
  if (!can_impersonate()) {
    http_response_code(403);
    exit('Forbidden');
  }

  $pdo = db();

  // 監査ログ開始
  $st = $pdo->prepare("
    INSERT INTO audit_impersonations
      (actor_user_id, actor_roles, target_user_id, store_id, started_at, ip_address, user_agent)
    VALUES
      (?, ?, ?, ?, NOW(), ?, ?)
  ");
  $st->execute([
    (int)$_SESSION['user_id'],
    implode(',', $_SESSION['roles'] ?? []),
    $target_user_id,
    $store_id,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  $audit_id = (int)$pdo->lastInsertId();

  // セッションに保存
  $_SESSION['impersonate'] = [
    'audit_id' => $audit_id,
    'actor_user_id' => (int)$_SESSION['user_id'],
    'target_user_id' => $target_user_id,
    'store_id' => $store_id,
  ];
}

/**
 * 代理終了
 */
function stop_impersonation(): void {
  ensure_session();
  if (!isset($_SESSION['impersonate'])) return;

  $pdo = db();
  $audit_id = (int)$_SESSION['impersonate']['audit_id'];

  $pdo->prepare("
    UPDATE audit_impersonations
    SET ended_at = NOW()
    WHERE id = ?
  ")->execute([$audit_id]);

  unset($_SESSION['impersonate']);
}

/**
 * 現在の「実効ユーザーID」
 * （代理中はキャストID）
 */
function effective_user_id(): int {
  ensure_session();
  if (isset($_SESSION['impersonate']['target_user_id'])) {
    return (int)$_SESSION['impersonate']['target_user_id'];
  }
  return (int)$_SESSION['user_id'];
}

/**
 * UI表示用
 */
function impersonation_banner(): ?string {
  if (!is_impersonating()) return null;
  $t = (int)$_SESSION['impersonate']['target_user_id'];
  return "⚠ 代理操作中（キャストID: {$t}）";
}

/**
 * OAuth等：IDからログイン確立（LINE/Google）
 * - users.last_login_at を更新（PWと同じ「最終ログイン」軸にするなら）
 * - updated_at は触らない（運用ログが荒れない）
 */
if (!function_exists('login_user_by_id')) {
  function login_user_by_id(int $userId): void {
    $pdo = db();

    $st = $pdo->prepare("SELECT id, login_id, display_name, is_active FROM users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['is_active'] !== 1) {
      http_response_code(403);
      exit('User disabled');
    }

    $st2 = $pdo->prepare("
      SELECT r.code, ur.store_id
      FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id
      WHERE ur.user_id = ?
    ");
    $st2->execute([$userId]);

    $roles = [];
    $storeIds = [];
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $roles[] = (string)$r['code'];
      if ($r['store_id'] !== null) $storeIds[] = (int)$r['store_id'];
    }

    ensure_session();
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['login_id'] = (string)$u['login_id'];
    $_SESSION['display_name'] = (string)$u['display_name'];
    $_SESSION['roles'] = array_values(array_unique($roles));
    $_SESSION['store_ids'] = array_values(array_unique($storeIds));
    restore_store_context_after_login($_SESSION['roles'], $_SESSION['store_ids']);

    // users.last_login_at があるなら更新（無くてもOK）
    try {
      $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=? LIMIT 1")
          ->execute([(int)$u['id']]);
    } catch (Throwable $e) {}
  }
}
