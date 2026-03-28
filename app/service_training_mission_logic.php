<?php
declare(strict_types=1);

const SERVICE_TRAINING_MISSION_SESSION_KEY = '__service_training_today_mission';
const SERVICE_TRAINING_MISSION_RECENT_KEY = '__service_training_recent_missions';
const SERVICE_TRAINING_MISSION_STATUS_KEY = '__service_training_mission_status_log';

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
  $ids = [];
  foreach (array_slice($rows, -max(1, $limit)) as $row) {
    if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
      $ids[] = (string)$row['id'];
      continue;
    }
    if (is_string($row) && trim($row) !== '') {
      $ids[] = trim($row);
    }
  }
  return $ids;
}

function service_training_push_recent_mission_id(string $missionId, ?string $date = null): void {
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
  $rows[] = [
    'id' => $missionId,
    'date' => $date ?: date('Y-m-d'),
  ];
  if (count($rows) > 10) {
    $rows = array_slice($rows, -10);
  }
  $_SESSION[SERVICE_TRAINING_MISSION_RECENT_KEY] = array_values($rows);
}

function service_training_mission_used_recently(string $missionId, int $recentDays): bool {
  if ($missionId === '' || $recentDays <= 0) {
    return false;
  }
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $rows = $_SESSION[SERVICE_TRAINING_MISSION_RECENT_KEY] ?? [];
  if (!is_array($rows) || !$rows) {
    return false;
  }

  $cutoff = strtotime('-' . max(1, $recentDays) . ' days');
  foreach (array_reverse($rows) as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string)($row['id'] ?? '') !== $missionId) {
      continue;
    }
    $date = strtotime((string)($row['date'] ?? ''));
    if ($date !== false && $date >= $cutoff) {
      return true;
    }
  }
  return false;
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
    $missionId = (string)($mission['id'] ?? $mission['mission_id'] ?? '');
    $category = (string)($mission['category'] ?? '');
    $skillTag = (string)($mission['skill_tag'] ?? '');
    $difficulty = (int)($mission['difficulty'] ?? 2);
    $targetTypes = array_values(array_map('strval', (array)($mission['recommended_for_types'] ?? $mission['target_types'] ?? [])));
    $avoidRecentDays = (int)($mission['avoid_if_recent_days'] ?? 0);

    if (isset($weakSkillTags[$skillTag])) {
      $score += 34 + ((int)$weakSkillTags[$skillTag] * 8);
    }
    if (in_array($typeKey, $targetTypes, true)) {
      $score += 22;
    }
    if (in_array($category, $recommendedCategories, true)) {
      $score += 14;
    }
    if ($difficulty <= 1) {
      $score += 12;
    } elseif ($difficulty === 2) {
      $score += 6;
    }
    if (in_array($missionId, $recentMissionIds, true)) {
      $score -= 28;
    }
    if (service_training_mission_used_recently($missionId, $avoidRecentDays)) {
      $score -= 40;
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
    service_training_push_recent_mission_id((string)($mission['id'] ?? $mission['mission_id'] ?? ''), $dateKey);
  }

  $_SESSION[SERVICE_TRAINING_MISSION_SESSION_KEY] = [
    'date' => $dateKey,
    'type_key' => $typeKey,
    'weak_hash' => $weakHash,
    'mission' => $mission,
  ];

  return $mission;
}

function service_training_mission_reason(array $mission): string {
  $reasons = [
    'conversation_entry' => '最初の空気が、その席の流れを決めるから。',
    'basic_manners' => '基本所作は、会話の前に安心感として伝わるから。',
    'service_behavior' => '動き方ひとつで、丁寧さと余裕が見えるから。',
    'air_reading' => '小さな先回りが、接客の完成度を上げるから。',
    'silence' => '沈黙の扱い方で、落ち着きと接客力が伝わるから。',
    'closing' => '最後の印象が、また会いたい気持ちにつながるから。',
    'nomination' => '次回導線は、自然な一言の積み重ねで生まれるから。',
    'appearance_strategy' => '見た目の整え方が、第一印象の信頼感を支えるから。',
  ];
  $category = (string)($mission['category'] ?? '');
  return $reasons[$category] ?? '小さな行動が、その日の接客の流れを変えるから。';
}

function service_training_mission_status_meta(string $status): array {
  $map = [
    'done' => [
      'label' => 'やった',
      'button' => '✅ やった',
      'feedback_title' => 'いいね 👍',
      'feedback_body' => '少しずつ、今日の接客の土台が伸びています。',
      'class' => 'is-done',
    ],
    'pending' => [
      'label' => 'まだ',
      'button' => '🤔 まだ',
      'feedback_title' => 'その感覚で大丈夫 👍',
      'feedback_body' => '今日はまだチャンスがあります。1回だけで十分です。',
      'class' => 'is-pending',
    ],
    'skipped' => [
      'label' => 'できなかった',
      'button' => '❌ できなかった',
      'feedback_title' => 'OK 👍',
      'feedback_body' => '明日は“1回だけ”意識してみよう。',
      'class' => 'is-skipped',
    ],
  ];
  return $map[$status] ?? $map['pending'];
}

function service_training_save_mission_status(string $missionId, string $status, ?string $dateKey = null): void {
  $allowed = ['done', 'pending', 'skipped'];
  if ($missionId === '' || !in_array($status, $allowed, true)) {
    return;
  }
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $dateKey = $dateKey ?: date('Y-m-d');
  $rows = $_SESSION[SERVICE_TRAINING_MISSION_STATUS_KEY] ?? [];
  if (!is_array($rows)) {
    $rows = [];
  }

  $replaced = false;
  foreach ($rows as $index => $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string)($row['date'] ?? '') === $dateKey) {
      $rows[$index] = [
        'date' => $dateKey,
        'mission_id' => $missionId,
        'status' => $status,
      ];
      $replaced = true;
      break;
    }
  }
  if (!$replaced) {
    $rows[] = [
      'date' => $dateKey,
      'mission_id' => $missionId,
      'status' => $status,
    ];
  }

  usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
  if (count($rows) > 30) {
    $rows = array_slice($rows, -30);
  }
  $_SESSION[SERVICE_TRAINING_MISSION_STATUS_KEY] = array_values($rows);
}

function service_training_get_today_mission_status(string $missionId, ?string $dateKey = null): ?string {
  if ($missionId === '') {
    return null;
  }
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $dateKey = $dateKey ?: date('Y-m-d');
  $rows = $_SESSION[SERVICE_TRAINING_MISSION_STATUS_KEY] ?? [];
  if (!is_array($rows)) {
    return null;
  }
  foreach (array_reverse($rows) as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string)($row['date'] ?? '') !== $dateKey) {
      continue;
    }
    if ((string)($row['mission_id'] ?? '') !== $missionId) {
      continue;
    }
    return (string)($row['status'] ?? '');
  }
  return null;
}

function service_training_mission_streak(): int {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $rows = $_SESSION[SERVICE_TRAINING_MISSION_STATUS_KEY] ?? [];
  if (!is_array($rows) || !$rows) {
    return 0;
  }

  usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
  $dates = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string)($row['status'] ?? '') !== 'done') {
      continue;
    }
    $date = (string)($row['date'] ?? '');
    if ($date !== '') {
      $dates[$date] = true;
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
