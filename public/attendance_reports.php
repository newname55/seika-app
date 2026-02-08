<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['manager','admin','super_user']);

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

render_page_start('勤怠レポート');
render_header('勤怠レポート');
?>
<div class="page">
  <div class="admin-wrap">
    <div class="rowTop">
      <a class="btn" href="/seika-app/public/dashboard.php">← ダッシュボード</a>
      <div class="title">📊 勤怠レポート</div>
    </div>

    <div class="card" style="margin-top:14px;">
      <div class="cardTitle">準備中</div>
      <div class="muted" style="margin-top:6px;">
        ここに「半期（1-15 / 16-末）」の労働時間集計・遅刻欠勤回数・給与計算の土台を載せます。
      </div>
      <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn" href="/seika-app/public/manager_today_schedule.php">本日の予定</a>
        <a class="btn" href="/seika-app/public/cast_week_plans.php">週予定入力</a>
      </div>
    </div>
  </div>
</div>

<style>
.rowTop{ display:flex; align-items:center; gap:12px; }
.title{ font-weight:1000; font-size:18px; }
.btn{ display:inline-flex; align-items:center; gap:6px; padding:10px 14px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; text-decoration:none; cursor:pointer; }
.card{ padding:14px; border:1px solid var(--line); border-radius:14px; background:var(--cardA); }
.cardTitle{ font-weight:900; }
.muted{ opacity:.75; font-size:12px; }
</style>

<?php render_page_end(); ?>