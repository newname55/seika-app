ALTER TABLE transport_assignments
  ADD COLUMN suggested_driver_id BIGINT UNSIGNED NULL AFTER driver_user_id,
  ADD COLUMN suggested_group_id VARCHAR(32) NULL AFTER suggested_driver_id,
  ADD COLUMN suggested_order INT NULL AFTER suggested_group_id;

ALTER TABLE transport_assignments
  ADD KEY idx_transport_assignments_store_date_suggested_driver (store_id, business_date, suggested_driver_id),
  ADD KEY idx_transport_assignments_store_date_suggested_group (store_id, business_date, suggested_group_id),
  ADD KEY idx_transport_assignments_store_date_suggested_order (store_id, business_date, suggested_order);

