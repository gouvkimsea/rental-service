-- Migration V2: Business Logic Enhancements for RentalKH
-- Preserves existing tables, data, and constraints while adding necessary columns and tables.

-- 1. Enhance products table (Vehicle Inventory)
ALTER TABLE `products`
  ADD COLUMN `reg_number` VARCHAR(50) NULL AFTER `category_id`,
  ADD COLUMN `status` ENUM('available', 'rented', 'maintenance', 'inactive') NOT NULL DEFAULT 'available' AFTER `quantity`,
  ADD COLUMN `condition_status` ENUM('excellent', 'good', 'fair', 'needs_service') NOT NULL DEFAULT 'good' AFTER `status`,
  ADD COLUMN `mileage` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `condition_status`,
  ADD COLUMN `fuel_type` VARCHAR(30) NULL DEFAULT 'Petrol' AFTER `mileage`,
  ADD COLUMN `transmission` VARCHAR(30) NULL DEFAULT 'Automatic' AFTER `fuel_type`,
  ADD COLUMN `seats` INT NOT NULL DEFAULT 2 AFTER `transmission`,
  ADD COLUMN `hourly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`,
  ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `seats`,
  ADD COLUMN `buffer_hours` INT NOT NULL DEFAULT 2 AFTER `is_archived`;

-- Populate initial unique registration numbers for existing vehicles
UPDATE `products` SET `reg_number` = CONCAT('RKH-', LPAD(id, 4, '0')) WHERE `reg_number` IS NULL OR `reg_number` = '';

-- Now add unique index on reg_number
ALTER TABLE `products` ADD UNIQUE KEY `uk_reg_number` (`reg_number`);

-- 2. Enhance users table (Customer Management)
ALTER TABLE `users`
  ADD COLUMN `phone` VARCHAR(30) NULL AFTER `email`,
  ADD COLUMN `address` TEXT NULL AFTER `phone`,
  ADD COLUMN `id_card_number` VARCHAR(50) NULL AFTER `address`,
  ADD COLUMN `driver_license` VARCHAR(50) NULL AFTER `id_card_number`;

-- 3. Enhance orders table (Booking Engine)
ALTER TABLE `orders`
  MODIFY COLUMN `status` ENUM('Pending', 'Approved', 'Active', 'Completed', 'Cancelled', 'Declined', 'Expired') DEFAULT 'Pending',
  ADD COLUMN `booking_ref` VARCHAR(30) NULL AFTER `id`,
  ADD COLUMN `start_time` TIME NOT NULL DEFAULT '09:00:00' AFTER `start_date`,
  ADD COLUMN `end_time` TIME NOT NULL DEFAULT '18:00:00' AFTER `end_date`,
  ADD COLUMN `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
  ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`,
  ADD COLUMN `deposit_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`,
  ADD COLUMN `expires_at` DATETIME NULL AFTER `shipping_address`,
  ADD COLUMN `cancellation_reason` TEXT NULL AFTER `expires_at`,
  ADD COLUMN `cancelled_at` DATETIME NULL AFTER `cancellation_reason`;

-- Backfill booking_ref and subtotal for existing orders
UPDATE `orders` 
SET 
  `booking_ref` = CONCAT('RNT-', DATE_FORMAT(order_date, '%Y%m%d'), '-', LPAD(id, 4, '0')),
  `subtotal` = `total_amount`
WHERE `booking_ref` IS NULL OR `booking_ref` = '';

ALTER TABLE `orders` ADD UNIQUE KEY `uk_booking_ref` (`booking_ref`);

-- 4. Enhance order_items table
ALTER TABLE `order_items`
  ADD COLUMN `rental_days` INT NOT NULL DEFAULT 1 AFTER `quantity`,
  ADD COLUMN `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `rental_days`,
  ADD COLUMN `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`;

-- Backfill order_items
UPDATE `order_items` SET `daily_rate` = `price`, `subtotal` = `quantity` * `price` WHERE `daily_rate` = 0;

-- 5. Create vehicle maintenance table
CREATE TABLE IF NOT EXISTS `vehicle_maintenance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `maintenance_type` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `start_date` DATE NOT NULL,
  `end_date` DATE NULL,
  `status` ENUM('scheduled', 'in_progress', 'completed') NOT NULL DEFAULT 'scheduled',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_maint_product` (`product_id`),
  INDEX `idx_maint_status` (`status`),
  CONSTRAINT `fk_maint_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Create system settings table
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(50) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('reservation_expiry_hours', '24', 'Hours before an unconfirmed pending booking expires'),
('default_buffer_hours', '2', 'Turnaround buffer hours for cleaning and inspection between bookings'),
('min_rental_days', '1', 'Minimum rental duration in days'),
('security_deposit_fixed', '50.00', 'Standard security deposit required per booking ($)'),
('cancellation_cutoff_hours', '24', 'Minimum hours before start date to cancel for full refund')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
