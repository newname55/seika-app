<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_quiz.php';
require_once __DIR__ . '/../app/service_training.php';
require_once __DIR__ . '/../app/service_training_mission_logic.php';
require_once __DIR__ . '/../app/service_training_badge_logic.php';

const SERVICE_TRAINING_SESSION_KEY = '__service_training_run';
const SERVICE_TRAINING_RESULT_KEY = '__service_training_result';
const SERVICE_TRAINING_HISTORY_KEY = '__service_training_history';

function service_training_question_map(array $questions): array {
  $map = [];
  foreach ($questions as $question) {
    $map[(string)($question['id'] ?? '')] = $question;
  }
  return $map;
}

function service_training_questions_by_ids(array $pool, array $ids): array {
  $map = service_training_question_map($pool);
  $rows = [];
  foreach ($ids as $id) {
    $key = (string)$id;
    if ($key !== '' && isset($map[$key])) {
      $rows[] = $map[$key];
    }
  }
  return $rows;
}

function service_training_group_by_category(array $questions): array {
  $grouped = [];
  foreach ($questions as $question) {
    $category = (string)($question['category'] ?? 'misc');
    $grouped[$category] ??= [];
    $grouped[$category][] = (string)($question['id'] ?? '');
  }
  return $grouped;
}

function service_training_select_question_ids(array $questions, array $recommendedCategories, int $targetCount = 8): array {
  $grouped = service_training_group_by_category($questions);
  $categories = array_keys($grouped);
  $targetCount = max(count($categories), min($targetCount, count($categories) * 2));

  $selected = [];
  $selectedLookup = [];
  $counts = [];

  foreach ($grouped as $category => $ids) {
    shuffle($ids);
    $pick = array_shift($ids);
    if ($pick === null || $pick === '') {
      continue;
    }
    $selected[] = $pick;
    $selectedLookup[$pick] = true;
    $counts[$category] = 1;
    $grouped[$category] = $ids;
  }

  $priorityCategories = array_values(array_intersect($recommendedCategories, $categories));
  while (count($selected) < $targetCount) {
    $picked = false;

    foreach ($priorityCategories as $category) {
      $pool = $grouped[$category] ?? [];
      if ((int)($counts[$category] ?? 0) >= 2 || !$pool) {
        continue;
      }
      shuffle($pool);
      $pick = array_shift($pool);
      if ($pick === null || isset($selectedLookup[$pick])) {
        $grouped[$category] = $pool;
        continue;
      }
      $selected[] = $pick;
      $selectedLookup[$pick] = true;
      $counts[$category] = (int)($counts[$category] ?? 0) + 1;
      $grouped[$category] = $pool;
      $picked = true;
      if (count($selected) >= $targetCount) {
        break;
      }
    }

    if ($picked) {
      continue;
    }

    $remainingCategories = $categories;
    shuffle($remainingCategories);
    foreach ($remainingCategories as $category) {
      $pool = $grouped[$category] ?? [];
      if ((int)($counts[$category] ?? 0) >= 2 || !$pool) {
        continue;
      }
      shuffle($pool);
      $pick = array_shift($pool);
      if ($pick === null || isset($selectedLookup[$pick])) {
        $grouped[$category] = $pool;
        continue;
      }
      $selected[] = $pick;
      $selectedLookup[$pick] = true;
      $counts[$category] = (int)($counts[$category] ?? 0) + 1;
      $grouped[$category] = $pool;
      $picked = true;
      break;
    }

    if (!$picked) {
      break;
    }
  }

  shuffle($selected);
  return array_values($selected);
}

function service_training_start_run(array $questions, array $recommendedCategories, int $targetCount = 8): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  $questionIds = service_training_select_question_ids($questions, $recommendedCategories, $targetCount);
  $run = [
    'token' => bin2hex(random_bytes(16)),
    'question_ids' => $questionIds,
    'question_count' => count($questionIds),
    'started_at' => date('Y-m-d H:i:s'),
  ];
  $_SESSION[SERVICE_TRAINING_SESSION_KEY] = $run;
  unset($_SESSION[SERVICE_TRAINING_RESULT_KEY]);
  return $run;
}

function service_training_get_run(): ?array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $run = $_SESSION[SERVICE_TRAINING_SESSION_KEY] ?? null;
  return is_array($run) ? $run : null;
}

function service_training_clear_run(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  unset($_SESSION[SERVICE_TRAINING_SESSION_KEY]);
}

function service_training_store_result(array $result): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $_SESSION[SERVICE_TRAINING_RESULT_KEY] = $result;
}

function service_training_get_result(): ?array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $result = $_SESSION[SERVICE_TRAINING_RESULT_KEY] ?? null;
  return is_array($result) ? $result : null;
}

function service_training_clear_result(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  unset($_SESSION[SERVICE_TRAINING_RESULT_KEY]);
}

function service_training_push_history(array $result): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $history = $_SESSION[SERVICE_TRAINING_HISTORY_KEY] ?? [];
  if (!is_array($history)) {
    $history = [];
  }
  $history[] = [
    'created_at' => date('Y-m-d H:i:s'),
    'stretch_points' => array_values((array)($result['stretch_points'] ?? [])),
    'weak_tags' => array_values((array)($result['weak_tags'] ?? [])),
  ];
  if (count($history) > 20) {
    $history = array_slice($history, -20);
  }
  $_SESSION[SERVICE_TRAINING_HISTORY_KEY] = array_values($history);
}

function service_training_recent_weak_tags(int $limit = 3): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $history = $_SESSION[SERVICE_TRAINING_HISTORY_KEY] ?? [];
  if (!is_array($history) || !$history) {
    return [];
  }

  $tagCounts = [];
  foreach (array_slice($history, -10) as $row) {
    foreach ((array)($row['weak_tags'] ?? []) as $tag) {
      $tag = trim((string)$tag);
      if ($tag === '') {
        continue;
      }
      $tagCounts[$tag] = (int)($tagCounts[$tag] ?? 0) + 1;
    }
  }

  arsort($tagCounts);
  return array_slice($tagCounts, 0, max(1, $limit), true);
}

function service_training_rank_meta(string $rank): array {
  $map = [
    'best' => ['label' => 'かなり良い', 'class' => 'is-best'],
    'good' => ['label' => '良い視点', 'class' => 'is-good'],
    'ok' => ['label' => '悪くない', 'class' => 'is-ok'],
    'weak' => ['label' => 'もう一工夫', 'class' => 'is-weak'],
  ];
  return $map[$rank] ?? ['label' => 'フィードバック', 'class' => 'is-ok'];
}

function service_training_category_meta(): array {
  return [
    'basic_manners' => ['label' => '基本マナー', 'tip' => '基本所作が整うと、安心感がひと目で伝わります。'],
    'service_behavior' => ['label' => '接客所作', 'tip' => '動きの丁寧さは、会話以上に印象へ残ります。'],
    'conversation_entry' => ['label' => '会話の入り', 'tip' => '最初の一言が、その後の空気をかなり左右します。'],
    'air_reading' => ['label' => '空気読み', 'tip' => '相手の状態に合わせた微調整が、接客の完成度を上げます。'],
    'appearance_strategy' => ['label' => '見た目戦略', 'tip' => '見た目の整え方は、第一印象の安心感を支えます。'],
  ];
}

