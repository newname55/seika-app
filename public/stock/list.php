<?php
declare(strict_types=1);

/**
 * public/stock/list.php
 * - haruto_core の現行スキーマ（stock_products / stock_item_locations / stock_items / stock_moves / stock_categories）で動く版
 * - 画面UIは「良かった方（KPI + フィルタ + PCテーブル + SPカード）」を維持
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

// 店舗コンテキストがあるなら利用（current_store_id() / require_store_selected() 等）
$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

/** ====== ユーティリティ ====== */
function table_exists(PDO $pdo, string $table): bool {
  $t = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table);
  $sql = "SHOW TABLES LIKE " . $pdo->quote($t);
  return (bool)$pdo->query($sql)->fetchColumn();
}

function table_has_column(PDO $pdo, string $table, string $col): bool {
  $c = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $col);
  $sql = "SHOW COLUMNS FROM `" . str_replace('`','``',$table) . "` LIKE " . $pdo->quote($c);
  return (bool)$pdo->query($sql)->fetch();
}

/**
 * 店舗ID決定（安全版）
 * 1) app/store.php があれば current_store_id() / require_store_selected() を優先
 * 2) それが無ければ GET store_id -> SESSION store_id
 * 3) それでも無ければ store_select.php へ（super_user含む）
 */
function require_store_selected_safe(): int {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  // ① current_store_id() があれば最優先で使う（引数不要で安全）
  if (function_exists('current_store_id')) {
    $sid = (int)current_store_id();
    if ($sid > 0) {
      $_SESSION['store_id'] = $sid;
      return $sid;
    }
  }

  // ② GET → SESSION fallback
  $sid = (int)($_GET['store_id'] ?? 0);
  if ($sid <= 0) {
    $sid = (int)($_SESSION['store_id'] ?? 0);
  }

  // ③ それでも無ければ店舗選択へ
  if ($sid <= 0) {
    $next = $_SERVER['REQUEST_URI'] ?? '/wbss/public/stock/list.php';
    header('Location: /wbss/public/store_select.php?next=' . urlencode($next));
    exit;
  }

  $_SESSION['store_id'] = $sid;
  return $sid;
}


/** ✅ barcodeが無い商品でも move.php で検索できるようにする */
function move_q_for_row(array $r): string {
  $b = trim((string)($r['barcode'] ?? ''));
  if ($b !== '') return $b;
  $n = trim((string)($r['name'] ?? ''));
  return $n;
}

function ptype_label(string $ptype): string {
  return match ($ptype) {
    'mixer'      => '割物',
    'bottle'     => '酒',
    'consumable' => '消耗品',
    default      => ($ptype !== '' ? $ptype : '-'),
  };
}

function format_stock_last_move(?string $value): string {
  $raw = trim((string)$value);
  if ($raw === '') return '-';
  $ts = strtotime($raw);
  if ($ts === false) return $raw;
  return date('m-d H:i', $ts);
}

function normalize_search_text(string $value): string {
  $value = trim($value);
  if ($value === '') return '';
  $value = mb_convert_kana($value, 'asKV', 'UTF-8');
  return mb_strtolower($value, 'UTF-8');
}

function kana_search_variants(string $value): array {
  $base = normalize_search_text($value);
  if ($base === '') return [];

  $variants = [
    $base,
    mb_convert_kana($base, 'c', 'UTF-8'),
    mb_convert_kana($base, 'C', 'UTF-8'),
  ];

  $uniq = [];
  foreach ($variants as $variant) {
    $variant = trim($variant);
    if ($variant === '' || isset($uniq[$variant])) continue;
    $uniq[$variant] = true;
  }
  return array_keys($uniq);
}

$store_id = (int)require_store_selected_safe();

/** ====== 前提チェック ====== */
if (!table_exists($pdo, 'stock_products')) {
  http_response_code(500);
  exit('stock_products が存在しません');
}
if (!table_has_column($pdo, 'stock_products', 'store_id')) {
  http_response_code(500);
  exit('stock_products.store_id が必要です（列が見つかりません）');
}

/** ====== フィルタ ====== */
$q      = trim((string)($_GET['q'] ?? ''));
$ptype  = trim((string)($_GET['ptype'] ?? ''));   // mixer/bottle/consumable
$cat_id = (int)($_GET['cat'] ?? 0);

$only_low  = ((string)($_GET['low'] ?? '') === '1');  // reorder未満だけ
$only_zero = ((string)($_GET['zero'] ?? '') === '1'); // 0だけ

$sort = (string)($_GET['sort'] ?? 'name'); // name|qty|updated
$dir  = (string)($_GET['dir'] ?? 'asc');   // asc|desc

$allowedSort = ['name','qty','updated'];
if (!in_array($sort, $allowedSort, true)) $sort = 'name';
$dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
$has_detail_filters = ($ptype !== '' || $cat_id > 0 || $sort !== 'name' || $dir !== 'asc');

$orderSql = match ($sort) {
  'qty'     => "qty {$dir}, p.name asc",
  'updated' => "last_move_at {$dir}, p.name asc",
  default   => "p.name {$dir}",
};

/** ====== カテゴリ一覧 ====== */
$has_categories = table_exists($pdo, 'stock_categories');
$categories = [];

