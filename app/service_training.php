<?php
declare(strict_types=1);

function service_training_history_tables_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $st = $pdo->query("
      SELECT TABLE_NAME
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('cast_service_training_sessions', 'cast_service_training_answers')
    ");
    $names = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $ready = in_array('cast_service_training_sessions', $names, true) && in_array('cast_service_training_answers', $names, true);
  } catch (Throwable $e) {
    $ready = false;
  }

  return $ready;
}

function service_training_save_history(
  PDO $pdo,
  int $storeId,
  int $castId,
  string $typeKey,
  string $typeName,
  array $result,
  array $questionIds
): void {
  if (!service_training_history_tables_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return;
  }

  $questionIdsJson = json_encode(array_values(array_map('strval', $questionIds)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $strongPointsJson = json_encode(array_values((array)($result['strong_points'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $stretchPointsJson = json_encode(array_values((array)($result['stretch_points'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $weakTagsJson = json_encode(array_values((array)($result['weak_tags'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $resultJson = json_encode([
    'summary' => (string)($result['summary'] ?? ''),
    'today_tip' => (string)($result['today_tip'] ?? ''),
    'focus_skills' => array_values((array)($result['focus_skills'] ?? [])),
    'best_category' => (string)($result['best_category'] ?? ''),
    'weak_category' => (string)($result['weak_category'] ?? ''),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if (!is_string($questionIdsJson) || !is_string($strongPointsJson) || !is_string($stretchPointsJson) || !is_string($weakTagsJson) || !is_string($resultJson)) {
    return;
  }

  $stSession = $pdo->prepare("
    INSERT INTO cast_service_training_sessions (
      store_id, cast_id, result_type_key, result_type_name, answered_count, total_score,
      question_ids_json, strong_points_json, stretch_points_json, weak_tags_json, result_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $stSession->execute([
    $storeId,
    $castId,
    $typeKey,
    $typeName,
    (int)($result['answered_count'] ?? 0),
    (int)($result['total_score'] ?? 0),
    $questionIdsJson,
    $strongPointsJson,
    $stretchPointsJson,
    $weakTagsJson,
    $resultJson,
  ]);
  $sessionId = (int)$pdo->lastInsertId();

  $stAnswer = $pdo->prepare("
    INSERT INTO cast_service_training_answers (
      session_id, store_id, cast_id, question_id, question_category, choice_key, choice_rank, choice_score, feedback_text, skill_tags_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  foreach ((array)($result['answered_rows'] ?? []) as $row) {
    $question = is_array($row['question'] ?? null) ? $row['question'] : [];
    $choice = is_array($row['choice'] ?? null) ? $row['choice'] : [];
    $skillTagsJson = json_encode(array_values((array)($question['skill_tags'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($skillTagsJson)) {
      $skillTagsJson = '[]';
    }
    $stAnswer->execute([
      $sessionId,
      $storeId,
      $castId,
      (string)($question['id'] ?? ''),
      (string)($question['category'] ?? ''),
      (string)($choice['key'] ?? ''),
      (string)($choice['rank'] ?? ''),
      (int)($choice['score'] ?? 0),
      (string)($choice['feedback'] ?? ''),
      $skillTagsJson,
    ]);
  }
}

function service_training_fetch_recent_weak_tags(PDO $pdo, int $storeId, int $castId, int $sessionLimit = 10, int $tagLimit = 3): array {
  if (!service_training_history_tables_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT weak_tags_json
    FROM cast_service_training_sessions
    WHERE store_id = ?
      AND cast_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT ?
  ");
  $st->bindValue(1, $storeId, PDO::PARAM_INT);
  $st->bindValue(2, $castId, PDO::PARAM_INT);
  $st->bindValue(3, max(1, $sessionLimit), PDO::PARAM_INT);
  $st->execute();

  $tagCounts = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $tags = json_decode((string)($row['weak_tags_json'] ?? '[]'), true);
    if (!is_array($tags)) {
      continue;
    }
    foreach ($tags as $tag) {
      $tag = trim((string)$tag);
      if ($tag === '') {
        continue;
      }
      $tagCounts[$tag] = (int)($tagCounts[$tag] ?? 0) + 1;
    }
  }

  arsort($tagCounts);
  return array_slice($tagCounts, 0, max(1, $tagLimit), true);
}

function service_training_mission_logs_table_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cast_service_training_mission_logs'
    ");
    $st->execute();
    $ready = ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    $ready = false;
  }

  return $ready;
}

function service_training_save_mission_log(
  PDO $pdo,
  int $storeId,
  int $castId,
  string $missionId,
  string $title,
  string $category,
  string $skillTag,
  string $status,
  ?string $logDate = null
): void {
  if (!service_training_mission_logs_table_ready($pdo) || $storeId <= 0 || $castId <= 0 || $missionId === '') {
    return;
  }

  $logDate = $logDate ?: date('Y-m-d');
  $allowed = ['done', 'pending', 'skipped'];
  if (!in_array($status, $allowed, true)) {
    return;
  }

  $st = $pdo->prepare("
    INSERT INTO cast_service_training_mission_logs (
      store_id, cast_id, log_date, mission_id, mission_title, mission_category, skill_tag, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      mission_id = VALUES(mission_id),
      mission_title = VALUES(mission_title),
      mission_category = VALUES(mission_category),
      skill_tag = VALUES(skill_tag),
      status = VALUES(status),
      updated_at = CURRENT_TIMESTAMP
  ");
  $st->execute([$storeId, $castId, $logDate, $missionId, $title, $category, $skillTag, $status]);
}

function service_training_get_today_mission_log(PDO $pdo, int $storeId, int $castId, ?string $logDate = null): ?array {
  if (!service_training_mission_logs_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return null;
  }
  $logDate = $logDate ?: date('Y-m-d');
  $st = $pdo->prepare("
    SELECT *
    FROM cast_service_training_mission_logs
    WHERE store_id = ?
      AND cast_id = ?
      AND log_date = ?
    LIMIT 1
  ");
  $st->execute([$storeId, $castId, $logDate]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function service_training_done_streak(PDO $pdo, int $storeId, int $castId): int {
  if (!service_training_mission_logs_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return 0;
  }

  $st = $pdo->prepare("
    SELECT log_date
    FROM cast_service_training_mission_logs
    WHERE store_id = ?
      AND cast_id = ?
      AND status = 'done'
    ORDER BY log_date DESC
    LIMIT 30
  ");
  $st->execute([$storeId, $castId]);
  $dates = [];
  foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $logDate) {
    $key = trim((string)$logDate);
    if ($key !== '') {
      $dates[$key] = true;
    }
  }
  if (!$dates) {
    return 0;
  }

  $streak = 0;
  $cursor = new DateTimeImmutable('today');
  while (true) {
    $key = $cursor->format('Y-m-d');
    if (!isset($dates[$key])) {
      break;
    }
    $streak++;
    $cursor = $cursor->modify('-1 day');
  }
  return $streak;
}

function service_training_mission_category_done_counts(PDO $pdo, int $storeId, int $castId): array {
  if (!service_training_mission_logs_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return [];
  }
  $st = $pdo->prepare("
    SELECT mission_category, COUNT(*) AS c
    FROM cast_service_training_mission_logs
    WHERE store_id = ?
      AND cast_id = ?
      AND status = 'done'
    GROUP BY mission_category
  ");
  $st->execute([$storeId, $castId]);
  $counts = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $counts[(string)($row['mission_category'] ?? '')] = (int)($row['c'] ?? 0);
  }
  return $counts;
}