function service_training_calculate_result(array $questions, array $answers, array $growthTheme, array $categoryMeta): array {
  $questionMap = service_training_question_map($questions);
  $totalScore = 0;
  $maxScore = 0;
  $tagTotals = [];
  $tagCounts = [];
  $categoryTotals = [];
  $categoryCounts = [];
  $answeredRows = [];

  foreach ($answers as $questionId => $choiceKey) {
    $questionId = (string)$questionId;
    $choiceKey = strtoupper(trim((string)$choiceKey));
    if (!isset($questionMap[$questionId])) {
      continue;
    }

    $question = $questionMap[$questionId];
    $choices = [];
    foreach ((array)($question['choices'] ?? []) as $choice) {
      $choices[(string)($choice['key'] ?? '')] = $choice;
      $maxScore = max($maxScore, (int)($choice['score'] ?? 0));
    }
    if (!isset($choices[$choiceKey])) {
      continue;
    }

    $choice = $choices[$choiceKey];
    $score = (int)($choice['score'] ?? 0);
    $totalScore += $score;

    $category = (string)($question['category'] ?? 'basic_manners');
    $categoryTotals[$category] = (int)($categoryTotals[$category] ?? 0) + $score;
    $categoryCounts[$category] = (int)($categoryCounts[$category] ?? 0) + 1;

    foreach ((array)($question['skill_tags'] ?? []) as $tag) {
      $tag = (string)$tag;
      if ($tag === '') {
        continue;
      }
      $tagTotals[$tag] = (int)($tagTotals[$tag] ?? 0) + $score;
      $tagCounts[$tag] = (int)($tagCounts[$tag] ?? 0) + 1;
    }

    $answeredRows[] = [
      'question' => $question,
      'choice' => $choice,
      'score' => $score,
      'rank' => (string)($choice['rank'] ?? 'ok'),
    ];
  }

  $strongTags = [];
  $stretchTags = [];
  foreach ($tagTotals as $tag => $scoreTotal) {
    $average = $scoreTotal / max(1, (int)($tagCounts[$tag] ?? 1));
    $entry = ['tag' => $tag, 'average' => $average];
    if ($average >= 2.0) {
      $strongTags[] = $entry;
    }
    if ($average <= 1.5) {
      $stretchTags[] = $entry;
    }
  }
  usort($strongTags, static fn(array $a, array $b): int => $b['average'] <=> $a['average']);
  usort($stretchTags, static fn(array $a, array $b): int => $a['average'] <=> $b['average']);

  $strongPoints = [];
  foreach (array_slice($strongTags, 0, 3) as $row) {
    $strongPoints[] = $row['tag'] . 'を自然に出せています。';
  }

  $stretchPoints = [];
  foreach (array_slice($stretchTags, 0, 3) as $row) {
    $stretchPoints[] = $row['tag'] . 'は、もう一工夫でさらに伸ばせます。';
  }
  $weakTags = array_map(static fn(array $row): string => (string)$row['tag'], array_slice($stretchTags, 0, 5));

  $categoryAverages = [];
  foreach ($categoryTotals as $category => $scoreTotal) {
    $categoryAverages[$category] = $scoreTotal / max(1, (int)($categoryCounts[$category] ?? 1));
  }
  arsort($categoryAverages);
  $bestCategory = array_key_first($categoryAverages);
  $weakCategory = array_key_last($categoryAverages);

  if (!$strongPoints && $bestCategory !== null) {
    $strongPoints[] = (($categoryMeta[$bestCategory]['label'] ?? $bestCategory)) . 'は安定してできています。';
  }
  if (!$stretchPoints && $weakCategory !== null) {
    $stretchPoints[] = (($categoryMeta[$weakCategory]['label'] ?? $weakCategory)) . 'は今日の伸びしろです。';
  }

  $todayTip = (string)($growthTheme['daily_tip'] ?? '今日はひとつだけ丁寧さを足す意識で十分です。');
  if ($weakCategory !== null && isset($categoryMeta[$weakCategory]['tip'])) {
    $todayTip .= ' ' . (string)$categoryMeta[$weakCategory]['tip'];
  }

  $summary = (string)($growthTheme['growth_message'] ?? '今日は、接客の土台を少しずつ整えていく回です。');
  $focusSkills = array_slice((array)($growthTheme['focus_skills'] ?? []), 0, 3);

  return [
    'answered_count' => count($answeredRows),
    'total_score' => $totalScore,
    'max_total_score' => max(1, count($answeredRows) * 3),
    'strong_points' => $strongPoints,
    'stretch_points' => $stretchPoints,
    'weak_tags' => $weakTags,
    'today_tip' => $todayTip,
    'summary' => $summary,
    'focus_skills' => $focusSkills,
    'best_category' => $bestCategory,
    'weak_category' => $weakCategory,
    'answered_rows' => $answeredRows,
  ];
}

function service_training_render_mission_card(array $mission, string $typeName, array $growthTheme, int $streak, ?string $todayStatus, string $returnTo, bool $compact = false): void {
  $missionId = (string)($mission['id'] ?? $mission['mission_id'] ?? '');
  if ($missionId === '') {
    return;
  }
  $reasonText = service_training_mission_reason($mission);
  $skillTag = (string)($mission['skill_tag'] ?? '接客力');
  $statusMeta = service_training_mission_status_meta($todayStatus ?? 'none');
  ?>
  <section class="card trainingMissionCard <?= $compact ? 'trainingMissionCard--compact' : '' ?>">
    <div class="trainingMissionCard__label">今日のミッション</div>
    <div class="trainingMissionCard__typeLink">
      あなたは「<?= h($typeName) ?>」
      <span>だから今日はこれ</span>
    </div>
    <h2 class="trainingMissionCard__title"><?= h((string)($mission['action_text'] ?? '')) ?></h2>
    <?php if (!$compact): ?>
      <div class="trainingMissionCard__reason">
        <strong>理由:</strong>
        <span><?= h($reasonText) ?></span>
      </div>
      <div class="trainingMissionCard__hint">今日のコツ: <?= h((string)($mission['success_hint'] ?? (string)($growthTheme['daily_tip'] ?? '1回だけ意識できれば十分です。'))) ?></div>
      <div class="trainingMissionCard__streak">🔥 連続達成: <?= (int)$streak ?>日</div>
    <?php else: ?>
      <div class="trainingMissionCard__compactMeta">
        <span>理由: <?= h($reasonText) ?></span>
        <span>🔥 <?= (int)$streak ?>日</span>
      </div>
    <?php endif; ?>

    <form method="post" class="trainingMissionActions">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="mission_status">
      <input type="hidden" name="mission_id" value="<?= h($missionId) ?>">
      <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
      <?php foreach (['done', 'pending', 'skipped'] as $status): ?>
        <?php $meta = service_training_mission_status_meta($status); ?>
        <?php
          $feedbackBody = (string)$meta['feedback_body'];
          if ($status === 'done') {
            $feedbackBody = $skillTag . 'が伸びています。';
          } elseif ($status === 'skipped') {
            $feedbackBody = '明日は“1回だけ”意識してみよう。';
          }
        ?>
        <button
          type="submit"
          name="mission_status"
          value="<?= h($status) ?>"
          class="trainingMissionAction <?= $todayStatus === $status ? 'is-current ' . h($meta['class']) : '' ?>"
          data-feedback-title="<?= h((string)$meta['feedback_title']) ?>"
          data-feedback-body="<?= h($feedbackBody) ?>"
        >
          <?= h((string)$meta['button']) ?>
        </button>
      <?php endforeach; ?>
    </form>

    <div class="trainingMissionStatus <?= h($statusMeta['class']) ?>">
      今日の状態: <?= h((string)$statusMeta['label']) ?>
    </div>
    <div id="mission-feedback" class="trainingMissionFeedback hidden" aria-live="polite">
      <div class="trainingMissionFeedback__title"></div>
      <div class="trainingMissionFeedback__body"></div>
    </div>
  </section>
  <?php
}

