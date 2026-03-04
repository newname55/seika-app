<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/attendance.php';
require_once __DIR__ . '/../../app/layout.php';

// store.php があれば読み込む（関数差分の保険）
$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
// 注文ランチャは管理側想定（端末でも使うなら staff/cast を追加）
require_role(['admin','manager','super_user']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// 店舗選択（attendance流儀）
$store_id = function_exists('att_safe_store_id') ? (int)att_safe_store_id() : (int)($_SESSION['store_id'] ?? 0);
if ($store_id <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}

// layout.php が store表示に current_store_id() を使うので保険で定義
if (!function_exists('current_store_id')) {
  function current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
  }
}
// 念のため session に store_id を確実に入れる（layoutの店舗表示を安定させる）
$_SESSION['store_id'] = $store_id;

// --------------------------------------------------
// 伝票がある卓だけ（open/locked）を一覧化
// ＋ 最終注文時刻（order_orders）も取得
// ＋ 開始時刻は opened_at が無ければ created_at にフォールバック
// --------------------------------------------------
$st = $pdo->prepare("
  SELECT
    t.id AS ticket_id,
    t.seat_id,
    t.status,
    COALESCE(t.opened_at, t.created_at) AS started_at,
    MAX(oo.created_at) AS last_order_at
  FROM tickets t
  LEFT JOIN order_orders oo
    ON oo.ticket_id = t.id
   AND oo.store_id  = t.store_id
  WHERE t.store_id = ?
    AND t.status IN ('open','locked')
    AND t.seat_id IS NOT NULL
  GROUP BY t.id, t.seat_id, t.status, started_at
  ORDER BY t.seat_id ASC, started_at DESC
");
$st->execute([$store_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// seat_id ごとに最新1件だけ残す（同じ席で複数openがあっても表示が壊れないように）
$activeBySeat = [];
foreach ($rows as $r) {
  $sid = (int)($r['seat_id'] ?? 0);
  if ($sid <= 0) continue;
  if (!isset($activeBySeat[$sid])) $activeBySeat[$sid] = $r;
}
ksort($activeBySeat);

// --------------------------------------------------
// ランチャーリンク（存在するものだけ表示）
// --------------------------------------------------
function link_item(string $title, string $desc, string $url, string $fileAbs, string $tag): ?array {
  if (!is_file($fileAbs)) return null;
  return [
    'title' => $title,
    'desc'  => $desc,
    'url'   => $url,
    'tag'   => $tag,
  ];
}

$items = [];

// 1) キッチン
if ($x = link_item(
  '🍳 キッチン（調理/提供）',
  '新規注文を見て「調理中」「提供済」を押す',
  '/seika-app/public/orders/kitchen.php',
  __DIR__ . '/kitchen.php',
  '現場'
)) $items[] = $x;

// 2) 提供済み一覧
if ($x = link_item(
  '🍶 提供済み一覧',
  '提供が完了した注文を時系列で確認',
  '/seika-app/public/orders/served.php',
  __DIR__ . '/served.php',
  '管理'
)) $items[] = $x;

// 3) 提供済みレポート
if ($x = link_item(
  '📊 提供済みレポート',
  '何が何本・卓ごと・金額をまとめて確認',
  '/seika-app/public/orders/done_report.php',
  __DIR__ . '/done_report.php',
  '管理'
)) $items[] = $x;

// 4) メニュー管理
if ($x = link_item(
  '🖼️ メニュー管理',
  'メニュー・価格・画像・売切を管理',
  '/seika-app/public/orders/admin_menus.php',
  __DIR__ . '/admin_menus.php',
  '管理'
)) $items[] = $x;

$right_html = '<span class="pill">稼働卓: ' . count($activeBySeat) . '</span>';

render_page_start('注文ランチャ');
render_header('注文ランチャ', [
  'back_href'  => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => $right_html,
  'show_store' => true,
  'show_user'  => true,
]);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; display:grid; gap:12px; }
  .muted{ color:var(--muted); }
  .steps{ display:grid; gap:6px; color:var(--muted); margin-top:8px; }

  /* 稼働卓グリッド：3列固定（見やすさ優先） */
  .seat-grid{
    display:grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap:12px;
  }
  @media (max-width: 900px){
    .seat-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 520px){
    .seat-grid{ grid-template-columns: 1fr; }
  }

  .seat-card{
    text-decoration:none;
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:14px;
    border:1px solid var(--line);
    border-radius:16px;
    background: linear-gradient(180deg, var(--cardA), var(--cardB));
    color: var(--txt);
    min-height: 120px;
  }
  .seat-top{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .seat-no{ font-weight:1000; font-size:22px; letter-spacing:.5px; }
  .seat-sub{ display:grid; gap:4px; font-size:12px; color:var(--muted); }
  .badge{
    font-size:12px;
    padding:4px 10px;
    border-radius:999px;
    border:1px solid var(--line);
    color:var(--muted);
    white-space:nowrap;
  }
  .badge.open{ border-color: rgba(80,170,255,.35); color:#9ed0ff; }
  .badge.locked{ border-color: rgba(255,204,102,.35); color:#ffd18a; }

  .seat-foot{
    margin-top:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
  }

  /* 下のリンク群 */
  .launcher-grid{
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap:12px;
    margin-top:12px;
  }
  .launch-card{ display:flex; flex-direction:column; gap:10px; min-height:150px; }
  .launch-title{ font-weight:1000; font-size:16px; }
  .launch-desc{ color:var(--muted); font-size:13px; line-height:1.4; }
  .launch-foot{ margin-top:auto; display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .tag{ font-size:12px; padding:4px 10px; border:1px solid var(--line); border-radius:999px; color:var(--muted); }
  a.btnlink{ text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
  code{ padding:2px 6px; border:1px solid var(--line); border-radius:10px; background: color-mix(in srgb, var(--cardA) 70%, transparent); }
</style>

<main class="page">
  <section class="card">
    <div style="display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:18px;">🧭 伝票がある卓だけ表示（安全運用）</div>
        <div class="steps">
          <div>① <b>伝票を作る</b>（cashier）</div>
          <div>② <b>席（卓）を選ぶ</b> → 保存すると <code>tickets.seat_id</code> に入る</div>
          <div>③ この画面に <b>稼働卓だけ</b> 出る → タップで注文へ</div>
        </div>
      </div>
      <div class="muted">
        稼働卓：<b><?= (int)count($activeBySeat) ?></b><br>
        対象：<span class="pill">open / locked</span>
      </div>
    </div>
  </section>

  <?php if (!count($activeBySeat)): ?>
    <section class="card" style="border-color: rgba(255,204,102,.35);">
      <b>稼働中の伝票（open/locked）がありません</b>
      <div class="muted" style="margin-top:6px;">
        先に会計（cashier）で伝票を作って、席（卓）を選んで保存してください。
      </div>
    </section>
  <?php else: ?>
    <section class="card">
      <div style="font-weight:1000; font-size:16px;">🪑 稼働卓（タップで注文）</div>
      <div class="muted" style="margin-top:6px;">※ 伝票がある卓だけ出ます。席移動しても自動で追従します。</div>

      <div class="seat-grid" style="margin-top:12px;">
        <?php foreach ($activeBySeat as $sid => $r): ?>
          <?php
            $ticketId = (int)($r['ticket_id'] ?? 0);

            $stt = (string)($r['status'] ?? 'open');
            $label = ($stt === 'open') ? '未精算' : (($stt === 'locked') ? '精算待ち' : $stt);
            $badgeClass = ($stt === 'locked') ? 'locked' : 'open';

            $started = $r['started_at'] ?? ($r['opened_at'] ?? null);
            $last    = $r['last_order_at'] ?? null;

            $href = '/seika-app/public/orders/index.php?ticket_id=' . $ticketId . '&seat_id=' . (int)$sid;
          ?>

          <a class="seat-card" href="<?= h($href) ?>">
            <div class="seat-top">
              <div class="seat-no">卓 <?= (int)$sid ?></div>
              <span class="badge <?= h($badgeClass) ?>"><?= h($label) ?></span>
            </div>

            <div class="muted small">
              開始: <?= $started ? h(date('m/d H:i', strtotime($started))) : '（開始未記録）' ?><br>
              最終注文: <?= $last ? h(date('m/d H:i', strtotime($last))) : '（注文なし）' ?>
            </div>

            <div class="seat-foot">
              <span class="muted">注文画面を開く</span>
              <span class="btn primary" style="pointer-events:none;">開く</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (count($items)): ?>
    <section class="card">
      <div style="font-weight:1000; font-size:16px;">🛠️ 運用メニュー</div>
      <div class="launcher-grid">
        <?php foreach ($items as $it): ?>
          <div class="card launch-card" style="box-shadow:none;">
            <div class="launch-title"><?= h((string)$it['title']) ?></div>
            <div class="launch-desc"><?= h((string)$it['desc']) ?></div>
            <div class="launch-foot">
              <span class="tag"><?= h((string)$it['tag']) ?></span>
              <a class="btn primary btnlink" href="<?= h((string)$it['url']) ?>">開く</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="card">
    <div class="muted">
      このページURL：<code>/seika-app/public/orders/dashboard_orders.php</code>
    </div>
  </section>
</main>

<?php render_page_end(); ?>