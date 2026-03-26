<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/repo_casts.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$pdo = db();
$err = '';

try {
  $stores = store_access_allowed_stores($pdo);
  $storeId = store_access_resolve_manageable_store_id($pdo, (int)($_GET['store_id'] ?? 0));
  $storeName = store_access_find_store_name($stores, $storeId);

  $filter = (string)($_GET['filter'] ?? 'all');
  if (!in_array($filter, ['all', 'line_unlinked', 'active_only'], true)) {
    $filter = 'all';
  }

  $casts = repo_fetch_casts($pdo, $storeId, $filter);
  $noteMap = repo_fetch_cast_notes($pdo, $storeId, array_map(
    static fn(array $cast): int => (int)($cast['id'] ?? 0),
    $casts
  ));
} catch (Throwable $e) {
  $stores = [];
  $storeId = 0;
  $storeName = '';
  $filter = 'all';
  $casts = [];
  $noteMap = [];
  $err = $e->getMessage();
}

render_page_start('キャスト一覧');
render_header('キャスト一覧', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page">
  <div class="admin-wrap">
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:#ef4444"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="rowTop">
      <div>
        <div class="title">👥 キャスト一覧（閲覧）</div>
        <div class="muted" style="margin-top:4px;">
          店舗：<b><?= h($storeName !== '' ? $storeName : '-') ?><?php if ($storeId > 0): ?> (#<?= (int)$storeId ?>)<?php endif; ?></b>
          / 表示：<b><?= h($filter) ?></b>
        </div>
      </div>

      <div class="rowBtns">
        <?php if (count($stores) > 1): ?>
          <form method="get">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
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
        <a class="btn" href="?store_id=<?= (int)$storeId ?>&filter=all">全員</a>
        <a class="btn" href="?store_id=<?= (int)$storeId ?>&filter=line_unlinked">LINE未連携</a>
        <a class="btn" href="?store_id=<?= (int)$storeId ?>&filter=active_only">在籍のみ</a>
      </div>
    </div>

    <div class="card" style="padding:14px; margin-top:12px;">
      <div class="castGrid">
        <?php foreach ($casts as $cast): ?>
          <?php
            $uid = (int)($cast['id'] ?? 0);
            $tag = trim((string)($cast['shop_tag'] ?? ''));
            $tagLabel = $tag !== '' ? $tag : (string)$uid;
            $etype = (string)($cast['employment_type'] ?? 'part_time');
            $etypeLabel = $etype === 'regular' ? 'レギュラー' : 'バイト';
            $dst = $cast['default_start_time'] ? substr((string)$cast['default_start_time'], 0, 5) : '-';
            $hasLine = ((int)($cast['has_line'] ?? 0) === 1);
            $active = ((int)($cast['is_active'] ?? 0) === 1);
            $note = (string)($noteMap[$uid] ?? '');
          ?>
          <article class="castItem">
            <div class="castMain">
              <div class="castTagBlock">
                <div class="fieldLabel">店番</div>
                <div class="castTag mono"><?= h($tagLabel) ?></div>
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
                <div><?= h($etypeLabel) ?></div>
              </div>
              <div class="metaItem">
                <div class="fieldLabel">基本開始</div>
                <div class="mono"><?= h($dst) ?></div>
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
                <div><?= $active ? '<span class="badge ok">在籍</span>' : '<span class="badge off">停止</span>' ?></div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="muted" style="margin-top:10px;">
        ※ 店番は <b>cast_profiles.shop_tag</b> を優先表示します（列が無い/空の間は user_id を表示）。<br>
        ※ 📝 はメモあり（PCはホバー、スマホはタップで表示）<br>
        ※ 各行の「連携QR」はそのキャスト専用です。
      </div>
    </div>
  </div>
</div>

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
.muted{ opacity:.75; font-size:12px; }
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

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
}
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
@media (min-width: 1180px){
  .castGrid{
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:12px;
  }
}
@media (min-width: 1500px){
  .castGrid{
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:12px;
  }
}
@media (min-width: 1750px){
  .castGrid{
    grid-template-columns:repeat(5, minmax(0, 1fr));
    gap:14px;
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
<?php render_page_end(); ?>
