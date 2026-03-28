<?php
declare(strict_types=1);

const SERVICE_TRAINING_BADGE_SEEN_KEY = '__service_training_badge_seen';

function service_training_badge_definitions(): array {
  $rows = require __DIR__ . '/service_training_badges.php';
  return is_array($rows) ? $rows : [];
}

function service_training_badge_rarity_label(int $rarity): string {
  $map = [
    1 => 'Common',
    2 => 'Rare',
    3 => 'Epic',
    4 => 'Legend',
  ];
  return $map[$rarity] ?? 'Badge';
}

function service_training_badge_rarity_stars(int $rarity): string {
  $rarity = max(1, min(4, $rarity));
  return str_repeat('★', $rarity);
}

function service_training_badge_category_label(string $category): string {
  $map = [
    'basic_manners' => '基本マナー',
    'conversation_entry' => '会話の入り',
    'air_reading' => '空気読み',
    'silence' => '沈黙対応',
    'closing' => '締め',
    'service_behavior' => '接客所作',
    'nomination' => '指名導線',
    'appearance_strategy' => '見た目戦略',
  ];
  return $map[$category] ?? $category;
}

function service_training_badge_axis_label(string $axis, string $direction): string {
  $map = [
    'talk_axis' => ['positive' => '主導寄り', 'negative' => '受容寄り'],
    'mood_axis' => ['positive' => '盛り上げ寄り', 'negative' => '安心寄り'],
    'response_axis' => ['positive' => '直感寄り', 'negative' => '観察寄り'],
    'relation_axis' => ['positive' => '恋愛演出寄り', 'negative' => '信頼寄り'],
  ];
  return $map[$axis][$direction] ?? $axis;
}

function service_training_badge_condition_text(array $definition): string {
  $condition = (array)($definition['condition'] ?? []);
  $type = (string)($condition['type'] ?? '');
  $threshold = (int)($condition['threshold'] ?? 0);

  if ($type === 'category_done') {
    $category = (string)($condition['category'] ?? '');
    return service_training_badge_category_label($category) . 'のミッションを' . $threshold . '回達成';
  }

  if ($type === 'mission_total_done') {
    return 'ミッションを合計' . $threshold . '回達成';
  }

  if ($type === 'streak_days') {
    return $threshold . '日連続でミッション達成';
  }

  if ($type === 'quiz_score') {
    $axis = (string)($condition['axis'] ?? '');
    $direction = (string)($condition['direction'] ?? 'positive');
    return service_training_badge_axis_label($axis, $direction) . 'が' . $threshold . '以上';
  }

  return '';
}

function service_training_badge_progress(array $definition, array $stats): array {
  $condition = (array)($definition['condition'] ?? []);
  $type = (string)($condition['type'] ?? '');
  $threshold = max(1, (int)($condition['threshold'] ?? 1));
  $current = 0;
  $unit = '回';

  if ($type === 'category_done') {
    $category = (string)($condition['category'] ?? '');
    $current = (int)($stats['category_done_counts'][$category] ?? 0);
  } elseif ($type === 'mission_total_done') {
    $current = (int)($stats['mission_total_done'] ?? 0);
  } elseif ($type === 'streak_days') {
    $current = (int)($stats['streak_days'] ?? 0);
    $unit = '日';
  } elseif ($type === 'quiz_score') {
    $axis = (string)($condition['axis'] ?? '');
    $direction = (string)($condition['direction'] ?? 'positive');
    $raw = (int)($stats['quiz_scores'][$axis] ?? 0);
    $current = $direction === 'negative' ? abs(min(0, $raw)) : max(0, $raw);
    $unit = '';
  }

  $clamped = min($current, $threshold);
  $remaining = max(0, $threshold - $current);

  return [
    'current' => $current,
    'max' => $threshold,
    'unit' => $unit,
    'ratio' => max(0, min(1, $clamped / max(1, $threshold))),
    'remaining' => $remaining,
    'text' => $current . '/' . $threshold . $unit,
    'hint' => $remaining > 0 ? 'あと' . $remaining . $unit : '達成済み',
  ];
}

function service_training_badge_progress_text(array $definition, array $stats): string {
  $progress = service_training_badge_progress($definition, $stats);
  return (string)($progress['hint'] ?? '');
}