require_login();
require_role(['cast']);

$pdo = db();
$userId = service_quiz_current_user_id();
$storeId = service_quiz_resolve_cast_store_id($pdo, $userId);

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が未設定です。管理者に所属店舗を設定してもらってください。');
}

$questionsPool = require __DIR__ . '/../app/service_training_questions.php';
$growthMap = require __DIR__ . '/../app/service_training_growth_map.php';
$categoryMeta = service_training_category_meta();
$trainingHistoryReady = service_training_history_tables_ready($pdo);
$missionLogsReady = service_training_mission_logs_table_ready($pdo);

$error = '';
$displayResult = null;
$isQuestionMode = ((string)($_GET['start'] ?? '') === '1');
$currentAnswers = [];

$latestQuizResult = service_quiz_fetch_latest_result($pdo, $storeId, $userId);
$typeKey = trim((string)($latestQuizResult['result_type_key'] ?? ''));
if ($typeKey === '') {
  $typeKey = 'all_rounder';
}
$typeName = (string)(($latestQuizResult['result_type'] ?? [])['name'] ?? 'バランス型');
$growthTheme = (array)($growthMap[$typeKey] ?? $growthMap['all_rounder'] ?? []);
$recommendedCategories = array_values(array_intersect(array_keys($categoryMeta), (array)($growthTheme['recommended_categories'] ?? [])));
$weakSkillTagsForMission = $trainingHistoryReady
  ? service_training_fetch_recent_weak_tags($pdo, $storeId, $userId, 10, 5)
  : service_training_recent_weak_tags(5);
$todayMissionLog = $missionLogsReady ? service_training_get_today_mission_log($pdo, $storeId, $userId) : null;
$todayMission = [];
if ($missionLogsReady && is_array($todayMissionLog) && trim((string)($todayMissionLog['mission_id'] ?? '')) !== '') {
  $todayMission = service_training_find_mission((string)$todayMissionLog['mission_id']);
}
if (!$todayMission) {
  $todayMission = service_training_resolve_today_mission($typeKey, $weakSkillTagsForMission, $growthTheme);
}
$todayMissionId = (string)($todayMission['id'] ?? $todayMission['mission_id'] ?? '');
$todayMissionStatus = $missionLogsReady
  ? (is_array($todayMissionLog) ? (string)($todayMissionLog['status'] ?? '') : null)
  : ($todayMissionId !== '' ? service_training_get_today_mission_status($todayMissionId) : null);
$missionStreak = $missionLogsReady ? service_training_done_streak($pdo, $storeId, $userId) : service_training_mission_streak();
$currentPagePath = '/wbss/public/service_training.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . (string)$_SERVER['QUERY_STRING'] : '');
$badgeState = service_training_user_badges($pdo, $storeId, $userId, $latestQuizResult);
$newBadgeKeys = service_training_newly_earned_badge_keys((array)($badgeState['earned'] ?? []));

if ($isQuestionMode && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  service_training_start_run($questionsPool, $recommendedCategories, 8);
}

$activeRun = service_training_get_run();
$selectedQuestionIds = is_array($activeRun['question_ids'] ?? null) ? array_values(array_filter(array_map('strval', $activeRun['question_ids']), static fn(string $id): bool => $id !== '')) : [];
$questions = service_training_questions_by_ids($questionsPool, $selectedQuestionIds);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_verify((string)($_POST['csrf_token'] ?? ''));

  if ((string)($_POST['action'] ?? '') === 'mission_status') {
    $missionId = trim((string)($_POST['mission_id'] ?? ''));
    $missionStatus = trim((string)($_POST['mission_status'] ?? ''));
    if ($missionId !== '' && $todayMissionId !== '' && $missionId === $todayMissionId) {
      if ($missionLogsReady) {
        service_training_save_mission_log(
          $pdo,
          $storeId,
          $userId,
          $missionId,
          (string)($todayMission['title'] ?? ''),
          (string)($todayMission['category'] ?? ''),
          (string)($todayMission['skill_tag'] ?? ''),
          $missionStatus
        );
      } else {
        service_training_save_mission_status($missionId, $missionStatus);
      }
    }
    $returnTo = trim((string)($_POST['return_to'] ?? '/wbss/public/service_training.php'));
    if ($returnTo === '' || str_starts_with($returnTo, 'http')) {
      $returnTo = '/wbss/public/service_training.php';
    }
    header('Location: ' . $returnTo);
    exit;
  }

  if ((string)($_POST['action'] ?? '') === 'restart') {
    service_training_clear_run();
    service_training_clear_result();
    header('Location: /wbss/public/service_training.php?start=1');
    exit;
  }

  $activeRun = service_training_get_run();
  $selectedQuestionIds = is_array($activeRun['question_ids'] ?? null) ? array_values(array_filter(array_map('strval', $activeRun['question_ids']), static fn(string $id): bool => $id !== '')) : [];
  if (!$selectedQuestionIds) {
    $activeRun = service_training_start_run($questionsPool, $recommendedCategories, 8);
    $selectedQuestionIds = array_values(array_filter(array_map('strval', (array)($activeRun['question_ids'] ?? [])), static fn(string $id): bool => $id !== ''));
  }
  $questions = service_training_questions_by_ids($questionsPool, $selectedQuestionIds);

  $currentAnswers = array_filter((array)($_POST['answers'] ?? []), static fn($value): bool => trim((string)$value) !== '');
  $questionId = trim((string)($_POST['question_id'] ?? ''));
  $choiceKey = strtoupper(trim((string)($_POST['choice'] ?? '')));
  $questionMap = service_training_question_map($questionsPool);

  if ($questionId === '' || !isset($questionMap[$questionId]) || !in_array($questionId, $selectedQuestionIds, true)) {
    $error = 'トレーニングデータの読み込みに失敗しました。最初からやり直してください。';
    $isQuestionMode = true;
  } else {
    $choiceMap = [];
    foreach ((array)($questionMap[$questionId]['choices'] ?? []) as $choice) {
      $choiceMap[(string)($choice['key'] ?? '')] = $choice;
    }
    if (!isset($choiceMap[$choiceKey])) {
      $error = '選択肢を選んでください。';
      $isQuestionMode = true;
    } else {
      $currentAnswers[$questionId] = $choiceKey;
      ksort($currentAnswers);
      if (count($currentAnswers) >= count($questions)) {
        $displayResult = service_training_calculate_result($questionsPool, $currentAnswers, $growthTheme, $categoryMeta);
        service_training_store_result($displayResult);
        if ($trainingHistoryReady) {
          service_training_save_history(
            $pdo,
            $storeId,
            $userId,
            $typeKey,
            $typeName,
            $displayResult,
            $selectedQuestionIds
          );
        } else {
          service_training_push_history($displayResult);
        }
        service_training_clear_run();
        header('Location: /wbss/public/service_training.php?done=1');
        exit;
      }
      $isQuestionMode = true;
    }
  }
}

if ((string)($_GET['done'] ?? '') === '1') {
  $displayResult = service_training_get_result();
}

