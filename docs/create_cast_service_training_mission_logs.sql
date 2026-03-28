CREATE TABLE IF NOT EXISTS cast_service_training_mission_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  log_date DATE NOT NULL,
  mission_id VARCHAR(80) NOT NULL,
  mission_title VARCHAR(255) NOT NULL DEFAULT '',
  mission_category VARCHAR(50) NOT NULL DEFAULT '',
  skill_tag VARCHAR(80) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cast_service_training_mission_logs_daily (store_id, cast_id, log_date),
  KEY idx_cast_service_training_mission_logs_cast (store_id, cast_id, log_date),
  KEY idx_cast_service_training_mission_logs_status (store_id, cast_id, status, log_date),
  KEY idx_cast_service_training_mission_logs_category (store_id, cast_id, mission_category, status, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
