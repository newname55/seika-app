<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_quiz.php';

require_login();
require_role(['cast']);

$pdo = db();
$userId = service_quiz_current_user_id();
$storeId = service_quiz_resolve_cast_store_id($pdo, $userId);

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が未設定です。管理者に所属店舗を設定してもらってください。');
}

$questions = service_quiz_questions();
$questionMap = service_quiz_question_map();
$tableReady = service_quiz_results_table_ready($pdo);
$error = '';
$saveNotice = '';
$latestResult = null;
$displayResult = null;
$resultId = (int)($_GET['result_id'] ?? 0);
$isQuestionMode = ((string)($_GET['start'] ?? '') === '1');
$currentAnswers = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_verify((string)($_POST['csrf_token'] ?? ''));

  if ((string)($_POST['action'] ?? '') === 'restart') {
    header('Location: /wbss/public/service_quiz.php?start=1');
    exit;
  }

  $currentAnswers = service_quiz_normalize_answers((array)($_POST['answers'] ?? []));
  $questionId = (int)($_POST['question_id'] ?? 0);
  $choice = strtoupper(trim((string)($_POST['choice'] ?? '')));

  if (!isset($questionMap[$questionId])) {
    $error = '診断データの読み込みに失敗しました。最初からやり直してください。';
    $isQuestionMode = true;
  } else {
    $choices = service_quiz_choice_map($questionMap[$questionId]);
    if (!isset($choices[$choice])) {
      $error = '選択肢を選んでください。';
      $isQuestionMode = true;
    } else {
      $currentAnswers[$questionId] = $choice;
      ksort($currentAnswers);

      if (count($currentAnswers) >= count($questions)) {
        $displayResult = service_quiz_calculate($currentAnswers);

        if ($tableReady) {
          try {
            $newId = service_quiz_save_result($pdo, $storeId, $userId, $currentAnswers);
            header('Location: /wbss/public/service_quiz.php?result_id=' . $newId . '&saved=1');
            exit;
          } catch (Throwable $e) {
            $error = '診断結果の保存に失敗しました: ' . $e->getMessage();
          }
        } else {
          $error = '診断結果テーブルが未作成のため、結果は表示のみです。`docs/create_cast_service_quiz_results.sql` を適用してください。';
        }
      } else {
        $isQuestionMode = true;
      }
    }
  }
}

if ($displayResult === null && $tableReady) {
  if ($resultId > 0) {
    $displayResult = service_quiz_fetch_result_by_id($pdo, $resultId, $storeId, $userId);
  }
  $latestResult = service_quiz_fetch_latest_result($pdo, $storeId, $userId);
  if ($displayResult === null && !$isQuestionMode) {
    $displayResult = $latestResult;
  }
} elseif ($displayResult === null) {
  $latestResult = null;
}

if ((string)($_GET['saved'] ?? '') === '1') {
  $saveNotice = '診断結果を保存しました。';
}

$nextIndex = count($currentAnswers);
$currentQuestion = $isQuestionMode ? ($questions[$nextIndex] ?? null) : null;
$progressCurrent = min($nextIndex + 1, count($questions));

