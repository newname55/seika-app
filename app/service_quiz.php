<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/service_quiz_questions.php';
require_once __DIR__ . '/store.php';

const SERVICE_QUIZ_SESSION_KEY = '__service_quiz_run';

function service_quiz_results_table_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cast_service_quiz_results'
    ");
    $st->execute();
    $ready = ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    $ready = false;
  }

  return $ready;
}

function service_quiz_current_user_id(): int {
  return function_exists('current_user_id') ? (int)(current_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
}

function service_quiz_resolve_cast_store_id(PDO $pdo, int $userId): int {
  if ($userId <= 0) {
    return 0;
  }

  $sid = (int)($_SESSION['store_id'] ?? 0);
  if ($sid > 0) {
    return $sid;
  }

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
  $storeId = (int)($st->fetchColumn() ?: 0);
  if ($storeId > 0) {
    $_SESSION['store_id'] = $storeId;
    if (function_exists('set_current_store_id')) {
      set_current_store_id($storeId);
    }
  }
  return $storeId;
}

function service_quiz_question_map(): array {
  $map = [];
  foreach (service_quiz_questions() as $question) {
    $map[(int)$question['id']] = $question;
  }
  return $map;
}

function service_quiz_questions_by_ids(array $questionIds): array {
  $map = service_quiz_question_map();
  $rows = [];
  foreach ($questionIds as $questionId) {
    $questionId = (int)$questionId;
    if ($questionId > 0 && isset($map[$questionId])) {
      $rows[] = $map[$questionId];
    }
  }
  return $rows;
}

function service_quiz_choice_map(array $question): array {
  $map = [];
  foreach ((array)($question['choices'] ?? []) as $choice) {
    $map[(string)$choice['key']] = $choice;
  }
  return $map;
}

function service_quiz_normalize_answers(array $rawAnswers): array {
  $questions = service_quiz_questions();
  $normalized = [];

  foreach ($questions as $question) {
    $questionId = (int)$question['id'];
    $value = strtoupper(trim((string)($rawAnswers[$questionId] ?? $rawAnswers[(string)$questionId] ?? '')));
    if ($value === '') {
      continue;
    }

    $choices = service_quiz_choice_map($question);
    if (!isset($choices[$value])) {
      continue;
    }
    $normalized[$questionId] = $value;
  }

  ksort($normalized);
  return $normalized;
}

function service_quiz_question_ids_by_category(): array {
  $grouped = [];
  foreach (service_quiz_questions() as $question) {
    $category = service_quiz_normalize_category_key((string)($question['category'] ?? 'misc'));
    $grouped[$category] ??= [];
    $grouped[$category][] = (int)$question['id'];
  }
  return $grouped;
}

function service_quiz_generate_question_ids(?int $targetCount = null): array {
  $specs = service_quiz_category_specs();
  $grouped = service_quiz_question_ids_by_category();
  $targetCount = $targetCount ?? random_int(12, 16);
  $targetCount = max(12, min(16, $targetCount));

  $selected = [];
  $counts = [];

  foreach ($specs as $category => $spec) {
    $pool = $grouped[$category] ?? [];
    if (!$pool) {
      continue;
    }
    shuffle($pool);
    $take = array_shift($pool);
    if ($take === null) {
      continue;
    }
    $selected[] = (int)$take;
    $counts[$category] = 1;
    $grouped[$category] = $pool;
  }

  $categoryOrder = array_keys($specs);
  shuffle($categoryOrder);
  while (count($selected) < $targetCount) {
    $picked = false;
    foreach ($categoryOrder as $category) {
      $pool = $grouped[$category] ?? [];
      $softMax = (int)($specs[$category]['soft_max'] ?? 2);
      $current = (int)($counts[$category] ?? 0);
      if (!$pool || $current >= $softMax) {
        continue;
      }
      $questionId = array_shift($pool);
      if ($questionId === null) {
        continue;
      }
      $selected[] = (int)$questionId;
      $counts[$category] = $current + 1;
      $grouped[$category] = $pool;
      $picked = true;
      if (count($selected) >= $targetCount) {
        break;
      }
    }
    if ($picked) {
      continue;
    }

    $remaining = [];
    foreach ($grouped as $pool) {
      foreach ($pool as $questionId) {
        $remaining[] = (int)$questionId;
      }
    }
    if (!$remaining) {
      break;
    }
    shuffle($remaining);
    foreach ($remaining as $questionId) {
      if (!in_array($questionId, $selected, true)) {
        $selected[] = $questionId;
        break;
      }
    }
    if (count(array_unique($selected)) !== count($selected)) {
      $selected = array_values(array_unique($selected));
    }
  }

  shuffle($selected);
  return array_values($selected);
}

function service_quiz_category_counts(array $questionIds): array {
  $counts = [];
  $map = service_quiz_question_map();
  foreach ($questionIds as $questionId) {
    $questionId = (int)$questionId;
    if (!isset($map[$questionId])) {
      continue;
    }
    $category = service_quiz_normalize_category_key((string)($map[$questionId]['category'] ?? 'misc'));
    $counts[$category] = (int)($counts[$category] ?? 0) + 1;
  }
  ksort($counts);
  return $counts;
}

function service_quiz_start_run(?int $targetCount = null): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  $questionIds = service_quiz_generate_question_ids($targetCount);
  $run = [
    'token' => bin2hex(random_bytes(16)),
    'question_ids' => $questionIds,
    'question_count' => count($questionIds),
    'category_counts' => service_quiz_category_counts($questionIds),
    'started_at' => date('Y-m-d H:i:s'),
  ];
  $_SESSION[SERVICE_QUIZ_SESSION_KEY] = $run;
  return $run;
}