if ($has_categories) {
  try {
    $hasCatStore = table_has_column($pdo, 'stock_categories', 'store_id');
    if ($hasCatStore) {
      $st = $pdo->prepare("
        SELECT id, name
        FROM stock_categories
        WHERE is_active=1
          AND (store_id = ? OR store_id IS NULL)
        ORDER BY sort_order, name
      ");
      $st->execute([$store_id]);
    } else {
      $st = $pdo->prepare("
        SELECT id, name
        FROM stock_categories
        WHERE is_active=1
        ORDER BY sort_order, name
      ");
      $st->execute();
    }
    $categories = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $categories = [];
  }
}

// category map
$catMap = [];
foreach ($categories as $c) $catMap[(int)$c['id']] = (string)$c['name'];

/** ====== 一覧取得 ====== */
$where  = [];
$params = [];

$where[]  = "p.store_id = ?";
$params[] = $store_id;

// is_active（無い環境もあるので保険）
if (table_has_column($pdo, 'stock_products', 'is_active')) {
  $where[] = "p.is_active = 1";
}

if ($q !== '') {
  // search_text があるなら拾う（無ければ name/barcode のみ）
  $hasSearchText = table_has_column($pdo, 'stock_products', 'search_text');
  $qVariants = kana_search_variants($q);
  $cond = [];
  foreach ($qVariants as $qVariant) {
    $like = '%' . $qVariant . '%';
    $cond[] = "p.name LIKE ?";
    $params[] = $like;
    $cond[] = "p.barcode LIKE ?";
    $params[] = $like;
    if ($hasSearchText) {
      $cond[] = "p.search_text LIKE ?";
      $params[] = $like;
    }
  }
  $where[] = "(" . implode(" OR ", $cond) . ")";
}

if ($ptype !== '') {
  if (table_has_column($pdo, 'stock_products', 'product_type')) {
    $where[] = "p.product_type = ?";
    $params[] = $ptype;
  }
}

if ($cat_id > 0) {
  if (table_has_column($pdo, 'stock_products', 'category_id')) {
    $where[] = "p.category_id = ?";
    $params[] = $cat_id;
  }
}

$having = [];
if ($only_zero) $having[] = "qty = 0";
if ($only_low)  $having[] = "(reorder_point IS NOT NULL AND qty < reorder_point)";

// 在庫数は stock_item_locations の合計を正本とし、無い環境だけ stock_items にフォールバックする。
$has_item_locations = table_exists($pdo, 'stock_item_locations') && table_has_column($pdo, 'stock_item_locations', 'qty');
$has_items = table_exists($pdo, 'stock_items') && table_has_column($pdo, 'stock_items', 'qty');
$has_moves = table_exists($pdo, 'stock_moves') && table_has_column($pdo, 'stock_moves', 'created_at');

$joinItemsSql = $has_item_locations ? "
  LEFT JOIN (
    SELECT product_id, SUM(qty) AS qty
    FROM stock_item_locations
    WHERE store_id = ?
    GROUP BY product_id
  ) il ON il.product_id = p.id
" : ($has_items ? "
  LEFT JOIN stock_items i
    ON i.product_id = p.id AND i.store_id = ?
" : "");

$joinMovesSql = $has_moves ? "
  LEFT JOIN (
    SELECT product_id, MAX(created_at) AS last_move_at
    FROM stock_moves
    WHERE store_id = ?
    GROUP BY product_id
  ) lm ON lm.product_id = p.id
" : "";

// SELECT列
$selectQty = $has_item_locations
  ? "COALESCE(il.qty, 0) AS qty"
  : ($has_items ? "COALESCE(i.qty, 0) AS qty" : "0 AS qty");
$selectLast = $has_moves ? "lm.last_move_at" : "NULL AS last_move_at";

// unitカラム名（unit であることはあなたの haruto_core の実データから確認済み）
$unitCol = table_has_column($pdo, 'stock_products', 'unit') ? "p.unit" : "NULL AS unit";

// reorder_point
$rpCol = table_has_column($pdo, 'stock_products', 'reorder_point') ? "p.reorder_point" : "NULL AS reorder_point";

// barcode
$bcCol = table_has_column($pdo, 'stock_products', 'barcode') ? "p.barcode" : "NULL AS barcode";

// product_type
$ptCol = table_has_column($pdo, 'stock_products', 'product_type') ? "p.product_type" : "'' AS product_type";

// category_id
$catIdCol = table_has_column($pdo, 'stock_products', 'category_id') ? "p.category_id" : "NULL AS category_id";

$sql = "
  SELECT
    p.id,
    p.name,
    {$unitCol},
    {$bcCol},
    {$ptCol},
    {$catIdCol},
    {$rpCol},
    {$selectQty},
    {$selectLast}
  FROM stock_products p
  {$joinItemsSql}
  {$joinMovesSql}
  " . (count($where) ? ("WHERE ".implode(" AND ", $where)) : "") . "
  " . (count($having) ? ("HAVING ".implode(" AND ", $having)) : "") . "
  ORDER BY {$orderSql}
";

$bind = [];
if ($has_item_locations || $has_items) $bind[] = $store_id;
if ($has_moves) $bind[] = $store_id; // subquery store_id=?
$bind = array_merge($bind, $params);

try {
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre style='white-space:pre-wrap;padding:16px;background:#111;color:#eee'>";
  echo "stock/list.php error\n\n";
  echo h($e->getMessage()) . "\n\n";
  echo "SQL:\n" . h($sql) . "\n\n";
  echo "BIND:\n" . h(json_encode($bind, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  echo "</pre>";
  exit;
}

/** ====== 集計 ====== */
$total_items = count($rows);
$total_qty   = 0;
$low_count   = 0;
$zero_count  = 0;

foreach ($rows as $r) {
  $qty2 = (int)($r['qty'] ?? 0);
  $total_qty += $qty2;
  if ($qty2 === 0) $zero_count++;

  $rp2 = $r['reorder_point'] ?? null;
  if ($rp2 !== null && $qty2 < (int)$rp2) $low_count++;
}

$activeFilterLabels = [];
if ($q !== '') $activeFilterLabels[] = '検索中';
if ($ptype !== '') $activeFilterLabels[] = '種別: ' . ptype_label($ptype);
if ($cat_id > 0 && isset($catMap[$cat_id])) $activeFilterLabels[] = 'カテゴリ: ' . $catMap[$cat_id];
if ($only_zero) $activeFilterLabels[] = '0在庫だけ';
if ($only_low) $activeFilterLabels[] = '発注点未満だけ';
if ($sort !== 'name' || $dir !== 'asc') {
  $sortLabel = match ($sort) {
    'qty' => '数量',
    'updated' => '最終更新',
    default => '名前',
  };
  $dirLabel = $dir === 'desc' ? '降順' : '昇順';
  $activeFilterLabels[] = '並び順: ' . $sortLabel . ' ' . $dirLabel;
}
$resultSummary = $activeFilterLabels
  ? ('絞り込み中: ' . implode(' / ', $activeFilterLabels) . ' / ' . $total_items . '件表示')
  : ('全商品を表示中 / ' . $total_items . '件');

/** ====== 画面 ====== */
$right = '
  <a class="btn" href="/wbss/public/stock/move.php">入出庫</a>
  <a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>
';
$currentStoreName = function_exists('layout_store_name') ? layout_store_name($store_id) : '';
if ($currentStoreName === '') {
  $currentStoreName = '店舗 #' . $store_id;
}

render_page_start('在庫一覧');
render_header('在庫一覧', [
  'back_href'  => '/wbss/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);

?>
<style>
/* ===== このページだけのUI（中学生でも使える） ===== */

.stock-top{
  display:grid;
  grid-template-columns: 1.3fr .7fr;
  gap:12px;
  align-items:start;
}
@media (max-width: 880px){
  .stock-top{ grid-template-columns: 1fr; }
}

.stock-hero-card{
  padding:16px 16px 14px;
  border:1px solid var(--line);
  border-radius:20px;
  background:linear-gradient(135deg, rgba(255,255,255,.07), rgba(255,255,255,.02));
}
.stock-hero-top{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.stock-hero-kicker{
  font-size:12px;
  font-weight:900;
  color:var(--muted);
  text-transform:uppercase;
  letter-spacing:.08em;
}
.stock-hero-store{
  display:inline-flex;
  align-items:center;
  min-height:34px;
  margin-top:10px;
  padding:0 12px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.05);
  font-size:12px;
  font-weight:900;
}
.stock-hero-title{
  margin-top:10px;
  font-size:28px;
  font-weight:1000;
  line-height:1.08;
}
.stock-hero-desc{
  margin-top:8px;
  max-width:720px;
  color:var(--muted);
  font-size:13px;
  line-height:1.6;
}
.stock-hero-actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  justify-content:flex-end;
}
.stock-stats{
  display:grid;
  grid-template-columns:repeat(4, minmax(0, 1fr));
  gap:10px;
  margin-top:14px;
}
.stock-stat{
  padding:12px 14px;
  border-radius:18px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
}
.stock-stat__label{
  display:flex;
  align-items:center;
  gap:8px;
  font-size:12px;
  color:var(--muted);
  font-weight:800;
}
.stock-stat__value{
  margin-top:8px;
  font-size:28px;
  line-height:1;
  font-weight:1000;
}
.kpi .dot,
.stock-stat .dot{
  width:10px;height:10px;border-radius:999px;
}
.dot-ok{ background: var(--ok); }
.dot-warn{ background: var(--warn); }
.dot-ng{ background: var(--ng); }
.dot-att{ background: var(--accent); }

.quick-actions{
  display:grid;
  grid-template-columns:repeat(4, minmax(0, 1fr));
  gap:10px;
  margin-top:12px;
}
.quick-action{
  display:flex;
  align-items:center;
  gap:12px;
  padding:14px;
  border-radius:18px;
  border:1px solid var(--line);
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  text-decoration:none;
  color:inherit;
  transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}
.quick-action:hover{
  transform:translateY(-2px);
  box-shadow:0 14px 28px rgba(0,0,0,.12);
  border-color:rgba(250,204,21,.35);
}
.quick-action__icon{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:46px;
  height:46px;
  border-radius:14px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.05);
  font-size:22px;
  flex:0 0 auto;
}
.quick-action__body{
  display:flex;
  flex-direction:column;
  gap:3px;
  min-width:0;
}
.quick-action__title{
  font-size:15px;
  font-weight:1000;
  line-height:1.2;
}
.quick-action__desc{
  font-size:11px;
  line-height:1.45;
  color:var(--muted);
}
.quick-action.is-primary{
  border-color:rgba(250,204,21,.45);
  background:linear-gradient(180deg, rgba(250,204,21,.14), rgba(255,255,255,.03));
}
.quick-action.is-danger{
  border-color:rgba(239,68,68,.35);
  background:linear-gradient(180deg, rgba(239,68,68,.12), rgba(255,255,255,.03));
}
.quick-action.is-warn{
  border-color:rgba(245,158,11,.38);
  background:linear-gradient(180deg, rgba(245,158,11,.14), rgba(255,255,255,.03));
}

.filter-shell{
  display:grid;
  gap:12px;
  margin-top:14px;
}
.simple-filters{
  display:grid;
  grid-template-columns:minmax(240px, 1.3fr) minmax(260px, 1fr) minmax(220px, .8fr);
  gap:12px;
  align-items:end;
}
.simple-filter-card{
  padding:10px 12px;
  border:1px solid var(--line);
  border-radius:16px;
  background:rgba(255,255,255,.04);
}
.simple-filter-card.search-card{
  padding:14px 16px;
}
.simple-filter-card label{
  display:block;
  font-size:12px;
  color:var(--muted);
  margin-bottom:4px;
}
.simple-filter-card .in{
  width:100%;
  min-height:42px;
}
.simple-toggles{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:8px;
}
.filter-summary{
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.03);
}
.filter-summary__text{
  font-size:12px;
  color:var(--muted);
  line-height:1.5;
}
.detail-toggle{
  display:inline-flex;
  align-items:center;
  gap:8px;
  cursor:pointer;
  font-size:13px;
  font-weight:900;
  list-style:none;
}
.detail-toggle::-webkit-details-marker{ display:none; }
.detail-toggle::before{
  content:"▶";
  font-size:11px;
  color:var(--muted);
}
details[open] .detail-toggle::before{
  content:"▼";
}

.boardSearch{
  display:flex;
  align-items:center;
  gap:8px;
  min-height:44px;
  padding:0 12px;
  border:1px solid rgba(15,23,42,.12);
  border-radius:12px;
  background:#fff;
}
.boardSearch__label{
  font-size:12px;
  font-weight:900;
  color:#475569;
  white-space:nowrap;
}
.boardSearch__input{
  width:100%;
  min-width:0;
  border:none;
  outline:none;
  background:transparent;
  color:#0f172a;
  font-size:14px;
}
.boardSearch__input::-webkit-search-cancel-button{
  cursor:pointer;
}
.list-count{
  margin-top:4px;
  font-size:12px;
  font-weight:800;
  color:var(--muted);
}
.product-row-is-highlighted{
  outline:2px solid rgba(59,130,246,.42);
  outline-offset:-2px;
}
.product-card-is-highlighted{
  outline:2px solid rgba(59,130,246,.42);
  outline-offset:2px;
}

.filter-grid{
  display:grid;
  grid-template-columns: minmax(220px, 1.25fr) minmax(120px, .62fr) minmax(120px, .62fr) minmax(220px, .95fr) minmax(280px, 1.05fr);
  gap:12px;
  align-items:end;
}
.filter-grid > *{
  min-width:0;
}
.fg label{ display:block; font-size:12px; opacity:.8; margin-bottom:4px; }
.fg .in{ width:100%; min-height:42px; }
.fg .row2{ display:flex; gap:8px; }
.filter-card{
  padding:8px 10px;
  border:1px solid var(--line);
  border-radius:16px;
  background:rgba(255,255,255,.04);
}
.filter-card.search{ grid-column: 1 / span 1; }
.filter-card.sort{ grid-column: 4 / span 2; }
.filter-card.narrow{ min-width:0; }
.filter-options{
  display:grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap:6px;
}
.filter-check{
  position:relative;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  min-height:46px;
  padding:8px 10px;
  border:1px solid var(--line);
  border-radius:18px;
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
  font-size:14px;
  font-weight:900;
  color:var(--txt);
  cursor:pointer;
  transition:transform .15s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
}
.filter-check input{
  position:absolute;
  inset:0;
  opacity:0;
  cursor:pointer;
}
.filter-check:hover{
  transform:translateY(-1px);
  border-color:rgba(96,165,250,.35);
}
.filter-check__text{
  display:flex;
  flex-direction:column;
  gap:1px;
}
.filter-check__title{
  line-height:1.2;
  font-size:13px;
}
.filter-check__hint{
  font-size:10px;
  color:var(--muted);
  font-weight:700;
  line-height:1.25;
}
.filter-state{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:52px;
  height:28px;
  padding:0 8px;
  border-radius:999px;
  background:rgba(148,163,184,.14);
  border:1px solid rgba(148,163,184,.35);
  flex:0 0 auto;
  transition:background .15s ease, border-color .15s ease, color .15s ease;
  font-size:12px;
  font-weight:1000;
  letter-spacing:.06em;
  color:var(--muted);
}
.filter-state::before{
  content:"OFF";
}
.filter-check.is-active{
  border-color:rgba(96,165,250,.45);
  background:linear-gradient(180deg, rgba(96,165,250,.18), rgba(96,165,250,.08));
  box-shadow:0 10px 24px rgba(59,130,246,.12);
}
.filter-check.is-active .filter-state{
  background:rgba(59,130,246,.95);
  border-color:rgba(59,130,246,.95);
  color:#fff;
}
.filter-check.is-active .filter-state::before{
  content:"ON";
}
.filter-check.is-warn.is-active{
  border-color:rgba(245,158,11,.45);
  background:linear-gradient(180deg, rgba(245,158,11,.18), rgba(245,158,11,.08));
  box-shadow:0 10px 24px rgba(245,158,11,.12);
}
.filter-check.is-warn.is-active .filter-state{
  background:rgba(245,158,11,.95);
  border-color:rgba(245,158,11,.95);
  color:#fff;
}
.filter-check.is-danger.is-active{
  border-color:rgba(239,68,68,.45);
  background:linear-gradient(180deg, rgba(239,68,68,.16), rgba(239,68,68,.08));
  box-shadow:0 10px 24px rgba(239,68,68,.12);
}
.filter-check.is-danger.is-active .filter-state{
  background:rgba(239,68,68,.95);
  border-color:rgba(239,68,68,.95);
  color:#fff;
}
.filter-actions{
  grid-column: 5 / 6;
  display:flex;
  justify-content:stretch;
  align-items:stretch;
  gap:8px;
  flex-wrap:wrap;
  min-width:0;
}
.filter-submit{
  flex:1 1 0;
  width:auto;
  min-width:0;
  max-width:none;
  min-height:46px;
  padding:10px 14px;
  white-space:nowrap;
}
.filter-clear{
  flex:0 0 auto;
  min-height:46px;
  white-space:nowrap;
}
.sort-groups{
  display:grid;
  grid-template-columns: 1.2fr .9fr;
  gap:8px;
  align-items:start;
}
.sort-group__label{
  font-size:12px;
  color:var(--muted);
  font-weight:700;
  margin-bottom:4px;
}
.sort-options{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(72px, 1fr));
  gap:6px;
}
.sort-option{
  position:relative;
  display:flex;
  align-items:center;
  justify-content:center;
  min-height:38px;
  padding:6px 8px;
  border:1px solid var(--line);
  border-radius:14px;
  background:rgba(255,255,255,.04);
  font-size:13px;
  font-weight:900;
  color:var(--txt);
  cursor:pointer;
  transition:border-color .15s ease, background .15s ease, box-shadow .15s ease, transform .15s ease;
}
.sort-option input{
  position:absolute;
  inset:0;
  opacity:0;
  cursor:pointer;
}
.sort-option:hover{
  transform:translateY(-1px);
  border-color:rgba(96,165,250,.35);
}
.sort-option.is-active{
  border-color:rgba(59,130,246,.55);
  background:linear-gradient(180deg, rgba(96,165,250,.18), rgba(96,165,250,.08));
  box-shadow:0 10px 24px rgba(59,130,246,.10);
}
@media (max-width: 980px){
  .quick-actions,
  .stock-stats,
  .simple-filters{
    grid-template-columns:1fr;
  }
  .filter-grid{
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    align-items:start;
  }
  .filter-card.search,
  .filter-card.sort,
  .filter-actions{
    grid-column: auto;
  }
  .sort-groups{
    grid-template-columns: 1fr;
  }
  .filter-options{
    grid-template-columns: 1fr;
  }
  .filter-submit{
    max-width:none;
    min-height:42px;
  }
}

body[data-theme="light"] .card{
  background:#fff;
  border-color:#d9e1ea;
  box-shadow:0 10px 24px rgba(15,23,42,.06);
}
body[data-theme="light"] .filter-card{
  background:linear-gradient(180deg, #ffffff, #f8fbff);
  border:1px solid #d9e1ea;
  box-shadow:0 4px 14px rgba(15,23,42,.04);
}
body[data-theme="light"] .stock-hero-card,
body[data-theme="light"] .stock-stat{
  background:#fff;
  border-color:#d9e1ea;
  box-shadow:0 10px 24px rgba(15,23,42,.06);
}
body[data-theme="light"] .simple-filter-card,
body[data-theme="light"] .filter-summary,
body[data-theme="light"] .quick-action,
body[data-theme="light"] .sp-card{
  background:#fff;
  border-color:#d9e1ea;
  box-shadow:0 4px 14px rgba(15,23,42,.04);
}
body[data-theme="light"] .fg label,
body[data-theme="light"] .muted{
  color:#5b6b80;
}
body[data-theme="light"] .btn.in,
body[data-theme="light"] select.btn.in,
body[data-theme="light"] input.btn.in{
  background:#fff;
  border:1px solid #c7d2e0;
  color:#102033;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
}
body[data-theme="light"] .btn.in:focus,
body[data-theme="light"] select.btn.in:focus,
body[data-theme="light"] input.btn.in:focus{
  outline:none;
  border-color:#3b82f6;
  box-shadow:0 0 0 4px rgba(59,130,246,.14);
}
body[data-theme="light"] .boardSearch{
  background:#fff;
  border-color:#d9e1ea;
  box-shadow:0 4px 14px rgba(15,23,42,.04);
}
body[data-theme="light"] .boardSearch__label{
  color:#5b6b80;
}
body[data-theme="light"] .boardSearch__input{
  color:#102033;
}
body[data-theme="light"] .filter-check{
  border:2px solid #d9e1ea;
  background:linear-gradient(180deg, #ffffff, #f7fafc);
  box-shadow:0 4px 14px rgba(15,23,42,.04);
}
body[data-theme="light"] .filter-check__title{
  color:#102033;
}
body[data-theme="light"] .filter-check__hint{
  color:#66768c;
}
body[data-theme="light"] .filter-check:hover{
  border-color:#93c5fd;
  box-shadow:0 8px 18px rgba(59,130,246,.10);
}
body[data-theme="light"] .filter-state{
  background:#eef2f7;
  border-color:#c7d2e0;
  color:#6b7280;
}
body[data-theme="light"] .chip{
  background:#fff;
  border-color:#d9e1ea;
  color:#102033;
}
body[data-theme="light"] .sort-option{
  background:linear-gradient(180deg, #ffffff, #f7fafc);
  border:2px solid #d9e1ea;
  color:#102033;
  box-shadow:0 4px 14px rgba(15,23,42,.04);
}
body[data-theme="light"] .sort-option:hover{
  border-color:#93c5fd;
  box-shadow:0 8px 18px rgba(59,130,246,.10);
}
body[data-theme="light"] .sort-option.is-active{
  border-color:#60a5fa;
  background:linear-gradient(180deg, rgba(219,234,254,.95), rgba(239,246,255,.98));
}
body[data-theme="light"] .tbl th{
  background:#f8fbff;
}
body[data-theme="light"] .tbl td{
  color:#102033;
}

.actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
  justify-content:flex-end;
}

.status-note{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:5px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  font-size:12px;
  font-weight:900;
  white-space:nowrap;
}
.status-note.is-zero{ background:rgba(239,68,68,.14); }
.status-note.is-low{ background:rgba(245,158,11,.16); }
.status-note.is-ok{ background:rgba(52,211,153,.12); }
.row-actions{
  display:flex;
  gap:6px;
  flex-wrap:nowrap;
  align-items:center;
}
.btn.btn-small{
  min-height:auto;
  padding:6px 10px;
  font-size:12px;
  white-space:nowrap;
  min-width:72px;
  justify-content:center;
}

/* テーブル（PC） */
.tbl{
  width:100%;
  border-collapse:collapse;
  font-size:14px;
}
.tbl th{
  text-align:left;
  padding:10px;
  border-bottom:2px solid var(--line);
  white-space:nowrap;
  opacity:.9;
}
.tbl td{
  padding:11px 10px;
  border-bottom:1px solid var(--line);
  vertical-align:middle;
}
.badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.06);
  white-space:nowrap;
}
.badge .dot{ width:8px;height:8px;border-radius:999px; }
.qty-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:6px 12px;
  border-radius:999px;
  border:1px solid var(--line);
  font-weight:1000;
}
.qty-ok{ background: rgba(52,211,153,.14); }
.qty-low{ background: rgba(245,158,11,.18); }
.qty-zero{ background: rgba(251,113,133,.18); }
.table-card{
  overflow:hidden;
}
.table-card__head{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:12px;
  margin-bottom:10px;
}
.table-card__title{
  font-size:18px;
  font-weight:1000;
}
.table-card__desc{
  margin-top:4px;
  font-size:12px;
  color:var(--muted);
}

/* スマホはカード表示（PCは現状維持） */
.pc-only{ display:block; }
.sp-only{ display:none; }
@media (max-width: 720px){
  .pc-only{ display:none; }
  .sp-only{ display:block; }

  .sp-cards{ display:grid; gap:10px; }
  .sp-card{
    border:1px solid var(--line);
    border-radius:18px;
    padding:12px;
    background:rgba(255,255,255,.04);
  }
  .sp-head{
    display:flex;
    gap:10px;
    align-items:flex-start;
    justify-content:space-between;
  }
  .sp-name{
    font-weight:1000;
    font-size:16px;
    line-height:1.2;
  }
  .sp-sub{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:8px;
  }
  .sp-row{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:10px;
    align-items:center;
  }
  .sp-row .muted{ font-size:12px; }
  .sp-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:12px;
  }

  /* バーコードはタップで展開（スマホでも邪魔にならない） */
  details.barcode-detail summary{
    cursor:pointer;
    user-select:none;
    font-weight:900;
  }
  details.barcode-detail summary::marker{ display:none; }
  details.barcode-detail summary:before{ content:"▶ "; }
  details.barcode-detail[open] summary:before{ content:"▼ "; }
  .barcode-value{
    margin-top:6px;
    word-break:break-all;
    font-size:13px;
  }
}

