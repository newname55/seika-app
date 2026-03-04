<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';


require_login();
require_role(['admin','manager','super_user']);

$pdo = db();

/* =========================
   helper
========================= */
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

function current_manageable_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.code IN ('admin','manager')
      AND ur.store_id IS NOT NULL
    LIMIT 1
  ");
  $st->execute([$userId]);
  $sid = $st->fetchColumn();
  if (!$sid) throw new RuntimeException('管理店舗が設定されていません');
  return (int)$sid;
}

/* =========================
   権限 / 店舗確定
========================= */
$isSuper = has_role('super_user');

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? 0);
  if ($storeId <= 0) {
    $storeId = (int)$pdo->query("
      SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1
    ")->fetchColumn();
    if ($storeId <= 0) throw new RuntimeException('有効な店舗がありません');
  }
} else {
  $storeId = current_manageable_store_id($pdo, current_user_id());
}

/* =========================
   マスタ
========================= */
$stores = $isSuper
  ? $pdo->query("SELECT id,name FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
  : [];

/* =========================
   キャスト一覧（B: 店番は cast_profiles.shop_tag）
========================= */
$st = $pdo->prepare("
  SELECT
    user_id AS id,
    display_name,
    CASE WHEN shop_tag='' THEN '-' ELSE shop_tag END AS shop_tag,
    employment_type,
    has_line
  FROM v_store_casts_active
  WHERE store_id=?
  ORDER BY
    CASE WHEN shop_tag='' THEN 999999 ELSE CAST(shop_tag AS UNSIGNED) END ASC,
    display_name ASC,
    user_id ASC
");
$st->execute([$storeId]);
$casts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
/* =========================
   招待リンク一覧
========================= */
$st = $pdo->prepare("
  SELECT *
  FROM invite_tokens
  WHERE store_id=?
  ORDER BY created_at DESC
  LIMIT 50
");
$st->execute([$storeId]);
$invites = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   POST
========================= */
$msg=''; $err='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    if (($_POST['action'] ?? '') === 'create_invite') {

      $expires = trim((string)($_POST['expires_at'] ?? ''));
      if ($expires === '') {
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
      }

      $raw  = rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
      $hash = hash('sha256',$raw);

      $pdo->prepare("
        INSERT INTO invite_tokens
          (token, token_hash, store_id, invite_type,
           created_by_user_id, created_at, expires_at, is_active)
        VALUES
          (?, ?, ?, 'cast', ?, NOW(), ?, 1)
      ")->execute([
        $raw, $hash, $storeId, current_user_id(), $expires
      ]);

      header('Location: store_casts.php?store_id='.$storeId.'&invite='.$raw);
      exit;
    }
  } catch(Throwable $e){
    $err = $e->getMessage();
  }
}