function service_quiz_get_active_run(): ?array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $run = $_SESSION[SERVICE_QUIZ_SESSION_KEY] ?? null;
  return is_array($run) ? $run : null;
}

function service_quiz_clear_run(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  unset($_SESSION[SERVICE_QUIZ_SESSION_KEY]);
}

function service_quiz_calculate(array $rawAnswers, ?array $questionIds = null): array {
  $answers = service_quiz_normalize_answers($rawAnswers);
  $questions = service_quiz_question_map();
  if (is_array($questionIds)) {
    $allowed = array_fill_keys(array_map('intval', $questionIds), true);
    $answers = array_filter(
      $answers,
      static fn(int $questionId): bool => isset($allowed[$questionId]),
      ARRAY_FILTER_USE_KEY
    );
  } else {
    $questionIds = array_map('intval', array_keys($answers));
  }
  $scores = [
    'talk_axis' => 0,
    'mood_axis' => 0,
    'response_axis' => 0,
    'relation_axis' => 0,
  ];

  $answeredChoices = [];
  foreach ($answers as $questionId => $choiceKey) {
    if (!isset($questions[$questionId])) {
      continue;
    }
    $choice = service_quiz_choice_map($questions[$questionId])[$choiceKey] ?? null;
    if (!is_array($choice)) {
      continue;
    }

    foreach ($scores as $axis => $total) {
      $scores[$axis] += (int)($choice['scores'][$axis] ?? 0);
    }

    $answeredChoices[] = [
      'question_id' => $questionId,
      'choice_key' => $choiceKey,
      'choice_text' => (string)$choice['text'],
      'scores' => $choice['scores'],
    ];
  }

  $resultTypeKey = service_quiz_detect_type($scores);
  $resultTypes = service_quiz_result_types();
  $resultType = $resultTypes[$resultTypeKey] ?? $resultTypes['all_rounder'];

  return [
    'answers' => $answers,
    'answered_choices' => $answeredChoices,
    'scores' => $scores,
    'axis_labels' => service_quiz_axis_labels($scores),
    'result_type_key' => $resultType['key'],
    'result_type' => $resultType,
    'question_ids' => array_values(array_map('intval', $questionIds)),
    'question_count' => count($answers),
    'question_category_counts' => service_quiz_category_counts(array_keys($answers)),
  ];
}