render_page_start('接客タイプ診断');
render_header('接客タイプ診断', [
  'back_href' => '/wbss/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
  'show_store' => false,
  'show_user' => false,
]);
?>
<div class="page">
  <div class="serviceQuiz">
    <?php if ($saveNotice !== ''): ?>
      <div class="card serviceQuizNotice serviceQuizNotice--ok"><?= h($saveNotice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="card serviceQuizNotice serviceQuizNotice--error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($currentQuestion !== null): ?>
      <?php
        $progressPercent = (int)floor(($progressCurrent / max(1, count($questions))) * 100);
        $questionTypeLabel = (string)($currentQuestion['type'] ?? 'question');
      ?>
      <section class="card serviceQuizCard serviceQuizHero">
        <div class="serviceQuizHero__top">
          <div>
            <div class="serviceQuizEyebrow">WBSS 接客タイプ診断</div>
            <h1 class="serviceQuizTitle"><?= h((string)$currentQuestion['title']) ?> / <?= $progressCurrent ?>問目</h1>
          </div>
          <div class="serviceQuizCount"><?= count($currentAnswers) ?>/<?= count($questions) ?> 回答済み</div>
        </div>
        <div class="serviceQuizProgress" aria-hidden="true">
          <span style="width:<?= $progressPercent ?>%"></span>
        </div>
        <div class="serviceQuizProgressMeta"><?= $progressPercent ?>% 完了</div>
      </section>

      <section class="card serviceQuizCard serviceQuizQuestion">
        <div class="serviceQuizQuestion__label">
          <?php if ($questionTypeLabel === 'customer_quote'): ?>
            お客様:
          <?php elseif ($questionTypeLabel === 'situation'): ?>
            状況:
          <?php else: ?>
            シーン:
          <?php endif; ?>
        </div>
        <div class="serviceQuizQuestion__body">
          <?= h((string)$currentQuestion['question']) ?>
        </div>
        <div class="serviceQuizQuestion__prompt">
          <?= h((string)($currentQuestion['prompt'] ?? 'あなたが自然に返しやすいのは？')) ?>
        </div>

        <form method="post" class="serviceQuizChoices">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="question_id" value="<?= (int)$currentQuestion['id'] ?>">
          <?php foreach ($currentAnswers as $answeredQuestionId => $answeredChoice): ?>
            <input type="hidden" name="answers[<?= (int)$answeredQuestionId ?>]" value="<?= h($answeredChoice) ?>">
          <?php endforeach; ?>

          <?php foreach ((array)$currentQuestion['choices'] as $choice): ?>
            <button class="serviceQuizChoice" type="submit" name="choice" value="<?= h((string)$choice['key']) ?>">
              <span class="serviceQuizChoice__key"><?= h((string)$choice['key']) ?></span>
              <span class="serviceQuizChoice__text"><?= h((string)$choice['text']) ?></span>
            </button>
          <?php endforeach; ?>
        </form>
      </section>

      <form method="post" class="serviceQuizRestart">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="restart">
        <button type="submit" class="btn">最初からやり直す</button>
      </form>

    <?php elseif (is_array($displayResult)): ?>
      <?php
        $type = (array)($displayResult['result_type'] ?? []);
        $scores = (array)($displayResult['scores'] ?? []);
        $labels = (array)($displayResult['axis_labels'] ?? []);
        $createdAt = trim((string)($displayResult['created_at'] ?? ''));
        $typeKey = trim((string)($displayResult['result_type_key'] ?? ''));
        $resultView = [
          'type' => $typeKey !== '' ? $typeKey : 'all_rounder',
          'type_label' => (string)($type['name'] ?? '診断結果'),
          'type_en' => (string)($type['type_en'] ?? ''),
          'copy' => (string)($type['copy'] ?? ''),
          'summary' => (string)($type['summary'] ?? ''),
          'saved_at' => $createdAt,
          'talk_score' => (int)($scores['talk_axis'] ?? 0),
          'talk_label' => (string)($labels['talk_axis'] ?? ''),
          'mood_score' => (int)($scores['mood_axis'] ?? 0),
          'mood_label' => (string)($labels['mood_axis'] ?? ''),
          'response_score' => (int)($scores['response_axis'] ?? 0),
          'response_label' => (string)($labels['response_axis'] ?? ''),
          'relation_score' => (int)($scores['relation_axis'] ?? 0),
          'relation_label' => (string)($labels['relation_axis'] ?? ''),
          'strengths' => (array)($type['strengths'] ?? []),
          'cautions' => (array)($type['cautions'] ?? []),
          'matches' => (array)($type['best_customers'] ?? []),
          'today_tip' => (string)($type['today_tip'] ?? ''),
        ];
        $imagePath = '/wbss/public/images/cast_type_images/' . rawurlencode($resultView['type']) . '.png';
      ?>
      <div class="cast-type-result-page">
        <div class="cast-type-result-header">
          <a href="/wbss/public/dashboard_cast.php" class="back-button">← ダッシュボード</a>
          <h1>接客タイプ診断</h1>
        </div>

        <?php if ($saveNotice !== ''): ?>
          <div class="result-notice"><?= h($saveNotice) ?></div>
        <?php endif; ?>

        <section class="result-hero">
          <div class="result-hero__card">
            <img
              src="<?= h($imagePath) ?>"
              alt="<?= h($resultView['type_label']) ?>"
              class="result-card-image"
            >
          </div>

          <div class="result-hero__summary card-panel">
            <div class="type-badge">あなたの接客タイプ</div>
            <h2 class="type-title"><?= h($resultView['type_label']) ?></h2>
            <p class="type-subtitle"><?= h($resultView['type_en']) ?></p>
            <p class="type-copy"><?= h($resultView['copy']) ?></p>
            <p class="type-description"><?= nl2br(h($resultView['summary'])) ?></p>
            <?php if ($resultView['saved_at'] !== ''): ?>
              <div class="saved-at">保存日時: <?= h($resultView['saved_at']) ?></div>
            <?php endif; ?>
          </div>
        </section>

        <section class="score-section card-panel">
          <h3>スコア</h3>
          <div class="score-grid">
            <div class="score-item">
              <div class="score-item__label">会話</div>
              <div class="score-item__value"><?= (int)$resultView['talk_score'] ?></div>
              <div class="score-item__sub"><?= h($resultView['talk_label']) ?></div>
              <div class="score-bar"><div class="score-bar__fill" style="width: <?= min(100, max(0, ($resultView['talk_score'] / 6) * 100)) ?>%;"></div></div>
            </div>
            <div class="score-item">
              <div class="score-item__label">空気</div>
              <div class="score-item__value"><?= (int)$resultView['mood_score'] ?></div>
              <div class="score-item__sub"><?= h($resultView['mood_label']) ?></div>
              <div class="score-bar"><div class="score-bar__fill" style="width: <?= min(100, max(0, ($resultView['mood_score'] / 6) * 100)) ?>%;"></div></div>
            </div>
            <div class="score-item">
              <div class="score-item__label">反応</div>
              <div class="score-item__value"><?= (int)$resultView['response_score'] ?></div>
              <div class="score-item__sub"><?= h($resultView['response_label']) ?></div>
              <div class="score-bar"><div class="score-bar__fill" style="width: <?= min(100, max(0, ($resultView['response_score'] / 6) * 100)) ?>%;"></div></div>
            </div>
            <div class="score-item">
              <div class="score-item__label">関係性</div>
              <div class="score-item__value"><?= (int)$resultView['relation_score'] ?></div>
              <div class="score-item__sub"><?= h($resultView['relation_label']) ?></div>
              <div class="score-bar"><div class="score-bar__fill" style="width: <?= min(100, max(0, ($resultView['relation_score'] / 6) * 100)) ?>%;"></div></div>
            </div>
          </div>
        </section>

        <section class="result-grid">
          <div class="card-panel">
            <h3>強み</h3>
            <ul class="result-list">
              <?php foreach ($resultView['strengths'] as $item): ?>
                <li><?= h((string)$item) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="card-panel">
            <h3>注意点</h3>
            <ul class="result-list">
              <?php foreach ($resultView['cautions'] as $item): ?>
                <li><?= h((string)$item) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="card-panel">
            <h3>相性の良い客層</h3>
            <ul class="result-list">
              <?php foreach ($resultView['matches'] as $item): ?>
                <li><?= h((string)$item) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="card-panel card-panel--highlight">
            <h3>今日の一言</h3>
            <p class="today-tip"><?= nl2br(h($resultView['today_tip'])) ?></p>
          </div>
        </section>

        <div class="result-actions">
          <a href="/wbss/public/service_quiz.php?start=1" class="btn btn-secondary">もう一度診断する</a>
          <a href="/wbss/public/dashboard_cast.php" class="btn btn-primary">ダッシュボードへ戻る</a>
        </div>
      </div>

    <?php else: ?>
      <section class="card serviceQuizCard serviceQuizIntro">
        <div class="serviceQuizEyebrow">WBSS 接客タイプ診断</div>
        <h1 class="serviceQuizTitle">接客実務向けの自己診断</h1>
        <p class="serviceQuizIntro__lead">MBTI風ですが、性格診断ではなく「接客で自然に出やすい反応傾向」を見る12問の4択診断です。1問ずつ答えるだけで、4軸スコアから8タイプに分類します。</p>
        <div class="serviceQuizIntro__chips">
          <span>12問</span>
          <span>4軸スコア</span>
          <span>8タイプ判定</span>
          <span>最新結果を保存</span>
        </div>
        <?php if (is_array($latestResult)): ?>
          <div class="serviceQuizLatest">
            <div class="serviceQuizLatest__label">最新結果</div>
            <div class="serviceQuizLatest__name"><?= h((string)(($latestResult['result_type'] ?? [])['name'] ?? '')) ?></div>
            <div class="serviceQuizLatest__summary"><?= h((string)(($latestResult['result_type'] ?? [])['today_tip'] ?? '')) ?></div>
          </div>
        <?php endif; ?>
        <div class="serviceQuizActions">
          <a class="btn btn-primary" href="/wbss/public/service_quiz.php?start=1">診断を始める</a>
          <?php if (is_array($latestResult)): ?>
            <a class="btn" href="/wbss/public/service_quiz.php?result_id=<?= (int)$latestResult['id'] ?>">最新結果を見る</a>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<style>
.serviceQuiz{max-width:1120px;margin:0 auto;display:grid;gap:18px}
.serviceQuizCard{padding:22px}
.serviceQuizHero,.serviceQuizResultHero,.serviceQuizIntro{
  background:
    radial-gradient(circle at top right, rgba(255,255,255,.78), transparent 30%),
    linear-gradient(180deg, #ffffff, #fbfbfd);
  border-color:#e6e7ee;
  box-shadow:0 18px 40px rgba(26,32,44,.06);
}
.serviceQuizEyebrow{display:inline-flex;padding:7px 11px;border-radius:999px;border:1px solid color-mix(in srgb, var(--accent) 24%, var(--line));font-size:11px;font-weight:1000;letter-spacing:.08em;color:var(--muted);background:rgba(255,255,255,.56)}
.serviceQuizTitle{margin:12px 0 0;font-size:28px;line-height:1.2;font-weight:1000}
.serviceQuizHero__top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.serviceQuizCount,.serviceQuizResultHero__meta{color:var(--muted);font-size:12px;font-weight:800}
.serviceQuizProgress{height:10px;border-radius:999px;background:rgba(255,255,255,.62);margin-top:14px;overflow:hidden}
.serviceQuizProgress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg, #ff7aad, #ffb6d8)}
.serviceQuizProgressMeta{margin-top:8px;color:var(--muted);font-size:12px}
.serviceQuizQuestion__label{font-size:12px;font-weight:1000;color:var(--muted);letter-spacing:.04em}
.serviceQuizQuestion__body{margin-top:10px;font-size:24px;line-height:1.45;font-weight:1000}
.serviceQuizQuestion__prompt{margin-top:16px;color:var(--muted);font-size:13px}
.serviceQuizChoices{display:grid;gap:10px;margin-top:18px}
.serviceQuizChoice{
  display:flex;align-items:flex-start;gap:12px;width:100%;padding:16px;border-radius:18px;
  border:1px solid color-mix(in srgb, var(--accent) 18%, var(--line));background:rgba(255,255,255,.78);
  color:var(--txt);font:inherit;text-align:left;cursor:pointer;transition:transform .14s ease,border-color .14s ease,box-shadow .14s ease
}
.serviceQuizChoice:hover{transform:translateY(-1px);border-color:rgba(255,145,194,.56);box-shadow:0 16px 30px rgba(255,165,206,.12)}
.serviceQuizChoice__key{
  width:38px;height:38px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;
  border-radius:14px;background:linear-gradient(180deg, rgba(255,245,250,.98), rgba(255,231,241,.92));
  border:1px solid rgba(255,182,211,.42);font-size:15px;font-weight:1000
}
.serviceQuizChoice__text{font-size:15px;line-height:1.65;font-weight:800}
.serviceQuizRestart{display:flex;justify-content:center}
.serviceQuizNotice{padding:14px 16px;font-weight:800}
.serviceQuizNotice--ok{border-color:rgba(111,224,176,.42);background:rgba(236,255,246,.8)}
.serviceQuizNotice--error{border-color:rgba(239,68,68,.35);background:rgba(255,241,241,.9)}
.serviceQuizResultHero__summary,.serviceQuizIntro__lead{margin:14px 0 0;color:var(--muted);font-size:14px;line-height:1.8}
.cast-type-result-page{
  max-width:1120px;
  margin:0 auto;
  padding:24px 16px 48px;
  color:#1f2937;
}
.cast-type-result-header{
  display:flex;
  align-items:center;
  gap:16px;
  margin-bottom:20px;
}
.cast-type-result-header h1{
  margin:0;
  font-size:32px;
  font-weight:800;
  letter-spacing:.02em;
}
.back-button{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:44px;
  padding:0 16px;
  border-radius:999px;
  background:#ffffff;
  border:1px solid #e5e7eb;
  text-decoration:none;
  color:#111827;
  font-weight:700;
  box-shadow:0 8px 24px rgba(15,23,42,.06);
}
.result-notice{
  margin-bottom:20px;
  padding:14px 18px;
  border-radius:16px;
  background:#ffffff;
  border:1px solid #e5e7eb;
  box-shadow:0 8px 24px rgba(15,23,42,.05);
  font-weight:700;
}
.result-hero{
  display:grid;
  grid-template-columns:400px 1fr;
  gap:24px;
  margin-bottom:24px;
  align-items:start;
}
.result-hero__card{
  background:#ffffff;
  border-radius:28px;
  padding:18px;
  box-shadow:0 18px 48px rgba(15,23,42,.08);
  border:1px solid #edf0f5;
}
.result-card-image{
  display:block;
  width:100%;
  height:auto;
  border-radius:24px;
}
.card-panel{
  background:#ffffff;
  border-radius:24px;
  padding:24px;
  box-shadow:0 18px 48px rgba(15,23,42,.08);
  border:1px solid #edf0f5;
}
.card-panel--highlight{
  background:linear-gradient(180deg, #fff7fb 0%, #ffffff 100%);
  border:1px solid #f4d9e7;
}
.type-badge{
  display:inline-flex;
  align-items:center;
  min-height:34px;
  padding:0 12px;
  border-radius:999px;
  border:1px solid #d8deea;
  color:#596275;
  font-size:13px;
  font-weight:700;
  margin-bottom:14px;
}
.type-title{
  margin:0 0 8px;
  font-size:40px;
  line-height:1.15;
  font-weight:900;
  letter-spacing:.01em;
}
.type-subtitle{
  margin:0 0 10px;
  color:#6b7280;
  font-size:18px;
  font-weight:700;
}
.type-copy{
  margin:0 0 14px;
  font-size:22px;
  line-height:1.5;
  font-weight:800;
  color:#111827;
}
.type-description{
  margin:0;
  color:#4b5563;
  font-size:16px;
  line-height:1.9;
}
.saved-at{
  margin-top:18px;
  color:#6b7280;
  font-size:13px;
  font-weight:600;
}
.score-section{
  margin-bottom:24px;
}
.score-section h3,
.result-grid h3{
  margin:0 0 18px;
  font-size:22px;
  font-weight:800;
}
.score-grid{
  display:grid;
  grid-template-columns:repeat(4, 1fr);
  gap:16px;
}
.score-item{
  padding:16px;
  border-radius:18px;
  background:#f8fafc;
  border:1px solid #e8edf5;
}
.score-item__label{
  font-size:13px;
  color:#6b7280;
  font-weight:700;
  margin-bottom:8px;
}
.score-item__value{
  font-size:34px;
  line-height:1;
  font-weight:900;
  color:#111827;
  margin-bottom:6px;
}
.score-item__sub{
  font-size:13px;
  font-weight:700;
  color:#374151;
  margin-bottom:12px;
}
.score-bar{
  height:10px;
  border-radius:999px;
  background:#e5e7eb;
  overflow:hidden;
}
.score-bar__fill{
  height:100%;
  border-radius:999px;
  background:linear-gradient(90deg, #f59e0b 0%, #ef4444 100%);
}
.result-grid{
  display:grid;
  grid-template-columns:repeat(2, 1fr);
  gap:24px;
  margin-bottom:28px;
}
.result-list{
  margin:0;
  padding-left:1.2em;
}
.result-list li{
  margin-bottom:12px;
  line-height:1.8;
  color:#374151;
}
.today-tip{
  margin:0;
  line-height:1.9;
  font-size:16px;
  font-weight:700;
  color:#7c2d12;
}
.result-actions{
  display:flex;
  justify-content:flex-start;
  gap:14px;
  flex-wrap:wrap;
}
.serviceQuizIntro__chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.serviceQuizIntro__chips span,.serviceQuizLatest{
  border:1px solid var(--line);background:rgba(255,255,255,.68);border-radius:16px
}
.serviceQuizIntro__chips span{padding:8px 12px;font-size:12px;font-weight:900}
.serviceQuizLatest{margin-top:16px;padding:14px}
.serviceQuizLatest__label{font-size:11px;color:var(--muted);font-weight:1000;letter-spacing:.08em}
.serviceQuizLatest__name{margin-top:6px;font-size:20px;font-weight:1000}
.serviceQuizLatest__summary{margin-top:6px;color:var(--muted);font-size:13px;line-height:1.7}
body[data-theme="dark"] .serviceQuizHero,
body[data-theme="dark"] .serviceQuizResultHero,
body[data-theme="dark"] .serviceQuizIntro,
body[data-theme="dark"] .card-panel,
body[data-theme="dark"] .result-hero__card,
body[data-theme="dark"] .result-notice,
body[data-theme="dark"] .back-button{
  background:
    radial-gradient(circle at top right, rgba(255,146,194,.2), transparent 38%),
    radial-gradient(circle at bottom left, rgba(164,140,255,.16), transparent 34%),
    linear-gradient(180deg, rgba(38,43,61,.96), rgba(44,50,71,.94));
}
body[data-theme="dark"] .score-item{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
}
body[data-theme="dark"] .serviceQuizEyebrow,
body[data-theme="dark"] .serviceQuizChoice,
body[data-theme="dark"] .serviceQuizIntro__chips span,
body[data-theme="dark"] .serviceQuizLatest,
body[data-theme="dark"] .type-badge{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
  color:#fff8fc;
}
body[data-theme="dark"] .serviceQuizProgress{background:rgba(255,255,255,.1)}
body[data-theme="dark"] .serviceQuizQuestion__label,
body[data-theme="dark"] .serviceQuizQuestion__prompt,
body[data-theme="dark"] .serviceQuizCount,
body[data-theme="dark"] .serviceQuizProgressMeta,
body[data-theme="dark"] .serviceQuizResultHero__summary,
body[data-theme="dark"] .serviceQuizIntro__lead,
body[data-theme="dark"] .serviceQuizLatest__summary,
body[data-theme="dark"] .type-subtitle,
body[data-theme="dark"] .type-description,
body[data-theme="dark"] .saved-at,
body[data-theme="dark"] .score-item__label,
body[data-theme="dark"] .score-item__sub,
body[data-theme="dark"] .result-list li{
  color:rgba(230,223,240,.82);
}
body[data-theme="dark"] .serviceQuizQuestion__body,
body[data-theme="dark"] .serviceQuizTitle,
body[data-theme="dark"] .serviceQuizLatest__name,
body[data-theme="dark"] .cast-type-result-header h1,
body[data-theme="dark"] .type-title,
body[data-theme="dark"] .type-copy,
body[data-theme="dark"] .score-item__value,
body[data-theme="dark"] .back-button,
body[data-theme="dark"] .today-tip{
  color:#fff8fc;
}
body[data-theme="dark"] .serviceQuizChoice__key{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.14)}
body[data-theme="dark"] .score-bar{background:rgba(255,255,255,.14)}
@media (max-width: 960px){
  .result-hero{grid-template-columns:1fr}
  .score-grid{grid-template-columns:repeat(2, 1fr)}
  .result-grid{grid-template-columns:1fr}
  .type-title{font-size:34px}
}
@media (max-width: 640px){
  .cast-type-result-page{padding:16px 12px 32px}
  .cast-type-result-header{
    align-items:flex-start;
    flex-direction:column;
    gap:12px;
  }
  .cast-type-result-header h1{font-size:26px}
  .serviceQuizCard{padding:16px}
  .serviceQuizTitle{font-size:24px}
  .serviceQuizQuestion__body{font-size:21px}
  .result-hero{grid-template-columns:1fr}
  .score-grid{grid-template-columns:1fr}
  .result-grid{grid-template-columns:1fr}
  .card-panel,
  .result-hero__card{
    border-radius:20px;
    padding:16px;
  }
  .type-title{font-size:30px}
  .type-copy{font-size:18px}
  .serviceQuizActions{display:grid}
  .serviceQuizActions .btn{width:100%;text-align:center}
  .result-actions{flex-direction:column}
  .btn{width:100%}
}
</style>
<?php render_page_end(); ?>
