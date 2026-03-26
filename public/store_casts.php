<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/service_store_casts.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$pdo = db();
$msg = '';
$err = '';
$showRetired = ((string)($_GET['show_retired'] ?? '0') === '1');
$isSuper = function_exists('is_role') ? is_role('super_user') : false;
$isAdmin = function_exists('is_role') ? is_role('admin') : false;
$canHardDelete = ($isSuper || $isAdmin);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    $storeIdPost = store_access_resolve_manageable_store_id($pdo, (int)($_POST['store_id'] ?? 0));
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_cast') {
      service_add_store_cast(
        $pdo,
        $storeIdPost,
        (string)($_POST['display_name'] ?? ''),
        (string)($_POST['employment_type'] ?? 'part'),
        (string)($_POST['staff_code'] ?? '')
      );
      header('Location: /wbss/public/store_casts.php?store_id=' . $storeIdPost . '&ok=added');
      exit;
    }

    if ($action === 'retire_cast') {
      service_retire_store_cast(
        $pdo,
        $storeIdPost,
        (int)($_POST['store_user_id'] ?? 0),
        (string)($_POST['retired_reason'] ?? '')
      );
      header('Location: /wbss/public/store_casts.php?store_id=' . $storeIdPost . '&ok=retired');
      exit;
    }

    if ($action === 'restore_cast') {
      service_restore_store_cast(
        $pdo,
        $storeIdPost,
        (int)($_POST['store_user_id'] ?? 0)
      );
      header('Location: /wbss/public/store_casts.php?store_id=' . $storeIdPost . '&show_retired=1&ok=restored');
      exit;
    }

    if ($action === 'hard_delete_cast') {
      if (!$canHardDelete) {
        throw new RuntimeException('完全削除は管理者以上のみ可能です');
      }
      service_hard_delete_store_cast(
        $pdo,
        $storeIdPost,
        (int)($_POST['store_user_id'] ?? 0)
      );
      header('Location: /wbss/public/store_casts.php?store_id=' . $storeIdPost . '&show_retired=1&ok=deleted');
      exit;
    }

    throw new RuntimeException('不明な操作です');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

try {
  $stores = store_access_allowed_stores($pdo);
  $storeId = store_access_resolve_manageable_store_id($pdo, (int)($_GET['store_id'] ?? 0));
  $storeName = store_access_find_store_name($stores, $storeId);
  $data = $showRetired
    ? service_fetch_store_cast_index_with_retired($pdo, $storeId)
    : service_fetch_store_cast_index($pdo, $storeId);
  $casts = $data['casts'];
  $noteMap = $data['note_map'];
} catch (Throwable $e) {
  $stores = [];
  $storeId = 0;
  $storeName = '';
  $casts = [];
  $noteMap = [];
  $err = $e->getMessage();
}

$ok = (string)($_GET['ok'] ?? '');
if ($err === '' && $ok === 'added') {
  $msg = 'キャストを追加しました';
} elseif ($err === '' && $ok === 'retired') {
  $msg = 'キャストを削除しました';
} elseif ($err === '' && $ok === 'restored') {
  $msg = 'キャストを復帰しました';
} elseif ($err === '' && $ok === 'deleted') {
  $msg = 'キャストを完全削除しました';
}

