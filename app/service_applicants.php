<?php
declare(strict_types=1);

require_once __DIR__ . '/repo_applicants.php';

function service_applicants_trim(?string $value): string {
  return trim((string)$value);
}

function service_applicants_nullable_string(?string $value): ?string {
  $value = trim((string)$value);
  return $value === '' ? null : $value;
}

function service_applicants_nullable_int($value): ?int {
  if ($value === null || $value === '') {
    return null;
  }
  $n = (int)$value;
  return $n > 0 ? $n : null;
}

function service_applicants_nullable_decimal($value): ?string {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  return is_numeric($value) ? (string)$value : null;
}

function service_applicants_calculate_age(?string $birthDate): ?int {
  $birthDate = trim((string)$birthDate);
  if ($birthDate === '') {
    return null;
  }

  try {
    $birth = new DateTimeImmutable($birthDate, new DateTimeZone('Asia/Tokyo'));
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $age = (int)$today->diff($birth)->y;
    if ($age < 0 || $age > 120) {
      return null;
    }
    return $age;
  } catch (Throwable $e) {
    return null;
  }
}

function service_applicants_zodiac(?string $birthDate): ?string {
  $birthDate = trim((string)$birthDate);
  if ($birthDate === '') {
    return null;
  }

  try {
    $year = (int)(new DateTimeImmutable($birthDate))->format('Y');
  } catch (Throwable $e) {
    return null;
  }

  $signs = ['申','酉','戌','亥','子','丑','寅','卯','辰','巳','午','未'];
  return $signs[$year % 12] ?? null;
}

function service_applicants_person_payload(array $input, int $actorUserId): array {
  $birthDate = service_applicants_nullable_string($input['birth_date'] ?? null);
  return [
    'legacy_source' => service_applicants_nullable_string($input['legacy_source'] ?? null),
    'legacy_record_no' => service_applicants_nullable_string($input['legacy_record_no'] ?? null),
    'person_code' => service_applicants_nullable_string($input['person_code'] ?? null),
    'last_name' => service_applicants_trim($input['last_name'] ?? ''),
    'first_name' => service_applicants_trim($input['first_name'] ?? ''),
    'last_name_kana' => service_applicants_nullable_string($input['last_name_kana'] ?? null),
    'first_name_kana' => service_applicants_nullable_string($input['first_name_kana'] ?? null),
    'birth_date' => $birthDate,
    'age_cached' => service_applicants_calculate_age($birthDate),
    'phone' => service_applicants_nullable_string($input['phone'] ?? null),
    'postal_code' => service_applicants_nullable_string($input['postal_code'] ?? null),
    'current_address' => service_applicants_nullable_string($input['current_address'] ?? null),
    'previous_address' => service_applicants_nullable_string($input['previous_address'] ?? null),
    'blood_type' => service_applicants_nullable_string($input['blood_type'] ?? null),
    'zodiac_sign' => service_applicants_zodiac($birthDate),
    'body_height_cm' => service_applicants_nullable_int($input['body_height_cm'] ?? null),
    'body_weight_kg' => service_applicants_nullable_decimal($input['body_weight_kg'] ?? null),
    'bust_cm' => service_applicants_nullable_int($input['bust_cm'] ?? null),
    'waist_cm' => service_applicants_nullable_int($input['waist_cm'] ?? null),
    'hip_cm' => service_applicants_nullable_int($input['hip_cm'] ?? null),
    'cup_size' => service_applicants_nullable_string($input['cup_size'] ?? null),
    'shoe_size' => service_applicants_nullable_decimal($input['shoe_size'] ?? null),
    'clothing_top_size' => service_applicants_nullable_string($input['clothing_top_size'] ?? null),
    'clothing_bottom_size' => service_applicants_nullable_string($input['clothing_bottom_size'] ?? null),
    'previous_job' => service_applicants_nullable_string($input['previous_job'] ?? null),
    'desired_hourly_wage' => service_applicants_nullable_decimal($input['desired_hourly_wage'] ?? null),
    'desired_daily_wage' => service_applicants_nullable_decimal($input['desired_daily_wage'] ?? null),
    'current_stage_name' => service_applicants_nullable_string($input['genji_name'] ?? null),
    'notes' => service_applicants_nullable_string($input['notes'] ?? null),
    'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
    'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
  ];
}

function service_applicants_assert_person_payload(array $payload): void {
  if ($payload['last_name'] === '' || $payload['first_name'] === '') {
    throw new InvalidArgumentException('姓と名は必須です');
  }
}

