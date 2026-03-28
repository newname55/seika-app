<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_messages.php';
require_once __DIR__ . '/../app/service_quiz.php';

require_login();
require_role(['cast']);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/** CSRF（プロジェクト側があればそれを使う / 無ければ簡易） */
function csrf_token_local(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}

$pdo = db();

/** 自分の user_id */
$me = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

/** cast の所属店舗を1つ解決（セッション優先→DB） */
function resolve_cast_store_id(PDO $pdo, int $userId): int {
  $sid = (int)($_SESSION['store_id'] ?? 0);
  if ($sid > 0) return $sid;

  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id AND r.code = 'cast'
    WHERE ur.user_id = ?
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  $sid = (int)($st->fetchColumn() ?: 0);
  if ($sid > 0) $_SESSION['store_id'] = $sid;
  return $sid;
}

/** 営業日（business_day_start で日付をずらす） */
function business_date_for_store(string $businessDayStart, ?DateTimeImmutable $now = null): string {
  $tz = new DateTimeZone('Asia/Tokyo');
  $now = $now ?: new DateTimeImmutable('now', $tz);

  $cut = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $businessDayStart) ? $businessDayStart : '06:00:00';
  if (strlen($cut) === 5) $cut .= ':00';

  $cutDT = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $cut, $tz);
  $biz = ($now < $cutDT) ? $now->modify('-1 day') : $now;
  return $biz->format('Y-m-d');
}

/** ===== 今日状態を作る ===== */
$storeId = ($me > 0) ? resolve_cast_store_id($pdo, $me) : 0;

$storeName = '-';
$bizDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$bizStart = '06:00:00';

$statusLabel = '未出勤';
$statusClass = 'st-none';
$clockIn = null;
$clockOut = null;
$messageSummary = [
  'unread_count' => 0,
  'recent_thanks' => [],
];
$serviceQuizLatest = null;
$serviceQuizTableReady = service_quiz_results_table_ready($pdo);

if ($storeId > 0) {
  $st = $pdo->prepare("SELECT name, business_day_start FROM stores WHERE id=? LIMIT 1");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $storeName = (string)($row['name'] ?? ('#' . $storeId));
  $bizStart  = (string)($row['business_day_start'] ?? '06:00:00');
  $bizDate   = business_date_for_store($bizStart);

  // ★★★ ここで店舗コンテキストを確定させる ★★★
  sync_store_context($pdo, $storeId, $storeName);

  $st = $pdo->prepare("
    SELECT business_date, clock_in, clock_out, status
    FROM attendances
    WHERE user_id=? AND store_id=? AND business_date=?
    LIMIT 1
  ");
  $st->execute([$me, $storeId, $bizDate]);
  $a = $st->fetch(PDO::FETCH_ASSOC);

  if ($a) {
    $clockIn  = $a['clock_in'] ?? null;
    $clockOut = $a['clock_out'] ?? null;

    if (!empty($clockOut)) {
      $statusLabel = '退勤済';
      $statusClass = 'st-done';
    } elseif (!empty($clockIn)) {
      $statusLabel = '出勤中';
      $statusClass = 'st-working';
    } else {
      $statusLabel = '未出勤';
      $statusClass = 'st-none';
    }
  }

  $messageSummary = message_fetch_dashboard_summary($pdo, $storeId, $me, 2);
  if ($serviceQuizTableReady) {
    $serviceQuizLatest = service_quiz_fetch_latest_result($pdo, $storeId, $me);
  }
}
/** store.php / layout.php の“店舗表示”を確実に合わせる */
function sync_store_context(PDO $pdo, int $storeId, string $storeName): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  // 1) よくあるキーを全埋め
  $_SESSION['store_id'] = $storeId;
  $_SESSION['current_store_id'] = $storeId;
  $_SESSION['store_name'] = $storeName;

  $_SESSION['store'] = [
    'id'   => $storeId,
    'name' => $storeName,
  ];

  $_SESSION['store_selected'] = 1;

  // 2) さらにありがちな別名も埋める（layout/store の実装差を吸収）
  $_SESSION['selected_store_id'] = $storeId;
  $_SESSION['selected_store'] = ['id'=>$storeId, 'name'=>$storeName];
  $_SESSION['storeId'] = $storeId;

  // 3) store.php 側に「店舗確定」系の関数があるなら、引数数に合わせて呼ぶ
  //    （これをやるとヘッダーの #0 が直ることが多い）
  $call = function(string $fn, array $args) {
    if (!function_exists($fn)) return;
    try {
      $rf = new ReflectionFunction($fn);
      $need = $rf->getNumberOfRequiredParameters();
      $use = array_slice($args, 0, max($need, 0));
      $rf->invokeArgs($use);
    } catch (Throwable $e) {
      // ここで落とさない（同期だけは残す）
    }
  };

  // “よくある名前”を片っ端から（存在するやつだけ実行される）
  $call('set_current_store_id', [$storeId]);
  $call('set_selected_store_id', [$storeId]);
  $call('set_store_id', [$storeId]);
  $call('set_store_selected', [$storeId, $storeName]);

  // require_store_selected_safe / require_store_selected が PDO 必須になってても対応
  // storeId は入ってるので、ここで「store.phpの正規手順」を通してヘッダー用の状態を確定させる
  $call('require_store_selected_safe', [$pdo]);
  $call('require_store_selected', [$pdo]);
}