function service_quiz_detect_type(array $scores): string {
  $talk = (int)($scores['talk_axis'] ?? 0);
  $mood = (int)($scores['mood_axis'] ?? 0);
  $response = (int)($scores['response_axis'] ?? 0);
  $relation = (int)($scores['relation_axis'] ?? 0);

  if (
    abs($talk) <= 2 &&
    abs($mood) <= 2 &&
    abs($response) <= 2 &&
    abs($relation) <= 2
  ) {
    return 'all_rounder';
  }

  if ($relation >= 4 && $mood >= 0) {
    return 'sweet_spark';
  }

  if ($talk >= 4 && $mood >= 0 && $response >= 0) {
    return 'flow_leader';
  }

  if ($mood >= 4 && $talk >= 1) {
    return 'energy_booster';
  }

  if ($mood <= -4 && $talk <= 0 && $response <= 0) {
    return 'soft_healer';
  }

  if ($response <= -4 && $talk <= 0) {
    return 'silent_analyzer';
  }

  if ($mood <= -2 && $response <= -2 && $talk <= 2) {
    return 'elegant_calm';
  }

  if ($mood <= -3 && $talk <= -1 && $relation <= 0) {
    return 'calm_empath';
  }

  if ($relation >= 3) {
    return 'sweet_spark';
  }
  if ($talk >= 3 && $mood >= -1) {
    return 'flow_leader';
  }
  if ($mood >= 3) {
    return 'energy_booster';
  }
  if ($response <= -3) {
    return 'silent_analyzer';
  }
  if ($mood <= -3) {
    return 'soft_healer';
  }

  return 'all_rounder';
}

function service_quiz_legacy_type_key(string $typeKey): string {
  static $map = [
    'empathy_comfort' => 'calm_empath',
    'healing_stable' => 'soft_healer',
    'mood_maker' => 'energy_booster',
    'lead_driver' => 'flow_leader',
    'romantic_director' => 'sweet_spark',
    'mature_comfort' => 'elegant_calm',
    'observant_support' => 'silent_analyzer',
    'balanced_flex' => 'all_rounder',
  ];

  return $map[$typeKey] ?? $typeKey;
}

function service_quiz_axis_labels(array $scores): array {
  return [
    'talk_axis' => service_quiz_axis_bucket((int)($scores['talk_axis'] ?? 0), '主導強め', '中間', '受容強め'),
    'mood_axis' => service_quiz_axis_bucket((int)($scores['mood_axis'] ?? 0), '盛り上げ強め', '中間', '安心強め'),
    'response_axis' => service_quiz_axis_bucket((int)($scores['response_axis'] ?? 0), '直感強め', '中間', '観察強め'),
    'relation_axis' => service_quiz_axis_bucket((int)($scores['relation_axis'] ?? 0), '恋愛演出強め', '中間', '信頼蓄積強め'),
  ];
}

function service_quiz_axis_bucket(int $value, string $positive, string $neutral, string $negative): string {
  if ($value >= 3) {
    return $positive;
  }
  if ($value <= -3) {
    return $negative;
  }
  return $neutral;
}

function service_quiz_history_tables_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $st = $pdo->query("
      SELECT TABLE_NAME
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('cast_service_quiz_sessions', 'cast_service_quiz_answers')
    ");
    $names = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $ready = in_array('cast_service_quiz_sessions', $names, true) && in_array('cast_service_quiz_answers', $names, true);
  } catch (Throwable $e) {
    $ready = false;
  }
  return $ready;
}

