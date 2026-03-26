CREATE TABLE IF NOT EXISTS transport_vehicle_positions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  driver_user_id BIGINT UNSIGNED NOT NULL,
  vehicle_label VARCHAR(64) DEFAULT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  accuracy_m DECIMAL(8,2) DEFAULT NULL,
  heading_deg DECIMAL(6,2) DEFAULT NULL,
  speed_kmh DECIMAL(6,2) DEFAULT NULL,
  recorded_at DATETIME NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'wbss_browser',
  battery_level TINYINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transport_vehicle_positions_store_recorded (store_id, recorded_at),
  KEY idx_transport_vehicle_positions_driver_recorded (driver_user_id, recorded_at),
  KEY idx_transport_vehicle_positions_store_driver_recorded (store_id, driver_user_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_vehicle_position_latest (
  store_id BIGINT UNSIGNED NOT NULL,
  driver_user_id BIGINT UNSIGNED NOT NULL,
  vehicle_label VARCHAR(64) DEFAULT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  accuracy_m DECIMAL(8,2) DEFAULT NULL,
  heading_deg DECIMAL(6,2) DEFAULT NULL,
  speed_kmh DECIMAL(6,2) DEFAULT NULL,
  recorded_at DATETIME NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'wbss_browser',
  battery_level TINYINT UNSIGNED DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (store_id, driver_user_id),
  KEY idx_transport_vehicle_position_latest_store_recorded (store_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 将来案:
-- ALTER TABLE transport_vehicle_positions
--   ADD COLUMN route_plan_id BIGINT UNSIGNED DEFAULT NULL AFTER vehicle_label;
--
-- ALTER TABLE transport_vehicle_positions
--   ADD KEY idx_transport_vehicle_positions_store_route_recorded (store_id, route_plan_id, recorded_at);