render_page_start('店別キャスト管理');
render_header('店別キャスト管理', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page">
  <div class="admin-wrap">
    <?php if ($msg !== ''): ?>
      <div class="card noticeOk"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:#ef4444"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="rowTop">
      <div>
        <div class="title">👥 店別キャスト一覧</div>
        <div class="muted" style="margin-top:4px;">
          店舗：<b><?= h($storeName !== '' ? $storeName : '-') ?><?php if ($storeId > 0): ?> (#<?= (int)$storeId ?>)<?php endif; ?></b>
        </div>
      </div>

      <div class="rowBtns">
        <?php if (count($stores) > 1): ?>
          <form method="get">
            <?php if ($showRetired): ?>
              <input type="hidden" name="show_retired" value="1">
            <?php endif; ?>
            <select class="btn" name="store_id" onchange="this.form.submit()">
              <?php foreach ($stores as $store): ?>
                <?php $sid = (int)($store['id'] ?? 0); ?>
                <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
                  <?= h((string)($store['name'] ?? '')) ?> (#<?= $sid ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
        <?php if ($storeId > 0): ?>
          <button type="button" class="btn btn-primary" onclick="openAddDialog()">キャスト追加</button>
          <a class="btn" href="/wbss/public/cast_transport_profiles.php?store_id=<?= (int)$storeId ?>">送迎設定</a>
          <a class="btn" href="/wbss/public/transport_routes.php?store_id=<?= (int)$storeId ?>">送迎ルート</a>
          <a class="btn" href="/wbss/public/store_casts.php?store_id=<?= (int)$storeId ?>&show_retired=<?= $showRetired ? '0' : '1' ?>">
            <?= $showRetired ? '在籍のみ表示' : '退店も表示' ?>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-top:12px;">
      <div class="castTableWrap">
        <table class="castTable">
          <thead>
            <tr>
              <th>店番</th>
              <th>名前</th>
              <th>雇用</th>
              <th>LINE</th>
              <th>在籍</th>
              <th>メモ</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($casts as $cast): ?>
              <?php
                $uid = (int)($cast['id'] ?? 0);
                $shopTag = trim((string)($cast['shop_tag'] ?? ''));
                $shopTagLabel = $shopTag !== '' ? $shopTag : (string)$uid;
                $employmentType = (string)($cast['employment_type'] ?? 'part_time');
                $employmentTypeLabel = $employmentType === 'regular' ? 'レギュラー' : 'バイト';
                $defaultStart = $cast['default_start_time'] ? substr((string)$cast['default_start_time'], 0, 5) : '-';
                $hasLine = ((int)($cast['has_line'] ?? 0) === 1);
                $active = ((int)($cast['is_active'] ?? 0) === 1);
                $note = (string)($noteMap[$uid] ?? '');
                $storeUserId = (int)($cast['store_user_id'] ?? 0);
                $storeStatus = (string)($cast['status'] ?? 'active');
                $isRetired = ($storeStatus !== 'active');
                $retiredReason = trim((string)($cast['retired_reason'] ?? ''));
              ?>
              <tr class="<?= $isRetired ? 'is-retired' : '' ?>">
                <td class="mono"><?= h($shopTagLabel) ?></td>
                <td>
                  <div class="castTableName"><?= h((string)($cast['display_name'] ?? '')) ?></div>
                </td>
                <td><?= h($employmentTypeLabel) ?></td>
                <td>
                  <div class="lineActions">
                    <?= $hasLine ? '<span class="badge ok">OK</span>' : '<span class="badge ng">未連携</span>' ?>
                    <?php if (!$hasLine && $storeId > 0): ?>
                      <a class="miniBtn" target="_blank" href="/wbss/public/print_user_link_qr.php?user_id=<?= $uid ?>&store_id=<?= (int)$storeId ?>">連携QR</a>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <?= $isRetired ? '<span class="badge off">退店</span>' : ($active ? '<span class="badge ok">在籍</span>' : '<span class="badge off">停止</span>') ?>
                  <?php if ($retiredReason !== ''): ?>
                    <div class="muted castTableSub"><?= h($retiredReason) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($note !== ''): ?>
                    <div class="castTableMemo"><?= nl2br(h($note)) ?></div>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="lineActions">
                    <?php if (!$isRetired): ?>
                      <a class="miniBtn" href="/wbss/public/admin/cast_shift_edit.php?store_id=<?= (int)$storeId ?>&user_id=<?= $uid ?>">出勤</a>
                      <a class="miniBtn" href="/wbss/public/cast_transport_profiles.php?store_id=<?= (int)$storeId ?>#cast-<?= $uid ?>">送迎</a>
                      <?php if ($storeUserId > 0): ?>
                        <button
                          type="button"
                          class="miniBtn dangerBtn"
                          onclick='openRetireDialog(<?= $storeUserId ?>, <?= json_encode((string)($cast["display_name"] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                        >削除</button>
                      <?php endif; ?>
                    <?php else: ?>
                      <?php if ($storeUserId > 0): ?>
                        <button
                          type="button"
                          class="miniBtn"
                          onclick='openRestoreDialog(<?= $storeUserId ?>, <?= json_encode((string)($cast["display_name"] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                        >復帰</button>
                        <?php if ($canHardDelete): ?>
                          <button
                            type="button"
                            class="miniBtn dangerBtn"
                            onclick='openDeleteDialog(<?= $storeUserId ?>, <?= json_encode((string)($cast["display_name"] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                          >完全削除</button>
                        <?php endif; ?>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="castGridMobile">
        <?php foreach ($casts as $cast): ?>
          <?php
            $uid = (int)($cast['id'] ?? 0);
            $shopTag = trim((string)($cast['shop_tag'] ?? ''));
            $shopTagLabel = $shopTag !== '' ? $shopTag : (string)$uid;
            $employmentType = (string)($cast['employment_type'] ?? 'part_time');
            $employmentTypeLabel = $employmentType === 'regular' ? 'レギュラー' : 'バイト';
            $defaultStart = $cast['default_start_time'] ? substr((string)$cast['default_start_time'], 0, 5) : '-';
            $hasLine = ((int)($cast['has_line'] ?? 0) === 1);
            $active = ((int)($cast['is_active'] ?? 0) === 1);
            $note = (string)($noteMap[$uid] ?? '');
            $storeUserId = (int)($cast['store_user_id'] ?? 0);
            $storeStatus = (string)($cast['status'] ?? 'active');
            $isRetired = ($storeStatus !== 'active');
            $retiredReason = trim((string)($cast['retired_reason'] ?? ''));
          ?>
          <article class="castItem<?= $isRetired ? ' is-retired' : '' ?>">
            <div class="castMain">
              <div class="castTagBlock">
                <div class="fieldLabel">店番</div>
                <div class="castTag mono"><?= h($shopTagLabel) ?></div>
              </div>
              <div class="castNameBlock">
                <div class="fieldLabel">名前</div>
                <div class="castNameRow">
                  <b class="castName"><?= h((string)($cast['display_name'] ?? '')) ?></b>
                  <?php if ($note !== ''): ?>
                    <span class="memoWrap">
                      <span class="memoIcon">📝</span>
                      <span class="memoTip"><?= nl2br(h($note)) ?></span>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="castMeta">
              <div class="metaItem">
                <div class="fieldLabel">雇用</div>
                <div><?= h($employmentTypeLabel) ?></div>
              </div>
              <div class="metaItem">
                <div class="fieldLabel">基本開始</div>
                <div class="mono"><?= h($defaultStart) ?></div>
              </div>
              <div class="metaItem">
                <div class="fieldLabel">LINE</div>
                <div class="lineActions">
                  <?= $hasLine ? '<span class="badge ok">OK</span>' : '<span class="badge ng">未連携</span>' ?>
                  <?php if (!$hasLine && $storeId > 0): ?>
                    <a class="miniBtn" target="_blank" href="/wbss/public/print_user_link_qr.php?user_id=<?= $uid ?>&store_id=<?= (int)$storeId ?>">連携QR</a>
                  <?php endif; ?>
                </div>
              </div>
              <div class="metaItem">
                <div class="fieldLabel">在籍</div>
                <div>
                  <?= $isRetired ? '<span class="badge off">退店</span>' : ($active ? '<span class="badge ok">在籍</span>' : '<span class="badge off">停止</span>') ?>
                  <?php if ($retiredReason !== ''): ?>
                    <div class="muted" style="margin-top:4px;"><?= h($retiredReason) ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="metaItem metaActions">
                <div class="fieldLabel">操作</div>
                <div class="lineActions">
                  <?php if (!$isRetired): ?>
                    <a class="miniBtn" href="/wbss/public/admin/cast_shift_edit.php?store_id=<?= (int)$storeId ?>&user_id=<?= $uid ?>">出勤</a>
                    <a class="miniBtn" href="/wbss/public/cast_transport_profiles.php?store_id=<?= (int)$storeId ?>#cast-<?= $uid ?>">送迎</a>
                    <?php if ($storeUserId > 0): ?>
                      <button
                        type="button"
                        class="miniBtn dangerBtn"
                        onclick='openRetireDialog(<?= $storeUserId ?>, <?= json_encode((string)($cast["display_name"] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                      >削除</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if ($storeUserId > 0): ?>
                      <button
                        type="button"
                        class="miniBtn"
                        onclick='openRestoreDialog(<?= $storeUserId ?>, <?= json_encode((string)($cast["display_name"] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                      >復帰</button>
                      <?php if ($canHardDelete): ?>
                        <button
                          type="button"
                          class="miniBtn dangerBtn"
                          onclick='openDeleteDialog(<?= $storeUserId ?>, <?= json_encode((string)($cast["display_name"] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                        >完全削除</button>
                      <?php endif; ?>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="muted" style="margin-top:10px;">
        ※ 店番は <b>cast_profiles.shop_tag</b> を優先表示します（未設定時は user_id を表示）。<br>
        ※ 上部の「招待QR」は新規キャスト用、各行の「連携QR」は既存キャスト個別のLINE連携用です。
      </div>
    </div>
  </div>
</div>

<dialog id="addDialog">
  <form method="post" class="dlg">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_cast">
    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">

    <div class="dlgTtl">キャスト追加</div>

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
      <button type="submit" class="btn btn-primary">追加</button>
    </div>
  </form>
</dialog>

<dialog id="restoreDialog">
  <form method="post" class="dlg" onsubmit="return confirm('このキャストを復帰しますか？');">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="restore_cast">
    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
    <input type="hidden" name="store_user_id" id="restore_store_user_id" value="0">
    <div class="dlgTtl">キャスト復帰</div>
    <div class="muted" id="restore_name" style="margin-bottom:8px;"></div>
    <div class="dlgBtns">
      <button type="button" class="btn" onclick="closeRestoreDialog()">キャンセル</button>
      <button type="submit" class="btn btn-primary">復帰する</button>
    </div>
  </form>
</dialog>

<dialog id="deleteDialog">
  <form method="post" class="dlg" onsubmit="return confirm('完全削除します。元に戻せません。OKですか？');">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="hard_delete_cast">
    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
    <input type="hidden" name="store_user_id" id="delete_store_user_id" value="0">
    <div class="dlgTtl">キャスト完全削除</div>
    <div class="muted" id="delete_name" style="margin-bottom:8px;"></div>
    <div class="muted">この店舗との所属、castロール、プロフィール連携を削除します。</div>
    <div class="dlgBtns">
      <button type="button" class="btn" onclick="closeDeleteDialog()">キャンセル</button>
      <button type="submit" class="btn dangerBtn">完全削除する</button>
    </div>
  </form>
</dialog>

<dialog id="retireDialog">
  <form method="post" class="dlg" onsubmit="return confirm('このキャストを削除しますか？');">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="retire_cast">
    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
    <input type="hidden" name="store_user_id" id="retire_store_user_id" value="0">

    <div class="dlgTtl">キャスト削除</div>
    <div class="muted" id="retire_name" style="margin-bottom:8px;"></div>

    <label class="dlgField">
      <span class="muted">理由（任意）</span>
      <input class="in" name="retired_reason" maxlength="255" placeholder="例：卒業 / 連絡なし / 事情により">
    </label>

    <div class="dlgBtns">
      <button type="button" class="btn" onclick="closeRetireDialog()">キャンセル</button>
      <button type="submit" class="btn dangerBtn">削除する</button>
    </div>
  </form>
</dialog>

<style>
.rowTop{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.rowBtns{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.title{ font-weight:1000; font-size:18px; line-height:1.2; }
.btn{
  display:inline-flex; align-items:center; gap:6px;
  padding:10px 14px; border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA); color:inherit;
  text-decoration:none; cursor:pointer;
}
.btn-primary{ background:rgba(59,130,246,.18); border-color:rgba(59,130,246,.35); }
.muted{ opacity:.75; font-size:12px; }
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
.noticeOk{ border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.10); margin-bottom:12px; }
.castTableWrap{
  display:none;
  overflow:auto;
  -webkit-overflow-scrolling:touch;
}
.castTable{
  width:100%;
  min-width:0;
  border-collapse:separate;
  border-spacing:0;
  font-size:13px;
  table-layout:fixed;
}
.castTable thead th{
  position:sticky;
  top:0;
  z-index:1;
  background:color-mix(in srgb, var(--cardA) 88%, transparent);
  backdrop-filter:blur(8px);
}
.castTable th,
.castTable td{
  padding:10px 10px;
  border-bottom:1px solid var(--line);
  text-align:left;
  vertical-align:top;
}
.castTable th{
  font-size:12px;
  font-weight:900;
  letter-spacing:.02em;
  color:var(--muted);
}
.castTable tbody tr:hover{
  background:rgba(255,255,255,.04);
}
.castTable tbody tr.is-retired{
  opacity:.72;
  background:linear-gradient(180deg, rgba(148,163,184,.08), rgba(255,255,255,.02));
}
.castTableName{
  font-size:16px;
  font-weight:1000;
  line-height:1.25;
}
.castTableMemo{
  max-width:220px;
  white-space:normal;
  line-height:1.55;
  font-size:12px;
  word-break:break-word;
}
.castTableSub{
  margin-top:4px;
}
.castGridMobile{
  display:grid;
  grid-template-columns:1fr;
  gap:12px;
}
.castGrid{
  display:grid;
  grid-template-columns:1fr;
  gap:12px;
}
.castItem{
  border:1px solid var(--line);
  border-radius:14px;
  background:var(--cardA);
  padding:12px 14px;
}
.castItem.is-retired{
  opacity:.78;
  border-color:rgba(148,163,184,.30);
  background:linear-gradient(180deg, rgba(148,163,184,.10), rgba(255,255,255,.02));
}
.castMain{
  display:grid;
  grid-template-columns:64px minmax(0, 1fr);
  gap:12px;
  align-items:start;
}
.castMeta{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:10px 12px;
  margin-top:12px;
  padding-top:12px;
  border-top:1px solid var(--line);
}
.fieldLabel{
  font-size:11px;
  opacity:.65;
  margin-bottom:4px;
}
.castTag{
  font-size:24px;
  font-weight:900;
  line-height:1;
}
.castNameRow{
  display:flex;
  align-items:center;
  gap:8px;
}
.castName{
  font-size:20px;
  line-height:1.15;
  word-break:break-word;
}
.lineActions{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.miniBtn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:28px;
  padding:0 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background:var(--cardB);
  color:inherit;
  text-decoration:none;
  font-size:12px;
  cursor:pointer;
}
.dangerBtn{ background:rgba(239,68,68,.14); border-color:rgba(239,68,68,.35); }
.badge{
  display:inline-flex; align-items:center; justify-content:center;
  padding:3px 10px; border-radius:999px; font-size:12px;
  border:1px solid var(--line);
  background: var(--cardB);
}
.badge.ok{ border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); }
.badge.ng{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }
.badge.off{ border-color: rgba(148,163,184,.35); background: rgba(148,163,184,.10); }
.memoWrap{ position:relative; display:inline-block; margin-left:8px; }
.memoIcon{
  display:inline-flex; align-items:center; justify-content:center;
  width:22px; height:22px; border-radius:999px;
  border:1px solid rgba(0,0,0,.12);
  background:rgba(255,255,255,.9);
  cursor:help;
  font-size:13px;
}
.memoTip{
  display:none;
  position:absolute;
  right:0;
  top:26px;
  z-index:50;
  width:260px;
  max-width:70vw;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(0,0,0,.12);
  background:rgba(15,23,42,.96);
  color:#fff;
  box-shadow:0 12px 30px rgba(0,0,0,.25);
  font-size:12px;
  line-height:1.55;
  white-space:normal;
}
.memoWrap:hover .memoTip,
.memoWrap:focus-within .memoTip{ display:block; }
.dlg{
  min-width:min(420px, 92vw);
  padding:18px;
  border:1px solid var(--line);
  border-radius:18px;
  background:var(--cardA);
}
.dlgTtl{ font-size:18px; font-weight:900; margin-bottom:12px; }
.dlgField{ display:flex; flex-direction:column; gap:6px; margin-top:10px; }
.dlgBtns{ display:flex; justify-content:flex-end; gap:8px; margin-top:16px; }
.in{
  padding:10px 12px;
  border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA);
  color:inherit;
}
dialog::backdrop{ background:rgba(0,0,0,.55); }
@media (min-width: 1180px){
  .castTableWrap{
    display:block;
  }
  .castGridMobile{
    display:none;
  }
}
@media (max-width: 640px){
  .castMain{
    grid-template-columns:1fr;
    gap:12px;
  }
  .castMeta{
    grid-template-columns:1fr 1fr;
  }
  .castTag{
    font-size:26px;
  }
  .castName{
    font-size:22px;
  }
}
</style>
<script>
function openAddDialog(){
  document.getElementById('addDialog')?.showModal();
}
function closeAddDialog(){
  document.getElementById('addDialog')?.close();
}
function openRetireDialog(storeUserId, name){
  const dlg = document.getElementById('retireDialog');
  const idInput = document.getElementById('retire_store_user_id');
  const nameBox = document.getElementById('retire_name');
  if (idInput) idInput.value = String(storeUserId);
  if (nameBox) nameBox.textContent = name + ' を削除対象にします';
  dlg?.showModal();
}
function closeRetireDialog(){
  document.getElementById('retireDialog')?.close();
}
function openRestoreDialog(storeUserId, name){
  const dlg = document.getElementById('restoreDialog');
  const idInput = document.getElementById('restore_store_user_id');
  const nameBox = document.getElementById('restore_name');
  if (idInput) idInput.value = String(storeUserId);
  if (nameBox) nameBox.textContent = name + ' を復帰対象にします';
  dlg?.showModal();
}
function closeRestoreDialog(){
  document.getElementById('restoreDialog')?.close();
}
function openDeleteDialog(storeUserId, name){
  const dlg = document.getElementById('deleteDialog');
  const idInput = document.getElementById('delete_store_user_id');
  const nameBox = document.getElementById('delete_name');
  if (idInput) idInput.value = String(storeUserId);
  if (nameBox) nameBox.textContent = name + ' を完全削除対象にします';
  dlg?.showModal();
}
function closeDeleteDialog(){
  document.getElementById('deleteDialog')?.close();
}
</script>
<?php render_page_end(); ?>
