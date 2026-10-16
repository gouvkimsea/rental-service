-- migration_v4.sql: Operational Management, RBAC, Maintenance Inspection, Notifications & Unified Audit
USE `rentalkh`;

-- 1. Add role-based access control column to users
ALTER TABLE `users` 
  ADD COLUMN `role` ENUM('admin', 'rental_manager', 'finance_staff', 'customer') NOT NULL DEFAULT 'customer' AFTER `driver_license`;

-- Sync existing users: admins become 'admin', non-admins become 'customer'
UPDATE `users` SET `role` = 'admin' WHERE `is_admin` = 1;
UPDATE `users` SET `role` = 'customer' WHERE `is_admin` = 0;

-- 2. Enhance vehicle_maintenance table with inspection results, service center, and conflict detection
ALTER TABLE `vehicle_maintenance`
  ADD COLUMN `service_center` VARCHAR(150) NULL AFTER `description`,
  ADD COLUMN `odometer_at_service` INT UNSIGNED NULL AFTER `service_center`,
  ADD COLUMN `inspection_result` ENUM('pending', 'passed', 'passed_with_notes', 'failed') NOT NULL DEFAULT 'pending' AFTER `status`,
  ADD COLUMN `inspection_notes` TEXT NULL AFTER `inspection_result`,
  ADD COLUMN `inspected_by` INT NULL AFTER `inspection_notes`,
  ADD COLUMN `inspected_at` DATETIME NULL AFTER `inspected_by`,
  ADD COLUMN `conflict_detected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `inspected_at`,
  ADD COLUMN `conflict_details` TEXT NULL AFTER `conflict_detected`;

-- 3. Add maintenance_conflict flag to orders table
ALTER TABLE `orders`
  ADD COLUMN `maintenance_conflict` TINYINT(1) NOT NULL DEFAULT 0 AFTER `documents_verified_at`;

-- 4. Create notifications table with idempotency keys
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `target_role` ENUM('all', 'all_staff', 'admin', 'rental_manager', 'finance_staff', 'customer') NOT NULL DEFAULT 'all',
  `notification_type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `reference_id` INT NULL,
  `reference_type` VARCHAR(50) NULL,
  `idempotency_key` VARCHAR(150) NULL UNIQUE,
  `channel` ENUM('internal', 'email_log', 'sms_log') NOT NULL DEFAULT 'internal',
  `status` ENUM('unread', 'read', 'archived') NOT NULL DEFAULT 'unread',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME NULL,
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_target_role` (`target_role`, `status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Create unified system_audit_logs table
CREATE TABLE IF NOT EXISTS `system_audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `actor_id` INT NULL,
  `actor_role` VARCHAR(50) NULL,
  `action_category` ENUM('maintenance', 'booking', 'operational', 'auth_permission', 'financial', 'system') NOT NULL,
  `action_type` VARCHAR(80) NOT NULL,
  `reference_id` INT NULL,
  `reference_type` VARCHAR(50) NULL,
  `details` TEXT NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_actor` (`actor_id`),
  INDEX `idx_category_created` (`action_category`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Insert new operational system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('enable_email_notifications', '0', 'Enable outbound email dispatch log (0=Internal only, 1=Enabled)'),
('enable_sms_notifications', '0', 'Enable outbound SMS dispatch log (0=Internal only, 1=Enabled)'),
('upcoming_pickup_reminder_hours', '24', 'Hours before pickup to trigger upcoming reminder notification'),
('upcoming_return_reminder_hours', '24', 'Hours before return to trigger return reminder notification')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
