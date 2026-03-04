<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

// store.php がある環境に対応
$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** --- CSRF --- */
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  return (string)$_SESSION['_csrf'];
}
function csrf_verify(?string $token): void {
  if (!$token || empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], (string)$token)) {
    http_response_code(403);
    exit('csrf');
  }
}

/** --- store_id 解決（store.php の関数シグネチャ違いでも落ちない） --- */
function resolve_store_id(PDO $pdo): int {
  // 1) current_store_id() があれば最優先
  if (function_exists('current_store_id')) {
    $sid = (int)current_store_id();
    if ($sid > 0) return $sid;
  }

  // 2) require_store_selected の引数有無に対応
  if (function_exists('require_store_selected')) {
    try {
      $rf = new ReflectionFunction('require_store_selected');
      $need = $rf->getNumberOfRequiredParameters();
      if ($need >= 1) {
        $sid = (int)require_store_selected($pdo);
      } else {
        $sid = (int)require_store_selected();
      }
      if ($sid > 0) return $sid;
    } catch (Throwable $e) {
      // フォールバックへ
    }
  }

  // 3) フォールバック：GET → SESSION
  $sid = (int)($_GET['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);

  // 4) それでも無いなら店舗選択へ
  if ($sid <= 0) {
    header('Location: /seika-app/public/store_select.php?next=' . urlencode('/seika-app/public/admin/cast_edit.php'));
    exit;
  }

  $_SESSION['store_id'] = $sid;
  return $sid;
}

$store_id = resolve_store_id($pdo);

/** --- store name --- */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$store_id]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$store_id));

/** --- roles: cast の role_id（無い場合は store_users/cast_profiles を主軸で表示） --- */
$castRoleId = null;
try {
  $st = $pdo->prepare("SELECT id FROM roles WHERE name='cast' LIMIT 1");
  $st->execute();
  $v = $st->fetchColumn();
  if ($v !== false) $castRoleId = (int)$v;
} catch (Throwable $e) {
  $castRoleId = null;
}

/** --- store_users テーブル存在チェック --- */
$hasStoreUsers = false;
try {
  $st = $pdo->prepare("SHOW TABLES LIKE 'store_users'");
  $st->execute();
  $hasStoreUsers = (bool)$st->fetchColumn();
} catch (Throwable $e) {
  $hasStoreUsers = false;
}

$msg = '';
$err = '';

/** login_id 自動生成（衝突回避） */
function generate_login_id(PDO $pdo, int $store_id): string {
  // 50文字制限内に収める
  for ($i=0; $i<12; $i++) {
    $suffix = bin2hex(random_bytes(3)); // 6 chars
    $base = 'cast_'.$store_id.'_'.date('ymdHis').'_'.$suffix;
    $login_id = substr($base, 0, 50);

    $st = $pdo->prepare("SELECT 1 FROM users WHERE login_id=? LIMIT 1");
    $st->execute([$login_id]);
    if ($st->fetchColumn() === false) return $login_id;
  }
  // 最後の手段
  return substr('cast_'.$store_id.'_'.bin2hex(random_bytes(10)), 0, 50);
}

