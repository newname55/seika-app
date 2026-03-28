CREATE TABLE IF NOT EXISTS cast_service_training_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  result_type_key VARCHAR(50) NOT NULL DEFAULT '',
  result_type_name VARCHAR(100) NOT NULL DEFAULT '',
  answered_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  total_score SMALLINT NOT NULL DEFAULT 0,
  question_ids_json JSON NOT NULL,
  strong_points_json JSON NOT NULL,
  stretch_points_json JSON NOT NULL,
  weak_tags_json JSON NOT NULL,
  result_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cast_service_training_sessions_cast (store_id, cast_id, created_at),
  KEY idx_cast_service_training_sessions_type (store_id, result_type_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cast_service_training_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  question_id VARCHAR(80) NOT NULL,
  question_category VARCHAR(50) NOT NULL,
  choice_key CHAR(1) NOT NULL,
  choice_rank VARCHAR(20) NOT NULL DEFAULT '',
  choice_score SMALLINT NOT NULL DEFAULT 0,
  feedback_text VARCHAR(255) NOT NULL DEFAULT '',
  skill_tags_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cast_service_training_answers_cast (store_id, cast_id, created_at),
  KEY idx_cast_service_training_answers_session (session_id),
  KEY idx_cast_service_training_answers_category (store_id, cast_id, question_category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