function service_applicants_upload_dir_fs(): string {
  return dirname(__DIR__) . '/public/uploads/applicants';
}

function service_applicants_upload_dir_url(): string {
  return '/wbss/public/uploads/applicants';
}

function service_applicants_mkdir(string $dir): void {
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('アップロード先ディレクトリを作成できません');
  }
}

function service_applicants_make_thumbnail(string $srcPath, string $destPath, string $mimeType): bool {
  if (!extension_loaded('gd')) {
    return false;
  }

  if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
    $image = @imagecreatefromjpeg($srcPath);
  } elseif ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
    $image = @imagecreatefrompng($srcPath);
  } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
    $image = @imagecreatefromwebp($srcPath);
  } else {
    return false;
  }

  if (!$image) {
    return false;
  }

  $srcWidth = imagesx($image);
  $srcHeight = imagesy($image);
  if ($srcWidth <= 0 || $srcHeight <= 0) {
    imagedestroy($image);
    return false;
  }

  $thumbSize = 240;
  $ratio = min($thumbSize / $srcWidth, $thumbSize / $srcHeight);
  $dstWidth = max(1, (int)floor($srcWidth * $ratio));
  $dstHeight = max(1, (int)floor($srcHeight * $ratio));
  $thumb = imagecreatetruecolor($dstWidth, $dstHeight);
  imagealphablending($thumb, false);
  imagesavealpha($thumb, true);
  imagecopyresampled($thumb, $image, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);

  $ok = imagewebp($thumb, $destPath, 82);
  imagedestroy($thumb);
  imagedestroy($image);
  return $ok;
}

function service_applicants_store_photo(PDO $pdo, int $personId, array $file, int $actorUserId): int {
  if (!isset($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
    throw new InvalidArgumentException('顔写真ファイルを選択してください');
  }
  if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('写真アップロードに失敗しました');
  }

  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > 5_000_000) {
    throw new InvalidArgumentException('顔写真は 5MB 以内でアップロードしてください');
  }

  $tmpName = (string)$file['tmp_name'];
  $mimeType = (string)(@mime_content_type($tmpName) ?: '');
  $ext = match ($mimeType) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };
  if ($ext === '') {
    throw new InvalidArgumentException('対応形式は JPG / PNG / WEBP のみです');
  }

  $baseFs = service_applicants_upload_dir_fs() . '/' . $personId;
  $baseUrl = service_applicants_upload_dir_url() . '/' . $personId;
  service_applicants_mkdir($baseFs);

  $baseName = 'face_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
  $fileName = $baseName . '.' . $ext;
  $destFs = $baseFs . '/' . $fileName;
  $destUrl = $baseUrl . '/' . $fileName;

  if (!@move_uploaded_file($tmpName, $destFs)) {
    throw new RuntimeException('顔写真の保存に失敗しました');
  }
  @chmod($destFs, 0664);

  $thumbName = $baseName . '_thumb.webp';
  $thumbFs = $baseFs . '/' . $thumbName;
  $thumbUrl = $destUrl;
  if (service_applicants_make_thumbnail($destFs, $thumbFs, $mimeType)) {
    @chmod($thumbFs, 0664);
    $thumbUrl = $baseUrl . '/' . $thumbName;
  }

  repo_applicants_set_all_photos_non_primary($pdo, $personId);
  $photoId = repo_applicants_insert_photo($pdo, [
    'person_id' => $personId,
    'file_name' => $fileName,
    'file_path' => $destUrl,
    'thumb_path' => $thumbUrl,
    'mime_type' => $mimeType,
    'file_size' => $size,
    'is_primary' => 1,
    'uploaded_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
  ]);

  return $photoId;
}