function service_training_badge_stats(PDO $pdo, int $storeId, int $castId, ?array $latestQuizResult): array {
  $categoryDoneCounts = service_training_mission_category_done_counts($pdo, $storeId, $castId);
  $totalDone = array_sum($categoryDoneCounts);
  $streak = service_training_done_streak($pdo, $storeId, $castId);
  $quizScores = is_array($latestQuizResult['scores'] ?? null) ? (array)$latestQuizResult['scores'] : [];

  return [
    'category_done_counts' => $categoryDoneCounts,
    'mission_total_done' => $totalDone,
    'streak_days' => $streak,
    'quiz_scores' => $quizScores,
  ];
}

function service_training_badge_is_earned(array $definition, array $stats): bool {
  $condition = (array)($definition['condition'] ?? []);
  $type = (string)($condition['type'] ?? '');
  $threshold = (int)($condition['threshold'] ?? 0);

  if ($type === 'category_done') {
    $category = (string)($condition['category'] ?? '');
    return (int)($stats['category_done_counts'][$category] ?? 0) >= $threshold;
  }

  if ($type === 'mission_total_done') {
    return (int)($stats['mission_total_done'] ?? 0) >= $threshold;
  }

  if ($type === 'streak_days') {
    return (int)($stats['streak_days'] ?? 0) >= $threshold;
  }

  if ($type === 'quiz_score') {
    $axis = (string)($condition['axis'] ?? '');
    $direction = (string)($condition['direction'] ?? 'positive');
    $current = (int)($stats['quiz_scores'][$axis] ?? 0);
    if ($direction === 'negative') {
      return abs(min(0, $current)) >= $threshold;
    }
    return max(0, $current) >= $threshold;
  }

  return false;
}

function service_training_user_badges(PDO $pdo, int $storeId, int $castId, ?array $latestQuizResult): array {
  $definitions = service_training_badge_definitions();
  $stats = service_training_badge_stats($pdo, $storeId, $castId, $latestQuizResult);
  $earned = [];
  $locked = [];
  $all = [];

  foreach ($definitions as $definition) {
    if (!is_array($definition)) {
      continue;
    }
    $item = $definition;
    $earnedFlag = service_training_badge_is_earned($definition, $stats);
    $progress = service_training_badge_progress($definition, $stats);
    $item['rarity'] = max(1, (int)($item['rarity'] ?? 1));
    $item['rarity_label'] = service_training_badge_rarity_label((int)$item['rarity']);
    $item['rarity_stars'] = service_training_badge_rarity_stars((int)$item['rarity']);
    $item['condition_text'] = service_training_badge_condition_text($definition);
    $item['progress'] = $progress;
    $item['progress_text'] = service_training_badge_progress_text($definition, $stats);
    $item['earned'] = $earnedFlag;
    $item['secret'] = !empty($item['secret']);
    $item['display_name'] = (!$earnedFlag && $item['secret']) ? '？？？' : (string)($item['name'] ?? '');
    $item['display_description'] = (!$earnedFlag && $item['secret']) ? 'まだ見ぬ条件を満たすと開放されます。' : (string)($item['description'] ?? '');
    $all[] = $item;
    if ($earnedFlag) {
      $earned[] = $item;
    } else {
      $locked[] = $item;
    }
  }

  $total = count($all);
  $earnedCount = count($earned);

  return [
    'all' => $all,
    'earned' => $earned,
    'locked' => $locked,
    'stats' => $stats,
    'summary' => [
      'earned_count' => $earnedCount,
      'total_count' => $total,
      'achievement_rate' => $total > 0 ? (int)round(($earnedCount / $total) * 100) : 0,
    ],
  ];
}

function service_training_newly_earned_badge_keys(array $earnedBadges): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $seen = $_SESSION[SERVICE_TRAINING_BADGE_SEEN_KEY] ?? [];
  if (!is_array($seen)) {
    $seen = [];
  }

  $current = [];
  $newKeys = [];
  foreach ($earnedBadges as $badge) {
    $key = trim((string)($badge['key'] ?? ''));
    if ($key === '') {
      continue;
    }
    $current[] = $key;
    if (!in_array($key, $seen, true)) {
      $newKeys[] = $key;
    }
  }

  $_SESSION[SERVICE_TRAINING_BADGE_SEEN_KEY] = array_values(array_unique(array_merge($seen, $current)));
  return $newKeys;
}