if (!is_array($displayResult) && !$isQuestionMode) {
  $displayResult = service_training_get_result();
}

$nextIndex = count($currentAnswers);
$currentQuestion = $isQuestionMode ? ($questions[$nextIndex] ?? null) : null;
$progressCurrent = min($nextIndex + 1, count($questions));
$questionRankMeta = service_training_rank_meta('ok');

render_page_start('接客マナートレーニング');
render_header('接客マナートレーニング', [
  'back_href' => '/wbss/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
  'show_store' => false,
  'show_user' => false,
]);
?>
<div class="page trainingPage<?= $currentQuestion !== null ? ' is-question-mode' : '' ?>">
  <?php if ($error !== ''): ?>
    <div class="card trainingNotice trainingNotice--error"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($currentQuestion !== null): ?>
    <?php
      $progressPercent = (int)floor(($progressCurrent / max(1, count($questions))) * 100);
      $currentCategoryKey = (string)($currentQuestion['category'] ?? 'basic_manners');
      $currentCategoryLabel = (string)($categoryMeta[$currentCategoryKey]['label'] ?? ($currentQuestion['category_label'] ?? '育成テーマ'));
    ?>
    <section class="card trainingProgressCard">
      <div class="trainingProgressCard__top">
        <div>
          <div class="trainingEyebrow">Q<?= $progressCurrent ?> / <?= count($questions) ?></div>
          <div class="trainingQuestionMeta"><?= h($currentCategoryLabel) ?></div>
        </div>
        <div class="trainingCount"><?= count($currentAnswers) ?>/<?= count($questions) ?> 回答済み</div>
      </div>
      <div class="trainingProgress" aria-hidden="true"><span style="width:<?= $progressPercent ?>%"></span></div>
      <div class="trainingProgressMeta"><?= $progressPercent ?>% 完了</div>
    </section>

    <section class="card trainingQuestionCard">
      <div class="trainingQuestionLabel">場面</div>
      <div class="trainingScene"><?= h((string)($currentQuestion['scene'] ?? '')) ?></div>
      <div class="trainingQuestionLabel trainingQuestionLabel--prompt">質問</div>
      <div class="trainingQuestionBody"><?= h((string)($currentQuestion['question'] ?? 'この場面で、より良い接客はどれ？')) ?></div>

      <form method="post" class="trainingChoices">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="question_id" value="<?= h((string)$currentQuestion['id']) ?>">
        <input type="hidden" name="choice" value="" class="trainingChoiceValue">
        <?php foreach ($currentAnswers as $answeredQuestionId => $answeredChoice): ?>
          <input type="hidden" name="answers[<?= h((string)$answeredQuestionId) ?>]" value="<?= h((string)$answeredChoice) ?>">
        <?php endforeach; ?>

        <?php foreach ((array)($currentQuestion['choices'] ?? []) as $choice): ?>
          <?php $rankMeta = service_training_rank_meta((string)($choice['rank'] ?? 'ok')); ?>
          <button
            class="trainingChoice quiz-option"
            type="submit"
            name="choice"
            value="<?= h((string)$choice['key']) ?>"
            data-rank="<?= h((string)($choice['rank'] ?? 'ok')) ?>"
            data-rank-label="<?= h($rankMeta['label']) ?>"
            data-feedback="<?= h((string)($choice['feedback'] ?? '')) ?>"
          >
            <span class="trainingChoice__key"><?= h((string)$choice['key']) ?></span>
            <span class="trainingChoice__text"><?= h((string)$choice['text']) ?></span>
          </button>
        <?php endforeach; ?>
      </form>
      <div id="training-feedback" class="trainingFeedback hidden" aria-live="polite">
        <div class="trainingFeedback__badge <?= h($questionRankMeta['class']) ?>"></div>
        <div class="trainingFeedback__body"></div>
      </div>
    </section>

    <form method="post" class="trainingRestart">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="restart">
      <button type="submit" class="btn">最初からやり直す</button>
    </form>

  <?php elseif (is_array($displayResult)): ?>
    <?php
      $bestCategoryKey = (string)($displayResult['best_category'] ?? '');
      $weakCategoryKey = (string)($displayResult['weak_category'] ?? '');
      $recentWeakTags = $trainingHistoryReady
        ? service_training_fetch_recent_weak_tags($pdo, $storeId, $userId, 10, 3)
        : service_training_recent_weak_tags(3);
    ?>
    <div class="trainingResultPage">
      <section class="card trainingThemeCard">
        <div class="trainingEyebrow">WBSS 接客マナートレーニング</div>
        <div class="trainingThemeCard__grid">
          <div>
            <h1 class="trainingTitle">今日の振り返り</h1>
            <p class="trainingLead"><?= h((string)($displayResult['summary'] ?? '小さな所作の積み重ねが、接客全体の印象を整えていきます。')) ?></p>
          </div>
          <div class="trainingTypeChip">
            <div class="trainingTypeChip__label">連動タイプ</div>
            <div class="trainingTypeChip__value"><?= h($typeName) ?></div>
          </div>
        </div>
      </section>
      <?php if ($todayMission): ?>
        <?php service_training_render_mission_card($todayMission, $typeName, $growthTheme, $missionStreak, $todayMissionStatus, $currentPagePath); ?>
      <?php endif; ?>

      <section class="trainingResultGrid">
        <div class="card trainingResultCard trainingResultCard--strong">
          <h2>今回強かったポイント</h2>
          <ul class="trainingResultList">
            <?php foreach ((array)($displayResult['strong_points'] ?? []) as $item): ?>
              <li><?= h((string)$item) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if ($bestCategoryKey !== ''): ?>
            <div class="trainingResultMeta">特に安定していたテーマ: <?= h((string)($categoryMeta[$bestCategoryKey]['label'] ?? $bestCategoryKey)) ?></div>
          <?php endif; ?>
        </div>

        <div class="card trainingResultCard">
          <h2>伸ばしたいポイント</h2>
          <ul class="trainingResultList">
            <?php foreach ((array)($displayResult['stretch_points'] ?? []) as $item): ?>
              <li><?= h((string)$item) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if ($weakCategoryKey !== ''): ?>
            <div class="trainingResultMeta">次に意識したいテーマ: <?= h((string)($categoryMeta[$weakCategoryKey]['label'] ?? $weakCategoryKey)) ?></div>
          <?php endif; ?>
        </div>

        <div class="card trainingResultCard">
          <h2>今日の育成テーマ</h2>
          <div class="trainingTags">
            <?php foreach ((array)($displayResult['focus_skills'] ?? []) as $skill): ?>
              <span><?= h((string)$skill) ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card trainingResultCard">
          <h2>最近よく弱く出る所作</h2>
          <?php if ($recentWeakTags): ?>
            <div class="trainingTags trainingTags--weak">
              <?php foreach ($recentWeakTags as $tag => $count): ?>
                <span><?= h((string)$tag) ?> × <?= (int)$count ?></span>
              <?php endforeach; ?>
            </div>
            <div class="trainingResultMeta">直近のトレーニングで繰り返し出やすいテーマです。ひとつだけ意識すると伸びやすいです。</div>
          <?php else: ?>
            <div class="trainingResultMeta">トレーニングを重ねると、ここに最近の伸びしろがたまっていきます。</div>
          <?php endif; ?>
        </div>

        <div class="card trainingResultCard trainingResultCard--tip">
          <h2>今日の一言</h2>
          <p class="trainingTip"><?= nl2br(h((string)($displayResult['today_tip'] ?? '今日は一つだけ丁寧さを足す意識で十分です。'))) ?></p>
        </div>

        <div class="card trainingResultCard trainingResultCard--badges">
          <div class="trainingResultCard__head">
            <h2>バッジ</h2>
            <div class="trainingResultCard__headActions">
              <button type="button" class="trainingSoundToggle" data-badge-sound-toggle aria-pressed="false">効果音 OFF</button>
              <a href="/wbss/public/service_badges.php" class="trainingResultLink">図鑑を見る</a>
            </div>
          </div>
          <?php if (!empty($badgeState['earned'])): ?>
            <div class="trainingBadgeList">
              <?php foreach ((array)$badgeState['earned'] as $badge): ?>
                <?php $isNewBadge = in_array((string)($badge['key'] ?? ''), $newBadgeKeys, true); ?>
                <span class="trainingBadgeTag <?= $isNewBadge ? 'is-new' : 'is-earned' ?>"><?= h((string)($badge['name'] ?? '')) ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="trainingResultMeta">まだ獲得したバッジはありません。</div>
          <?php endif; ?>

          <?php if (!empty($badgeState['locked'])): ?>
            <div class="trainingBadgeLockedList">
              <?php foreach (array_slice((array)$badgeState['locked'], 0, 4) as $badge): ?>
                <div class="trainingBadgeLockedItem">
                  <span><?= h((string)($badge['name'] ?? '')) ?></span>
                  <small><?= h((string)($badge['progress_text'] ?? '')) ?></small>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <div class="trainingActions">
        <a href="/wbss/public/service_training.php?start=1" class="btn btn-secondary">もう一度トレーニングする</a>
        <a href="/wbss/public/dashboard_cast.php" class="btn btn-primary">ダッシュボードへ戻る</a>
      </div>
    </div>
    <?php if (!empty($newBadgeKeys)): ?>
      <?php
        $newBadgeNames = [];
        foreach ((array)($badgeState['earned'] ?? []) as $badge) {
          $badgeKey = (string)($badge['key'] ?? '');
          if (in_array($badgeKey, $newBadgeKeys, true)) {
            $newBadgeNames[] = (string)($badge['name'] ?? '');
          }
        }
      ?>
      <div
        class="badgeToast"
        id="badge-toast"
        aria-live="polite"
        data-badge-names="<?= h(json_encode(array_values($newBadgeNames), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') ?>"
      >
        <span class="badgeToast__spark badgeToast__spark--one" aria-hidden="true"></span>
        <span class="badgeToast__spark badgeToast__spark--two" aria-hidden="true"></span>
        <div class="badgeToast__eyebrow">✨ NEW BADGE ✨</div>
        <div class="badgeToast__name"></div>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <section class="card trainingThemeCard">
      <div class="trainingEyebrow">WBSS 接客マナートレーニング</div>
      <div class="trainingThemeCard__grid">
        <div>
          <h1 class="trainingTitle">気づいたら身につく、接客の土台づくり</h1>
          <p class="trainingLead"><?= h((string)($growthTheme['growth_message'] ?? '基本所作から空気読みまで、その子に合った育成テーマを少しずつ整えます。')) ?></p>
          <div class="trainingTags">
            <?php foreach ((array)($growthTheme['focus_skills'] ?? []) as $skill): ?>
              <span><?= h((string)$skill) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="trainingTypeChip">
          <div class="trainingTypeChip__label">連動タイプ</div>
          <div class="trainingTypeChip__value"><?= h($typeName) ?></div>
        </div>
      </div>
      <?php if ($todayMission): ?>
        <?php service_training_render_mission_card($todayMission, $typeName, $growthTheme, $missionStreak, $todayMissionStatus, $currentPagePath); ?>
      <?php endif; ?>
      <div class="trainingActions trainingActions--intro">
        <a href="/wbss/public/service_training.php?start=1" class="btn btn-primary">トレーニングを始める</a>
        <a href="/wbss/public/service_quiz.php" class="btn">接客タイプ診断を見る</a>
      </div>
    </section>
  <?php endif; ?>
