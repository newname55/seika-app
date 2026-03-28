CREATE TABLE IF NOT EXISTS cast_service_quiz_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  result_id BIGINT UNSIGNED NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  quiz_version VARCHAR(30) NOT NULL DEFAULT 'v0.2',
  question_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  answered_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  question_ids_json JSON NOT NULL,
  category_counts_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cast_service_quiz_sessions_cast (store_id, cast_id, created_at),
  KEY idx_cast_service_quiz_sessions_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cast_service_quiz_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  result_id BIGINT UNSIGNED NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  question_category VARCHAR(50) NOT NULL,
  choice_key CHAR(1) NOT NULL,
  answer_scores_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cast_service_quiz_answers_cast (store_id, cast_id, created_at),
  KEY idx_cast_service_quiz_answers_session (session_id),
  KEY idx_cast_service_quiz_answers_category (store_id, cast_id, question_category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
