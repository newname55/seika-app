ALTER TABLE stores
  ADD COLUMN lifecycle_status ENUM('active','suspended','decommissioning','decommissioned')
    NOT NULL DEFAULT 'active' AFTER status,
  ADD COLUMN decommission_requested_at DATETIME NULL AFTER lifecycle_status,
  ADD COLUMN decommission_approved_at DATETIME NULL AFTER decommission_requested_at,
  ADD COLUMN decommission_scheduled_at DATETIME NULL AFTER decommission_approved_at,
  ADD COLUMN decommission_completed_at DATETIME NULL AFTER decommission_scheduled_at;

CREATE TABLE IF NOT EXISTS store_decommission_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  executed_by BIGINT UNSIGNED NULL,
  status ENUM(
    'requested',
    'approved',
    'scheduled',
    'running',
    'completed',
    'cancelled',
    'failed'
  ) NOT NULL DEFAULT 'requested',
  reason TEXT NULL,
  confirm_token CHAR(64) NOT NULL,
  export_path VARCHAR(255) NULL,
  export_ready TINYINT(1) NOT NULL DEFAULT 0,
  backup_purge_status ENUM('not_requested','pending','running','completed','failed')
    NOT NULL DEFAULT 'not_requested',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  scheduled_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  failed_at DATETIME NULL,
  failure_reason TEXT NULL,
  requested_ip VARCHAR(64) NULL,
  approved_ip VARCHAR(64) NULL,
  executed_ip VARCHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_status (store_id, status),
  INDEX idx_status_scheduled (status, scheduled_at),
  CONSTRAINT fk_store_decommission_jobs_store
    FOREIGN KEY (store_id) REFERENCES stores(id)
    ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS store_decommission_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  step_key VARCHAR(64) NOT NULL,
  step_label VARCHAR(255) NOT NULL,
  status ENUM('started','completed','failed') NOT NULL,
  message TEXT NULL,
  context_json JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_job_created (job_id, created_at),
  INDEX idx_store_created (store_id, created_at),
  CONSTRAINT fk_store_decommission_logs_job
    FOREIGN KEY (job_id) REFERENCES store_decommission_jobs(id)
    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS store_decommission_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  customers_count INT UNSIGNED NOT NULL DEFAULT 0,
  tickets_count INT UNSIGNED NOT NULL DEFAULT 0,
  orders_count INT UNSIGNED NOT NULL DEFAULT 0,
  attendances_count INT UNSIGNED NOT NULL DEFAULT 0,
  nominations_count INT UNSIGNED NOT NULL DEFAULT 0,
  interviews_count INT UNSIGNED NOT NULL DEFAULT 0,
  attachments_count INT UNSIGNED NOT NULL DEFAULT 0,
  attachments_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  snapshot_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_store_decommission_snapshots_job
    FOREIGN KEY (job_id) REFERENCES store_decommission_jobs(id)
    ON DELETE CASCADE
);
