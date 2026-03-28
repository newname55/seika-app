CREATE TABLE IF NOT EXISTS service_quiz_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_text TEXT NOT NULL,
  question_type VARCHAR(30) NOT NULL DEFAULT 'customer_quote',
  prompt_text VARCHAR(255) NOT NULL DEFAULT '',
  category VARCHAR(50) NOT NULL,
  axis_focus VARCHAR(30) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_service_quiz_questions_active_category (is_active, category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_quiz_choices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  choice_key CHAR(1) NOT NULL,
  choice_text TEXT NOT NULL,
  talk_score SMALLINT NOT NULL DEFAULT 0,
  mood_score SMALLINT NOT NULL DEFAULT 0,
  response_score SMALLINT NOT NULL DEFAULT 0,
  relation_score SMALLINT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_quiz_choices_question_key (question_id, choice_key),
  KEY idx_service_quiz_choices_question (question_id, sort_order),
  CONSTRAINT fk_service_quiz_choices_question
    FOREIGN KEY (question_id) REFERENCES service_quiz_questions (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
