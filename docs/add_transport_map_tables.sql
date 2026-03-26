CREATE TABLE IF NOT EXISTS transport_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  pickup_name VARCHAR(128) DEFAULT NULL,
  pickup_address VARCHAR(255) NOT NULL,
  pickup_lat DECIMAL(10,7) DEFAULT NULL,
  pickup_lng DECIMAL(10,7) DEFAULT NULL,
  pickup_geocoded_at DATETIME DEFAULT NULL,
  pickup_note VARCHAR(255) DEFAULT NULL,
  pickup_time_from TIME DEFAULT NULL,
  pickup_time_to TIME DEFAULT NULL,
  area_name VARCHAR(128) DEFAULT NULL,
  direction_bucket VARCHAR(32) DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  driver_user_id BIGINT UNSIGNED DEFAULT NULL,
  vehicle_label VARCHAR(64) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  route_group_key VARCHAR(64) DEFAULT NULL,
  route_hint_json LONGTEXT DEFAULT NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'manual',
  source_ref_id BIGINT UNSIGNED DEFAULT NULL,
  created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
  updated_by_user_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transport_assignments_store_date_cast (store_id, business_date, cast_id),
  KEY idx_transport_assignments_store_date_status (store_id, business_date, status),
  KEY idx_transport_assignments_store_date_driver (store_id, business_date, driver_user_id),
  KEY idx_transport_assignments_store_date_direction (store_id, business_date, direction_bucket),
  KEY idx_transport_assignments_store_date_time (store_id, business_date, pickup_time_from, pickup_time_to),
  KEY idx_transport_assignments_store_date_sort (store_id, business_date, sort_order, id),
  KEY idx_transport_assignments_store_date_coords (store_id, business_date, pickup_lat, pickup_lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_assignment_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(32) NOT NULL,
  actor_user_id BIGINT UNSIGNED DEFAULT NULL,
  before_json LONGTEXT DEFAULT NULL,
  after_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transport_assignment_logs_assignment (assignment_id, created_at),
  KEY idx_transport_assignment_logs_store (store_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 将来のルート最適化連携で追加する想定
-- ALTER TABLE transport_assignments
--   ADD COLUMN route_plan_id BIGINT UNSIGNED DEFAULT NULL AFTER route_group_key;
--
-- ALTER TABLE transport_assignments
--   ADD KEY idx_transport_assignments_store_date_route_plan (store_id, business_date, route_plan_id);

INSERT INTO transport_assignments (
  store_id,
  business_date,
  cast_id,
  pickup_name,
  pickup_address,
  pickup_lat,
  pickup_lng,
  pickup_note,
  pickup_time_from,
  pickup_time_to,
  area_name,
  direction_bucket,
  status,
  driver_user_id,
  vehicle_label,
  sort_order,
  source_type,
  created_at,
  updated_at
) VALUES
  (1, '2026-03-27', 101, 'あい',   '岡山市北区駅元町1-1',     34.6668510, 133.9185100, '駅前ロータリー', '21:30:00', '22:00:00', '岡山駅周辺',     '東',   'pending',     NULL, '1号車', 10, 'manual', NOW(), NOW()),
  (1, '2026-03-27', 102, 'りお',   '岡山市中区東川原88-2',     34.6724800, 133.9522400, '',               '22:00:00', '22:20:00', '東川原',         '東',   'assigned',    21,   '1号車', 20, 'manual', NOW(), NOW()),
  (1, '2026-03-27', 103, 'のあ',   '岡山市南区泉田3-7-15',     34.6305500, 133.9346400, 'マンション前',   '22:10:00', '22:40:00', '泉田',           '南',   'assigned',    21,   '2号車', 30, 'manual', NOW(), NOW()),
  (1, '2026-03-27', 104, 'さき',   '岡山市北区津島新野1-3-8', 34.6906100, 133.9107700, 'コンビニ横',     '22:30:00', '23:00:00', '津島',           '北',   'in_progress', 22,   '2号車', 40, 'manual', NOW(), NOW()),
  (1, '2026-03-27', 105, 'みく',   '岡山市東区西大寺南1-2-3', 34.6555000, 134.0229000, '',               '23:00:00', '23:20:00', '西大寺',         '東',   'done',        22,   '3号車', 50, 'manual', NOW(), NOW()),
  (1, '2026-03-27', 106, 'ゆあ',   '岡山市北区表町2-6-44',     NULL,       NULL,         '座標未登録',     '23:10:00', '23:40:00', '表町',           NULL,   'pending',     NULL, NULL,   60, 'manual', NOW(), NOW()),
  (1, '2026-03-27', 107, 'れい',   '岡山市北区今7-24-18',      34.6488900, 133.9002500, '',               '23:20:00', '23:50:00', '今エリア',       '西',   'cancelled',   NULL, NULL,   70, 'manual', NOW(), NOW()),
  (1, '2026-03-27', 108, 'まな',   '岡山市北区伊福町4-5-9',    34.6817400, 133.9064600, '路地入口',       '23:40:00', '00:10:00', '伊福町',         '北西', 'pending',     NULL, NULL,   80, 'manual', NOW(), NOW());