function service_quiz_save_result(PDO $pdo, int $storeId, int $castId, array $rawAnswers, ?array $questionIds = null, string $quizVersion = 'v0.2'): int {
  $result = service_quiz_calculate($rawAnswers, $questionIds);
  $answersJson = json_encode($result['answers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $scoresJson = json_encode($result['scores'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $resultJson = json_encode([
    'axis_labels' => $result['axis_labels'],
    'result_type' => $result['result_type'],
    'question_ids' => $result['question_ids'],
    'question_category_counts' => $result['question_category_counts'],
    'answered_choices' => $result['answered_choices'],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if (!is_string($answersJson) || !is_string($scoresJson) || !is_string($resultJson)) {
    throw new RuntimeException('診断結果の保存に失敗しました');
  }

  $st = $pdo->prepare("
    INSERT INTO cast_service_quiz_results (
      store_id,
      cast_id,
      quiz_version,
      result_type_key,
      result_type_name,
      talk_axis_score,
      mood_axis_score,
      response_axis_score,
      relation_axis_score,
      answers_json,
      scores_json,
      result_json
    ) VALUES (
      :store_id,
      :cast_id,
      :quiz_version,
      :result_type_key,
      :result_type_name,
      :talk_axis_score,
      :mood_axis_score,
      :response_axis_score,
      :relation_axis_score,
      :answers_json,
      :scores_json,
      :result_json
    )
  ");
  $st->execute([
    ':store_id' => $storeId,
    ':cast_id' => $castId,
    ':quiz_version' => $quizVersion,
    ':result_type_key' => (string)$result['result_type_key'],
    ':result_type_name' => (string)$result['result_type']['name'],
    ':talk_axis_score' => (int)$result['scores']['talk_axis'],
    ':mood_axis_score' => (int)$result['scores']['mood_axis'],
    ':response_axis_score' => (int)$result['scores']['response_axis'],
    ':relation_axis_score' => (int)$result['scores']['relation_axis'],
    ':answers_json' => $answersJson,
    ':scores_json' => $scoresJson,
    ':result_json' => $resultJson,
  ]);

  $resultId = (int)$pdo->lastInsertId();

  if (service_quiz_history_tables_ready($pdo)) {
    service_quiz_save_history($pdo, $resultId, $storeId, $castId, $quizVersion, $result);
  }

  return $resultId;
}

function service_quiz_save_history(PDO $pdo, int $resultId, int $storeId, int $castId, string $quizVersion, array $result): void {
  $questionIdsJson = json_encode((array)($result['question_ids'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $categoryCountsJson = json_encode((array)($result['question_category_counts'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($questionIdsJson) || !is_string($categoryCountsJson)) {
    return;
  }

  $stSession = $pdo->prepare("
    INSERT INTO cast_service_quiz_sessions (
      result_id, store_id, cast_id, quiz_version, question_count, answered_count, question_ids_json, category_counts_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $stSession->execute([
    $resultId,
    $storeId,
    $castId,
    $quizVersion,
    (int)($result['question_count'] ?? 0),
    count((array)($result['answers'] ?? [])),
    $questionIdsJson,
    $categoryCountsJson,
  ]);
  $sessionId = (int)$pdo->lastInsertId();

  $questionMap = service_quiz_question_map();
  $stAnswer = $pdo->prepare("
    INSERT INTO cast_service_quiz_answers (
      session_id, result_id, store_id, cast_id, question_id, question_category, choice_key, answer_scores_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  ");

  foreach ((array)($result['answers'] ?? []) as $questionId => $choiceKey) {
    $questionId = (int)$questionId;
    $choiceKey = (string)$choiceKey;
    if (!isset($questionMap[$questionId])) {
      continue;
    }
    $choice = service_quiz_choice_map($questionMap[$questionId])[$choiceKey] ?? null;
    if (!is_array($choice)) {
      continue;
    }
    $answerScoresJson = json_encode((array)($choice['scores'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($answerScoresJson)) {
      continue;
    }
    $stAnswer->execute([
      $sessionId,
      $resultId,
      $storeId,
      $castId,
      $questionId,
      service_quiz_normalize_category_key((string)($questionMap[$questionId]['category'] ?? 'misc')),
      $choiceKey,
      $answerScoresJson,
    ]);
  }
}

function service_quiz_fetch_cumulative_summary(PDO $pdo, int $storeId, int $castId, int $limit = 20): array {
  $summary = [
    'session_count' => 0,
    'average_scores' => [
      'talk_axis' => 0.0,
      'mood_axis' => 0.0,
      'response_axis' => 0.0,
      'relation_axis' => 0.0,
    ],
    'category_counts' => [],
  ];

  if (!service_quiz_history_tables_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return $summary;
  }

  $stSessions = $pdo->prepare("
    SELECT result_id
    FROM cast_service_quiz_sessions
    WHERE store_id = ?
      AND cast_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT ?
  ");
  $stSessions->bindValue(1, $storeId, PDO::PARAM_INT);
  $stSessions->bindValue(2, $castId, PDO::PARAM_INT);
  $stSessions->bindValue(3, max(1, $limit), PDO::PARAM_INT);
  $stSessions->execute();
  $resultIds = array_values(array_filter(array_map('intval', $stSessions->fetchAll(PDO::FETCH_COLUMN) ?: [])));
  if (!$resultIds) {
    return $summary;
  }

  $summary['session_count'] = count($resultIds);
  $ph = implode(',', array_fill(0, count($resultIds), '?'));
  $stResults = $pdo->prepare("
    SELECT talk_axis_score, mood_axis_score, response_axis_score, relation_axis_score
    FROM cast_service_quiz_results
    WHERE id IN ($ph)
  ");
  $stResults->execute($resultIds);
  $rows = $stResults->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows) {
    foreach ($rows as $row) {
      $summary['average_scores']['talk_axis'] += (int)($row['talk_axis_score'] ?? 0);
      $summary['average_scores']['mood_axis'] += (int)($row['mood_axis_score'] ?? 0);
      $summary['average_scores']['response_axis'] += (int)($row['response_axis_score'] ?? 0);
      $summary['average_scores']['relation_axis'] += (int)($row['relation_axis_score'] ?? 0);
    }
    foreach ($summary['average_scores'] as $axis => $value) {
      $summary['average_scores'][$axis] = round($value / count($rows), 2);
    }
  }

  $stCategories = $pdo->prepare("
    SELECT question_category, COUNT(*) AS c
    FROM cast_service_quiz_answers
    WHERE store_id = ?
      AND cast_id = ?
    GROUP BY question_category
    ORDER BY question_category ASC
  ");
  $stCategories->execute([$storeId, $castId]);
  foreach (($stCategories->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $categoryKey = service_quiz_normalize_category_key((string)($row['question_category'] ?? 'misc'));
    $summary['category_counts'][$categoryKey] = (int)($summary['category_counts'][$categoryKey] ?? 0) + (int)($row['c'] ?? 0);
  }

  return $summary;
}

function service_quiz_fetch_latest_result(PDO $pdo, int $storeId, int $castId): ?array {
  if (!service_quiz_results_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return null;
  }

  $st = $pdo->prepare("
    SELECT *
    FROM cast_service_quiz_results
    WHERE store_id = ?
      AND cast_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT 1
  ");
  $st->execute([$storeId, $castId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? service_quiz_hydrate_result_row($row) : null;
}

function service_quiz_fetch_result_by_id(PDO $pdo, int $resultId, int $storeId, int $castId): ?array {
  if (!service_quiz_results_table_ready($pdo) || $resultId <= 0 || $storeId <= 0 || $castId <= 0) {
    return null;
  }

  $st = $pdo->prepare("
    SELECT *
    FROM cast_service_quiz_results
    WHERE id = ?
      AND store_id = ?
      AND cast_id = ?
    LIMIT 1
  ");
  $st->execute([$resultId, $storeId, $castId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? service_quiz_hydrate_result_row($row) : null;
}

function service_quiz_hydrate_result_row(array $row): array {
  $scores = [
    'talk_axis' => (int)($row['talk_axis_score'] ?? 0),
    'mood_axis' => (int)($row['mood_axis_score'] ?? 0),
    'response_axis' => (int)($row['response_axis_score'] ?? 0),
    'relation_axis' => (int)($row['relation_axis_score'] ?? 0),
  ];

  $answers = json_decode((string)($row['answers_json'] ?? '{}'), true);
  if (!is_array($answers)) {
    $answers = [];
  }

  $resultJson = json_decode((string)($row['result_json'] ?? '{}'), true);
  if (!is_array($resultJson)) {
    $resultJson = [];
  }

  $typeKey = service_quiz_legacy_type_key((string)($row['result_type_key'] ?? ''));
  $types = service_quiz_result_types();
  $type = $types[$typeKey] ?? $types['all_rounder'];

  return [
    'id' => (int)($row['id'] ?? 0),
    'store_id' => (int)($row['store_id'] ?? 0),
    'cast_id' => (int)($row['cast_id'] ?? 0),
    'quiz_version' => (string)($row['quiz_version'] ?? ''),
    'result_type_key' => $type['key'],
    'result_type_name' => (string)($row['result_type_name'] ?? $type['name']),
    'scores' => $scores,
    'axis_labels' => is_array($resultJson['axis_labels'] ?? null) ? $resultJson['axis_labels'] : service_quiz_axis_labels($scores),
    'result_type' => is_array($resultJson['result_type'] ?? null) ? ($resultJson['result_type'] + $type) : $type,
    'answers' => service_quiz_normalize_answers($answers),
    'created_at' => (string)($row['created_at'] ?? ''),
  ];
}