function service_applicants_log(PDO $pdo, array $data): void {
  repo_applicants_insert_log($pdo, [
    'person_id' => (int)$data['person_id'],
    'interview_id' => service_applicants_nullable_int($data['interview_id'] ?? null),
    'assignment_id' => service_applicants_nullable_int($data['assignment_id'] ?? null),
    'action_type' => (string)$data['action_type'],
    'from_status' => service_applicants_nullable_string($data['from_status'] ?? null),
    'to_status' => service_applicants_nullable_string($data['to_status'] ?? null),
    'store_id' => service_applicants_nullable_int($data['store_id'] ?? null),
    'target_store_id' => service_applicants_nullable_int($data['target_store_id'] ?? null),
    'actor_user_id' => service_applicants_nullable_int($data['actor_user_id'] ?? null),
    'action_note' => service_applicants_nullable_string($data['action_note'] ?? null),
    'payload_json' => isset($data['payload_json']) ? json_encode($data['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
  ]);
}

function service_applicants_refresh_summary(PDO $pdo, int $personId, int $actorUserId = 0): void {
  $currentAssignment = repo_applicants_find_current_assignment($pdo, $personId);
  $latestInterview = repo_applicants_find_latest_interview($pdo, $personId);
  $primaryPhoto = repo_applicants_find_primary_photo($pdo, $personId);
  $hasAssignments = repo_applicants_count_assignments($pdo, $personId) > 0;
  $person = repo_applicants_find_person($pdo, $personId);

  $currentStatus = 'interviewing';
  $isCurrent = 0;
  $currentStoreId = null;
  $currentAssignmentId = null;
  $currentStageName = null;

  if ($currentAssignment) {
    $currentStatus = 'active';
    $isCurrent = 1;
    $currentStoreId = (int)$currentAssignment['store_id'];
    $currentAssignmentId = (int)$currentAssignment['id'];
    $currentStageName = service_applicants_nullable_string($currentAssignment['genji_name'] ?? null);
  } elseif ($hasAssignments) {
    $currentStatus = 'left';
  } elseif ($latestInterview) {
    $trialStatus = (string)($latestInterview['trial_status'] ?? 'not_set');
    $interviewResult = (string)($latestInterview['interview_result'] ?? 'pending');
    if (in_array($trialStatus, ['scheduled','completed','passed'], true)) {
      $currentStatus = 'trial';
    } elseif ($interviewResult === 'hold') {
      $currentStatus = 'hold';
    } else {
      $currentStatus = 'interviewing';
    }
  }

  if ($currentStageName === null && $person) {
    $currentStageName = service_applicants_nullable_string($person['current_stage_name'] ?? null);
  }

  repo_applicants_update_person_summary($pdo, $personId, [
    'current_status' => $currentStatus,
    'is_currently_employed' => $isCurrent,
    'current_store_id' => $currentStoreId,
    'current_assignment_id' => $currentAssignmentId,
    'latest_interview_id' => $latestInterview ? (int)$latestInterview['id'] : null,
    'latest_interviewed_at' => $latestInterview['interview_date'] ?? null,
    'latest_interview_result' => $latestInterview['interview_result'] ?? null,
    'primary_photo_id' => $primaryPhoto ? (int)$primaryPhoto['id'] : null,
    'current_stage_name' => $currentStageName,
    'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
  ]);
}

function service_applicants_save_person(PDO $pdo, array $input, ?array $photoFile, int $actorUserId): int {
  $payload = service_applicants_person_payload($input, $actorUserId);
  service_applicants_assert_person_payload($payload);
  $personId = (int)($input['person_id'] ?? 0);
  $genjiName = service_applicants_nullable_string($input['genji_name'] ?? null);

  $pdo->beginTransaction();
  try {
    if ($personId > 0) {
      repo_applicants_update_person($pdo, $personId, $payload);
      $currentAssignment = repo_applicants_find_current_assignment($pdo, $personId);
      if ($currentAssignment) {
        repo_applicants_update_assignment_genji_name(
          $pdo,
          (int)$currentAssignment['id'],
          $genjiName,
          $actorUserId > 0 ? $actorUserId : null
        );
      }
      service_applicants_log($pdo, [
        'person_id' => $personId,
        'action_type' => 'person_updated',
        'actor_user_id' => $actorUserId,
        'action_note' => '面接基本情報を更新',
      ]);
    } else {
      $personId = repo_applicants_insert_person($pdo, $payload);
      service_applicants_log($pdo, [
        'person_id' => $personId,
        'action_type' => 'person_created',
        'actor_user_id' => $actorUserId,
        'action_note' => '面接者を新規登録',
      ]);
    }

    if ($photoFile && isset($photoFile['tmp_name']) && (int)($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $photoId = service_applicants_store_photo($pdo, $personId, $photoFile, $actorUserId);
      service_applicants_log($pdo, [
        'person_id' => $personId,
        'action_type' => 'photo_uploaded',
        'actor_user_id' => $actorUserId,
        'action_note' => '顔写真を登録',
        'payload_json' => ['photo_id' => $photoId],
      ]);
    }

    service_applicants_refresh_summary($pdo, $personId, $actorUserId);
    $person = repo_applicants_find_person($pdo, $personId);
    if (!$person || empty($person['primary_photo_id'])) {
      throw new RuntimeException('顔写真は必須です');
    }

    $pdo->commit();
    return $personId;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function service_applicants_upload_photo(PDO $pdo, int $personId, array $photoFile, int $actorUserId): void {
  if ($personId <= 0) {
    throw new InvalidArgumentException('対象の面接者が不正です');
  }

  $pdo->beginTransaction();
  try {
    $photoId = service_applicants_store_photo($pdo, $personId, $photoFile, $actorUserId);
    service_applicants_refresh_summary($pdo, $personId, $actorUserId);
    service_applicants_log($pdo, [
      'person_id' => $personId,
      'action_type' => 'photo_uploaded',
      'actor_user_id' => $actorUserId,
      'action_note' => '顔写真を更新',
      'payload_json' => ['photo_id' => $photoId],
    ]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function service_applicants_add_interview(PDO $pdo, int $personId, array $input, int $actorUserId): int {
  if ($personId <= 0) {
    throw new InvalidArgumentException('対象の面接者が見つかりません');
  }

  $interviewDate = service_applicants_nullable_string($input['interview_date'] ?? null);
  $storeId = service_applicants_nullable_int($input['interview_store_id'] ?? null);
  if ($interviewDate === null || $storeId === null) {
    throw new InvalidArgumentException('面接日と面接店舗は必須です');
  }

  $scoreFields = [
    'appearance_score',
    'communication_score',
    'motivation_score',
    'cleanliness_score',
    'sales_potential_score',
    'retention_potential_score',
  ];
  $scorePayload = [];
  $total = 0;
  $hasScore = false;
  foreach ($scoreFields as $field) {
    $value = service_applicants_nullable_int($input[$field] ?? null);
    $scorePayload[$field] = $value;
    if ($value !== null) {
      $total += $value;
      $hasScore = true;
    }
  }
  $scorePayload['total_score'] = $hasScore ? $total : null;
  $scorePayload['comment'] = service_applicants_nullable_string($input['score_comment'] ?? null);

  $payload = [
    'person_id' => $personId,
    'interview_date' => $interviewDate,
    'interview_time' => service_applicants_nullable_string($input['interview_time'] ?? null),
    'interview_store_id' => $storeId,
    'interviewer_user_id' => service_applicants_nullable_int($input['interviewer_user_id'] ?? null),
    'interview_result' => service_applicants_nullable_string($input['interview_result'] ?? 'pending') ?? 'pending',
    'application_route' => service_applicants_nullable_string($input['application_route'] ?? null),
    'recruitment_slot_note' => service_applicants_nullable_string($input['recruitment_slot_note'] ?? null),
    'interview_notes' => service_applicants_nullable_string($input['interview_notes'] ?? null),
    'previous_job' => service_applicants_nullable_string($input['previous_job'] ?? null),
    'desired_hourly_wage' => service_applicants_nullable_decimal($input['desired_hourly_wage'] ?? null),
    'desired_daily_wage' => service_applicants_nullable_decimal($input['desired_daily_wage'] ?? null),
    'preferred_store_id' => service_applicants_nullable_int($input['preferred_store_id'] ?? null),
    'trial_status' => service_applicants_nullable_string($input['trial_status'] ?? 'not_set') ?? 'not_set',
    'trial_date' => service_applicants_nullable_string($input['trial_date'] ?? null),
    'trial_feedback' => service_applicants_nullable_string($input['trial_feedback'] ?? null),
    'join_decision' => service_applicants_nullable_string($input['join_decision'] ?? 'undecided') ?? 'undecided',
    'join_date' => service_applicants_nullable_string($input['join_date'] ?? null),
    'next_action_note' => service_applicants_nullable_string($input['next_action_note'] ?? null),
    'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
    'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
  ];

  $pdo->beginTransaction();
  try {
    $interviewId = repo_applicants_insert_interview($pdo, $payload);
    repo_applicants_upsert_interview_score($pdo, $interviewId, $scorePayload);
    service_applicants_refresh_summary($pdo, $personId, $actorUserId);
    service_applicants_log($pdo, [
      'person_id' => $personId,
      'interview_id' => $interviewId,
      'action_type' => 'interview_created',
      'actor_user_id' => $actorUserId,
      'store_id' => $storeId,
      'action_note' => '面接記録を追加',
      'payload_json' => ['interview_result' => $payload['interview_result'], 'trial_status' => $payload['trial_status']],
    ]);
    $pdo->commit();
    return $interviewId;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function service_applicants_change_status(PDO $pdo, int $personId, string $action, array $input, int $actorUserId): void {
  $action = trim($action);
  if (!in_array($action, ['trial','active','left'], true)) {
    throw new InvalidArgumentException('不明な状態変更です');
  }

  $person = repo_applicants_find_person($pdo, $personId);
  if (!$person) {
    throw new InvalidArgumentException('対象の面接者が見つかりません');
  }

  $fromStatus = (string)($person['current_status'] ?? '');
  $latestInterview = repo_applicants_find_latest_interview($pdo, $personId);

  $pdo->beginTransaction();
  try {
    if ($action === 'trial') {
      $note = service_applicants_nullable_string($input['note'] ?? null);
      if ($latestInterview) {
        $trialDate = service_applicants_nullable_string($input['trial_date'] ?? null);
        $trialFeedback = service_applicants_nullable_string($input['trial_feedback'] ?? null);
        $st = $pdo->prepare("
          UPDATE wbss_applicant_interviews
          SET trial_status = :trial_status,
              trial_date = :trial_date,
              trial_feedback = :trial_feedback,
              updated_by_user_id = :updated_by_user_id,
              updated_at = NOW()
          WHERE id = :id
          LIMIT 1
        ");
        $st->execute([
          'trial_status' => service_applicants_nullable_string($input['trial_status'] ?? 'scheduled') ?? 'scheduled',
          'trial_date' => $trialDate,
          'trial_feedback' => $trialFeedback,
          'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
          'id' => (int)$latestInterview['id'],
        ]);
      }

      repo_applicants_update_person_summary($pdo, $personId, [
        'current_status' => 'trial',
        'is_currently_employed' => 0,
        'current_store_id' => null,
        'current_assignment_id' => null,
        'latest_interview_id' => $latestInterview ? (int)$latestInterview['id'] : null,
        'latest_interviewed_at' => $latestInterview['interview_date'] ?? null,
        'latest_interview_result' => $latestInterview['interview_result'] ?? null,
        'primary_photo_id' => $person['primary_photo_id'] ?? null,
        'current_stage_name' => $person['current_stage_name'] ?? null,
        'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
      ]);

      service_applicants_log($pdo, [
        'person_id' => $personId,
        'interview_id' => $latestInterview['id'] ?? null,
        'action_type' => 'trial_updated',
        'from_status' => $fromStatus,
        'to_status' => 'trial',
        'actor_user_id' => $actorUserId,
        'action_note' => $note ?: '体験入店状態を更新',
      ]);
    }

    if ($action === 'active') {
      $storeId = service_applicants_nullable_int($input['store_id'] ?? null);
      $startDate = service_applicants_nullable_string($input['effective_date'] ?? null);
      $genjiName = service_applicants_nullable_string($input['genji_name'] ?? null);
      if ($storeId === null || $startDate === null) {
        throw new InvalidArgumentException('在籍化には店舗と入店日が必要です');
      }

      $current = repo_applicants_find_current_assignment($pdo, $personId);
      if ($current && (int)$current['store_id'] !== $storeId) {
        throw new RuntimeException('別店舗に現在在籍中です。店舗移動を使用してください');
      }

      if ($current) {
        $assignmentId = (int)$current['id'];
        repo_applicants_update_assignment_genji_name(
          $pdo,
          $assignmentId,
          $genjiName,
          $actorUserId > 0 ? $actorUserId : null
        );
      } else {
        $assignmentId = repo_applicants_insert_assignment($pdo, [
          'person_id' => $personId,
          'store_id' => $storeId,
          'source_interview_id' => $latestInterview ? (int)$latestInterview['id'] : null,
          'assignment_status' => 'active',
          'transition_type' => repo_applicants_count_assignments($pdo, $personId) > 0 ? 'rejoin' : 'join',
          'start_date' => $startDate,
          'end_date' => null,
          'genji_name' => $genjiName,
          'move_reason' => null,
          'leave_reason' => null,
          'is_current' => 1,
          'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
          'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ]);
      }

      if ($latestInterview) {
        $st = $pdo->prepare("
          UPDATE wbss_applicant_interviews
          SET join_decision = 'approved',
              join_date = COALESCE(join_date, :join_date),
              updated_by_user_id = :updated_by_user_id,
              updated_at = NOW()
          WHERE id = :id
          LIMIT 1
        ");
        $st->execute([
          'join_date' => $startDate,
          'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
          'id' => (int)$latestInterview['id'],
        ]);
      }

      service_applicants_refresh_summary($pdo, $personId, $actorUserId);
      service_applicants_log($pdo, [
        'person_id' => $personId,
        'interview_id' => $latestInterview['id'] ?? null,
        'assignment_id' => $assignmentId,
        'action_type' => 'joined',
        'from_status' => $fromStatus,
        'to_status' => 'active',
        'store_id' => $storeId,
        'actor_user_id' => $actorUserId,
        'action_note' => service_applicants_nullable_string($input['note'] ?? null) ?: '在籍化',
      ]);
    }

    if ($action === 'left') {
      $current = repo_applicants_find_current_assignment($pdo, $personId);
      $leftDate = service_applicants_nullable_string($input['effective_date'] ?? null);
      if ($leftDate === null) {
        throw new InvalidArgumentException('退店日を入力してください');
      }

      if ($current) {
        repo_applicants_close_assignment($pdo, (int)$current['id'], [
          'assignment_status' => 'left',
          'end_date' => $leftDate,
          'move_reason' => null,
          'leave_reason' => service_applicants_nullable_string($input['leave_reason'] ?? null),
          'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ]);
      }

      service_applicants_refresh_summary($pdo, $personId, $actorUserId);
      service_applicants_log($pdo, [
        'person_id' => $personId,
        'assignment_id' => $current['id'] ?? null,
        'action_type' => 'left',
        'from_status' => $fromStatus,
        'to_status' => 'left',
        'store_id' => $current['store_id'] ?? null,
        'actor_user_id' => $actorUserId,
        'action_note' => service_applicants_nullable_string($input['leave_reason'] ?? null) ?: '退店',
      ]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function service_applicants_move_store(PDO $pdo, int $personId, array $input, int $actorUserId): void {
  $current = repo_applicants_find_current_assignment($pdo, $personId);
  if (!$current) {
    throw new RuntimeException('現在在籍中の店舗がありません。先に在籍化してください');
  }

  $toStoreId = service_applicants_nullable_int($input['to_store_id'] ?? null);
  $moveDate = service_applicants_nullable_string($input['move_date'] ?? null);
  if ($toStoreId === null || $moveDate === null) {
    throw new InvalidArgumentException('移動先店舗と移動日が必要です');
  }
  if ((int)$current['store_id'] === $toStoreId) {
    throw new InvalidArgumentException('現在店舗と同じです');
  }

  $person = repo_applicants_find_person($pdo, $personId);
  if (!$person) {
    throw new InvalidArgumentException('対象の面接者が見つかりません');
  }
  $fromStatus = (string)($person['current_status'] ?? '');

  $pdo->beginTransaction();
  try {
    repo_applicants_close_assignment($pdo, (int)$current['id'], [
      'assignment_status' => 'left',
      'end_date' => $moveDate,
      'move_reason' => service_applicants_nullable_string($input['move_reason'] ?? null),
      'leave_reason' => null,
      'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
    ]);

    $newAssignmentId = repo_applicants_insert_assignment($pdo, [
      'person_id' => $personId,
      'store_id' => $toStoreId,
      'source_interview_id' => service_applicants_nullable_int($person['latest_interview_id'] ?? null),
      'assignment_status' => 'active',
      'transition_type' => 'move',
      'start_date' => $moveDate,
      'end_date' => null,
      'genji_name' => service_applicants_nullable_string($input['genji_name'] ?? null),
      'move_reason' => service_applicants_nullable_string($input['move_reason'] ?? null),
      'leave_reason' => null,
      'is_current' => 1,
      'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
      'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
    ]);

    service_applicants_refresh_summary($pdo, $personId, $actorUserId);
    service_applicants_log($pdo, [
      'person_id' => $personId,
      'assignment_id' => $newAssignmentId,
      'action_type' => 'moved',
      'from_status' => $fromStatus,
      'to_status' => 'active',
      'store_id' => (int)$current['store_id'],
      'target_store_id' => $toStoreId,
      'actor_user_id' => $actorUserId,
      'action_note' => service_applicants_nullable_string($input['move_reason'] ?? null) ?: '店舗移動',
    ]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}
