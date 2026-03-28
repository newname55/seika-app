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

$questionMap = service_quiz_question_map();
$tableReady = service_quiz_results_table_ready($pdo);
$error = '';
$saveNotice = '';
$latestResult = null;
$displayResult = null;
$cumulativeSummary = null;
$resultId = (int)($_GET['result_id'] ?? 0);
$isQuestionMode = ((string)($_GET['start'] ?? '') === '1');
$currentAnswers = [];
$activeRun = null;

if ($isQuestionMode && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  $activeRun = service_quiz_start_run();
} else {
  $activeRun = service_quiz_get_active_run();
}

$selectedQuestionIds = is_array($activeRun['question_ids'] ?? null) ? array_map('intval', $activeRun['question_ids']) : [];
$questions = service_quiz_questions_by_ids($selectedQuestionIds);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_verify((string)($_POST['csrf_token'] ?? ''));

  if ((string)($_POST['action'] ?? '') === 'restart') {
    service_quiz_clear_run();
    header('Location: /wbss/public/service_quiz.php?start=1');
    exit;
  }

  $activeRun = service_quiz_get_active_run();
  $selectedQuestionIds = is_array($activeRun['question_ids'] ?? null) ? array_map('intval', $activeRun['question_ids']) : [];
  $questions = service_quiz_questions_by_ids($selectedQuestionIds);
  if (!$selectedQuestionIds) {
    $activeRun = service_quiz_start_run();
    $selectedQuestionIds = array_map('intval', (array)($activeRun['question_ids'] ?? []));
    $questions = service_quiz_questions_by_ids($selectedQuestionIds);
  }

  $currentAnswers = service_quiz_normalize_answers((array)($_POST['answers'] ?? []));
  $questionId = (int)($_POST['question_id'] ?? 0);
  $choice = strtoupper(trim((string)($_POST['choice'] ?? '')));

  if (!isset($questionMap[$questionId]) || !in_array($questionId, $selectedQuestionIds, true)) {
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
        $displayResult = service_quiz_calculate($currentAnswers, $selectedQuestionIds);

        if ($tableReady) {
          try {
            $newId = service_quiz_save_result($pdo, $storeId, $userId, $currentAnswers, $selectedQuestionIds);
            service_quiz_clear_run();
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

if (is_array($displayResult) && service_quiz_history_tables_ready($pdo)) {
  $cumulativeSummary = service_quiz_fetch_cumulative_summary($pdo, $storeId, $userId, 20);
}

if ((string)($_GET['saved'] ?? '') === '1') {
  $saveNotice = '診断結果を保存しました。';
}

$nextIndex = count($currentAnswers);
$currentQuestion = $isQuestionMode ? ($questions[$nextIndex] ?? null) : null;
$progressCurrent = min($nextIndex + 1, count($questions));
$categoryLabels = service_quiz_category_specs();

render_page_start('接客タイプ診断');
render_header('接客タイプ診断', [
  'back_href' => '/wbss/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
  'show_store' => false,
  'show_user' => false,
]);
?>
<div class="page">
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
            <h1 class="serviceQuizTitle">Q<?= $progressCurrent ?> / <?= count($questions) ?></h1>
            <div class="serviceQuizQuestionMeta">
              <?= h((string)($categoryLabels[(string)($currentQuestion['category'] ?? '')]['label'] ?? '接客シーン')) ?>
            </div>
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
          <?= h((string)($currentQuestion['prompt'] ?? 'この場面で、一番“あなたらしい”のはどれ？')) ?>
        </div>

        <form method="post" class="serviceQuizChoices">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="question_id" value="<?= (int)$currentQuestion['id'] ?>">
          <input type="hidden" name="choice" value="" class="serviceQuizChoiceValue">
          <?php foreach ($currentAnswers as $answeredQuestionId => $answeredChoice): ?>
            <input type="hidden" name="answers[<?= (int)$answeredQuestionId ?>]" value="<?= h($answeredChoice) ?>">
          <?php endforeach; ?>

          <?php foreach ((array)$currentQuestion['choices'] as $choice): ?>
            <?php $choiceScores = (array)($choice['scores'] ?? []); ?>
            <button
              class="serviceQuizChoice quiz-option"
              type="submit"
              name="choice"
              value="<?= h((string)$choice['key']) ?>"
              data-score-talk="<?= (int)($choiceScores['talk_axis'] ?? 0) ?>"
              data-score-mood="<?= (int)($choiceScores['mood_axis'] ?? 0) ?>"
              data-score-response="<?= (int)($choiceScores['response_axis'] ?? 0) ?>"
              data-score-relation="<?= (int)($choiceScores['relation_axis'] ?? 0) ?>"
            >
              <span class="serviceQuizChoice__key"><?= h((string)$choice['key']) ?></span>
              <span class="serviceQuizChoice__text"><?= h((string)$choice['text']) ?></span>
            </button>
          <?php endforeach; ?>
        </form>
        <div id="quiz-feedback" class="quiz-feedback hidden" aria-live="polite"></div>
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
        $typeThemes = [
          'calm_empath' => ['bar' => 'linear-gradient(90deg, #f5a8c8 0%, #ef7cae 100%)', 'tip_bg' => 'linear-gradient(180deg, #fff1f7 0%, #fff8fb 100%)', 'tip_border' => '#f2bfd5'],
          'soft_healer' => ['bar' => 'linear-gradient(90deg, #7bdcb5 0%, #4fbf9f 100%)', 'tip_bg' => 'linear-gradient(180deg, #effcf7 0%, #f8fffc 100%)', 'tip_border' => '#bfead9'],
          'energy_booster' => ['bar' => 'linear-gradient(90deg, #f59e0b 0%, #ef4444 100%)', 'tip_bg' => 'linear-gradient(180deg, #fff4e8 0%, #fff9f2 100%)', 'tip_border' => '#f6d0ad'],
          'flow_leader' => ['bar' => 'linear-gradient(90deg, #f59e0b 0%, #f97316 50%, #ef4444 100%)', 'tip_bg' => 'linear-gradient(180deg, #fff1e8 0%, #fff8f4 100%)', 'tip_border' => '#f5c2a3'],
          'sweet_spark' => ['bar' => 'linear-gradient(90deg, #fb7185 0%, #ec4899 100%)', 'tip_bg' => 'linear-gradient(180deg, #fff0f5 0%, #fff8fb 100%)', 'tip_border' => '#f5bfd2'],
          'elegant_calm' => ['bar' => 'linear-gradient(90deg, #a78bfa 0%, #7c3aed 100%)', 'tip_bg' => 'linear-gradient(180deg, #f5f3ff 0%, #faf8ff 100%)', 'tip_border' => '#d8ccff'],
          'silent_analyzer' => ['bar' => 'linear-gradient(90deg, #60a5fa 0%, #2563eb 100%)', 'tip_bg' => 'linear-gradient(180deg, #eef6ff 0%, #f8fbff 100%)', 'tip_border' => '#bdd7ff'],
          'all_rounder' => ['bar' => 'linear-gradient(90deg, #94a3b8 0%, #64748b 100%)', 'tip_bg' => 'linear-gradient(180deg, #f4f6f8 0%, #fbfcfd 100%)', 'tip_border' => '#d7dee7'],
        ];
        $theme = $typeThemes[$resultView['type']] ?? $typeThemes['all_rounder'];
        $minusBar = 'linear-gradient(90deg, #93c5fd 0%, #60a5fa 100%)';
        $imagePath = '/wbss/public/images/cast_type_images/' . rawurlencode($resultView['type']) . '.png';
        $averageScores = is_array($cumulativeSummary['average_scores'] ?? null) ? $cumulativeSummary['average_scores'] : [];
        $categoryCounts = is_array($cumulativeSummary['category_counts'] ?? null) ? $cumulativeSummary['category_counts'] : [];
        arsort($categoryCounts);
        $topCategories = array_slice($categoryCounts, 0, 3, true);
        $cumulativeAxes = [
          'talk_axis' => ['title' => '会話', 'left' => '受容', 'right' => '主導', 'positive' => '主導強め', 'neutral' => '中間', 'negative' => '受容強め'],
          'mood_axis' => ['title' => '空気', 'left' => '安心', 'right' => '盛り上げ', 'positive' => '盛り上げ強め', 'neutral' => '中間', 'negative' => '安心強め'],
          'response_axis' => ['title' => '反応', 'left' => '観察', 'right' => '直感', 'positive' => '直感強め', 'neutral' => '中間', 'negative' => '観察強め'],
          'relation_axis' => ['title' => '関係性', 'left' => '信頼', 'right' => '恋愛演出', 'positive' => '恋愛演出強め', 'neutral' => '中間', 'negative' => '信頼蓄積強め'],
        ];
        $cumulativeHeadline = '最近の接客傾向は、全体としてバランスよく出ています。';
        if ($averageScores) {
          $dominantAxisKey = null;
          $dominantAxisValue = 0.0;
          foreach ($cumulativeAxes as $axisKey => $axisMeta) {
            $axisValue = (float)($averageScores[$axisKey] ?? 0.0);
            if (abs($axisValue) > abs($dominantAxisValue)) {
              $dominantAxisKey = $axisKey;
              $dominantAxisValue = $axisValue;
            }
          }
          if ($dominantAxisKey !== null && abs($dominantAxisValue) >= 1.0) {
            $dominantMeta = $cumulativeAxes[$dominantAxisKey];
            $dominantSide = $dominantAxisValue < 0 ? $dominantMeta['left'] : $dominantMeta['right'];
            $cumulativeHeadline = 'あなたは最近さらに' . $dominantSide . '寄りです。';
          }
        }
      ?>
      <div class="cast-type-result-page" style="--result-tip-bg: <?= h($theme['tip_bg']) ?>; --result-tip-border: <?= h($theme['tip_border']) ?>;">
        <?php if ($saveNotice !== ''): ?>
          <div class="result-notice"><?= h($saveNotice) ?></div>
        <?php endif; ?>

        <div class="cast-type-result-header">
          <div>
            <h1>接客タイプ診断</h1>
            <p class="cast-type-result-sub">今の接客スタイルを、見返しやすく整理しました。</p>
          </div>
        </div>

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
              <?php $talkScore = (int)$resultView['talk_score']; ?>
              <div class="score-header">
                <span class="score-item__label">会話</span>
                <div class="score-value-group">
                  <span class="score-side-label"><?= $talkScore < 0 ? '受容寄り' : ($talkScore > 0 ? '主導寄り' : '中間') ?></span>
                  <span class="score-item__value"><?= abs($talkScore) ?></span>
                </div>
              </div>
              <div class="score-direction"><span>← 受容</span><span>主導 →</span></div>
                <div class="score-bar">
                  <div class="score-bar-center"></div>
                <?php if ($talkScore < 0): ?>
                  <?php $talkWidth = min(50.0, max(4.0, (abs($talkScore) / 10) * 50)); ?>
                  <div class="score-bar__fill minus" style="width: <?= $talkWidth ?>%; background-image: <?= h($minusBar) ?>;"></div>
                <?php elseif ($talkScore > 0): ?>
                  <?php $talkWidth = min(50.0, max(4.0, ($talkScore / 10) * 50)); ?>
                  <div class="score-bar__fill plus" style="width: <?= $talkWidth ?>%; background-image: <?= h($theme['bar']) ?>;"></div>
                <?php endif; ?>
              </div>
              <div class="score-item__sub"><?= h($resultView['talk_label']) ?></div>
            </div>
            <div class="score-item">
              <?php $moodScore = (int)$resultView['mood_score']; ?>
              <div class="score-header">
                <span class="score-item__label">空気</span>
                <div class="score-value-group">
                  <span class="score-side-label"><?= $moodScore < 0 ? '安心寄り' : ($moodScore > 0 ? '盛り上げ寄り' : '中間') ?></span>
                  <span class="score-item__value"><?= abs($moodScore) ?></span>
                </div>
              </div>
              <div class="score-direction"><span>← 安心</span><span>盛り上げ →</span></div>
              <div class="score-bar">
                <div class="score-bar-center"></div>
                <?php if ($moodScore < 0): ?>
                  <?php $moodWidth = min(50.0, max(4.0, (abs($moodScore) / 10) * 50)); ?>
                  <div class="score-bar__fill minus" style="width: <?= $moodWidth ?>%; background-image: <?= h($minusBar) ?>;"></div>
                <?php elseif ($moodScore > 0): ?>
                  <?php $moodWidth = min(50.0, max(4.0, ($moodScore / 10) * 50)); ?>
                  <div class="score-bar__fill plus" style="width: <?= $moodWidth ?>%; background-image: <?= h($theme['bar']) ?>;"></div>
                <?php endif; ?>
              </div>
              <div class="score-item__sub"><?= h($resultView['mood_label']) ?></div>
            </div>
            <div class="score-item">
              <?php $responseScore = (int)$resultView['response_score']; ?>
              <div class="score-header">
                <span class="score-item__label">反応</span>
                <div class="score-value-group">
                  <span class="score-side-label"><?= $responseScore < 0 ? '観察寄り' : ($responseScore > 0 ? '直感寄り' : '中間') ?></span>
                  <span class="score-item__value"><?= abs($responseScore) ?></span>
                </div>
              </div>
              <div class="score-direction"><span>← 観察</span><span>直感 →</span></div>
              <div class="score-bar">
                <div class="score-bar-center"></div>
                <?php if ($responseScore < 0): ?>
                  <?php $responseWidth = min(50.0, max(4.0, (abs($responseScore) / 10) * 50)); ?>
                  <div class="score-bar__fill minus" style="width: <?= $responseWidth ?>%; background-image: <?= h($minusBar) ?>;"></div>
                <?php elseif ($responseScore > 0): ?>
                  <?php $responseWidth = min(50.0, max(4.0, ($responseScore / 10) * 50)); ?>
                  <div class="score-bar__fill plus" style="width: <?= $responseWidth ?>%; background-image: <?= h($theme['bar']) ?>;"></div>
                <?php endif; ?>
              </div>
              <div class="score-item__sub"><?= h($resultView['response_label']) ?></div>
            </div>
            <div class="score-item">
              <?php $relationScore = (int)$resultView['relation_score']; ?>
              <div class="score-header">
                <span class="score-item__label">関係性</span>
                <div class="score-value-group">
                  <span class="score-side-label"><?= $relationScore < 0 ? '信頼寄り' : ($relationScore > 0 ? '恋愛演出寄り' : '中間') ?></span>
                  <span class="score-item__value"><?= abs($relationScore) ?></span>
                </div>
              </div>
              <div class="score-direction"><span>← 信頼</span><span>恋愛演出 →</span></div>
              <div class="score-bar">
                <div class="score-bar-center"></div>
                <?php if ($relationScore < 0): ?>
                  <?php $relationWidth = min(50.0, max(4.0, (abs($relationScore) / 10) * 50)); ?>
                  <div class="score-bar__fill minus" style="width: <?= $relationWidth ?>%; background-image: <?= h($minusBar) ?>;"></div>
                <?php elseif ($relationScore > 0): ?>
                  <?php $relationWidth = min(50.0, max(4.0, ($relationScore / 10) * 50)); ?>
                  <div class="score-bar__fill plus" style="width: <?= $relationWidth ?>%; background-image: <?= h($theme['bar']) ?>;"></div>
                <?php endif; ?>
              </div>
              <div class="score-item__sub"><?= h($resultView['relation_label']) ?></div>
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

        <?php if (is_array($cumulativeSummary) && (int)($cumulativeSummary['session_count'] ?? 0) > 0): ?>
          <section class="card-panel cumulative-panel">
            <div class="cumulative-panel__head">
              <div>
                <div class="cumulative-panel__badge">累積傾向</div>
                <h3>最近の接客スタイルの積み上がり</h3>
                <p class="cumulative-panel__lead">直近 <?= (int)$cumulativeSummary['session_count'] ?> 回分の平均から、今の安定した傾向をまとめています。</p>
                <p class="cumulative-panel__headline"><?= h($cumulativeHeadline) ?></p>
              </div>
            </div>

            <div class="cumulative-grid">
              <?php foreach ($cumulativeAxes as $axisKey => $axisMeta): ?>
                <?php
                  $averageValue = (float)($averageScores[$axisKey] ?? 0.0);
                  $roundedAverage = (int)round($averageValue);
                  $directionLabel = $roundedAverage < 0
                    ? $axisMeta['left'] . '寄り'
                    : ($roundedAverage > 0 ? $axisMeta['right'] . '寄り' : '中間');
                  $bucketLabel = service_quiz_axis_bucket($roundedAverage, $axisMeta['positive'], $axisMeta['neutral'], $axisMeta['negative']);
                  $fillWidth = min(50.0, max(0.0, (abs($averageValue) / 10) * 50));
                  $formattedAverage = number_format(abs($averageValue), 1);
                ?>
                <div class="cumulative-item">
                  <div class="cumulative-item__top">
                    <span class="cumulative-item__title"><?= h($axisMeta['title']) ?></span>
                    <span class="cumulative-item__value"><?= h($formattedAverage) ?></span>
                  </div>
                  <div class="score-direction cumulative-item__direction"><span>← <?= h($axisMeta['left']) ?></span><span><?= h($axisMeta['right']) ?> →</span></div>
                  <div class="score-bar cumulative-item__bar">
                    <div class="score-bar-center"></div>
                    <?php if ($averageValue < 0): ?>
                      <div class="score-bar__fill minus" style="width: <?= $fillWidth ?>%; background-image: <?= h($minusBar) ?>;"></div>
                    <?php elseif ($averageValue > 0): ?>
                      <div class="score-bar__fill plus" style="width: <?= $fillWidth ?>%; background-image: <?= h($theme['bar']) ?>;"></div>
                    <?php endif; ?>
                  </div>
                  <div class="cumulative-item__meta">
                    <span><?= h($directionLabel) ?></span>
                    <span><?= h($bucketLabel) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="cumulative-panel__categories">
              <h4>よく出ている接客シーン</h4>
              <?php if ($topCategories): ?>
                <div class="cumulative-tags">
                  <?php foreach ($topCategories as $categoryKey => $count): ?>
                    <span><?= h((string)($categoryLabels[$categoryKey]['label'] ?? $categoryKey)) ?> × <?= (int)$count ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="cumulative-panel__empty">履歴がたまると、接客シーンごとの偏りもここに表示されます。</p>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>

        <div class="result-actions">
          <a href="/wbss/public/service_quiz.php?start=1" class="btn btn-secondary">もう一度診断する</a>
          <a href="/wbss/public/dashboard_cast.php" class="btn btn-primary">ダッシュボードへ戻る</a>

        </div>
      </div>

    <?php else: ?>
      <section class="card serviceQuizCard serviceQuizIntro">
        <div class="serviceQuizEyebrow">WBSS 接客タイプ診断</div>
        <h1 class="serviceQuizTitle">接客実務向けの自己診断</h1>
        <p class="serviceQuizIntro__lead">固定12問ではなく、カテゴリごとにバランスを取った質問プールから毎回12〜16問を出題します。接客で自然に出やすい反応傾向を、4軸スコアから見ていく診断です。</p>
        <div class="serviceQuizIntro__chips">
          <span>12〜16問</span>
          <span>カテゴリ抽選</span>
          <span>4軸スコア</span>
          <span>8タイプ判定</span>
          <span>履歴保存対応</span>
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
.serviceQuizQuestionMeta{margin-top:6px;color:var(--muted);font-size:12px;font-weight:800;letter-spacing:.04em}
.serviceQuizHero__top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.serviceQuizCount,.serviceQuizResultHero__meta{color:var(--muted);font-size:12px;font-weight:800}
.serviceQuizProgress{height:10px;border-radius:999px;background:rgba(255,255,255,.62);margin-top:14px;overflow:hidden}
.serviceQuizProgress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg, #ff7aad, #ffb6d8)}
.serviceQuizProgressMeta{margin-top:8px;color:var(--muted);font-size:12px}
.serviceQuizQuestion__label{
  font-size:12px;
  font-weight:1000;
  color:var(--muted);
  letter-spacing:.08em;
  text-transform:none;
}
.serviceQuizQuestion__body{
  margin-top:12px;
  font-size:26px;
  line-height:1.65;
  font-weight:1000;
  letter-spacing:.01em;
}
.serviceQuizQuestion__prompt{
  margin-top:18px;
  color:var(--txt);
  font-size:16px;
  line-height:1.8;
  font-weight:800;
}
.serviceQuizChoices{display:grid;gap:10px;margin-top:18px}
.serviceQuizChoice{
  display:flex;align-items:flex-start;gap:12px;width:100%;padding:16px;border-radius:18px;
  border:1px solid color-mix(in srgb, var(--accent) 18%, var(--line));background:rgba(255,255,255,.78);
  color:var(--txt);font:inherit;text-align:left;cursor:pointer;transition:all .15s ease
}
.serviceQuizChoice:hover,
.quiz-option:hover{
  transform:translateY(-1px);
  background:#f9fafb;
  border-color:rgba(255,145,194,.56);
  box-shadow:0 16px 30px rgba(255,165,206,.12);
}
.serviceQuizChoice:active,
.quiz-option:active{
  transform:scale(.97);
}
.serviceQuizChoice.is-active,
.quiz-option.active{
  animation:quizChoiceTap .1s ease;
  background:#fef3f2;
  border-color:rgba(251,146,60,.38);
  box-shadow:0 14px 28px rgba(251,146,60,.14);
}
.serviceQuizChoice__key{
  width:38px;height:38px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;
  border-radius:14px;background:linear-gradient(180deg, rgba(255,245,250,.98), rgba(255,231,241,.92));
  border:1px solid rgba(255,182,211,.42);font-size:15px;font-weight:1000
}
.serviceQuizChoice__text{font-size:15px;line-height:1.65;font-weight:800}
.quiz-feedback{
  margin-top:12px;
  padding:12px 14px;
  border-radius:14px;
  background:#111827;
  color:#fff;
  text-align:center;
  font-size:14px;
  line-height:1.6;
  font-weight:800;
  opacity:0;
  transform:translateY(4px);
}
.quiz-feedback.show{
  opacity:1;
  transform:translateY(0);
  transition:opacity .2s ease, transform .2s ease;
}
.quiz-feedback.hidden{
  display:none;
}
.serviceQuizRestart{display:flex;justify-content:center}
.serviceQuizNotice{padding:14px 16px;font-weight:800}
.serviceQuizNotice--ok{border-color:rgba(111,224,176,.42);background:rgba(236,255,246,.8)}
.serviceQuizNotice--error{border-color:rgba(239,68,68,.35);background:rgba(255,241,241,.9)}
.serviceQuizResultHero__summary,.serviceQuizIntro__lead{margin:14px 0 0;color:var(--muted);font-size:14px;line-height:1.8}
.cast-type-result-page{
  max-width:1120px;
  margin:0 auto;
  padding:0 16px 48px;
  color:#1f2937;
}
.cast-type-result-header{
  display:flex;
  align-items:flex-start;
  gap:16px;
  margin-bottom:14px;
}
.cast-type-result-header h1{
  margin:0;
  font-size:32px;
  font-weight:800;
  letter-spacing:.02em;
}
.cast-type-result-sub{
  margin:8px 0 0;
  color:#6b7280;
  font-size:13px;
  line-height:1.7;
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
  position:sticky;
  top:16px;
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
  background:var(--result-tip-bg, linear-gradient(180deg, #fff7fb 0%, #ffffff 100%));
  border:1px solid var(--result-tip-border, #f4d9e7);
  box-shadow:0 20px 52px rgba(201,70,120,.12);
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
  margin:0 0 10px;
  font-size:40px;
  line-height:1.15;
  font-weight:900;
  letter-spacing:.01em;
}
.type-copy{
  margin:0 0 10px;
  font-size:24px;
  line-height:1.45;
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
  color:#9ca3af;
  font-size:11px;
  font-weight:500;
  letter-spacing:.02em;
  padding-top:14px;
  border-top:1px solid #eef2f7;
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
  padding:18px 16px 16px;
  border-radius:18px;
  background:#f8fafc;
  border:1px solid #e8edf5;
}
.score-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
}
.score-value-group{
  display:grid;
  justify-items:end;
  gap:4px;
}
.score-side-label{
  display:inline-flex;
  align-items:center;
  min-height:22px;
  padding:0 8px;
  border-radius:999px;
  background:#eef2f7;
  color:#667085;
  font-size:11px;
  font-weight:800;
  white-space:nowrap;
}
.score-item__label{
  font-size:13px;
  color:#6b7280;
  font-weight:700;
  margin-bottom:10px;
}
.score-item__value{
  font-size:34px;
  line-height:1;
  font-weight:900;
  color:#111827;
  margin-bottom:8px;
}
.score-direction{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
  color:#6b7280;
  font-size:12px;
  font-weight:700;
  letter-spacing:.02em;
}
.score-direction span{
  white-space:nowrap;
}
.score-item__sub{
  font-size:13px;
  font-weight:700;
  color:#374151;
  margin-top:14px;
}
.score-bar{
  position:relative;
  height:11px;
  border-radius:999px;
  background:#d7dde7;
  overflow:hidden;
}
.score-bar-center{
  position:absolute;
  left:50%;
  top:0;
  width:2px;
  height:100%;
  background:#9ca3af;
  transform:translateX(-50%);
  z-index:2;
}
.score-bar__fill{
  position:absolute;
  top:0;
  height:100%;
  border-radius:999px;
  box-shadow:0 0 0 1px rgba(255,255,255,.22) inset;
  z-index:1;
}
.score-bar__fill.minus{
  right:50%;
  background-color:#60a5fa;
  background-repeat:no-repeat;
  background-size:100% 100%;
}
.score-bar__fill.plus{
  left:50%;
  background-color:#f97316;
  background-repeat:no-repeat;
  background-size:100% 100%;
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
  line-height:2.05;
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
.cumulative-panel{
  margin-bottom:28px;
}
.cumulative-panel__head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}
.cumulative-panel__badge{
  display:inline-flex;
  align-items:center;
  min-height:30px;
  padding:0 10px;
  border-radius:999px;
  background:rgba(17,24,39,.04);
  border:1px solid #e2e8f0;
  color:#596275;
  font-size:12px;
  font-weight:800;
  margin-bottom:10px;
}
.cumulative-panel h3{
  margin:0;
  font-size:22px;
  font-weight:800;
}
.cumulative-panel__lead{
  margin:8px 0 0;
  color:#6b7280;
  font-size:14px;
  line-height:1.8;
}
.cumulative-panel__headline{
  margin:10px 0 0;
  color:#111827;
  font-size:15px;
  line-height:1.8;
  font-weight:800;
}
.cumulative-grid{
  display:grid;
  grid-template-columns:repeat(2, 1fr);
  gap:16px;
}
.cumulative-item{
  padding:18px 16px;
  border-radius:20px;
  background:#f8fafc;
  border:1px solid #e8edf5;
}
.cumulative-item__top{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
  margin-bottom:10px;
}
.cumulative-item__title{
  font-size:14px;
  font-weight:800;
  color:#374151;
}
.cumulative-item__value{
  font-size:24px;
  line-height:1;
  font-weight:900;
  color:#111827;
}
.cumulative-item__direction{
  margin-bottom:10px;
}
.cumulative-item__bar{
  margin-bottom:12px;
}
.cumulative-item__meta{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  color:#4b5563;
  font-size:13px;
  font-weight:700;
}
.cumulative-panel__categories{
  margin-top:20px;
  padding-top:20px;
  border-top:1px solid #edf2f7;
}
.cumulative-panel__categories h4{
  margin:0 0 12px;
  font-size:16px;
  font-weight:800;
  color:#1f2937;
}
.cumulative-tags{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
}
.cumulative-tags span{
  display:inline-flex;
  align-items:center;
  min-height:34px;
  padding:0 12px;
  border-radius:999px;
  background:#f4f7fb;
  border:1px solid #dfe7f1;
  color:#374151;
  font-size:13px;
  font-weight:800;
}
.cumulative-panel__empty{
  margin:0;
  color:#6b7280;
  font-size:14px;
  line-height:1.7;
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
body[data-theme="dark"] .result-notice{
  background:
    radial-gradient(circle at top right, rgba(255,146,194,.2), transparent 38%),
    radial-gradient(circle at bottom left, rgba(164,140,255,.16), transparent 34%),
    linear-gradient(180deg, rgba(38,43,61,.96), rgba(44,50,71,.94));
}
body[data-theme="dark"] .score-item{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
}
body[data-theme="dark"] .cumulative-item,
body[data-theme="dark"] .cumulative-tags span,
body[data-theme="dark"] .cumulative-panel__badge{
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
body[data-theme="dark"] .serviceQuizQuestionMeta,
body[data-theme="dark"] .serviceQuizResultHero__summary,
body[data-theme="dark"] .serviceQuizIntro__lead,
body[data-theme="dark"] .serviceQuizLatest__summary,
body[data-theme="dark"] .cast-type-result-sub,
body[data-theme="dark"] .type-subtitle,
body[data-theme="dark"] .type-description,
body[data-theme="dark"] .saved-at,
body[data-theme="dark"] .score-item__label,
body[data-theme="dark"] .score-direction,
body[data-theme="dark"] .score-item__sub,
body[data-theme="dark"] .result-list li{
  color:rgba(230,223,240,.82);
}
body[data-theme="dark"] .score-side-label{
  background:rgba(255,255,255,.10);
  color:rgba(230,223,240,.88);
}
body[data-theme="dark"] .serviceQuizQuestion__body,
body[data-theme="dark"] .serviceQuizTitle,
body[data-theme="dark"] .serviceQuizLatest__name,
body[data-theme="dark"] .cast-type-result-header h1,
body[data-theme="dark"] .type-title,
body[data-theme="dark"] .type-copy,
body[data-theme="dark"] .score-item__value,
body[data-theme="dark"] .cumulative-panel h3,
body[data-theme="dark"] .cumulative-panel__categories h4,
body[data-theme="dark"] .cumulative-item__title,
body[data-theme="dark"] .cumulative-item__value,
body[data-theme="dark"] .today-tip{
  color:#fff8fc;
}
body[data-theme="dark"] .cumulative-item__meta,
body[data-theme="dark"] .cumulative-panel__lead,
body[data-theme="dark"] .cumulative-panel__empty,
body[data-theme="dark"] .cumulative-tags span{
  color:rgba(230,223,240,.82);
}
body[data-theme="dark"] .cumulative-panel__headline{
  color:#fff8fc;
}
body[data-theme="dark"] .serviceQuizChoice__key{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.14)}
body[data-theme="dark"] .serviceQuizChoice.is-active,
body[data-theme="dark"] .quiz-option.active{
  background:rgba(251,146,60,.18);
  border-color:rgba(251,146,60,.34);
}
body[data-theme="dark"] .score-bar{background:rgba(255,255,255,.14)}
body[data-theme="dark"] .score-bar-center{background:rgba(255,255,255,.36)}
body[data-theme="dark"] .cumulative-panel__categories{border-top-color:rgba(255,255,255,.10)}
body[data-theme="dark"] .quiz-feedback{
  background:#f8fafc;
  color:#111827;
}
@media (max-width: 960px){
  .result-hero{grid-template-columns:1fr}
  .score-grid{grid-template-columns:repeat(2, 1fr)}
  .result-grid{grid-template-columns:1fr}
  .cumulative-grid{grid-template-columns:1fr}
  .type-title{font-size:34px}
}
@media (max-width: 640px){
  .cast-type-result-page{padding:16px 12px 32px}
  .cast-type-result-header{
    flex-direction:column;
    gap:8px;
    margin-bottom:10px;
  }
  .cast-type-result-header h1{font-size:26px}
  .cast-type-result-sub{font-size:13px}
  .serviceQuizCard{padding:16px}
  .serviceQuizTitle{font-size:24px}
  .serviceQuizQuestion__body{
    font-size:22px;
    line-height:1.7;
  }
  .serviceQuizQuestion__prompt{
    margin-top:16px;
    font-size:15px;
    line-height:1.8;
  }
  .result-hero{grid-template-columns:1fr}
  .score-grid{grid-template-columns:1fr}
  .result-grid{grid-template-columns:1fr}
  .result-hero__card{position:static}
  .card-panel,
  .result-hero__card{
    border-radius:20px;
    padding:16px;
  }
  .type-title{font-size:30px}
  .type-copy{font-size:18px}
  .serviceQuizActions{display:grid}
  .serviceQuizActions .btn{width:100%;text-align:center}
  .result-actions{
    flex-direction:column;
    gap:12px;
  }
  .btn{width:100%}
}
@keyframes quizChoiceTap{
  0%{transform:scale(.98)}
  100%{transform:scale(1)}
}
</style>
<script>
(() => {
  const feedbackEl = document.getElementById('quiz-feedback');
  const choiceButtons = document.querySelectorAll('.serviceQuizChoice');
  if (!feedbackEl || !choiceButtons.length) {
    return;
  }

  const generateFeedback = (scores) => {
    if (scores.mood <= -2) return '安心感が強い選択です';
    if (scores.mood >= 2) return '盛り上げ力が出ています';
    if (scores.response <= -2) return '観察力が出ています';
    if (scores.response >= 2) return '直感タイプの動きです';
    if (scores.relation >= 2) return '恋愛演出寄りです';
    if (scores.relation <= -2) return '信頼構築寄りです';
    if (scores.talk >= 2) return '主導力が自然に出ています';
    if (scores.talk <= -2) return '受け止める力が出ています';
    return 'バランスの良い選択です';
  };

  choiceButtons.forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();

      const form = button.closest('form');
      const choiceInput = form ? form.querySelector('.serviceQuizChoiceValue') : null;
      if (!form || !choiceInput) {
        return;
      }

      choiceButtons.forEach((item) => {
        item.disabled = true;
        item.classList.remove('is-active', 'active');
      });

      button.classList.add('is-active', 'active');
      choiceInput.value = button.value;

      const scores = {
        talk: Number(button.dataset.scoreTalk || 0),
        mood: Number(button.dataset.scoreMood || 0),
        response: Number(button.dataset.scoreResponse || 0),
        relation: Number(button.dataset.scoreRelation || 0),
      };

      feedbackEl.textContent = generateFeedback(scores);
      feedbackEl.classList.remove('hidden');
      feedbackEl.classList.add('show');

      window.setTimeout(() => {
        form.submit();
      }, 1000);
    });
  });
})();
</script>
<?php render_page_end(); ?>