</div>

<style>
.trainingPage.is-question-mode{padding-top:6px}
.trainingThemeCard,.trainingProgressCard,.trainingQuestionCard,.trainingResultCard{
  background:
    radial-gradient(circle at top right, rgba(255,255,255,.78), transparent 30%),
    linear-gradient(180deg, #ffffff, #fbfbfd);
  border-color:#e6e7ee;
  box-shadow:0 18px 40px rgba(26,32,44,.06);
}
.trainingMissionCard{
  margin-top:18px;
  padding:22px;
  background:linear-gradient(180deg, #fff4e8 0%, #fffaf4 100%);
  border-color:#f3d1b0;
  box-shadow:0 18px 40px rgba(251,146,60,.10);
}
.trainingMissionCard--compact{
  padding:16px 16px 14px;
  margin-top:12px;
}
.trainingEyebrow{display:inline-flex;padding:7px 11px;border-radius:999px;border:1px solid color-mix(in srgb, var(--accent) 24%, var(--line));font-size:11px;font-weight:1000;letter-spacing:.08em;color:var(--muted);background:rgba(255,255,255,.56)}
.trainingMissionCard__label{font-size:12px;font-weight:1000;letter-spacing:.08em;color:#9a3412}
.trainingMissionCard__typeLink{margin-top:12px;font-size:14px;line-height:1.7;font-weight:800;color:#9a3412}
.trainingMissionCard__typeLink span{display:block;font-size:12px;font-weight:700;color:#b45309}
.trainingMissionCard__title{margin:10px 0 0;font-size:24px;line-height:1.35;font-weight:1000;color:#7c2d12}
.trainingMissionCard--compact .trainingMissionCard__typeLink{margin-top:8px;font-size:12px;line-height:1.5}
.trainingMissionCard--compact .trainingMissionCard__typeLink span{display:inline;margin-left:6px}
.trainingMissionCard--compact .trainingMissionCard__title{margin-top:8px;font-size:20px;line-height:1.45}
.trainingMissionCard__action{margin:12px 0 0;font-size:15px;line-height:1.85;font-weight:800;color:#7c2d12}
.trainingMissionCard__reason{margin-top:12px;display:grid;gap:4px;font-size:13px;line-height:1.7;color:#9a3412}
.trainingMissionCard__hint{margin-top:12px;font-size:13px;line-height:1.7;font-weight:700;color:#9a3412}
.trainingMissionCard__streak{margin-top:12px;font-size:14px;font-weight:900;color:#b45309}
.trainingMissionCard__compactMeta{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-top:8px;
  color:#b45309;
  font-size:11px;
  font-weight:800;
}
.trainingMissionActions{display:grid;grid-template-columns:repeat(3, minmax(0, 1fr));gap:10px;margin-top:16px}
.trainingMissionAction{
  min-height:46px;padding:0 12px;border-radius:14px;border:1px solid #f0cfb3;background:#fffaf5;
  color:#7c2d12;font:inherit;font-weight:900;cursor:pointer;transition:all .15s ease
}
.trainingMissionCard--compact .trainingMissionActions{margin-top:12px;gap:8px}
.trainingMissionCard--compact .trainingMissionAction{min-height:42px;padding:0 10px;font-size:13px}
.trainingMissionAction.is-current.is-none{background:#f8fafc;border-color:#e5e7eb;color:#6b7280}
.trainingMissionAction:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(251,146,60,.12)}
.trainingMissionAction.is-current.is-done{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
.trainingMissionAction.is-current.is-pending{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.trainingMissionAction.is-current.is-skipped{background:#fff1f2;border-color:#fecdd3;color:#be123c}
.trainingMissionStatus{margin-top:12px;font-size:13px;font-weight:800}
.trainingMissionStatus.is-none{color:#6b7280}
.trainingMissionStatus.is-done{color:#166534}
.trainingMissionStatus.is-pending{color:#1d4ed8}
.trainingMissionStatus.is-skipped{color:#be123c}
.trainingMissionFeedback{
  margin-top:12px;padding:12px 14px;border-radius:14px;background:#111827;color:#fff;
  opacity:0;transform:translateY(4px)
}
.trainingMissionFeedback.show{opacity:1;transform:translateY(0);transition:opacity .2s ease,transform .2s ease}
.trainingMissionFeedback.hidden{display:none}
.trainingMissionFeedback__title{font-size:14px;font-weight:900}
.trainingMissionFeedback__body{margin-top:6px;font-size:13px;line-height:1.7;font-weight:700}
.trainingTitle{margin:12px 0 0;font-size:28px;line-height:1.25;font-weight:1000}
.trainingLead{margin:12px 0 0;color:var(--muted);font-size:14px;line-height:1.85}
.trainingThemeCard__grid{display:grid;grid-template-columns:1fr 240px;gap:16px;align-items:start}
.trainingThemeCard--compact{padding:16px}
.trainingThemeCard__grid--compact{grid-template-columns:1fr 170px;gap:12px}
.trainingTitle--compact{margin-top:8px;font-size:22px}
.trainingLead--compact{margin-top:8px;font-size:12px;line-height:1.6}
.trainingTypeChip{padding:18px;border-radius:20px;background:#f8fafc;border:1px solid #e8edf5}
.trainingTypeChip--compact{padding:14px}
.trainingTypeChip__label{font-size:12px;font-weight:800;color:#6b7280}
.trainingTypeChip__value{margin-top:8px;font-size:20px;font-weight:1000;color:#111827}
.trainingThemeCard--compact .trainingTags{margin-top:10px}
.trainingTags{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.trainingTags span{display:inline-flex;align-items:center;min-height:34px;padding:0 12px;border-radius:999px;background:#f5f7fb;border:1px solid #dde5ef;font-size:12px;font-weight:900;color:#334155}
.trainingTags--weak span{background:#fff4f4;border-color:#f3d0d0;color:#8b3a3a}
.trainingProgressCard{margin-top:12px;padding:14px 16px}
.trainingProgressCard__top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.trainingQuestionMeta,.trainingCount,.trainingProgressMeta{color:var(--muted);font-size:12px;font-weight:800}
.trainingProgress{height:10px;border-radius:999px;background:rgba(255,255,255,.62);margin-top:14px;overflow:hidden}
.trainingProgress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg, #ff7aad, #ffb6d8)}
.trainingQuestionCard{margin-top:12px;padding:16px}
.trainingQuestionLabel{font-size:12px;font-weight:1000;color:var(--muted);letter-spacing:.08em}
.trainingQuestionLabel--prompt{margin-top:14px}
.trainingScene{margin-top:8px;font-size:18px;line-height:1.6;font-weight:900;color:var(--txt)}
.trainingQuestionBody{margin-top:8px;font-size:16px;line-height:1.7;font-weight:800;color:var(--txt)}
.trainingChoices{display:grid;gap:8px;margin-top:14px}
.trainingChoice{
  display:flex;align-items:center;gap:12px;width:100%;padding:14px 14px;border-radius:16px;
  border:1px solid color-mix(in srgb, var(--accent) 18%, var(--line));background:rgba(255,255,255,.78);
  color:var(--txt);font:inherit;text-align:left;cursor:pointer;transition:all .15s ease
}
.trainingChoice:hover,.quiz-option:hover{
  transform:translateY(-1px);
  background:#f9fafb;
  border-color:rgba(255,145,194,.56);
  box-shadow:0 16px 30px rgba(255,165,206,.12);
}
.trainingChoice:active,.quiz-option:active{transform:scale(.97)}
.trainingChoice.is-active,.quiz-option.active{
  animation:trainingChoiceTap .1s ease;
  background:#fef3f2;
  border-color:rgba(251,146,60,.38);
  box-shadow:0 14px 28px rgba(251,146,60,.14);
}
.trainingChoice__key{
  width:36px;height:36px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;
  border-radius:12px;background:linear-gradient(180deg, rgba(255,245,250,.98), rgba(255,231,241,.92));
  border:1px solid rgba(255,182,211,.42);font-size:15px;font-weight:1000
}
.trainingChoice__text{font-size:14px;line-height:1.55;font-weight:800}
.trainingFeedback{
  margin-top:12px;padding:14px 16px;border-radius:16px;background:#111827;color:#fff;
  opacity:0;transform:translateY(4px);position:relative;z-index:20
}
.trainingFeedback.show{opacity:1;transform:translateY(0);transition:opacity .2s ease,transform .2s ease}
.trainingFeedback.hidden{display:none}
.trainingFeedback__badge{
  display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;
  font-size:12px;font-weight:900;background:rgba(255,255,255,.16)
}
.trainingFeedback__badge.is-best{background:#dcfce7;color:#166534}
.trainingFeedback__badge.is-good{background:#dbeafe;color:#1d4ed8}
.trainingFeedback__badge.is-ok{background:#fef3c7;color:#92400e}
.trainingFeedback__badge.is-weak{background:#fee2e2;color:#b91c1c}
.trainingFeedback__body{margin-top:10px;font-size:14px;line-height:1.7;font-weight:700}
.trainingRestart{display:flex;justify-content:center;margin-top:18px}
.trainingNotice{padding:14px 16px;font-weight:800}
.trainingNotice--error{border-color:rgba(239,68,68,.35);background:rgba(255,241,241,.9)}
.trainingResultPage{display:grid;gap:18px}
.trainingResultGrid{display:grid;grid-template-columns:repeat(2, 1fr);gap:18px}
.trainingResultCard{padding:22px}
.trainingResultCard h2{margin:0 0 14px;font-size:22px;font-weight:900}
.trainingResultCard__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.trainingResultCard__headActions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.trainingResultLink{font-size:12px;font-weight:900;color:#b45309;text-decoration:none}
.trainingSoundToggle{
  min-height:32px;padding:0 12px;border-radius:999px;border:1px solid #f0cfb3;background:#fffaf5;
  color:#9a3412;font:inherit;font-size:12px;font-weight:900;cursor:pointer;transition:all .15s ease
}
.trainingSoundToggle:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(251,146,60,.12)}
.trainingSoundToggle.is-on{background:#fff1dc;border-color:#fdba74;color:#7c2d12}
.trainingResultCard--strong{border-color:#dbeafe;background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%)}
.trainingResultCard--badges{border-color:#f5d8a8;background:linear-gradient(180deg, #fffaf0 0%, #ffffff 100%)}
.trainingResultCard--tip{border-color:#f6d0ad;background:linear-gradient(180deg, #fff4e8 0%, #ffffff 100%)}
.trainingResultList{margin:0;padding-left:1.2em}
.trainingResultList li{margin-bottom:12px;line-height:1.8;color:#374151}
.trainingResultMeta{margin-top:14px;color:#6b7280;font-size:13px;font-weight:700}
.trainingTip{margin:0;line-height:2;font-size:16px;font-weight:800;color:#7c2d12}
.trainingBadgeList{display:flex;flex-wrap:wrap;gap:10px}
.trainingBadgeTag{
  display:inline-flex;align-items:center;min-height:34px;padding:0 12px;border-radius:999px;
  font-size:12px;font-weight:1000;border:1px solid #e5d7b0;background:#fff7e6;color:#8a4b12
}
.trainingBadgeTag.is-earned{background:#fff7e6}
.trainingBadgeTag.is-new{background:#ffedd5;border-color:#fdba74;color:#9a3412;animation:badgeFadeIn .45s ease}
.badgeToast{
  position:fixed;
  left:50%;
  top:50%;
  z-index:70;
  width:min(calc(100vw - 32px), 320px);
  padding:18px 18px 16px;
  border-radius:22px;
  border:1px solid rgba(253,186,116,.8);
  background:linear-gradient(180deg, rgba(255,250,240,.98), rgba(255,244,232,.98));
  box-shadow:0 22px 56px rgba(15,23,42,.18);
  text-align:center;
  pointer-events:none;
  opacity:0;
  transform:translate(-50%, calc(-50% + 16px)) scale(.96);
}
.badgeToast.is-show{
  animation:badgeToastIn .24s ease forwards, badgeToastOut .28s ease 1.02s forwards;
}
.badgeToast__eyebrow{font-size:12px;font-weight:1000;letter-spacing:.12em;color:#b45309}
.badgeToast__name{margin-top:10px;font-size:24px;line-height:1.35;font-weight:1000;color:#7c2d12}
.badgeToast__spark{
  position:absolute;
  width:12px;
  height:12px;
  border-radius:999px;
  background:radial-gradient(circle, rgba(255,255,255,.95) 0%, rgba(255,204,128,.9) 45%, rgba(255,204,128,0) 72%);
  opacity:.9;
}
.badgeToast__spark--one{top:14px;left:18px;animation:badgeSpark 1s ease-in-out infinite alternate}
.badgeToast__spark--two{right:20px;bottom:18px;animation:badgeSpark 1.2s ease-in-out .1s infinite alternate}
.trainingBadgeLockedList{display:grid;gap:10px;margin-top:14px}
.trainingBadgeLockedItem{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:12px 13px;border-radius:14px;background:#f8fafc;border:1px solid #e5e7eb;
  font-size:13px;font-weight:800;color:#374151
}
.trainingBadgeLockedItem small{font-size:12px;color:#6b7280;font-weight:800}
.trainingActions{display:flex;gap:14px;flex-wrap:wrap;margin-top:18px}
.trainingActions--intro{margin-top:20px}
body[data-theme="dark"] .trainingThemeCard,
body[data-theme="dark"] .trainingProgressCard,
body[data-theme="dark"] .trainingQuestionCard,
body[data-theme="dark"] .trainingResultCard,
body[data-theme="dark"] .trainingMissionCard{
  background:
    radial-gradient(circle at top right, rgba(255,146,194,.2), transparent 38%),
    radial-gradient(circle at bottom left, rgba(164,140,255,.16), transparent 34%),
    linear-gradient(180deg, rgba(38,43,61,.96), rgba(44,50,71,.94));
}
body[data-theme="dark"] .trainingTypeChip,
body[data-theme="dark"] .trainingTags span{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
}
body[data-theme="dark"] .trainingBadgeLockedItem,
body[data-theme="dark"] .trainingBadgeTag{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
}
body[data-theme="dark"] .trainingSoundToggle{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
  color:#fff8fc;
}
body[data-theme="dark"] .trainingSoundToggle.is-on{
  background:rgba(251,191,36,.18);
  border-color:rgba(251,191,36,.34);
}
body[data-theme="dark"] .trainingTags--weak span{
  background:rgba(248,113,113,.14);
  border-color:rgba(248,113,113,.24);
}
body[data-theme="dark"] .trainingLead,
body[data-theme="dark"] .trainingMissionCard__label,
body[data-theme="dark"] .trainingMissionCard__typeLink,
body[data-theme="dark"] .trainingMissionCard__typeLink span,
body[data-theme="dark"] .trainingMissionCard__reason,
body[data-theme="dark"] .trainingMissionCard__hint,
body[data-theme="dark"] .trainingQuestionLabel,
body[data-theme="dark"] .trainingQuestionMeta,
body[data-theme="dark"] .trainingCount,
body[data-theme="dark"] .trainingProgressMeta,
body[data-theme="dark"] .trainingResultList li,
body[data-theme="dark"] .trainingResultMeta,
body[data-theme="dark"] .trainingBadgeLockedItem,
body[data-theme="dark"] .trainingBadgeLockedItem small,
body[data-theme="dark"] .trainingResultLink{
  color:rgba(230,223,240,.82);
}
body[data-theme="dark"] .trainingTitle,
body[data-theme="dark"] .trainingMissionCard__title,
body[data-theme="dark"] .trainingMissionCard__action,
body[data-theme="dark"] .trainingTypeChip__value,
body[data-theme="dark"] .trainingScene,
body[data-theme="dark"] .trainingQuestionBody,
body[data-theme="dark"] .trainingResultCard h2,
body[data-theme="dark"] .trainingTip{
  color:#fff8fc;
}
body[data-theme="dark"] .trainingChoice__key{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.14)}
body[data-theme="dark"] .trainingChoice.is-active,
body[data-theme="dark"] .quiz-option.active{background:rgba(251,146,60,.18);border-color:rgba(251,146,60,.34)}
body[data-theme="dark"] .trainingFeedback{background:#f8fafc;color:#111827}
body[data-theme="dark"] .trainingMissionAction{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
  color:#fff8fc;
}
body[data-theme="dark"] .trainingMissionAction.is-current.is-none{color:rgba(230,223,240,.72)}
body[data-theme="dark"] .trainingMissionFeedback{background:#f8fafc;color:#111827}
body[data-theme="dark"] .badgeToast{
  background:
    radial-gradient(circle at top right, rgba(255,191,105,.22), transparent 36%),
    linear-gradient(180deg, rgba(38,43,61,.98), rgba(44,50,71,.96));
  border-color:rgba(251,191,36,.34);
}
body[data-theme="dark"] .badgeToast__eyebrow{color:#fbbf24}
body[data-theme="dark"] .badgeToast__name{color:#fff8fc}
@media (max-width: 960px){
  .trainingThemeCard__grid,
  .trainingResultGrid{grid-template-columns:1fr}
}
@media (max-width: 640px){
  .app-header__inner{
    grid-template-columns:minmax(0, 1fr) auto !important;
    grid-template-areas:"start end" !important;
    gap:8px 10px !important;
    align-items:center !important;
    padding:8px 12px !important;
  }
  .app-header__main{display:none !important}
  .app-header__start{grid-area:start}
  .app-header__end{grid-area:end}
  .app-back{
    min-height:38px !important;
    width:auto !important;
    padding:8px 12px !important;
    border-radius:12px !important;
    font-size:13px !important;
  }
  .app-back__icon{font-size:13px !important}
  .userMenu__btn{
    width:auto !important;
    min-height:38px !important;
    padding:8px 10px !important;
    gap:8px !important;
    border-radius:12px !important;
  }
  .userMenu__avatar{width:22px !important;height:22px !important;font-size:11px !important}
  .userMenu__name{font-size:12px !important}
  .userMenu__role{display:none !important}
  .trainingTitle{font-size:24px}
  .trainingThemeCard--compact{padding:14px}
  .trainingThemeCard__grid--compact{grid-template-columns:1fr}
  .trainingTypeChip--compact{padding:12px}
  .trainingTypeChip__value{font-size:18px}
  .trainingScene{font-size:16px}
  .trainingQuestionBody{font-size:15px}
  .trainingTags span{min-height:30px;padding:0 10px;font-size:11px}
  .trainingActions{flex-direction:column}
  .trainingActions .btn{width:100%}
  .trainingMissionActions{grid-template-columns:1fr}
  .trainingMissionCard--compact .trainingMissionActions{grid-template-columns:repeat(3, minmax(0, 1fr))}
  .trainingMissionCard--compact .trainingMissionAction{min-height:40px;font-size:12px}
  .trainingMissionCard__compactMeta{font-size:10px}
  .trainingProgressCard{padding:12px 14px}
  .trainingQuestionCard{padding:14px}
  .trainingChoice{padding:13px 12px}
  .trainingChoice__key{width:34px;height:34px}
  .trainingChoice__text{font-size:14px}
  .trainingRestart{margin-top:14px}
  .trainingRestart .btn{min-height:42px;padding:0 16px;font-size:13px}
  .trainingResultCard__head{align-items:flex-start}
  .trainingResultCard__headActions{justify-content:flex-start}
  .trainingFeedback{
    position:fixed;
    left:50%;
    top:50%;
    width:min(calc(100vw - 32px), 360px);
    margin:0;
    transform:translate(-50%, calc(-50% + 8px));
    box-shadow:0 20px 48px rgba(15,23,42,.26);
  }
  .trainingFeedback.show{
    transform:translate(-50%, -50%);
  }
  .badgeToast{
    top:48%;
    padding:16px 16px 14px;
  }
  .badgeToast__name{font-size:21px}
}
@keyframes trainingChoiceTap{
  0%{transform:scale(.98)}
  100%{transform:scale(1)}
}
@keyframes badgeFadeIn{
  0%{opacity:0;transform:translateY(6px)}
  100%{opacity:1;transform:translateY(0)}
}
@keyframes badgeToastIn{
  0%{opacity:0;transform:translate(-50%, calc(-50% + 16px)) scale(.96)}
  100%{opacity:1;transform:translate(-50%, -50%) scale(1)}
}
@keyframes badgeToastOut{
  0%{opacity:1;transform:translate(-50%, -50%) scale(1)}
  100%{opacity:0;transform:translate(-50%, calc(-50% - 8px)) scale(1.02)}
}
@keyframes badgeSpark{
  0%{transform:scale(.85);opacity:.4}
  100%{transform:scale(1.15);opacity:1}
}
</style>
<script>
(() => {
  const feedbackEl = document.getElementById('training-feedback');
  const choiceButtons = document.querySelectorAll('.trainingChoice');
  if (!feedbackEl || !choiceButtons.length) {
    return;
  }

  const badgeEl = feedbackEl.querySelector('.trainingFeedback__badge');
  const bodyEl = feedbackEl.querySelector('.trainingFeedback__body');

  choiceButtons.forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();

      const form = button.closest('form');
      const choiceInput = form ? form.querySelector('.trainingChoiceValue') : null;
      if (!form || !choiceInput || !badgeEl || !bodyEl) {
        return;
      }

      choiceButtons.forEach((item) => {
        item.disabled = true;
        item.classList.remove('is-active', 'active');
      });

      button.classList.add('is-active', 'active');
      choiceInput.value = button.value;

      badgeEl.className = 'trainingFeedback__badge ' + (button.dataset.rank ? 'is-' + button.dataset.rank : 'is-ok');
      badgeEl.textContent = button.dataset.rankLabel || 'フィードバック';
      bodyEl.textContent = button.dataset.feedback || '良い視点です。次の問題へ進みます。';

      feedbackEl.classList.remove('hidden');
      feedbackEl.classList.add('show');

      window.setTimeout(() => {
        form.submit();
      }, 1500);
    });
  });
})();

(() => {
  const missionFeedback = document.getElementById('mission-feedback');
  const missionButtons = document.querySelectorAll('.trainingMissionAction');
  if (!missionFeedback || !missionButtons.length) {
    return;
  }

  const titleEl = missionFeedback.querySelector('.trainingMissionFeedback__title');
  const bodyEl = missionFeedback.querySelector('.trainingMissionFeedback__body');

  missionButtons.forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const form = button.closest('form');
      if (!form || !titleEl || !bodyEl) {
        return;
      }

      titleEl.textContent = button.dataset.feedbackTitle || 'いいね 👍';
      bodyEl.textContent = button.dataset.feedbackBody || '今日は1回できれば十分です。';
      missionFeedback.classList.remove('hidden');
      missionFeedback.classList.add('show');

      window.setTimeout(() => {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit(button);
          return;
        }
        form.submit();
      }, 700);
    });
  });
})();

(() => {
  const toast = document.getElementById('badge-toast');
  const toggle = document.querySelector('[data-badge-sound-toggle]');
  const storageKey = 'wbss_badge_sound_enabled';

  const updateToggle = (enabled) => {
    if (!toggle) return;
    toggle.classList.toggle('is-on', enabled);
    toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    toggle.textContent = enabled ? '効果音 ON' : '効果音 OFF';
  };

  let soundEnabled = false;
  try {
    soundEnabled = window.localStorage.getItem(storageKey) === '1';
  } catch (error) {
    soundEnabled = false;
  }
  updateToggle(soundEnabled);

  if (toggle) {
    toggle.addEventListener('click', () => {
      soundEnabled = !soundEnabled;
      try {
        window.localStorage.setItem(storageKey, soundEnabled ? '1' : '0');
      } catch (error) {
      }
      updateToggle(soundEnabled);
    });
  }

  if (!toast) {
    return;
  }

  let badgeNames = [];
  try {
    badgeNames = JSON.parse(toast.dataset.badgeNames || '[]');
  } catch (error) {
    badgeNames = [];
  }
  if (!Array.isArray(badgeNames) || !badgeNames.length) {
    return;
  }

  const nameEl = toast.querySelector('.badgeToast__name');
  if (!nameEl) {
    return;
  }

  const playSound = () => {
    if (!soundEnabled || typeof window.AudioContext === 'undefined') {
      return;
    }
    try {
      const context = new window.AudioContext();
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = 'triangle';
      oscillator.frequency.setValueAtTime(740, context.currentTime);
      oscillator.frequency.exponentialRampToValueAtTime(980, context.currentTime + 0.12);
      gain.gain.setValueAtTime(0.0001, context.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.045, context.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.26);
      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.start();
      oscillator.stop(context.currentTime + 0.26);
      oscillator.onended = () => context.close().catch(() => {});
    } catch (error) {
    }
  };

  const queue = badgeNames.slice(0, 3);
  const showToast = (index) => {
    if (!queue[index]) {
      return;
    }
    nameEl.textContent = queue[index];
    toast.classList.remove('is-show');
    window.requestAnimationFrame(() => {
      toast.classList.add('is-show');
      playSound();
    });

    window.setTimeout(() => {
      toast.classList.remove('is-show');
      if (queue[index + 1]) {
        window.setTimeout(() => showToast(index + 1), 220);
      }
    }, 1320);
  };

  showToast(0);
})();
</script>
<?php render_page_end(); ?>
