<?php
declare(strict_types=1);

function repo_applicants_fetch_interviewers(PDO $pdo): array {
  $sql = "
    SELECT id, display_name
    FROM users
    WHERE is_active = 1
    ORDER BY display_name ASC, id ASC
  ";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_applicants_fetch_stores_by_ids(PDO $pdo, array $storeIds): array {
  $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds), static fn(int $id): bool => $id > 0)));
  if ($storeIds === []) {
    return [];
  }

  $ph = implode(',', array_fill(0, count($storeIds), '?'));
  $sql = "SELECT id, name FROM stores WHERE id IN ({$ph}) ORDER BY name ASC, id ASC";
  $st = $pdo->prepare($sql);
  $st->execute($storeIds);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_applicants_list(PDO $pdo, array $filters, array $allowedStoreIds): array {
  $where = [];
  $params = [];

  $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds), static fn(int $id): bool => $id > 0)));
  if ($allowedStoreIds !== []) {
    $ph = implode(',', array_fill(0, count($allowedStoreIds), '?'));
    $where[] = "(
      p.current_store_id IN ({$ph})
      OR (
        p.current_store_id IS NULL
        AND (
          p.latest_interview_id IS NULL
          OR EXISTS (
            SELECT 1
            FROM wbss_applicant_interviews wi_scope
            WHERE wi_scope.id = p.latest_interview_id
              AND wi_scope.interview_store_id IN ({$ph})
          )
        )
      )
    )";
    $params = array_merge($params, $allowedStoreIds, $allowedStoreIds);
  }

  $keyword = trim((string)($filters['q'] ?? ''));
  if ($keyword !== '') {
    $where[] = "(
      CONCAT_WS('', p.last_name, p.first_name) LIKE ?
      OR CONCAT_WS('', p.last_name_kana, p.first_name_kana) LIKE ?
      OR p.phone LIKE ?
    )";
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
  }

  $phone = trim((string)($filters['phone'] ?? ''));
  if ($phone !== '') {
    $where[] = "p.phone LIKE ?";
    $params[] = '%' . $phone . '%';
  }

  $interviewerId = (int)($filters['interviewer_user_id'] ?? 0);
  if ($interviewerId > 0) {
    $where[] = "li.interviewer_user_id = ?";
    $params[] = $interviewerId;
  }

  $storeId = (int)($filters['store_id'] ?? 0);
  if ($storeId > 0) {
    $where[] = "(
      p.current_store_id = ?
      OR EXISTS (
        SELECT 1
        FROM wbss_applicant_interviews wi2
        WHERE wi2.person_id = p.id
          AND wi2.interview_store_id = ?
      )
    )";
    $params[] = $storeId;
    $params[] = $storeId;
  }

  $employmentFilter = (string)($filters['employment_filter'] ?? '');
  if ($employmentFilter === 'active') {
    $where[] = "p.is_currently_employed = 1";
  } elseif ($employmentFilter === 'inactive') {
    $where[] = "p.is_currently_employed = 0";
  } elseif ($employmentFilter === 'left') {
    $where[] = "p.current_status = 'left'";
  }

  $trialOnly = (string)($filters['trial_only'] ?? '');
  if ($trialOnly === '1') {
    $where[] = "p.current_status = 'trial'";
  }

  $interviewResult = trim((string)($filters['latest_interview_result'] ?? ''));
  if ($interviewResult !== '') {
    $where[] = "p.latest_interview_result = ?";
    $params[] = $interviewResult;
  }

  $from = trim((string)($filters['latest_interview_from'] ?? ''));
  if ($from !== '') {
    $where[] = "p.latest_interviewed_at >= ?";
    $params[] = $from;
  }

  $to = trim((string)($filters['latest_interview_to'] ?? ''));
  if ($to !== '') {
    $where[] = "p.latest_interviewed_at <= ?";
    $params[] = $to;
  }

  $sql = "
    SELECT
      p.*,
      cs.name AS current_store_name,
      li.interview_date,
      li.trial_status,
      li.join_decision,
      iu.display_name AS interviewer_name,
      ph.thumb_path,
      ph.file_path AS photo_path
    FROM wbss_applicant_persons p
    LEFT JOIN stores cs
      ON cs.id = p.current_store_id
    LEFT JOIN wbss_applicant_interviews li
      ON li.id = p.latest_interview_id
    LEFT JOIN users iu
      ON iu.id = li.interviewer_user_id
    LEFT JOIN wbss_applicant_photos ph
      ON ph.id = p.primary_photo_id
  ";

  if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }

  $sql .= " ORDER BY p.is_currently_employed DESC, p.current_store_id IS NULL ASC, p.updated_at DESC, p.id DESC LIMIT 300";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_applicants_find_person(PDO $pdo, int $personId): ?array {
  $sql = "
    SELECT
      p.*,
      cs.name AS current_store_name,
      ph.file_path AS primary_photo_path,
      ph.thumb_path AS primary_photo_thumb_path
    FROM wbss_applicant_persons p
    LEFT JOIN stores cs
      ON cs.id = p.current_store_id
    LEFT JOIN wbss_applicant_photos ph
      ON ph.id = p.primary_photo_id
    WHERE p.id = ?
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function repo_applicants_fetch_interviews(PDO $pdo, int $personId): array {
  $sql = "
    SELECT
      i.*,
      s.name AS interview_store_name,
      u.display_name AS interviewer_name,
      ps.appearance_score,
      ps.communication_score,
      ps.motivation_score,
      ps.cleanliness_score,
      ps.sales_potential_score,
      ps.retention_potential_score,
      ps.total_score,
      ps.comment AS score_comment
    FROM wbss_applicant_interviews i
    LEFT JOIN stores s
      ON s.id = i.interview_store_id
    LEFT JOIN users u
      ON u.id = i.interviewer_user_id
    LEFT JOIN wbss_applicant_interview_scores ps
      ON ps.interview_id = i.id
    WHERE i.person_id = ?
    ORDER BY i.interview_date DESC, i.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_applicants_fetch_assignments(PDO $pdo, int $personId): array {
  $sql = "
    SELECT
      a.*,
      s.name AS store_name
    FROM wbss_applicant_store_assignments a
    JOIN stores s
      ON s.id = a.store_id
    WHERE a.person_id = ?
    ORDER BY a.is_current DESC, a.start_date DESC, a.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_applicants_fetch_logs(PDO $pdo, int $personId): array {
  $sql = "
    SELECT
      l.*,
      su.name AS store_name,
      tu.name AS target_store_name,
      u.display_name AS actor_name
    FROM wbss_applicant_status_logs l
    LEFT JOIN stores su
      ON su.id = l.store_id
    LEFT JOIN stores tu
      ON tu.id = l.target_store_id
    LEFT JOIN users u
      ON u.id = l.actor_user_id
    WHERE l.person_id = ?
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 200
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_applicants_fetch_photos(PDO $pdo, int $personId): array {
  $sql = "
    SELECT *
    FROM wbss_applicant_photos
    WHERE person_id = ?
    ORDER BY is_primary DESC, id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_applicants_insert_person(PDO $pdo, array $data): int {
  $sql = "
    INSERT INTO wbss_applicant_persons (
      legacy_source, legacy_record_no, person_code,
      last_name, first_name, last_name_kana, first_name_kana,
      birth_date, age_cached, phone, postal_code, current_address, previous_address,
      blood_type, zodiac_sign, body_height_cm, body_weight_kg, bust_cm, waist_cm, hip_cm,
      cup_size, shoe_size, clothing_top_size, clothing_bottom_size,
      previous_job, desired_hourly_wage, desired_daily_wage, current_stage_name, notes,
      created_by_user_id, updated_by_user_id
    ) VALUES (
      :legacy_source, :legacy_record_no, :person_code,
      :last_name, :first_name, :last_name_kana, :first_name_kana,
      :birth_date, :age_cached, :phone, :postal_code, :current_address, :previous_address,
      :blood_type, :zodiac_sign, :body_height_cm, :body_weight_kg, :bust_cm, :waist_cm, :hip_cm,
      :cup_size, :shoe_size, :clothing_top_size, :clothing_bottom_size,
      :previous_job, :desired_hourly_wage, :desired_daily_wage, :current_stage_name, :notes,
      :created_by_user_id, :updated_by_user_id
    )
  ";
  $pdo->prepare($sql)->execute($data);
  return (int)$pdo->lastInsertId();
}

function repo_applicants_update_person(PDO $pdo, int $personId, array $data): void {
  unset($data['created_by_user_id']);
  $data['id'] = $personId;
  $sql = "
    UPDATE wbss_applicant_persons
    SET
      legacy_source = :legacy_source,
      legacy_record_no = :legacy_record_no,
      person_code = :person_code,
      last_name = :last_name,
      first_name = :first_name,
      last_name_kana = :last_name_kana,
      first_name_kana = :first_name_kana,
      birth_date = :birth_date,
      age_cached = :age_cached,
      phone = :phone,
      postal_code = :postal_code,
      current_address = :current_address,
      previous_address = :previous_address,
      blood_type = :blood_type,
      zodiac_sign = :zodiac_sign,
      body_height_cm = :body_height_cm,
      body_weight_kg = :body_weight_kg,
      bust_cm = :bust_cm,
      waist_cm = :waist_cm,
      hip_cm = :hip_cm,
      cup_size = :cup_size,
      shoe_size = :shoe_size,
      clothing_top_size = :clothing_top_size,
      clothing_bottom_size = :clothing_bottom_size,
      previous_job = :previous_job,
      desired_hourly_wage = :desired_hourly_wage,
      desired_daily_wage = :desired_daily_wage,
      current_stage_name = :current_stage_name,
      notes = :notes,
      updated_by_user_id = :updated_by_user_id,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ";
  $pdo->prepare($sql)->execute($data);
}

function repo_applicants_set_all_photos_non_primary(PDO $pdo, int $personId): void {
  $pdo->prepare("UPDATE wbss_applicant_photos SET is_primary = 0 WHERE person_id = ?")->execute([$personId]);
}

function repo_applicants_insert_photo(PDO $pdo, array $data): int {
  $sql = "
    INSERT INTO wbss_applicant_photos (
      person_id, file_name, file_path, thumb_path, mime_type, file_size, is_primary, uploaded_by_user_id
    ) VALUES (
      :person_id, :file_name, :file_path, :thumb_path, :mime_type, :file_size, :is_primary, :uploaded_by_user_id
    )
  ";
  $pdo->prepare($sql)->execute($data);
  return (int)$pdo->lastInsertId();
}

function repo_applicants_insert_interview(PDO $pdo, array $data): int {
  $sql = "
    INSERT INTO wbss_applicant_interviews (
      person_id, interview_date, interview_time, interview_store_id, interviewer_user_id,
      interview_result, application_route, recruitment_slot_note, interview_notes, previous_job,
      desired_hourly_wage, desired_daily_wage, preferred_store_id,
      trial_status, trial_date, trial_feedback, join_decision, join_date, next_action_note,
      created_by_user_id, updated_by_user_id
    ) VALUES (
      :person_id, :interview_date, :interview_time, :interview_store_id, :interviewer_user_id,
      :interview_result, :application_route, :recruitment_slot_note, :interview_notes, :previous_job,
      :desired_hourly_wage, :desired_daily_wage, :preferred_store_id,
      :trial_status, :trial_date, :trial_feedback, :join_decision, :join_date, :next_action_note,
      :created_by_user_id, :updated_by_user_id
    )
  ";
  $pdo->prepare($sql)->execute($data);
  return (int)$pdo->lastInsertId();
}

function repo_applicants_upsert_interview_score(PDO $pdo, int $interviewId, array $data): void {
  $sql = "
    INSERT INTO wbss_applicant_interview_scores (
      interview_id,
      appearance_score, communication_score, motivation_score, cleanliness_score,
      sales_potential_score, retention_potential_score, total_score, comment
    ) VALUES (
      :interview_id,
      :appearance_score, :communication_score, :motivation_score, :cleanliness_score,
      :sales_potential_score, :retention_potential_score, :total_score, :comment
    )
    ON DUPLICATE KEY UPDATE
      appearance_score = VALUES(appearance_score),
      communication_score = VALUES(communication_score),
      motivation_score = VALUES(motivation_score),
      cleanliness_score = VALUES(cleanliness_score),
      sales_potential_score = VALUES(sales_potential_score),
      retention_potential_score = VALUES(retention_potential_score),
      total_score = VALUES(total_score),
      comment = VALUES(comment),
      updated_at = NOW()
  ";
  $data['interview_id'] = $interviewId;
  $pdo->prepare($sql)->execute($data);
}

function repo_applicants_find_current_assignment(PDO $pdo, int $personId): ?array {
  $sql = "
    SELECT *
    FROM wbss_applicant_store_assignments
    WHERE person_id = ?
      AND is_current = 1
    ORDER BY id DESC
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function repo_applicants_insert_assignment(PDO $pdo, array $data): int {
  $sql = "
    INSERT INTO wbss_applicant_store_assignments (
      person_id, store_id, source_interview_id, assignment_status, transition_type,
      start_date, end_date, genji_name, move_reason, leave_reason, is_current,
      created_by_user_id, updated_by_user_id
    ) VALUES (
      :person_id, :store_id, :source_interview_id, :assignment_status, :transition_type,
      :start_date, :end_date, :genji_name, :move_reason, :leave_reason, :is_current,
      :created_by_user_id, :updated_by_user_id
    )
  ";
  $pdo->prepare($sql)->execute($data);
  return (int)$pdo->lastInsertId();
}

function repo_applicants_close_assignment(PDO $pdo, int $assignmentId, array $data): void {
  $data['id'] = $assignmentId;
  $sql = "
    UPDATE wbss_applicant_store_assignments
    SET
      assignment_status = :assignment_status,
      end_date = :end_date,
      move_reason = :move_reason,
      leave_reason = :leave_reason,
      is_current = 0,
      updated_by_user_id = :updated_by_user_id,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ";
  $pdo->prepare($sql)->execute($data);
}

function repo_applicants_update_assignment_genji_name(PDO $pdo, int $assignmentId, ?string $genjiName, ?int $updatedByUserId): void {
  $sql = "
    UPDATE wbss_applicant_store_assignments
    SET
      genji_name = :genji_name,
      updated_by_user_id = :updated_by_user_id,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ";
  $pdo->prepare($sql)->execute([
    'id' => $assignmentId,
    'genji_name' => $genjiName,
    'updated_by_user_id' => $updatedByUserId,
  ]);
}

function repo_applicants_update_person_summary(PDO $pdo, int $personId, array $data): void {
  $data['id'] = $personId;
  $sql = "
    UPDATE wbss_applicant_persons
    SET
      current_status = :current_status,
      is_currently_employed = :is_currently_employed,
      current_store_id = :current_store_id,
      current_assignment_id = :current_assignment_id,
      latest_interview_id = :latest_interview_id,
      latest_interviewed_at = :latest_interviewed_at,
      latest_interview_result = :latest_interview_result,
      primary_photo_id = :primary_photo_id,
      current_stage_name = :current_stage_name,
      updated_by_user_id = :updated_by_user_id,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ";
  $pdo->prepare($sql)->execute($data);
}

function repo_applicants_find_latest_interview(PDO $pdo, int $personId): ?array {
  $sql = "
    SELECT *
    FROM wbss_applicant_interviews
    WHERE person_id = ?
    ORDER BY interview_date DESC, id DESC
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function repo_applicants_find_primary_photo(PDO $pdo, int $personId): ?array {
  $sql = "
    SELECT *
    FROM wbss_applicant_photos
    WHERE person_id = ?
    ORDER BY is_primary DESC, id DESC
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$personId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function repo_applicants_count_assignments(PDO $pdo, int $personId): int {
  $st = $pdo->prepare("SELECT COUNT(*) FROM wbss_applicant_store_assignments WHERE person_id = ?");
  $st->execute([$personId]);
  return (int)$st->fetchColumn();
}

function repo_applicants_insert_log(PDO $pdo, array $data): void {
  $sql = "
    INSERT INTO wbss_applicant_status_logs (
      person_id, interview_id, assignment_id, action_type, from_status, to_status,
      store_id, target_store_id, actor_user_id, action_note, payload_json
    ) VALUES (
      :person_id, :interview_id, :assignment_id, :action_type, :from_status, :to_status,
      :store_id, :target_store_id, :actor_user_id, :action_note, :payload_json
    )
  ";
  $pdo->prepare($sql)->execute($data);
}