/* ==== PCテーブル：商品名の破綻（1文字改行）を防ぐ ==== */
@media (min-width: 800px){
  .tbl{ table-layout: fixed; }

  .tbl th:nth-child(1),
  .tbl td:nth-child(1){ width: 48px; }

  .tbl th:nth-child(2),
  .tbl td:nth-child(2){
    width: 24%;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .tbl td:nth-child(2){
    word-break: normal !important;
    overflow-wrap: normal !important;
  }

  .tbl th:nth-child(3),
  .tbl td:nth-child(3){ width: 76px; }

  .tbl th:nth-child(4),
  .tbl td:nth-child(4){ width: 56px; }

  .tbl th:nth-child(5),
  .tbl td:nth-child(5){ width: 86px; }

  .tbl th:nth-child(6),
  .tbl td:nth-child(6){ width: 14%; }

  .tbl th:nth-child(7),
  .tbl td:nth-child(7){ width: 62px; }

  .tbl th:nth-child(8),
  .tbl td:nth-child(8){ width: 130px; }

  .tbl th:nth-child(9),
  .tbl td:nth-child(9){ width: 170px; }
}

/* 数量セル：中央に置く */
.td-qty{
  text-align:center;
  vertical-align:middle;
  white-space:nowrap;
}

.qty-pill{
  position:relative;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:28px;
  min-width:46px;
  padding:0 14px;
  padding-left:26px;
  line-height:1;
  font-weight:900;
}
.qty-pill .dot{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  width:8px;
  height:8px;
  border-radius:999px;
}

.tbl td, .tbl th { vertical-align: middle; }

/* バーコード列は細く・省スペース */
</style>

<div class="page">

  <div class="card">
    <div class="stock-hero-card">
      <div class="stock-hero-top">
        <div>
          <div class="stock-hero-kicker">Stock Watch</div>
          <div class="stock-hero-store"><?= h($currentStoreName) ?></div>
          <div class="stock-hero-title">在庫判断を最短でできる一覧</div>
          <div class="stock-hero-desc">不足、0在庫、更新状況を同じ視線の流れで見られるように整えています。まずは上の集計を見て、必要ならそのまま入出庫へ進めます。</div>
        </div>
        <div class="stock-hero-actions">
          <a class="btn btn-primary" href="/wbss/public/stock/move.php<?= $ptype!==''?('?ptype='.urlencode($ptype)) : '' ?>">＋ 入出庫</a>
          <a class="btn" href="/wbss/public/stock/inventory.php">棚卸へ進む</a>
        </div>
      </div>

      <div class="stock-stats">
        <div class="stock-stat">
          <div class="stock-stat__label"><span class="dot dot-att"></span>登録商品</div>
          <div class="stock-stat__value"><?= (int)$total_items ?></div>
        </div>
        <div class="stock-stat">
          <div class="stock-stat__label"><span class="dot dot-ok"></span>合計数量</div>
          <div class="stock-stat__value"><?= (int)$total_qty ?></div>
        </div>
        <div class="stock-stat">
          <div class="stock-stat__label"><span class="dot dot-ng"></span>0在庫</div>
          <div class="stock-stat__value"><?= (int)$zero_count ?></div>
        </div>
        <div class="stock-stat">
          <div class="stock-stat__label"><span class="dot dot-warn"></span>低在庫</div>
          <div class="stock-stat__value"><?= (int)$low_count ?></div>
        </div>
      </div>
    </div>

    <div class="quick-actions">
      <a class="quick-action is-primary" href="/wbss/public/stock/move.php<?= $ptype!==''?('?ptype='.urlencode($ptype)) : '' ?>">
        <span class="quick-action__icon">📥</span>
        <span class="quick-action__body">
          <span class="quick-action__title">入出庫へ進む</span>
          <span class="quick-action__desc">いま見ている条件のまま数量を増減</span>
        </span>
      </a>
      <a class="quick-action is-danger" href="/wbss/public/stock/list.php?<?= h(http_build_query(['store_id'=>$store_id, 'zero'=>1])) ?>">
        <span class="quick-action__icon">🟥</span>
        <span class="quick-action__body">
          <span class="quick-action__title">0在庫だけ確認</span>
          <span class="quick-action__desc">欠品している商品だけに絞る</span>
        </span>
      </a>
      <a class="quick-action is-warn" href="/wbss/public/stock/list.php?<?= h(http_build_query(['store_id'=>$store_id, 'low'=>1])) ?>">
        <span class="quick-action__icon">🟨</span>
        <span class="quick-action__body">
          <span class="quick-action__title">少ない商品を確認</span>
          <span class="quick-action__desc">補充判断が必要なものだけを見る</span>
        </span>
      </a>
      <a class="quick-action" href="/wbss/public/stock/list.php">
        <span class="quick-action__icon">🧹</span>
        <span class="quick-action__body">
          <span class="quick-action__title">条件をクリア</span>
          <span class="quick-action__desc">一覧を最初の状態に戻す</span>
        </span>
      </a>
    </div>

    <form method="get" class="filter-shell">
      <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">

      <div class="simple-filters">
        <div class="simple-filter-card search-card">
          <label>商品をさがす</label>
          <div class="boardSearch" id="stockSearchForm">
            <span class="boardSearch__label">検索</span>
            <input
              type="search"
              id="stockSearch"
              class="boardSearch__input"
              name="q"
              value="<?= h($q) ?>"
              placeholder="例）角 / 鏡月 / 490..."
              autocomplete="off"
            >
          </div>
        </div>

        <div class="simple-filter-card">
          <label>かんたん絞り込み</label>
          <div class="simple-toggles">
            <label class="filter-check is-danger <?= $only_zero ? 'is-active' : '' ?>">
              <input type="checkbox" name="zero" value="1" <?= $only_zero?'checked':'' ?>>
              <span class="filter-check__text">
                <span class="filter-check__title">0だけ</span>
                <span class="filter-check__hint">在庫ゼロの商品だけ見る</span>
              </span>
              <span class="filter-state" aria-hidden="true"></span>
            </label>
            <label class="filter-check is-warn <?= $only_low ? 'is-active' : '' ?>">
              <input type="checkbox" name="low" value="1" <?= $only_low?'checked':'' ?>>
              <span class="filter-check__text">
                <span class="filter-check__title">少ないものだけ</span>
                <span class="filter-check__hint">補充が必要な商品だけ見る</span>
              </span>
              <span class="filter-state" aria-hidden="true"></span>
            </label>
          </div>
        </div>

        <div class="filter-actions">
          <button class="btn btn-primary filter-submit" type="submit">条件を反映</button>
          <a class="btn filter-clear" href="/wbss/public/stock/list.php?<?= h(http_build_query(['store_id' => $store_id])) ?>">リセット</a>
        </div>
      </div>

      <div class="filter-summary">
        <div class="filter-summary__text"><?= h($resultSummary) ?></div>
        <details <?= $has_detail_filters ? 'open' : '' ?>>
          <summary class="detail-toggle">詳細条件をひらく</summary>
          <div class="filter-grid" style="margin-top:12px;">
            <div class="fg filter-card narrow">
              <label class="muted">種別</label>
              <select class="btn in" name="ptype">
                <option value="">全部</option>
                <option value="bottle"     <?= $ptype==='bottle'?'selected':'' ?>>酒</option>
                <option value="mixer"      <?= $ptype==='mixer'?'selected':'' ?>>割物</option>
                <option value="consumable" <?= $ptype==='consumable'?'selected':'' ?>>消耗品</option>
              </select>
            </div>

            <?php if ($has_categories): ?>
            <div class="fg filter-card narrow">
              <label class="muted">カテゴリ</label>
              <select class="btn in" name="cat">
                <option value="0">全部</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $cat_id) ? 'selected' : '' ?>>
                    <?= h((string)$c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="fg filter-card sort">
              <label class="muted">並び替え</label>
              <div class="sort-groups">
                <div class="sort-group">
                  <div class="sort-group__label">基準</div>
                  <div class="sort-options">
                    <label class="sort-option <?= $sort==='name' ? 'is-active' : '' ?>">
                      <input type="radio" name="sort" value="name" <?= $sort==='name'?'checked':'' ?>>
                      名前
                    </label>
                    <label class="sort-option <?= $sort==='qty' ? 'is-active' : '' ?>">
                      <input type="radio" name="sort" value="qty" <?= $sort==='qty'?'checked':'' ?>>
                      数量
                    </label>
                    <label class="sort-option <?= $sort==='updated' ? 'is-active' : '' ?>">
                      <input type="radio" name="sort" value="updated" <?= $sort==='updated'?'checked':'' ?>>
                      最終更新
                    </label>
                  </div>
                </div>

                <div class="sort-group">
                  <div class="sort-group__label">順序</div>
                  <div class="sort-options">
                    <label class="sort-option <?= $dir==='asc' ? 'is-active' : '' ?>">
                      <input type="radio" name="dir" value="asc" <?= $dir==='asc'?'checked':'' ?>>
                      昇順
                    </label>
                    <label class="sort-option <?= $dir==='desc' ? 'is-active' : '' ?>">
                      <input type="radio" name="dir" value="desc" <?= $dir==='desc'?'checked':'' ?>>
                      降順
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </details>
      </div>
    </form>

    <div class="muted" style="margin-top:10px;">
      0在庫＝赤 / 低在庫＝黄 / OK＝緑（見間違い防止）
    </div>
  </div>

  <!-- =======================
       PC: テーブル
  ======================= -->
  <div class="card pc-only table-card" style="margin-top:14px;">
    <div class="table-card__head">
      <div>
        <div class="table-card__title">商品一覧</div>
        <div class="table-card__desc">数量、発注点、最終更新、操作を一行で見られるようにしています。</div>
        <div class="list-count"><span id="visibleProductCount"><?= count($rows) ?></span> / <?= count($rows) ?> 件を表示中</div>
      </div>
    </div>
    <div>
      <table class="tbl">
        <thead>
          <tr>
            <th>ID</th>
            <th>商品</th>
            <th>数量</th>
            <th>単位</th>
            <th>種別</th>
            <th>カテゴリ</th>
            <th style="text-align:right;">発注点</th>
            <th>最終更新</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="muted" style="padding:12px;">該当データがありません</td></tr>
          <?php endif; ?>

          <?php foreach ($rows as $r): ?>
            <?php
              $qty3 = (int)($r['qty'] ?? 0);
              $rp3  = $r['reorder_point'] ?? null;
              $is_low  = ($rp3 !== null && $qty3 < (int)$rp3);
              $is_zero = ($qty3 === 0);

              $dotColor = $is_zero ? 'var(--ng)' : ($is_low ? 'var(--warn)' : 'var(--ok)');
              $qtyClass = $is_zero ? 'qty-zero' : ($is_low ? 'qty-low' : 'qty-ok');
              $statusLabel = $is_zero ? '在庫なし' : ($is_low ? '少ないです' : '在庫OK');
              $statusClass = $is_zero ? 'is-zero' : ($is_low ? 'is-low' : 'is-ok');

              $cid   = (int)($r['category_id'] ?? 0);
              $cname = $cid > 0 ? ($catMap[$cid] ?? ('#'.$cid)) : '-';

              $last  = format_stock_last_move((string)($r['last_move_at'] ?? ''));
              $moveq = move_q_for_row($r);
              $searchParts = array_filter([
                (string)($r['id'] ?? ''),
                (string)($r['name'] ?? ''),
                (string)($r['barcode'] ?? ''),
                (string)$cname,
                (string)ptype_label((string)($r['product_type'] ?? '')),
              ], static fn($v) => $v !== '');
              $searchText = normalize_search_text(implode(' ', $searchParts));
            ?>
            <tr
              id="product-row-<?= (int)$r['id'] ?>"
              data-product-row
              data-search="<?= h($searchText) ?>"
            >
              <td><?= (int)$r['id'] ?></td>
              <td style="font-weight:900;" title="<?= h((string)$r['name']) ?>">
                <div><?= h((string)$r['name']) ?></div>
                <div class="muted" style="margin-top:4px; font-size:11px;"><?= h($statusLabel) ?></div>
              </td>

              <td class="td-qty">
                <span class="qty-pill <?= h($qtyClass) ?>">
                  <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                  <?= (int)$qty3 ?>
                </span>
              </td>

              <td><?= h((string)($r['unit'] ?? '')) ?></td>

              <td>
                <span class="badge">
                  <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                  <?= h(ptype_label((string)($r['product_type'] ?? ''))) ?>
                </span>
              </td>

              <td><?= h($cname) ?></td>

              <td style="text-align:right;"><?= $rp3 === null ? '-' : (int)$rp3 ?></td>
              <td><div class="muted"><?= h($last) ?></div></td>

              <td>
                <div class="row-actions">
                  <a class="btn btn-small btn-primary"
                     href="/wbss/public/stock/move.php?<?= h(http_build_query(['q'=>$moveq, 'ptype'=>$ptype ?: null])) ?>">
                    <?= $is_zero ? '入庫する' : '入出庫' ?>
                  </a>
                  <a class="btn btn-small"
                     href="/wbss/public/stock/inventory.php?<?= h(http_build_query(['q'=>$moveq])) ?>">
                    棚卸
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- =======================
       SP: カード
  ======================= -->
  <div class="card sp-only" style="margin-top:14px;">
    <?php if (!$rows): ?>
      <div class="muted">該当データがありません</div>
    <?php else: ?>
      <div class="sp-cards">
        <?php foreach ($rows as $r): ?>
          <?php
            $qty3 = (int)($r['qty'] ?? 0);
            $rp3  = $r['reorder_point'] ?? null;
            $is_low  = ($rp3 !== null && $qty3 < (int)$rp3);
            $is_zero = ($qty3 === 0);

            $dotColor = $is_zero ? 'var(--ng)' : ($is_low ? 'var(--warn)' : 'var(--ok)');
            $qtyClass = $is_zero ? 'qty-zero' : ($is_low ? 'qty-low' : 'qty-ok');
            $statusLabel = $is_zero ? '在庫なし' : ($is_low ? '少ないです' : '在庫OK');
            $statusClass = $is_zero ? 'is-zero' : ($is_low ? 'is-low' : 'is-ok');

            $cid   = (int)($r['category_id'] ?? 0);
            $cname = $cid > 0 ? ($catMap[$cid] ?? ('#'.$cid)) : '-';

            $last    = format_stock_last_move((string)($r['last_move_at'] ?? ''));
            $moveq   = move_q_for_row($r);
            $searchParts = array_filter([
              (string)($r['id'] ?? ''),
              (string)($r['name'] ?? ''),
              (string)($r['barcode'] ?? ''),
              (string)$cname,
              (string)ptype_label((string)($r['product_type'] ?? '')),
            ], static fn($v) => $v !== '');
            $searchText = normalize_search_text(implode(' ', $searchParts));
          ?>
          <div
            class="sp-card"
            id="product-card-<?= (int)$r['id'] ?>"
            data-product-card
            data-product-id="<?= (int)$r['id'] ?>"
            data-search="<?= h($searchText) ?>"
          >
            <div class="sp-head">
              <div>
                <div class="sp-name"><?= h((string)$r['name']) ?></div>
                <div class="sp-sub">
                  <span class="badge">
                    <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                    <?= h(ptype_label((string)($r['product_type'] ?? ''))) ?>
                  </span>
                  <span class="badge">
                    <span class="dot dot-att"></span>
                    <?= h($cname) ?>
                  </span>
                </div>
              </div>

              <div style="text-align:right;">
                <div class="muted" style="font-size:12px;">在庫</div>
                <div style="margin-top:4px;">
                  <span class="qty-pill <?= h($qtyClass) ?>">
                    <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                    <?= (int)$qty3 ?>
                  </span>
                  <span class="muted" style="margin-left:6px; font-weight:900;"><?= h((string)($r['unit'] ?? '')) ?></span>
                </div>
              </div>
            </div>

            <div class="sp-row">
              <div class="muted">発注点</div>
              <div style="font-weight:900;"><?= $rp3 === null ? '—' : (int)$rp3 ?></div>
            </div>

            <div class="sp-row">
              <div class="muted">最終更新</div>
              <div class="muted" style="text-align:right;"><?= h($last) ?></div>
            </div>
            <div class="sp-row">
              <div class="muted">状態</div>
              <div class="status-note <?= h($statusClass) ?>"><?= h($statusLabel) ?></div>
            </div>
            <div class="sp-actions">
              <a class="btn btn-primary" style="flex:1; justify-content:center;"
                 href="/wbss/public/stock/move.php?<?= h(http_build_query(['q'=>$moveq, 'ptype'=>$ptype ?: null])) ?>">
                <?= $is_zero ? 'この商品を入庫' : 'この商品を入出庫' ?>
              </a>
              <a class="btn" style="flex:1; justify-content:center;"
                 href="/wbss/public/stock/inventory.php?<?= h(http_build_query(['q'=>$moveq])) ?>">
                棚卸する
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
document.querySelectorAll('.filter-check input[type="checkbox"]').forEach((input) => {
  const label = input.closest('.filter-check');
  if (!label) return;
  const sync = () => label.classList.toggle('is-active', input.checked);
  sync();
  input.addEventListener('change', sync);
});

document.querySelectorAll('.sort-option input[type="radio"]').forEach((input) => {
  const syncGroup = () => {
    const name = input.name;
    document.querySelectorAll(`.sort-option input[name="${name}"]`).forEach((radio) => {
      radio.closest('.sort-option')?.classList.toggle('is-active', radio.checked);
    });
  };
  syncGroup();
  input.addEventListener('change', syncGroup);
});

(() => {
  const searchInput = document.getElementById('stockSearch');
  const searchForm = document.getElementById('stockSearchForm');
  const visibleCount = document.getElementById('visibleProductCount');
  const rows = Array.from(document.querySelectorAll('[data-product-row]'));
  const cards = Array.from(document.querySelectorAll('[data-product-card]'));

  if (!searchInput || (rows.length === 0 && cards.length === 0)) {
    return;
  }

  const normalizeText = (value) => String(value || '')
    .trim()
    .normalize('NFKC')
    .toLowerCase()
    .replace(/[\u30a1-\u30f6]/g, (ch) =>
      String.fromCharCode(ch.charCodeAt(0) - 0x60)
    );

  function applyProductSearch() {
    const keyword = normalizeText(searchInput.value);
    let shown = 0;

    rows.forEach((row) => {
      const matches = keyword === '' || normalizeText(row.dataset.search).includes(keyword);
      row.hidden = !matches;
      row.classList.remove('product-row-is-highlighted');
      if (matches) shown += 1;
    });

    cards.forEach((card) => {
      const matches = keyword === '' || normalizeText(card.dataset.search).includes(keyword);
      card.hidden = !matches;
      card.classList.remove('product-card-is-highlighted');
    });

    if (visibleCount) {
      visibleCount.textContent = String(shown);
    }
  }

  function scrollToFirstVisible() {
    const firstRow = rows.find((row) => !row.hidden);
    if (firstRow) {
      firstRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      firstRow.classList.add('product-row-is-highlighted');
      window.setTimeout(() => firstRow.classList.remove('product-row-is-highlighted'), 1600);
      return;
    }

    const firstCard = cards.find((card) => !card.hidden);
    if (firstCard) {
      firstCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      firstCard.classList.add('product-card-is-highlighted');
      window.setTimeout(() => firstCard.classList.remove('product-card-is-highlighted'), 1600);
    }
  }

  searchInput.addEventListener('input', applyProductSearch);
  searchInput.addEventListener('search', applyProductSearch);
  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      applyProductSearch();
      if (searchInput.value.trim() !== '') {
        scrollToFirstVisible();
      }
    }
  });

  if (searchForm) {
    searchForm.addEventListener('submit', (e) => {
      e.preventDefault();
      applyProductSearch();
      if (searchInput.value.trim() !== '') {
        scrollToFirstVisible();
      }
    });
  }

  applyProductSearch();
})();
</script>

<?php render_page_end(); ?>