/** --- POST --- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_verify($_POST['csrf_token'] ?? null);

  $action = (string)($_POST['action'] ?? '');
  if ($action === 'update') {
    $store_user_id  = (int)($_POST['store_user_id'] ?? 0);
    $user_id        = (int)($_POST['user_id'] ?? 0);
    $display_name   = trim((string)($_POST['display_name'] ?? ''));
    $employment_type = (string)($_POST['employment_type'] ?? 'part'); // store_users側
    $staff_code     = trim((string)($_POST['staff_code'] ?? ''));

    if ($store_user_id <= 0 || $user_id <= 0) {
      $err = 'IDが不正です';
    } elseif ($display_name === '') {
      $err = '名前が空です';
    } elseif (!in_array($employment_type, ['regular','part','trial','support'], true)) {
      $err = '雇用区分が不正です';
    } else {
      try {
        $pdo->beginTransaction();

        // users.display_name 更新
        $st = $pdo->prepare("UPDATE users SET display_name=? WHERE id=? LIMIT 1");
        $st->execute([$display_name, $user_id]);

        if ($hasStoreUsers) {
          $st = $pdo->prepare("
            UPDATE store_users
               SET staff_code=?,
                   employment_type=?,
                   updated_at=NOW()
             WHERE id=? AND store_id=? AND user_id=?
             LIMIT 1
          ");
          $st->execute([$staff_code === '' ? null : $staff_code, $employment_type, $store_user_id, $store_id, $user_id]);
        }

        // 互換：既存コードが cast_profiles.shop_tag / employment_type を参照している場合に備えて同期
        try {
          $sql = "
            INSERT INTO cast_profiles (user_id, store_id, employment_type, shop_tag, updated_at, created_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              employment_type=VALUES(employment_type),
              shop_tag=VALUES(shop_tag),
              updated_at=NOW()
          ";
          $st = $pdo->prepare($sql);
          $st->execute([$user_id, $store_id, $employment_type, $staff_code]);
        } catch (Throwable $e) {
          // cast_profiles が無い/違う環境は無視
        }

        $pdo->commit();
        $msg = '更新しました';
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = '更新に失敗: ' . $e->getMessage();
      }
    }
  }

  if ($action === 'add_cast') {
    $display_name    = trim((string)($_POST['display_name'] ?? ''));
    $employment_type = (string)($_POST['employment_type'] ?? 'part');
    $staff_code      = trim((string)($_POST['staff_code'] ?? ''));

    if ($display_name === '') {
      $err = '名前が空です';
    } elseif (!in_array($employment_type, ['regular','part','trial','support'], true)) {
      $err = '雇用区分が不正です';
    } elseif (!$hasStoreUsers) {
      $err = 'store_users テーブルが見つかりません（先にDB作成してください）';
    } else {
      try {
        $pdo->beginTransaction();

        // users作成（login_id / password_hash がNOT NULLのため自動生成）
        $login_id = generate_login_id($pdo, $store_id);
        $plain = bin2hex(random_bytes(16));
        $hash = password_hash($plain, PASSWORD_DEFAULT);

        // users.employment_type は enum(regular,part) なので、trial/support は part へ寄せる
        $userEmployment = ($employment_type === 'regular') ? 'regular' : 'part';

        $st = $pdo->prepare("
          INSERT INTO users (login_id, password_hash, display_name, employment_type, is_active, created_at, updated_at)
          VALUES (?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $st->execute([$login_id, $hash, $display_name, $userEmployment]);
        $user_id = (int)$pdo->lastInsertId();

        // store_users 作成
        $st = $pdo->prepare("
          INSERT INTO store_users (store_id, user_id, staff_code, employment_type, status, created_at, updated_at)
          VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
        ");
        $st->execute([$store_id, $user_id, $staff_code === '' ? null : $staff_code, $employment_type]);
        $store_user_id = (int)$pdo->lastInsertId();

        // castロールを付与（ある環境のみ）
        if ($castRoleId !== null) {
          try {
            $st = $pdo->prepare("
              INSERT INTO user_roles (user_id, role_id, store_id, created_at)
              VALUES (?, ?, ?, NOW())
            ");
            $st->execute([$user_id, $castRoleId, $store_id]);
          } catch (Throwable $e) {
            // user_rolesが無い/ユニーク制約等は無視（後で別画面で付与しても良い）
          }
        }

        // 互換：cast_profiles にも作成
        try {
          $st = $pdo->prepare("
            INSERT INTO cast_profiles (user_id, store_id, employment_type, shop_tag, updated_at, created_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              employment_type=VALUES(employment_type),
              shop_tag=VALUES(shop_tag),
              updated_at=NOW()
          ");
          $st->execute([$user_id, $store_id, $employment_type, $staff_code]);
        } catch (Throwable $e) {
          // ignore
        }

        $pdo->commit();
        $msg = '追加しました';
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = '追加に失敗: ' . $e->getMessage();
      }
    }
  }

  if ($action === 'retire') {
    $store_user_id = (int)($_POST['store_user_id'] ?? 0);
    $reason = trim((string)($_POST['retired_reason'] ?? ''));

    if ($store_user_id <= 0) {
      $err = 'IDが不正です';
    } elseif (!$hasStoreUsers) {
      $err = 'store_users テーブルが見つかりません';
    } else {
      try {
        $st = $pdo->prepare("
          UPDATE store_users
             SET status='retired',
                 retired_at=NOW(),
                 retired_reason=?,
                 updated_at=NOW()
           WHERE id=? AND store_id=?
           LIMIT 1
        ");
        $st->execute([$reason === '' ? null : $reason, $store_user_id, $store_id]);
        $msg = '退店にしました';
      } catch (Throwable $e) {
        $err = '退店に失敗: ' . $e->getMessage();
      }
    }
  }

  if ($action === 'reinstate') {
    $store_user_id = (int)($_POST['store_user_id'] ?? 0);

    if ($store_user_id <= 0) {
      $err = 'IDが不正です';
    } elseif (!$hasStoreUsers) {
      $err = 'store_users テーブルが見つかりません';
    } else {
      try {
        $st = $pdo->prepare("
          UPDATE store_users
             SET status='active',
                 retired_at=NULL,
                 retired_reason=NULL,
                 updated_at=NOW()
           WHERE id=? AND store_id=?
           LIMIT 1
        ");
        $st->execute([$store_user_id, $store_id]);
        $msg = '在籍に戻しました';
      } catch (Throwable $e) {
        $err = '復帰に失敗: ' . $e->getMessage();
      }
    }
  }
}

/** --- 一覧取得 --- */
$q = trim((string)($_GET['q'] ?? ''));
$show_retired = (int)($_GET['show_retired'] ?? 0) === 1;