render_page_start('WBSS');
render_header('WBSS', [
  'show_store' => false,
  'show_user' => false,
]);
?>
<div class="page">
  <div class="cast-dashboard">
    <div class="cast-message-stack">
      <a class="card cast-message-card is-message" href="/wbss/public/messages.php?store_id=<?= (int)$storeId ?>">
        <div class="cast-message-card__row">
          <div class="cast-message-card__title">📨 未読メッセージ</div>
          <span class="cast-badge <?= (int)$messageSummary['unread_count'] > 0 ? 'is-alert' : '' ?>">
            <?= (int)$messageSummary['unread_count'] > 0 ? '未読あり' : '確認済み' ?>
          </span>
        </div>
        <div class="cast-message-card__row">
          <div class="cast-message-card__value"><?= (int)$messageSummary['unread_count'] ?> 件</div>
          <div class="cast-message-card__mini-actions">
            <span>一覧</span>
            <span>送信</span>
          </div>
        </div>
      </a>

      <a class="card cast-message-card is-thanks" href="/wbss/public/thanks.php?store_id=<?= (int)$storeId ?>">
        <div class="cast-message-card__row">
          <div class="cast-message-card__title">💐 最近のありがとう</div>
          <span class="cast-badge">THANKS</span>
        </div>
        <div class="cast-message-card__row">
          <div class="cast-message-card__summary">
            <?php if (!empty($messageSummary['recent_thanks'])): ?>
              <?php $latestThanks = $messageSummary['recent_thanks'][0]; ?>
              今月 <?= (int)($messageSummary['monthly_thanks_count'] ?? 0) ?> 件 / <?= h(trim((string)($latestThanks['title'] ?? '')) !== '' ? (string)$latestThanks['title'] : 'ありがとう') ?>
            <?php else: ?>
              今月 <?= (int)($messageSummary['monthly_thanks_count'] ?? 0) ?> 件 / まだ届いていません
            <?php endif; ?>
          </div>
          <div class="cast-message-card__mini-actions">
            <span>一覧</span>
            <span>送る</span>
          </div>
        </div>
      </a>
    </div>

    <section class="cast-hero card">
      <div class="cast-hero__bgMascot" aria-hidden="true">
        <img class="cast-hero__bgMascotImg" src="/wbss/public/images/omotedake_renkei.png" alt="">
      </div>
      <div class="cast-hero__top">
        <div class="cast-hero__copy">
          <span class="cast-hero__eyebrow">β-TEST</span>
          <h1 class="cast-hero__title">Dreams Are Kind Energy</h1>
          <p class="cast-hero__lead">必要な操作だけを、迷わずすぐ押せるようにまとめています。</p>
        </div>
        <a class="cast-hero__fortune" href="https://omotedake.com/">
          <span class="cast-hero__fortuneIcon">🔮</span>
          <span>
            <strong>今日の運勢をみる</strong>
            <small>気分転換にワンタップ</small>
          </span>
        </a>
      </div>
    </section>

    <section class="cast-status card">
      <div class="cast-status__head">
        <div>
          <div class="cast-sectionTitle">📍 今日の状態</div>
          <div class="cast-sectionSub">今の勤務状況と、LINE経由の出退勤操作です。</div>
        </div>
        <div class="status <?= h($statusClass) ?>">
          <span class="status__dot"></span>
          <?= h($statusLabel) ?>
        </div>
      </div>

      <div class="cast-status__body">
        <div class="cast-status__info">
          <?php if ($storeId <= 0): ?>
            所属店舗が見つかりません。管理者に「所属店舗」を設定してもらってください。
          <?php else: ?>
            <?php if ($clockIn): ?><span>出勤 <?= h((string)$clockIn) ?></span><?php endif; ?>
            <?php if ($clockOut): ?><span>退勤 <?= h((string)$clockOut) ?></span><?php endif; ?>
            <?php if (!$clockIn && !$clockOut): ?><span>まだ出勤記録がありません。</span><?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="cast-status__actions">
          <button class="cast-actionBtn cast-actionBtn--clockin" type="button" onclick="geoReq('clock_in')" <?= $storeId<=0?'disabled':'' ?>>
            <span class="cast-actionBtn__icon">✅</span>
            <span>
              <strong>出勤する</strong>
              <small>LINEで位置情報を送信</small>
            </span>
          </button>
          <button class="cast-actionBtn cast-actionBtn--clockout" type="button" onclick="geoReq('clock_out')" <?= $storeId<=0?'disabled':'' ?>>
            <span class="cast-actionBtn__icon">🟦</span>
            <span>
              <strong>退勤する</strong>
              <small>LINEで位置情報を送信</small>
            </span>
          </button>
        </div>
      </div>

      <div id="geoMsg" class="cast-status__message muted"></div>

      <input type="hidden" id="csrf" value="<?= h(csrf_token_local()) ?>">
      <input type="hidden" id="store_id" value="<?= (int)$storeId ?>">
    </section>

    <a class="card cast-diagnosis" href="/wbss/public/service_quiz.php">
      <div class="cast-diagnosis__head">
        <div>
          <div class="cast-sectionTitle">🪞 接客タイプ診断</div>
          <div class="cast-sectionSub">12問の4択で、今の接客傾向を4軸から見える化します。</div>
        </div>
        <span class="cast-badge <?= $serviceQuizLatest ? 'is-diagnosis' : '' ?>">
          <?= $serviceQuizLatest ? '最新結果あり' : '未診断' ?>
        </span>
      </div>

      <?php if ($serviceQuizLatest): ?>
        <?php
          $quizType = (array)($serviceQuizLatest['result_type'] ?? []);
          $quizScores = (array)($serviceQuizLatest['scores'] ?? []);
          $quizLabels = (array)($serviceQuizLatest['axis_labels'] ?? []);
        ?>
        <div class="cast-diagnosis__body">
          <div class="cast-diagnosis__main">
            <div class="cast-diagnosis__type"><?= h((string)($quizType['name'] ?? '診断結果')) ?></div>
            <div class="cast-diagnosis__summary"><?= h((string)($quizType['summary'] ?? '')) ?></div>
            <div class="cast-diagnosis__tip">今日の一言: <?= h((string)($quizType['today_tip'] ?? '')) ?></div>
          </div>
          <div class="cast-diagnosis__axes">
            <span>会話 <?= (int)($quizScores['talk_axis'] ?? 0) ?> / <?= h((string)($quizLabels['talk_axis'] ?? '')) ?></span>
            <span>空気 <?= (int)($quizScores['mood_axis'] ?? 0) ?> / <?= h((string)($quizLabels['mood_axis'] ?? '')) ?></span>
            <span>反応 <?= (int)($quizScores['response_axis'] ?? 0) ?> / <?= h((string)($quizLabels['response_axis'] ?? '')) ?></span>
            <span>関係 <?= (int)($quizScores['relation_axis'] ?? 0) ?> / <?= h((string)($quizLabels['relation_axis'] ?? '')) ?></span>
          </div>
        </div>
      <?php else: ?>
        <div class="cast-diagnosis__empty">
          まだ診断結果がありません。最初の1回を受けると、最新タイプをここに表示できます。
        </div>
      <?php endif; ?>
    </a>

    <div class="card-grid cast-grid">
      <a class="card big cast-navCard cast-navCard--diagnosis" href="/wbss/public/service_quiz.php">
        <div class="cast-navCard__icon">🪞</div>
        <b>接客タイプ診断</b>
        <span>12問で自分の傾向をチェック</span>
      </a>

      <a class="card big cast-navCard cast-navCard--training" href="/wbss/public/service_training.php">
        <div class="cast-navCard__icon">🎓</div>
        <b>接客マナートレーニング</b>
        <span>タイプに合わせて接客の土台を育てる</span>
      </a>

      <a class="card big cast-navCard cast-navCard--schedule" href="/wbss/public/cast_week.php">
        <div class="cast-navCard__icon">📅</div>
        <b>出勤予定</b>
        <span>今週の予定を確認</span>
      </a>

      <a class="card big cast-navCard cast-navCard--customer" href="/wbss/public/customer.php">
        <div class="cast-navCard__icon">📝</div>
        <b>顧客管理（営業ノート）</b>
        <span>やり取りやメモを整理</span>
      </a>
      <a class="card big cast-navCard cast-navCard--profile" href="/wbss/public/profile.php">
        <div class="cast-navCard__icon">👤</div>
        <b>プロフィール</b>
        <span>自分の情報を見直す</span>
      </a>

      <a class="card big cast-navCard cast-navCard--help" href="/wbss/public/help.php">
        <div class="cast-navCard__icon cast-navCard__icon--image">
          <img src="/wbss/public/images/help.png" alt="ヘルプ">
        </div>
        <b>ヘルプ</b>
        <span>困った時の確認用</span>
      </a>
    </div>
  </div>
