<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/attendance.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
require_role(['admin','manager','super_user']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$store_id = att_safe_store_id();
if ($store_id <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}
if (!function_exists('current_store_id')) {
  function current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
  }
}

$store = att_fetch_store($pdo, $store_id);
$storeName = $store['name'] ?? ('#' . $store_id);

/** CSRF（このページ専用） */
if (!isset($_SESSION['orders_csrf'])) $_SESSION['orders_csrf'] = bin2hex(random_bytes(16));
function csrf_token(): string { return (string)($_SESSION['orders_csrf'] ?? ''); }
function csrf_verify(?string $t): bool {
  return is_string($t) && $t !== '' && hash_equals((string)($_SESSION['orders_csrf'] ?? ''), $t);
}

/** 画像アップロード */
function uploads_dir_abs(int $store_id): string {
  return realpath(__DIR__ . '/..') . '/uploads/order_menus/' . $store_id;
}
function uploads_url_base(int $store_id): string {
  return '/seika-app/public/uploads/order_menus/' . $store_id;
}
function ensure_dir(string $dir): void { if (!is_dir($dir)) mkdir($dir, 0775, true); }
function handle_image_upload(int $store_id, array $file): ?string {
  if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

  $max = 2 * 1024 * 1024;
  if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $max) return null;

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']) ?: '';

  $ext = null;
  if ($mime === 'image/jpeg') $ext = 'jpg';
  elseif ($mime === 'image/png') $ext = 'png';
  elseif ($mime === 'image/webp') $ext = 'webp';
  else return null;

  $dir = uploads_dir_abs($store_id);
  ensure_dir($dir);

  $name = 'm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $dest = $dir . '/' . $name;

  if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
  return uploads_url_base($store_id) . '/' . $name;
}