$rows = [];
try {
  if ($hasStoreUsers) {
    // store_users + users (+ cast_profiles互換)
    // role有り/無しでSQLを分ける（bindを安全に）
    if ($castRoleId !== null) {
      $bind = [$castRoleId, $store_id, $store_id, $store_id];
      $where = "su.store_id=? ";
      if (!$show_retired) $where .= " AND su.status='active' ";
      if ($q !== '') {
        $where .= " AND (COALESCE(su.staff_code, cp.shop_tag, '') LIKE ? OR u.display_name LIKE ?) ";
        $like = '%'.$q.'%';
        $bind[] = $like;
        $bind[] = $like;
      }

      $sql = "
        SELECT
          su.id AS store_user_id,
          u.id AS user_id,
          u.display_name,
          COALESCE(su.employment_type, cp.employment_type, 'part') AS employment_type,
          COALESCE(su.staff_code, cp.shop_tag, '') AS staff_code,
          su.status,
          su.retired_at,
          su.retired_reason
        FROM store_users su
        INNER JOIN users u ON u.id=su.user_id
        INNER JOIN user_roles ur
          ON ur.user_id=u.id AND ur.role_id=? AND (ur.store_id=? OR ur.store_id IS NULL)
        LEFT JOIN cast_profiles cp
          ON cp.user_id=u.id AND cp.store_id=?
        WHERE {$where}
        ORDER BY
          CASE WHEN su.status='active' THEN 0 ELSE 1 END,
          CAST(NULLIF(COALESCE(su.staff_code, cp.shop_tag), _utf8mb4'' COLLATE utf8mb4_bin) AS UNSIGNED) ASC,
          COALESCE(su.staff_code, cp.shop_tag, _utf8mb4'' COLLATE utf8mb4_bin) ASC,
          u.display_name ASC
        LIMIT 800
      ";
      $st = $pdo->prepare($sql);
      $st->execute($bind);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $where = "su.store_id=? ";
      $bind = [$store_id];
      if (!$show_retired) $where .= " AND su.status='active' ";
      if ($q !== '') {
        $where .= " AND (COALESCE(su.staff_code, cp.shop_tag, '') LIKE ? OR u.display_name LIKE ?) ";
        $like = '%'.$q.'%';
        $bind[] = $like;
        $bind[] = $like;
      }

      $sql = "
        SELECT
          su.id AS store_user_id,
          u.id AS user_id,
          u.display_name,
          COALESCE(su.employment_type, cp.employment_type, 'part') AS employment_type,
          COALESCE(su.staff_code, cp.shop_tag, '') AS staff_code,
          su.status,
          su.retired_at,
          su.retired_reason
        FROM store_users su
        INNER JOIN users u ON u.id=su.user_id
        INNER JOIN cast_profiles cp
          ON cp.user_id=u.id AND cp.store_id=?
        WHERE {$where}
        ORDER BY
          CASE WHEN su.status='active' THEN 0 ELSE 1 END,
          CAST(NULLIF(COALESCE(su.staff_code, cp.shop_tag), _utf8mb4'' COLLATE utf8mb4_bin) AS UNSIGNED) ASC,
          COALESCE(su.staff_code, cp.shop_tag, _utf8mb4'' COLLATE utf8mb4_bin) ASC,
          u.display_name ASC
        LIMIT 800
      ";
      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$store_id], $bind));  // cp.store_id=? を先頭に
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } else {
    // まだstore_usersが無い → 旧ロジック（表示だけ）
    if ($castRoleId !== null) {
      $sql = "
        SELECT
          u.id AS user_id,
          u.display_name,
          cp.employment_type,
          cp.shop_tag AS staff_code,
          'active' AS status,
          NULL AS retired_at,
          NULL AS store_user_id,
          NULL AS retired_reason
        FROM users u
        INNER JOIN user_roles ur
          ON ur.user_id=u.id AND ur.role_id=? AND (ur.store_id=? OR ur.store_id IS NULL)
        LEFT JOIN cast_profiles cp
          ON cp.user_id=u.id AND cp.store_id=?
        WHERE u.is_active=1
          ".($q!=='' ? "AND (u.display_name LIKE ? OR cp.shop_tag LIKE ?)" : "")."
        ORDER BY CAST(NULLIF(cp.shop_tag, _utf8mb4'' COLLATE utf8mb4_bin) AS UNSIGNED) ASC, cp.shop_tag ASC, u.display_name ASC
        LIMIT 500
      ";
      $st = $pdo->prepare($sql);
      $bind = [$castRoleId, $store_id, $store_id];
      if ($q !== '') {
        $like = '%'.$q.'%';
        $bind[] = $like;
        $bind[] = $like;
      }
      $st->execute($bind);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $sql = "
        SELECT
          u.id AS user_id,
          u.display_name,
          cp.employment_type,
          cp.shop_tag AS staff_code,
          'active' AS status,
          NULL AS retired_at,
          NULL AS store_user_id,
          NULL AS retired_reason
        FROM cast_profiles cp
        INNER JOIN users u ON u.id=cp.user_id
        WHERE cp.store_id=?
          ".($q!=='' ? "AND (u.display_name LIKE ? OR cp.shop_tag LIKE ?)" : "")."
        ORDER BY CAST(NULLIF(cp.shop_tag, _utf8mb4'' COLLATE utf8mb4_bin) AS UNSIGNED) ASC, cp.shop_tag ASC, u.display_name ASC
        LIMIT 500
      ";
      $st = $pdo->prepare($sql);
      $bind = [$store_id];
      if ($q !== '') {
        $like = '%'.$q.'%';
        $bind[] = $like;
        $bind[] = $like;
      }
      $st->execute($bind);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre style='white-space:pre-wrap;padding:16px;background:#111;color:#eee'>";
  echo "admin/cast_edit.php error\n\n";
  echo h($e->getMessage()) . "\n";
  echo "</pre>";
  exit;
}

render_page_start('キャスト編集');
render_header('キャスト編集', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '
    <a class="btn" href="/seika-app/public/admin/index.php">管理TOP</a>
    <a class="btn" href="/seika-app/public/store_select.php">店舗切替</a>
  ',
]);
?>
<div class="page">
  <div class="admin-wrap">

    <div class="card">
      <div class="headRow">
        <div>
          <div class="ttl">👤 キャスト編集</div>
          <div class="sub">店舗：<b><?= h($storeName) ?></b> (#<?= (int)$store_id ?>)</div>
        </div>

        <form method="get" class="searchRow">
          <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
          <input class="in" name="q" value="<?= h($q) ?>" placeholder="名前 / 店番で検索">
          <label class="chk">
            <input type="checkbox" name="show_retired" value="1" <?= $show_retired ? 'checked' : '' ?>>
            <span>退店者を表示</span>
          </label>
          <button class="btn">検索</button>
          <a class="btn" href="/seika-app/public/admin/cast_edit.php?store_id=<?= (int)$store_id ?>">クリア</a>

          <button class="btn primary" type="button" onclick="openAddDialog()" <?= $hasStoreUsers ? '' : 'disabled title="store_usersが必要です"' ?>>
            ＋キャスト追加
          </button>
        </form>
      </div>

      <?php if ($msg): ?>
        <div class="notice ok"><?= h($msg) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="notice ng"><?= h($err) ?></div>
      <?php endif; ?>

      <div class="muted" style="margin-top:10px;">
        店番・雇用・名前を編集できます。退店は店舗ごとに管理します。
        <?php if (!$hasStoreUsers): ?>
          <span class="notice ng" style="display:inline-block;margin-left:8px;padding:6px 10px;">store_users が未作成です（先にDB作成）</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-top:12px;">
      <div class="muted" style="margin-bottom:10px;"><?= count($rows) ?> 件</div>

      <div class="tblWrap">
        <table class="tbl">
          <thead>
            <tr>
              <th style="width:140px;">店番</th>
              <th>名前</th>
              <th style="width:170px;">雇用</th>
              <th style="width:140px;">在籍</th>
              <th style="width:260px;">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted" style="padding:12px;">該当データがありません</td></tr>
          <?php endif; ?>

          <?php foreach ($rows as $r): ?>
            <?php
              $uid  = (int)$r['user_id'];
              $suid = (int)($r['store_user_id'] ?? 0);
              $ename = (string)($r['employment_type'] ?? 'part');
              $code  = (string)($r['staff_code'] ?? '');
              $status = (string)($r['status'] ?? 'active');
              $isRetired = ($status === 'retired');
            ?>
            <tr class="<?= $isRetired ? 'retired' : '' ?>">
              <td>
                <form method="post" class="rowForm">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="user_id" value="<?= $uid ?>">
                  <input type="hidden" name="store_user_id" value="<?= $suid ?>">

                  <input class="in mono" name="staff_code" value="<?= h($code) ?>" placeholder="例 10" inputmode="numeric" <?= $hasStoreUsers ? '' : 'disabled' ?>>
              </td>

              <td>
                  <input class="in" name="display_name" value="<?= h((string)$r['display_name']) ?>" style="width:100%;" <?= $hasStoreUsers ? '' : 'disabled' ?>>
              </td>

              <td>
                  <select class="in" name="employment_type" <?= $hasStoreUsers ? '' : 'disabled' ?>>
                    <option value="regular" <?= $ename==='regular'?'selected':'' ?>>レギュラー</option>
                    <option value="part" <?= $ename==='part'?'selected':'' ?>>アルバイト</option>
                    <option value="trial" <?= $ename==='trial'?'selected':'' ?>>体験</option>
                    <option value="support" <?= $ename==='support'?'selected':'' ?>>ヘルプ</option>
                  </select>
              </td>

              <td>
                <?php if (!$isRetired): ?>
                  <span class="badge ok">在籍</span>
                <?php else: ?>
                  <span class="badge muted">退店</span>
                  <?php if (!empty($r['retired_at'])): ?>
                    <span class="muted" style="margin-left:6px;font-size:12px;">
                      (<?= h(date('Y-m-d', strtotime((string)$r['retired_at']))) ?>)
                    </span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>

              <td style="white-space:nowrap;">
                <button class="btn primary" type="submit" <?= $hasStoreUsers ? '' : 'disabled' ?>>更新</button>
                <a class="btn" href="/seika-app/public/admin/cast_shift_edit.php?store_id=<?= (int)$store_id ?>&user_id=<?= $uid ?>">出勤</a>

                <?php if ($hasStoreUsers && $suid > 0): ?>
                  <?php if (!$isRetired): ?>
                    <button class="btn danger" type="button" onclick="openRetireDialog(<?= (int)$suid ?>,'<?= h(addslashes((string)$r['display_name'])) ?>')">退店</button>
                  <?php else: ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('在籍に戻しますか？');">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="reinstate">
                      <input type="hidden" name="store_user_id" value="<?= (int)$suid ?>">
                      <button class="btn">復帰</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>

                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- 追加ダイアログ -->
<dialog id="addDialog">
  <form method="post" class="dlg">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_cast">

    <div class="dlgTtl">＋キャスト追加</div>

    <label class="dlgField">
      <span class="muted">店番</span>
      <input class="in mono" name="staff_code" maxlength="32" placeholder="例 10" inputmode="numeric">
    </label>

    <label class="dlgField">
      <span class="muted">名前</span>
      <input class="in" name="display_name" maxlength="100" required placeholder="例 りん">
    </label>

    <label class="dlgField">
      <span class="muted">雇用</span>
      <select class="in" name="employment_type">
        <option value="regular">レギュラー</option>
        <option value="part" selected>アルバイト</option>
        <option value="trial">体験</option>
        <option value="support">ヘルプ</option>
      </select>
    </label>

    <div class="dlgBtns">
      <button type="button" class="btn" onclick="closeAddDialog()">キャンセル</button>
      <button type="submit" class="btn primary">追加</button>
    </div>

    <div class="muted" style="margin-top:8px;">
      ※ login_id / パスワードは内部で自動生成します（後でLINE連携に置き換え可能）。
    </div>
  </form>
</dialog>

<!-- 退店ダイアログ -->
<dialog id="retireDialog">
  <form method="post" class="dlg" onsubmit="return confirm('退店にしますか？');">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="retire">
    <input type="hidden" name="store_user_id" id="retire_store_user_id" value="0">

    <div class="dlgTtl">退店処理</div>
    <div class="muted" id="retire_name" style="margin-bottom:8px;"></div>

    <label class="dlgField">
      <span class="muted">退店理由（任意）</span>
      <input class="in" name="retired_reason" maxlength="255" placeholder="例：卒業 / 連絡なし / 事情により">
    </label>

    <div class="dlgBtns">
      <button type="button" class="btn" onclick="closeRetireDialog()">キャンセル</button>
      <button type="submit" class="btn danger">退店にする</button>
    </div>
  </form>
</dialog>

<style>
/* 既存テーマに寄せる（layout.php の var を優先して破壊しない） */
.headRow{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap}
.ttl{font-weight:1000;font-size:18px}
.sub{margin-top:4px;font-size:12px;opacity:.75}
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.searchRow{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.notice{margin-top:10px;padding:10px 12px;border-radius:12px;border:1px solid var(--line)}
.notice.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
.notice.ng{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)}
.muted{opacity:.75;font-size:12px}
.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace}
.chk{display:inline-flex;gap:6px;align-items:center;opacity:.9;font-size:12px}
.chk input{transform:translateY(1px)}

