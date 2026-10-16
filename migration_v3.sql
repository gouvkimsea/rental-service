-- Migration V3: Rental Operations, Financial Ledger, Handover/Return, and Audit Trail

-- 1. Enhance orders table with operational lifecycle and financial balances
ALTER TABLE `orders`
  ADD COLUMN `documents_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN `documents_verified_by` INT NULL AFTER `documents_verified`,
  ADD COLUMN `documents_verified_at` DATETIME NULL AFTER `documents_verified_by`,
  ADD COLUMN `handover_at` DATETIME NULL AFTER `documents_verified_at`,
  ADD COLUMN `handover_by` INT NULL AFTER `handover_at`,
  ADD COLUMN `handover_odometer` INT UNSIGNED NULL AFTER `handover_by`,
  ADD COLUMN `handover_fuel_level` VARCHAR(20) NULL DEFAULT 'Full' AFTER `handover_odometer`,
  ADD COLUMN `handover_notes` TEXT NULL AFTER `handover_fuel_level`,
  ADD COLUMN `return_at` DATETIME NULL AFTER `handover_notes`,
  ADD COLUMN `return_by` INT NULL AFTER `return_at`,
  ADD COLUMN `return_odometer` INT UNSIGNED NULL AFTER `return_by`,
  ADD COLUMN `return_fuel_level` VARCHAR(20) NULL AFTER `return_odometer`,
  ADD COLUMN `return_notes` TEXT NULL AFTER `return_fuel_level`,
  ADD COLUMN `late_hours` INT NOT NULL DEFAULT 0 AFTER `return_notes`,
  ADD COLUMN `late_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `late_hours`,
  ADD COLUMN `fuel_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `late_fee`,
  ADD COLUMN `damage_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `fuel_fee`,
  ADD COLUMN `deposit_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `damage_fee`,
  ADD COLUMN `deposit_refunded` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `deposit_deducted`,
  ADD COLUMN `deposit_status` ENUM('not_required', 'pending', 'held', 'partially_refunded', 'fully_refunded', 'forfeited') NOT NULL DEFAULT 'pending' AFTER `deposit_refunded`,
  ADD COLUMN `payment_status` ENUM('unpaid', 'partially_paid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid' AFTER `deposit_status`,
  ADD COLUMN `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `payment_status`,
  ADD COLUMN `cancellation_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `paid_amount`,
  ADD COLUMN `cancellation_refund` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `cancellation_fee`;

-- Backfill initial operational & payment statuses for existing orders
UPDATE `orders` 
SET 
  `payment_status` = CASE 
      WHEN `status` = 'Completed' THEN 'paid' 
      WHEN `status` = 'Active' THEN 'paid'
      WHEN `status` = 'Cancelled' THEN 'refunded'
      ELSE 'unpaid' 
  END,
  `paid_amount` = CASE 
      WHEN `status` IN ('Completed', 'Active') THEN `total_amount` 
      ELSE 0.00 
  END,
  `deposit_status` = CASE 
      WHEN `status` = 'Completed' THEN 'fully_refunded'
      WHEN `status` = 'Active' THEN 'held'
      WHEN `status` = 'Cancelled' THEN 'fully_refunded'
      ELSE 'pending'
  END,
  `deposit_refunded` = CASE 
      WHEN `status` = 'Completed' AND `deposit_amount` > 0 THEN `deposit_amount`
      ELSE 0.00
  END;

-- 2. Create payments table (The Payment Ledger)
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
  `payment_type` ENUM('rental_payment', 'security_deposit', 'additional_charge', 'damage_fee', 'late_fee', 'refund', 'deposit_refund') NOT NULL,
  `payment_method` ENUM('cash', 'bank_transfer', 'credit_card', 'aba_pay', 'other') NOT NULL,
  `payment_reference` VARCHAR(100) NOT NULL UNIQUE,
  `status` ENUM('pending', 'verified', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  `staff_id` INT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `verified_at` DATETIME NULL,
  INDEX `idx_pay_order` (`order_id`),
  INDEX `idx_pay_status` (`status`),
  INDEX `idx_pay_type` (`payment_type`),
  CONSTRAINT `fk_pay_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pay_staff` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create financial audit logs table
CREATE TABLE IF NOT EXISTS `financial_audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NULL,
  `actor_id` INT NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10,2) NULL DEFAULT 0.00,
  `reason` TEXT NOT NULL,
  `metadata` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_order` (`order_id`),
  INDEX `idx_audit_actor` (`actor_id`),
  INDEX `idx_audit_action` (`action_type`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Insert operational and financial system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('late_fee_per_hour', '10.00', 'Late return penalty fee per hour after grace period ($)'),
('late_grace_period_minutes', '30', 'Grace period in minutes before late charges begin'),
('fuel_fee_per_quarter', '15.00', 'Fee per 1/4 tank of missing fuel upon vehicle return ($)'),
('cancellation_fee_fixed', '10.00', 'Standard administrative cancellation fee ($)'),
('require_documents_for_handover', '1', 'Require verified driver license and ID before handing over vehicle (1=yes, 0=no)'),
('require_deposit_for_handover', '1', 'Require verified security deposit before handing over vehicle (1=yes, 0=no)'),
('allow_partial_payments', '1', 'Allow customers to make partial payments toward rental total (1=yes, 0=no)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