</div>

<style>
.cast-dashboard{max-width:720px;margin:0 auto;display:grid;gap:14px}
.cast-hero{position:relative;overflow:hidden;padding:20px;background:
  radial-gradient(circle at top right, rgba(255,169,208,.34), transparent 42%),
  radial-gradient(circle at 0% 100%, rgba(255,224,240,.38), transparent 38%),
  linear-gradient(180deg, color-mix(in srgb, var(--cardA) 94%, #fff4fb), color-mix(in srgb, var(--cardB) 92%, #fff9fc))}
.cast-hero:before{
  content:"";
  position:absolute;
  inset:auto -20% -38% auto;
  width:220px;height:220px;border-radius:50%;
  background:rgba(255,183,217,.26);
  filter:blur(14px);
  pointer-events:none;
}
.cast-hero__bgMascot{
  position:absolute;
  right:-18px;
  bottom:-18px;
  width:210px;
  opacity:.98;
  pointer-events:none;
  z-index:0;
}
.cast-hero__bgMascotImg{
  display:block;
  width:100%;
  height:auto;
  filter:drop-shadow(0 18px 26px rgba(232,128,172,.18));
}
.cast-hero__top{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:start}
.cast-hero__copy{position:relative;z-index:1;padding-right:128px}
.cast-hero__eyebrow{display:inline-flex;padding:7px 11px;border-radius:999px;border:1px solid color-mix(in srgb, var(--accent) 24%, var(--line));font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);background:rgba(255,255,255,.52)}
.cast-hero__title{margin:10px 0 8px;font-size:30px;line-height:1.12;font-weight:1000}
.cast-hero__lead{margin:0;max-width:34em;color:var(--muted);font-size:13px;line-height:1.75}
.cast-hero__fortune{position:relative;z-index:1;display:flex;align-items:center;gap:12px;padding:14px 16px;min-width:210px;border-radius:22px;border:1px solid rgba(255,183,217,.48);background:linear-gradient(180deg, rgba(255,255,255,.9), rgba(255,242,248,.94));box-shadow:0 16px 32px rgba(232,128,172,.12)}
.cast-hero__fortune strong{display:block;font-size:14px;line-height:1.25}
.cast-hero__fortune small{display:block;margin-top:4px;color:var(--muted);font-size:11px}
.cast-hero__fortuneIcon{font-size:24px}
.cast-status,.cast-message-card,.cast-navCard,.cast-diagnosis{transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease, background .16s ease}
.cast-diagnosis{
  display:grid;gap:14px;padding:18px;text-decoration:none;color:inherit;
  background:linear-gradient(180deg, rgba(255,255,255,.86), rgba(255,246,251,.88))
}
.cast-diagnosis__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.cast-diagnosis__body{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(250px,.8fr);gap:14px;align-items:start}
.cast-diagnosis__type{font-size:24px;font-weight:1000;line-height:1.2}
.cast-diagnosis__summary{margin-top:8px;color:var(--muted);font-size:13px;line-height:1.75}
.cast-diagnosis__tip{margin-top:12px;font-size:12px;font-weight:900;color:color-mix(in srgb, var(--txt) 84%, var(--accent))}
.cast-diagnosis__axes{display:grid;gap:8px}
.cast-diagnosis__axes span,.cast-diagnosis__empty{
  display:block;padding:12px 13px;border-radius:16px;border:1px solid var(--line);background:rgba(255,255,255,.68);
  font-size:12px;line-height:1.55
}
.cast-diagnosis__empty{color:var(--muted)}
.cast-sectionTitle{font-size:18px;font-weight:1000;line-height:1.25}
.cast-sectionSub{margin-top:5px;color:var(--muted);font-size:12px;line-height:1.6}
.cast-status{padding:18px;background:
  linear-gradient(180deg, color-mix(in srgb, var(--cardA) 95%, #fff7fb), color-mix(in srgb, var(--cardB) 94%, #fff2f8))}
.cast-status__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.cast-status__body{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px, 340px);gap:18px;align-items:start;margin-top:14px}
.cast-status__info{display:grid;gap:8px;color:var(--muted);font-size:13px;line-height:1.7}
.cast-status__info span{display:block;padding:12px 13px;border-radius:16px;background:rgba(255,255,255,.68);border:1px solid color-mix(in srgb, var(--accent) 12%, var(--line))}
.cast-status__actions{display:grid;gap:12px}
.cast-actionBtn{
  display:flex;align-items:center;gap:12px;width:100%;
  padding:16px;border-radius:18px;border:1px solid var(--line);
  background:rgba(255,255,255,.76);
  color:var(--txt);cursor:pointer;text-align:left;font:inherit;
}
.cast-actionBtn strong{display:block;font-size:17px;line-height:1.2}
.cast-actionBtn small{display:block;margin-top:4px;color:var(--muted);font-size:12px}
.cast-actionBtn__icon{
  width:48px;height:48px;display:flex;align-items:center;justify-content:center;
  border-radius:16px;font-size:23px;background:rgba(255,255,255,.92);flex:0 0 auto
}
.cast-actionBtn--clockin{
  border-color:rgba(120,214,176,.48);
  background:linear-gradient(135deg, rgba(235,255,245,.98), rgba(240,252,248,.92));
}
.cast-actionBtn--clockout{
  border-color:rgba(255,179,214,.58);
  background:linear-gradient(135deg, rgba(255,242,248,.98), rgba(255,235,244,.9));
}
.cast-actionBtn:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
.cast-status__message{margin-top:12px;min-height:18px}
.cast-message-stack{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cast-message-card{text-decoration:none;color:inherit;padding:15px;display:grid;gap:10px;background:
  linear-gradient(180deg, rgba(255,255,255,.84), rgba(255,247,251,.86))}
.cast-message-card:hover,.cast-navCard:hover,.cast-hero__fortune:hover{transform:translateY(-2px)}
.cast-message-card.is-message{border-color:rgba(255,178,212,.48)}
.cast-message-card.is-thanks{
  border-color:rgba(255,211,138,.45);
  background:linear-gradient(180deg, rgba(255,250,236,.92), rgba(255,245,250,.84))
}
.cast-message-card__row{display:flex;justify-content:space-between;gap:10px;align-items:center}
.cast-message-card__title{font-size:15px;font-weight:1000;line-height:1.3}
.cast-message-card__value{font-size:34px;font-weight:1000;line-height:1;letter-spacing:-.02em;color:color-mix(in srgb, var(--txt) 90%, var(--accent))}
.cast-message-card__summary{font-size:12px;font-weight:800;color:var(--muted);line-height:1.5;flex:1;min-width:0}
.cast-message-card__mini-actions{display:flex;gap:6px;flex-wrap:wrap}
.cast-message-card__mini-actions span,.cast-badge{
  display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:0 10px;
  border-radius:999px;border:1px solid var(--line);font-size:11px;font-weight:1000;
  background:rgba(255,255,255,.7)
}
.cast-badge.is-alert{
  border-color:rgba(255,178,212,.58);
  background:rgba(255,231,241,.88)
}
.cast-badge.is-diagnosis{
  border-color:rgba(255,185,214,.58);
  background:rgba(255,237,246,.9)
}
.card-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cast-navCard{
  display:grid;align-content:flex-start;gap:10px;padding:18px;min-height:156px;
  text-align:left;background:linear-gradient(180deg, rgba(255,255,255,.86), rgba(255,245,250,.84))
}
.cast-navCard__icon{
  width:48px;height:48px;display:flex;align-items:center;justify-content:center;
  border-radius:18px;font-size:24px;border:1px solid rgba(255,196,220,.44);background:rgba(255,255,255,.82)
}
.cast-navCard__icon--image{
  width:64px;
  height:64px;
  padding:6px;
  background:linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,245,250,.92));
  box-shadow:0 10px 22px rgba(255,182,208,.16);
}
.cast-navCard__icon--image img{
  display:block;
  width:100%;
  height:100%;
  object-fit:contain;
}
.cast-navCard b{font-size:25px;font-size:clamp(19px, 3.6vw, 24px);line-height:1.25}
.cast-navCard span{color:var(--muted);font-size:12px;line-height:1.5}
.cast-navCard--diagnosis{border-color:rgba(255,190,212,.56)}
.cast-navCard--training{border-color:rgba(186,232,211,.56)}
.cast-navCard--schedule{border-color:rgba(186,209,255,.5)}
.cast-navCard--customer{border-color:rgba(255,214,165,.5)}
.cast-navCard--profile{border-color:rgba(185,232,206,.5)}
.cast-navCard--help{border-color:rgba(255,190,212,.5)}
.status{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;
  border-radius:999px;border:1px solid var(--line);font-weight:1000;min-width:120px
}
.status__dot{width:9px;height:9px;border-radius:999px;background:currentColor;opacity:.9}
.st-none{background:color-mix(in srgb, var(--muted) 16%, var(--cardA));color:var(--muted)}
.st-working{background:color-mix(in srgb, var(--ok) 16%, var(--cardA));color:var(--ok)}
.st-done{background:color-mix(in srgb, var(--accent) 16%, var(--cardA));color:var(--accent)}
body[data-theme="light"] .cast-actionBtn__icon{background:rgba(255,255,255,.82)}
body[data-theme="dark"] .cast-hero{
  background:
    radial-gradient(circle at top right, rgba(255,146,194,.22), transparent 40%),
    radial-gradient(circle at 0% 100%, rgba(171,141,255,.16), transparent 36%),
    linear-gradient(180deg, rgba(36,40,58,.96), rgba(45,51,72,.94));
  border-color: rgba(255,255,255,.12);
}
body[data-theme="dark"] .cast-hero__eyebrow{
  background: rgba(255,255,255,.12);
  border-color: rgba(255,183,217,.26);
  color: rgba(255,236,245,.82);
}
body[data-theme="dark"] .cast-hero__title{ color: #fff7fb; }
body[data-theme="dark"] .cast-hero__lead{ color: rgba(232,223,240,.84); }
body[data-theme="dark"] .cast-hero__fortune{
  background: linear-gradient(180deg, rgba(255,246,250,.96), rgba(255,233,242,.92));
  border-color: rgba(255,183,217,.28);
}
body[data-theme="dark"] .cast-hero__bgMascot{
  opacity:.9;
}
body[data-theme="dark"] .cast-hero__bgMascotImg{
  filter:drop-shadow(0 14px 28px rgba(0,0,0,.24));
}
body[data-theme="dark"] .cast-hero__fortune strong{ color: #5d2d49; }
body[data-theme="dark"] .cast-hero__fortune small{ color: #9b6b86; }
body[data-theme="dark"] .cast-status{
  background: linear-gradient(180deg, rgba(39,44,62,.98), rgba(42,47,68,.96));
  border-color: rgba(255,255,255,.12);
}
body[data-theme="dark"] .cast-diagnosis{
  background: linear-gradient(180deg, rgba(40,45,64,.97), rgba(46,52,73,.94));
  border-color: rgba(255,255,255,.12);
}
body[data-theme="dark"] .cast-sectionTitle{ color: #fff6fb; }
body[data-theme="dark"] .cast-sectionSub,
body[data-theme="dark"] .cast-status__message,
body[data-theme="dark"] .cast-status__info,
body[data-theme="dark"] .cast-diagnosis__summary,
body[data-theme="dark"] .cast-diagnosis__empty{
  color: rgba(226,220,239,.82);
}
body[data-theme="dark"] .cast-status__info span{
  background: rgba(255,255,255,.08);
  border-color: rgba(255,255,255,.10);
  color: rgba(244,239,248,.9);
}
body[data-theme="dark"] .cast-diagnosis__type,
body[data-theme="dark"] .cast-diagnosis__tip,
body[data-theme="dark"] .cast-diagnosis__axes span{
  color:#fff7fb;
}
body[data-theme="dark"] .cast-diagnosis__axes span,
body[data-theme="dark"] .cast-diagnosis__empty,
body[data-theme="dark"] .cast-badge.is-diagnosis{
  background: rgba(255,255,255,.08);
  border-color: rgba(255,255,255,.10);
}
body[data-theme="dark"] .cast-actionBtn{
  background: rgba(255,255,255,.08);
  border-color: rgba(255,255,255,.12);
  color: #fff7fb;
}
body[data-theme="dark"] .cast-actionBtn strong{ color: #fff7fb; }
body[data-theme="dark"] .cast-actionBtn small{ color: rgba(230,223,240,.82); }
body[data-theme="dark"] .cast-actionBtn__icon{
  background: rgba(255,255,255,.14);
}
body[data-theme="dark"] .cast-actionBtn--clockin{
  background: linear-gradient(135deg, rgba(111,224,176,.16), rgba(104,208,193,.10));
  border-color: rgba(111,224,176,.34);
}
body[data-theme="dark"] .cast-actionBtn--clockout{
  background: linear-gradient(135deg, rgba(255,165,206,.18), rgba(255,196,227,.10));
  border-color: rgba(255,165,206,.34);
}
body[data-theme="dark"] .cast-message-card{
  background: linear-gradient(180deg, rgba(43,48,69,.96), rgba(49,55,78,.92));
  border-color: rgba(255,255,255,.10);
}
body[data-theme="dark"] .cast-message-card.is-message{
  border-color: rgba(255,173,211,.26);
}
body[data-theme="dark"] .cast-message-card.is-thanks{
  background: linear-gradient(180deg, rgba(56,52,70,.96), rgba(52,50,69,.92));
  border-color: rgba(255,214,148,.22);
}
body[data-theme="dark"] .cast-message-card__title,
body[data-theme="dark"] .cast-message-card__value,
body[data-theme="dark"] .cast-badge,
body[data-theme="dark"] .cast-message-card__mini-actions span{
  color: #fff7fb;
}
body[data-theme="dark"] .cast-message-card__summary{
  color: rgba(229,223,240,.82);
}
body[data-theme="dark"] .cast-message-card__mini-actions span,
body[data-theme="dark"] .cast-badge{
  background: rgba(255,255,255,.08);
  border-color: rgba(255,255,255,.12);
}
body[data-theme="dark"] .cast-badge.is-alert{
  background: rgba(255,162,204,.18);
  border-color: rgba(255,162,204,.30);
}
body[data-theme="dark"] .cast-navCard{
  background: linear-gradient(180deg, rgba(41,46,66,.98), rgba(46,51,73,.94));
  border-color: rgba(255,255,255,.10);
}
body[data-theme="dark"] .cast-navCard b{ color: #fff7fb; }
body[data-theme="dark"] .cast-navCard span{ color: rgba(227,221,239,.82); }
body[data-theme="dark"] .cast-navCard__icon{
  background: rgba(255,255,255,.10);
  border-color: rgba(255,255,255,.12);
}
body[data-theme="dark"] .cast-navCard__icon--image{
  background: linear-gradient(180deg, rgba(255,255,255,.16), rgba(255,255,255,.08));
  box-shadow:0 12px 22px rgba(0,0,0,.16);
}
body[data-theme="cast"] .cast-hero{background:
  radial-gradient(circle at top right, rgba(255,111,163,.26), transparent 42%),
  radial-gradient(circle at 0% 100%, rgba(255,230,240,.44), transparent 38%),
  linear-gradient(180deg, rgba(255,255,255,.82), rgba(255,240,247,.94))}
body[data-theme="staff"] .cast-hero{background:
  radial-gradient(circle at top right, rgba(255,164,206,.24), transparent 42%),
  radial-gradient(circle at 0% 100%, rgba(242,231,255,.34), transparent 38%),
  linear-gradient(180deg, rgba(255,255,255,.8), rgba(245,244,255,.9))}
@media (min-width:721px){
  .cast-hero{min-height:268px}
  .cast-hero__top{display:block}
  .cast-hero__copy{padding-right:120px}
  .cast-hero__title{white-space:nowrap}
  .cast-hero__lead{max-width:none;white-space:nowrap}
  .cast-hero__fortune{
    position:relative;
    right:auto;
    bottom:auto;
    min-width:210px;
    margin-top:18px;
    max-width:280px;
  }
  .cast-hero__bgMascot{
    width:238px;
    right:-10px;
    bottom:-10px;
  }
}
@media (min-width:1200px){
  .cast-hero{min-height:278px}
  .cast-hero__copy{padding-right:340px}
  .cast-hero__title{font-size:32px}
  .cast-hero__fortune{
    position:relative;
    right:auto;
    bottom:auto;
    min-width:268px;
    margin-top:20px;
    max-width:300px;
  }
  .cast-hero__bgMascot{
    width:260px;
    right:-6px;
    bottom:-8px;
  }
}
@media (max-width:720px){
  .cast-hero__top,.cast-status__body,.cast-diagnosis__body{grid-template-columns:1fr}
  .cast-hero__fortune{min-width:0;max-width:260px;padding:12px 14px}
  .cast-hero__copy{padding-right:112px}
  .cast-hero__bgMascot{
    width:168px;
    right:-14px;
    bottom:-10px;
    opacity:.95;
  }
}
@media (max-width:520px){
  .cast-message-card__row{align-items:flex-start;flex-wrap:wrap}
  .cast-message-card__value{font-size:28px}
  .cast-hero__title{font-size:24px}
  .cast-hero__copy{padding-right:92px}
  .cast-hero__fortune{
    max-width:230px;
    padding:10px 12px;
    gap:10px;
    border-radius:18px;
  }
  .cast-hero__fortune strong{font-size:13px}
  .cast-hero__fortune small{font-size:10px}
  .cast-hero__fortuneIcon{font-size:22px}
  .cast-hero__bgMascot{
    width:152px;
    right:-8px;
    bottom:-8px;
    opacity:.94;
  }
  .cast-navCard{min-height:140px;padding:16px}
}
@media (max-width:390px){
  .cast-message-stack,.card-grid{grid-template-columns:1fr}
}
</style>

<script>
async function geoReq(action){
  const msg = document.getElementById('geoMsg');
  msg.textContent = 'LINEに送信中…';

  const body = new URLSearchParams();
  body.set('csrf_token', document.getElementById('csrf').value);
  body.set('store_id', document.getElementById('store_id').value);
  body.set('action', action);

  try{
    const res = await fetch('/wbss/public/attendance/api/attendance_geo_request.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString()
    });
    const text = await res.text();
    let j = null;
    try { j = JSON.parse(text); } catch(e) {}

    if (!res.ok){
      msg.textContent = '送信失敗: ' + (j && j.error ? j.error : text);
      return;
    }

    if (j && j.ok){
      msg.textContent = '送信OK。LINEを開いて「位置情報を送る」を押してね。';
      return;
    }
    msg.textContent = '送信OK: ' + text;
  } catch(e){
    msg.textContent = '通信エラー: ' + (e && e.message ? e.message : String(e));
  }
}
</script>
<script src="/wbss/public/assets/js/push_notifications.js?v=20260320b"></script>

<?php render_page_end(); ?>
