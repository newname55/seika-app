<?php
declare(strict_types=1);

const SERVICE_TRAINING_MISSION_SESSION_KEY = '__service_training_today_mission';
const SERVICE_TRAINING_MISSION_RECENT_KEY = '__service_training_recent_missions';

function service_training_mission_pool(): array {
  $rows = require __DIR__ . '/service_training_missions.php';
  return is_array($rows) ? $rows : [];
}

function service_training_recent_mission_ids(int $limit = 5): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $rows = $_SESSION[SERVICE_TRAINING_MISSION_RECENT_KEY] ?? [];
  if (!is_array($rows)) {
    return [];
  }
  return array_slice(array_values(array_filter(array_map('strval', $rows))), -max(1, $limit));
}

function service_training_push_recent_mission_id(string $missionId): void {
  if ($missionId === '') {
    return;
  }
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $rows = $_SESSION[SERVICE_TRAINING_MISSION_RECENT_KEY] ?? [];
  if (!is_array($rows)) {
    $rows = [];
  }
  $rows[] = $missionId;
  if (count($rows) > 10) {
    $rows = array_slice($rows, -10);
  }
  $_SESSION[SERVICE_TRAINING_MISSION_RECENT_KEY] = array_values($rows);
}

function service_training_generate_mission(
  string $typeKey,
  array $weakSkillTags,
  array $recommendedCategories,
  array $recentMissionIds = []
): array {
  $missions = service_training_mission_pool();
  if (!$missions) {
    return [];
  }

  $scored = [];
  foreach ($missions as $mission) {
    if (!is_array($mission)) {
      continue;
    }

    $score = 0;
    $missionId = (string)($mission['mission_id'] ?? '');
    $category = (string)($mission['category'] ?? '');
    $skillTag = (string)($mission['skill_tag'] ?? '');
    $difficulty = (string)($mission['difficulty'] ?? 'medium');
    $targetTypes = array_values(array_map('strval', (array)($mission['target_types'] ?? [])));

    if (in_array($typeKey, $targetTypes, true)) {
      $score += 32;
    }
    if (in_array($category, $recommendedCategories, true)) {
      $score += 24;
    }
    if (isset($weakSkillTags[$skillTag])) {
      $score += 14 + ((int)$weakSkillTags[$skillTag] * 6);
    }
    if ($difficulty === 'low') {
      $score += 12;
    } elseif ($difficulty === 'medium') {
      $score += 6;
    }
    if (in_array($missionId, $recentMissionIds, true)) {
      $score -= 28;
    }

    $score += random_int(0, 4);
    $scored[] = ['score' => $score, 'mission' => $mission];
  }

  usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
  $top = array_slice($scored, 0, 3);
  if (!$top) {
    return [];
  }
  $pick = $top[array_rand($top)]['mission'];
  return is_array($pick) ? $pick : [];
}

function service_training_resolve_today_mission(
  string $typeKey,
  array $weakSkillTags,
  array $growthTheme,
  ?string $dateKey = null
): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  $dateKey = $dateKey ?: date('Y-m-d');
  $recommendedCategories = array_values(array_map('strval', (array)($growthTheme['recommended_categories'] ?? [])));
  $weakHash = md5(json_encode($weakSkillTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

  $cached = $_SESSION[SERVICE_TRAINING_MISSION_SESSION_KEY] ?? null;
  if (is_array($cached)
    && (string)($cached['date'] ?? '') === $dateKey
    && (string)($cached['type_key'] ?? '') === $typeKey
    && (string)($cached['weak_hash'] ?? '') === $weakHash
    && is_array($cached['mission'] ?? null)
  ) {
    return $cached['mission'];
  }

  $mission = service_training_generate_mission(
    $typeKey,
    $weakSkillTags,
    $recommendedCategories,
    service_training_recent_mission_ids(5)
  );

  if ($mission) {
    service_training_push_recent_mission_id((string)($mission['mission_id'] ?? ''));
  }

  $_SESSION[SERVICE_TRAINING_MISSION_SESSION_KEY] = [
    'date' => $dateKey,
    'type_key' => $typeKey,
    'weak_hash' => $weakHash,
    'mission' => $mission,
  ];

  return $mission;
}