/** DB fetch */
function fetch_categories(PDO $pdo, int $store_id): array {
  $st = $pdo->prepare("
    SELECT id, name, sort_order, is_active
    FROM order_menu_categories
    WHERE store_id=?
    ORDER BY sort_order ASC, id ASC
  ");
  $st->execute([$store_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_menus(PDO $pdo, int $store_id): array {
  $st = $pdo->prepare("
    SELECT
      m.id, m.category_id, m.name, m.price_ex, m.image_url, m.description,
      m.is_sold_out, m.is_active, m.sort_order, m.updated_at,
      c.name AS category_name
    FROM order_menus m
    LEFT JOIN order_menu_categories c ON c.id=m.category_id
    WHERE m.store_id=?
    ORDER BY m.is_active DESC, m.is_sold_out ASC, c.sort_order ASC, m.sort_order ASC, m.id DESC
  ");
  $st->execute([$store_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** POST */
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf'] ?? '');
  if (!csrf_verify($token)) {
    $err = 'CSRFが不正です（再読み込みしてやり直してください）';
  } else {
    $action = (string)($_POST['action'] ?? '');
    try {
      if ($action === 'cat_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 100);
        if ($name === '') throw new RuntimeException('カテゴリ名が空です');
        $st = $pdo->prepare("INSERT INTO order_menu_categories (store_id, name, sort_order, is_active) VALUES (?, ?, ?, 1)");
        $st->execute([$store_id, $name, $sort]);
        $msg = 'カテゴリを追加しました';
      }

      if ($action === 'cat_update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 100);
        $act  = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
        if ($id <= 0) throw new RuntimeException('ID不正');
        if ($name === '') throw new RuntimeException('カテゴリ名が空です');
        $st = $pdo->prepare("UPDATE order_menu_categories SET name=?, sort_order=?, is_active=? WHERE id=? AND store_id=?");
        $st->execute([$name, $sort, $act, $id, $store_id]);
        $msg = 'カテゴリを更新しました';
      }

      if ($action === 'menu_add') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $price = (int)($_POST['price_ex'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);
        $sort  = (int)($_POST['sort_order'] ?? 100);
        $desc  = trim((string)($_POST['description'] ?? ''));
        if ($name === '') throw new RuntimeException('メニュー名が空です');
        if ($price < 0) throw new RuntimeException('価格が不正です');

        $imgUrl = null;
        if (isset($_FILES['image']) && is_array($_FILES['image'])) {
          $imgUrl = handle_image_upload($store_id, $_FILES['image']);
        }

        $st = $pdo->prepare("
          INSERT INTO order_menus
            (store_id, category_id, name, price_ex, image_url, description, is_sold_out, is_active, sort_order)
          VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?)
        ");
        $st->execute([
          $store_id,
          ($catId > 0 ? $catId : null),
          $name,
          $price,
          ($imgUrl !== null ? $imgUrl : null),
          ($desc !== '' ? $desc : null),
          $sort
        ]);
        $msg = 'メニューを追加しました';
      }

      if ($action === 'menu_update') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $price = (int)($_POST['price_ex'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);
        $sort  = (int)($_POST['sort_order'] ?? 100);
        $desc  = trim((string)($_POST['description'] ?? ''));
        $sold  = (int)($_POST['is_sold_out'] ?? 0) === 1 ? 1 : 0;
        $act   = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($id <= 0) throw new RuntimeException('ID不正');
        if ($name === '') throw new RuntimeException('メニュー名が空です');
        if ($price < 0) throw new RuntimeException('価格が不正です');

        $imgUrl = null;
        if (isset($_FILES['image']) && is_array($_FILES['image'])) {
          $imgUrl = handle_image_upload($store_id, $_FILES['image']);
        }

        if ($imgUrl !== null) {
          $st = $pdo->prepare("
            UPDATE order_menus
            SET category_id=?, name=?, price_ex=?, image_url=?, description=?, is_sold_out=?, is_active=?, sort_order=?
            WHERE id=? AND store_id=?
          ");
          $st->execute([
            ($catId > 0 ? $catId : null),
            $name, $price, $imgUrl,
            ($desc !== '' ? $desc : null),
            $sold, $act, $sort,
            $id, $store_id
          ]);
        } else {
          $st = $pdo->prepare("
            UPDATE order_menus
            SET category_id=?, name=?, price_ex=?, description=?, is_sold_out=?, is_active=?, sort_order=?
            WHERE id=? AND store_id=?
          ");
          $st->execute([
            ($catId > 0 ? $catId : null),
            $name, $price,
            ($desc !== '' ? $desc : null),
            $sold, $act, $sort,
            $id, $store_id
          ]);
        }
        $msg = 'メニューを更新しました';
      }

      if ($action === 'menu_toggle_soldout') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('ID不正');
        $st = $pdo->prepare("UPDATE order_menus SET is_sold_out = 1 - is_sold_out WHERE id=? AND store_id=?");
        $st->execute([$id, $store_id]);
        $msg = '売切状態を切り替えました';
      }

      if ($action === 'menu_toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('ID不正');
        $st = $pdo->prepare("UPDATE order_menus SET is_active = 1 - is_active WHERE id=? AND store_id=?");
        $st->execute([$id, $store_id]);
        $msg = '有効状態を切り替えました';
      }

    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

$categories = fetch_categories($pdo, $store_id);
$menus      = fetch_menus($pdo, $store_id);

$right_html = '
  <a class="btn" href="/seika-app/public/orders/index.php?table=1">🛎️ 注文へ</a>
  <a class="btn" href="/seika-app/public/orders/kitchen.php">🍳 キッチン</a>
';

render_page_start('メニュー管理');
render_header('メニュー管理', [
  'back_href'  => '/seika-app/public/orders/dashboard_orders.php',
  'back_label' => '← 注文ランチャーへ',
  'right_html' => $right_html,
]);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; }
  .grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  @media (max-width: 980px){ .grid2{ grid-template-columns:1fr; } }

  .table{ width:100%; border-collapse:collapse; }
  .table th,.table td{ border-bottom:1px solid var(--line); padding:10px 8px; vertical-align:top; }
  .table th{ text-align:left; color:var(--muted); font-weight:900; font-size:12px; }

  .row-actions{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
  .thumb{ width:72px; height:54px; object-fit:cover; border-radius:14px; border:1px solid var(--line); background:#111; }

  .formrow{ display:grid; grid-template-columns: 140px 1fr; gap:10px; align-items:center; margin-bottom:10px; }
  @media (max-width: 640px){ .formrow{ grid-template-columns:1fr; } }

  .input, select.input, textarea.input{
    width:100%; min-height: var(--tap);
    padding:12px 14px;
    border-radius:14px;
    border:1px solid var(--line);
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
    color: var(--txt);
    outline:none;
  }
  textarea.input{ min-height: 90px; }
  details > summary.btn { list-style:none; }
  details > summary.btn::-webkit-details-marker { display:none; }

  .pill.ok{ border-color: rgba(57,217,138,.35); }
  .pill.bad{ border-color: rgba(255,92,92,.35); }
</style>

<main class="page" style="display:grid; gap:12px;">

  <?php if ($msg !== ''): ?>
    <section class="card" style="border-color: rgba(57,217,138,.30);">
      <b style="color:var(--accent);"><?= h($msg) ?></b>
    </section>
  <?php endif; ?>

  <?php if ($err !== ''): ?>
    <section class="card" style="border-color: rgba(255,92,92,.35);">
      <b style="color:#ff8a8a;"><?= h($err) ?></b>
    </section>
  <?php endif; ?>

  <section class="card">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:18px;">🖼️ メニュー表 管理</div>
        <div class="muted" style="margin-top:6px;">店舗：<?= h($storeName) ?>（#<?= (int)$store_id ?>）</div>
      </div>
      <div class="muted">
        1) カテゴリを作る → 2) メニュー追加 → 3) 注文画面で確認
      </div>
    </div>
  </section>

  <div class="grid2">
    <!-- カテゴリ -->
    <section class="card">
      <div style="font-weight:1000; font-size:16px; margin-bottom:10px;">カテゴリ</div>

      <form method="post" class="card" style="box-shadow:none;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="cat_add">

        <div class="formrow">
          <div class="muted">カテゴリ名</div>
          <input class="input" name="name" required>
        </div>
        <div class="formrow">
          <div class="muted">並び順</div>
          <input class="input" name="sort_order" type="number" value="100">
        </div>
        <button class="btn primary" type="submit">追加</button>
      </form>

      <div style="height:10px;"></div>

      <table class="table">
        <thead>
          <tr><th>ID</th><th>名前</th><th>順</th><th>状態</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= h((string)$c['name']) ?></td>
            <td><?= (int)$c['sort_order'] ?></td>
            <td>
              <?php if ((int)$c['is_active'] === 1): ?>
                <span class="pill ok">有効</span>
              <?php else: ?>
                <span class="pill bad">無効</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <details>
                <summary class="btn">編集</summary>
                <form method="post" style="margin-top:10px;">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="cat_update">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

                  <div class="formrow">
                    <div class="muted">カテゴリ名</div>
                    <input class="input" name="name" value="<?= h((string)$c['name']) ?>" required>
                  </div>
                  <div class="formrow">
                    <div class="muted">並び順</div>
                    <input class="input" name="sort_order" type="number" value="<?= (int)$c['sort_order'] ?>">
                  </div>
                  <div class="formrow">
                    <div class="muted">有効</div>
                    <select class="input" name="is_active">
                      <option value="1" <?= ((int)$c['is_active']===1?'selected':'') ?>>有効</option>
                      <option value="0" <?= ((int)$c['is_active']===0?'selected':'') ?>>無効</option>
                    </select>
                  </div>
                  <button class="btn primary" type="submit">更新</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <!-- メニュー -->
    <section class="card">
      <div style="font-weight:1000; font-size:16px; margin-bottom:10px;">メニュー</div>

      <form method="post" enctype="multipart/form-data" class="card" style="box-shadow:none;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="menu_add">

        <div class="formrow">
          <div class="muted">カテゴリ</div>
          <select class="input" name="category_id">
            <option value="0">(未分類)</option>
            <?php foreach ($categories as $co): ?>
              <option value="<?= (int)$co['id'] ?>"><?= h((string)$co['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="formrow">
          <div class="muted">名前</div>
          <input class="input" name="name" required>
        </div>

        <div class="formrow">
          <div class="muted">価格(税抜)</div>
          <input class="input" name="price_ex" type="number" min="0" value="0" required>
        </div>

        <div class="formrow">
          <div class="muted">並び順</div>
          <input class="input" name="sort_order" type="number" value="100">
        </div>

        <div class="formrow">
          <div class="muted">説明</div>
          <textarea class="input" name="description" placeholder="例: 氷少なめOK"></textarea>
        </div>

        <div class="formrow">
          <div class="muted">画像</div>
          <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
        </div>

        <div class="muted">※ 画像は JPEG/PNG/WebP、最大2MB</div>
        <div style="height:10px;"></div>
        <button class="btn primary" type="submit">追加</button>
      </form>

      <div style="height:10px;"></div>

      <table class="table">
        <thead>
          <tr><th>画像</th><th>名前</th><th>カテゴリ</th><th>価格</th><th>順</th><th>状態</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($menus as $m): ?>
          <tr>
            <td>
              <?php $img = (string)($m['image_url'] ?? ''); ?>
              <?php if ($img !== ''): ?>
                <img class="thumb" src="<?= h($img) ?>" alt="">
              <?php else: ?>
                <div class="thumb" style="display:flex;align-items:center;justify-content:center;color:var(--muted);">no</div>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-weight:1000;"><?= h((string)$m['name']) ?></div>
              <div class="muted"><?= h((string)($m['description'] ?? '')) ?></div>
            </td>
            <td class="muted"><?= h((string)($m['category_name'] ?? '(未分類)')) ?></td>
            <td><?= (int)$m['price_ex'] ?>円</td>
            <td><?= (int)$m['sort_order'] ?></td>
            <td>
              <?= ((int)$m['is_active']===1) ? '<span class="pill ok">有効</span>' : '<span class="pill bad">無効</span>' ?>
              <?= ((int)$m['is_sold_out']===1) ? '<span class="pill bad">売切</span>' : '<span class="pill">販売中</span>' ?>
            </td>
            <td style="text-align:right;">
              <div class="row-actions">
                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="menu_toggle_soldout">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button class="btn" type="submit"><?= ((int)$m['is_sold_out']===1) ? '売切解除' : '売切' ?></button>
                </form>

                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="menu_toggle_active">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button class="btn" type="submit"><?= ((int)$m['is_active']===1) ? '無効' : '有効' ?></button>
                </form>

                <details>
                  <summary class="btn">編集</summary>
                  <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="menu_update">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">

                    <div class="formrow">
                      <div class="muted">カテゴリ</div>
                      <select class="input" name="category_id">
                        <option value="0">(未分類)</option>
                        <?php foreach ($categories as $co): ?>
                          <option value="<?= (int)$co['id'] ?>" <?= ((int)$m['category_id']===(int)$co['id']?'selected':'') ?>>
                            <?= h((string)$co['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="formrow">
                      <div class="muted">名前</div>
                      <input class="input" name="name" value="<?= h((string)$m['name']) ?>" required>
                    </div>

                    <div class="formrow">
                      <div class="muted">価格(税抜)</div>
                      <input class="input" name="price_ex" type="number" min="0" value="<?= (int)$m['price_ex'] ?>" required>
                    </div>

                    <div class="formrow">
                      <div class="muted">並び順</div>
                      <input class="input" name="sort_order" type="number" value="<?= (int)$m['sort_order'] ?>">
                    </div>

                    <div class="formrow">
                      <div class="muted">説明</div>
                      <textarea class="input" name="description"><?= h((string)($m['description'] ?? '')) ?></textarea>
                    </div>

                    <div class="formrow">
                      <div class="muted">売切</div>
                      <select class="input" name="is_sold_out">
                        <option value="0" <?= ((int)$m['is_sold_out']===0?'selected':'') ?>>販売中</option>
                        <option value="1" <?= ((int)$m['is_sold_out']===1?'selected':'') ?>>売切</option>
                      </select>
                    </div>

                    <div class="formrow">
                      <div class="muted">有効</div>
                      <select class="input" name="is_active">
                        <option value="1" <?= ((int)$m['is_active']===1?'selected':'') ?>>有効</option>
                        <option value="0" <?= ((int)$m['is_active']===0?'selected':'') ?>>無効</option>
                      </select>
                    </div>

                    <div class="formrow">
                      <div class="muted">画像差替</div>
                      <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                    </div>

                    <button class="btn primary" type="submit">更新</button>
                  </form>
                </details>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

    </section>
  </div>
</main>
<?php render_page_end(); ?>