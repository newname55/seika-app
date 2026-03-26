ALTER TABLE stores
  ADD COLUMN lifecycle_status ENUM('active','suspended','decommissioning','decommissioned')
    NOT NULL DEFAULT 'active',
  ADD COLUMN decommission_requested_at DATETIME NULL,
  ADD COLUMN decommission_approved_at DATETIME NULL,
  ADD COLUMN decommission_scheduled_at DATETIME NULL,
  ADD COLUMN decommission_completed_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS store_decommission_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requested_by INT UNSIGNED NOT NULL,
  status ENUM(
    'scheduled',
    'running',
    'completed',
    'dry_run_completed',
    'partial_failed',
    'failed',
    'cancelled'
  ) NOT NULL DEFAULT 'scheduled',
  reason TEXT NULL,
  dry_run TINYINT(1) NOT NULL DEFAULT 0,
  scheduled_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  failure_reason TEXT NULL,
  requested_ip VARCHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_batch_status_scheduled (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 既存環境では store_decommission_jobs に batch_id と dry_run_completed を反映してください。
-- 例:
-- ALTER TABLE store_decommission_jobs
--   ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER id,
--   ADD INDEX idx_batch_status (batch_id, status),
--   ADD CONSTRAINT fk_store_decommission_jobs_batch
--     FOREIGN KEY (batch_id) REFERENCES store_decommission_batches(id)
--     ON DELETE SET NULL;
-- status ENUM には 'dry_run_completed' を追加してください。

CREATE TABLE IF NOT EXISTS store_decommission_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NULL,
  store_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  executed_by INT UNSIGNED NULL,
  status ENUM(
    'requested',
    'approved',
    'scheduled',
    'running',
    'completed',
    'dry_run_completed',
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
  INDEX idx_batch_status (batch_id, status),
  INDEX idx_store_status (store_id, status),
  INDEX idx_status_scheduled (status, scheduled_at),
  CONSTRAINT fk_store_decommission_jobs_batch
    FOREIGN KEY (batch_id) REFERENCES store_decommission_batches(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_decommission_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_decommission_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS store_decommission_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requested_by INT UNSIGNED NOT NULL,
  status ENUM(
    'scheduled',
    'running',
    'completed',
    'dry_run_completed',
    'partial_failed',
    'failed',
    'cancelled'
  ) NOT NULL DEFAULT 'scheduled',
  reason TEXT NULL,
  dry_run TINYINT(1) NOT NULL DEFAULT 0,
  scheduled_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  failure_reason TEXT NULL,
  requested_ip VARCHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_batch_status_scheduled (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE store_decommission_jobs
  ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE store_decommission_jobs
  MODIFY COLUMN status ENUM(
    'requested',
    'approved',
    'scheduled',
    'running',
    'completed',
    'dry_run_completed',
    'cancelled',
    'failed'
  ) NOT NULL DEFAULT 'requested';

ALTER TABLE store_decommission_jobs
  ADD INDEX idx_batch_status (batch_id, status);

ALTER TABLE store_decommission_jobs
  ADD CONSTRAINT fk_store_decommission_jobs_batch
    FOREIGN KEY (batch_id) REFERENCES store_decommission_batches(id)
    ON DELETE SET NULL;

COMMIT;