/* =========================
   表示
========================= */
render_page_start('店別キャスト管理');
render_header('店別キャスト管理',[
  'back_href'=>'/seika-app/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);

$inviteRaw = (string)($_GET['invite'] ?? '');
$showInviteId = (int)($_GET['show_invite_id'] ?? 0);
$showInvite = null;

if ($showInviteId > 0) {
  $st = $pdo->prepare("SELECT * FROM invite_tokens WHERE id=? AND store_id=?");
  $st->execute([$showInviteId,$storeId]);
  $showInvite = $st->fetch(PDO::FETCH_ASSOC);
}
?>
<div class="page">
<div class="admin-wrap">

<?php if($inviteRaw): ?>
<div class="card" style="border-color:#22c55e">
  <b>🔗 招待リンク</b>
  <div class="muted" style="word-break:break-all;margin-top:6px">
    <?=h('/seika-app/public/line_login_start.php?invite='.$inviteRaw)?>
  </div>
</div>
<?php endif; ?>

<?php if($err): ?><div class="card" style="border-color:#ef4444"><?=h($err)?></div><?php endif; ?>

<div class="card">
<h3>🔗 招待リンク発行</h3>
<form method="post" class="searchRow">
<input type="hidden" name="action" value="create_invite">
<label>期限
  <input class="btn" name="expires_at" placeholder="未入力で7日">
</label>
<button class="btn btn-primary">発行</button>
</form>
</div>

<div class="card">
<h3>📜 招待リンク一覧</h3>
<table class="tbl">
<tr><th>作成</th><th>期限</th><th>状態</th><th></th></tr>
<?php foreach($invites as $i): ?>
<tr>
<td><?=h($i['created_at'])?></td>
<td><?=h($i['expires_at'])?></td>
<td>
<?= !$i['is_active'] ? '使用済'
   : ($i['expires_at']<date('Y-m-d H:i:s') ? '期限切れ':'有効') ?>
</td>
<td>
<a class="btn"
 href="store_casts.php?store_id=<?=$storeId?>&show_invite_id=<?=$i['id']?>">表示</a>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if($showInvite): ?>
<div class="card" style="border-color:#22c55e">
  <b>🔍 招待リンク詳細</b>
  <div class="muted" style="word-break:break-all;margin-top:6px">
    <?=h('/seika-app/public/line_login_start.php?invite='.$showInvite['token'])?>
  </div>
  <div style="margin-top:8px;display:flex;gap:8px">
    <a class="btn" target="_blank"
       href="/seika-app/public/line_login_start.php?invite=<?=h($showInvite['token'])?>">
       開く
    </a>
    <a class="btn" target="_blank"
       href="/seika-app/public/print_invite_qr.php?invite=<?=h($showInvite['token'])?>">
       🖨 QR
    </a>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>👥 キャスト一覧</h3>

  <div class="tblWrap">
    <table class="tblCast">
      <thead>
        <tr>
          <th class="col-id">ID</th>
          <th>名前</th>
          <th class="col-tag">店番</th>
          <th class="col-etype">レギュラー・アルバイト</th>
          <th class="col-line">LINE</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($casts as $c): ?>
          <?php
            $etype = (string)($c['employment_type'] ?? 'part_time');
            $etypeLabel = ($etype === 'regular') ? 'レギュラー' : 'バイト';
            $etypeCls   = ($etype === 'regular') ? 'badge badge-strong' : 'badge';

            $hasLine = ((int)($c['has_line'] ?? 0) === 1);
            $lineLabel = $hasLine ? '連携済' : '未連携';
            $lineCls   = $hasLine ? 'badge badge-ok' : 'badge badge-ng';
          ?>
          <tr>
            <td class="col-id mono muted"><?= (int)$c['id'] ?></td>
            <td><b><?= h((string)$c['display_name']) ?></b></td>
            <td class="col-tag mono"><?= h((string)$c['shop_tag']) ?></td>
            <td class="col-etype"><span class="<?= h($etypeCls) ?>"><?= h($etypeLabel) ?></span></td>
            <td class="col-line"><span class="<?= h($lineCls) ?>"><?= h($lineLabel) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

  <div class="muted" style="margin-top:10px;">
    ※ 店番は <code>users.shop_tag</code> が存在する場合のみ表示されます（無ければ “-”）。
  </div>
</div>

</div>
</div>
<style>
/* =========================
  Cast table: 強制コントラスト（最優先）
  ※ store_casts.php の style の一番最後に置く
========================= */

/* 文字色を必ず濃くする（テーマに負けない） */
.tblWrap,
.tblCast,
.tblCast th,
.tblCast td{
  color:#0f172a !important; /* slate-900 */
}

/* コンテナ */
.tblWrap{
  overflow:auto;
  border:1px solid rgba(15,23,42,.14) !important;
  border-radius:14px;
  background:#ffffff !important;
}

/* テーブル */
.tblCast{
  width:100%;
  min-width:720px;
  border-collapse:separate;
  border-spacing:0;
}

/* ヘッダー：濃い背景＋白文字（最も見やすい） */
.tblCast thead th{
  position:sticky;
  top:0;
  z-index:2;
  background:#0f172a !important; /* 濃紺 */
  color:#ffffff !important;
  font-weight:900;
  padding:12px 14px;
  border-bottom:1px solid rgba(255,255,255,.18) !important;
  white-space:nowrap;
}

/* 行 */
.tblCast tbody td{
  padding:12px 14px;
  border-bottom:1px solid rgba(15,23,42,.08) !important;
  vertical-align:middle;
  background:#ffffff !important;
}

.tblCast tbody tr:hover td{
  background:#f8fafc !important; /* very light */
}

/* 列：IDは“読めるけど目立たない” */
.col-id{
  width:64px;
  text-align:right;
  font-size:12px;
  color:#64748b !important; /* slate-500 */
  letter-spacing:.2px;
}

.col-tag{ width:84px; text-align:center; }
.col-etype{ width:120px; }
.col-line{ width:120px; }

/* バッジ：薄くしない（枠・背景・文字を強く） */
.badge{
  display:inline-flex;
  align-items:center;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:800;
  border:1px solid rgba(15,23,42,.18) !important;
  background:#f1f5f9 !important; /* slate-100 */
  color:#0f172a !important;
  white-space:nowrap;
}

.badge-strong{
  border-color:rgba(37,99,235,.30) !important;
  background:rgba(37,99,235,.12) !important;
  color:#1d4ed8 !important;
}

.badge-ok{
  border-color:rgba(34,197,94,.35) !important;
  background:rgba(34,197,94,.14) !important;
  color:#166534 !important;
}

.badge-ng{
  border-color:rgba(239,68,68,.35) !important;
  background:rgba(239,68,68,.12) !important;
  color:#991b1b !important;
}

/* モバイル：ID列は非表示でスッキリ */
@media (max-width:520px){
  .col-id{ display:none; }
  .tblCast{ min-width:520px; }
}
</style>
<?php render_page_end(); ?>