.tblWrap{overflow:auto}
.tbl{width:100%;border-collapse:collapse;min-width:980px}
.tbl th,.tbl td{padding:3px;border-bottom:1px solid rgba(255,255,255,.10);vertical-align:middle}
.tbl th{opacity:.9;text-align:left;white-space:nowrap}
.rowForm{display:flex;gap:5px;align-items:center}
.in{padding:5px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;min-height:10px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
.btn.danger{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.35)}

.badge{padding:2px 10px;border-radius:999px;border:1px solid var(--line);font-size:12px;display:inline-block}
.badge.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
.badge.muted{opacity:.8;background:rgba(255,255,255,.06)}

tr.retired{opacity:.45;filter:grayscale(1)}

dialog::backdrop{background:rgba(0,0,0,.55)}
.dlg{display:flex;flex-direction:column;gap:10px;min-width:340px;padding:14px}
.dlgTtl{font-weight:900;font-size:16px}
.dlgField{display:flex;flex-direction:column;gap:4px}
.dlgBtns{display:flex;gap:10px;justify-content:flex-end;margin-top:4px}
</style>

<script>
function openAddDialog(){ const d=document.getElementById('addDialog'); if(d) d.showModal(); }
function closeAddDialog(){ const d=document.getElementById('addDialog'); if(d) d.close(); }

function openRetireDialog(storeUserId, name){
  const d=document.getElementById('retireDialog');
  document.getElementById('retire_store_user_id').value = String(storeUserId||0);
  document.getElementById('retire_name').textContent = '対象：' + (name||'');
  if(d) d.showModal();
}
function closeRetireDialog(){ const d=document.getElementById('retireDialog'); if(d) d.close(); }
</script>

<?php render_page_end(); ?>
