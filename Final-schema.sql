-- ============================================================
-- METROMART — Complete Database Schema v2.0
-- Created: May 2026
-- Purpose: Multi-vendor delivery platform for groceries
-- Database: metromart
-- ============================================================

-- Drop existing database (optional - comment out for safety)
-- DROP DATABASE IF EXISTS metromart;

CREATE DATABASE IF NOT EXISTS `metromart`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `metromart`;

-- ============================================================
-- 1. USERS TABLE (Core authentication)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(180) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','employee','rider','customer') NOT NULL DEFAULT 'customer',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `name` VARCHAR(120),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `force_password_change` TINYINT(1) DEFAULT 0,
  `security_question_id` TINYINT DEFAULT NULL,
  `security_answer_hash` VARCHAR(255) DEFAULT NULL,
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. CATEGORIES TABLE (Product categories)
-- ============================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(60) NOT NULL UNIQUE,
  `name` VARCHAR(80) NOT NULL,
  INDEX `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `categories` (`slug`, `name`) VALUES
('grocery', 'Grocery'),
('fresh', 'Fresh Produce'),
('dairy', 'Dairy & Eggs'),
('meat', 'Meat & Seafood'),
('bakery', 'Bakery'),
('beverages', 'Beverages'),
('snacks', 'Snacks'),
('household', 'Household'),
('personal', 'Personal Care'),
('baby', 'Baby & Kids'),
('pets', 'Pet Supplies');

-- ============================================================
-- 3. MERCHANTS TABLE (Store/shop information)
-- ============================================================
CREATE TABLE IF NOT EXISTS `merchants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `address` VARCHAR(255),
  `latitude` DECIMAL(10,7),
  `longitude` DECIMAL(10,7),
  `contact` VARCHAR(60),
  `image_path` VARCHAR(255),
  `created_by` INT UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_name` (`name`),
  INDEX `idx_location` (`latitude`, `longitude`),
  INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. CUSTOMERS TABLE (Customer profile)
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT UNSIGNED PRIMARY KEY,
  `fname` VARCHAR(60) NOT NULL,
  `lname` VARCHAR(60) NOT NULL,
  `phone` VARCHAR(30),
  `address` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_name` (`fname`, `lname`),
  INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. EMPLOYEES TABLE (Store employees/staff)
-- ============================================================
CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT UNSIGNED PRIMARY KEY,
  `fname` VARCHAR(60) NOT NULL,
  `lname` VARCHAR(60) NOT NULL,
  `phone` VARCHAR(30),
  `position` VARCHAR(80),
  `merchant_id` INT UNSIGNED NOT NULL,
  `profile_image` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`merchant_id`) REFERENCES `merchants`(`id`) ON DELETE CASCADE,
  INDEX `idx_merchant` (`merchant_id`),
  INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. RIDERS TABLE (Delivery personnel)
-- ============================================================
CREATE TABLE IF NOT EXISTS `riders` (
  `id` INT UNSIGNED PRIMARY KEY,
  `fname` VARCHAR(60) NOT NULL,
  `lname` VARCHAR(60) NOT NULL,
  `phone` VARCHAR(30),
  `vehicle_type` ENUM('motorcycle','bicycle','car') DEFAULT 'motorcycle',
  `rider_status` ENUM('active','busy','offline') DEFAULT 'offline',
  `current_lat` DECIMAL(10,7),
  `current_lng` DECIMAL(10,7),
  `profile_image` VARCHAR(255),
  `wallet_balance` DECIMAL(10,2) DEFAULT 0.00,
  `pending_cashouts` DECIMAL(10,2) DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_status` (`rider_status`),
  INDEX `idx_location` (`current_lat`, `current_lng`),
  INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. PRODUCTS TABLE (Items for sale)
-- ============================================================
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL,
  `category_id` VARCHAR(60) DEFAULT NULL,
  `category_ids` JSON DEFAULT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` TEXT,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `qty` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('Available','Out of Stock') NOT NULL DEFAULT 'Out of Stock',
  `image_path` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`merchant_id`) REFERENCES `merchants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`slug`) ON DELETE SET NULL,
  INDEX `idx_merchant` (`merchant_id`),
  INDEX `idx_category` (`category_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TRIGGER: Auto-update product status based on quantity
-- ============================================================
DROP TRIGGER IF EXISTS `trg_product_status_insert`;
DROP TRIGGER IF EXISTS `trg_product_status_update`;

DELIMITER //

CREATE TRIGGER `trg_product_status_insert`
BEFORE INSERT ON `products`
FOR EACH ROW
BEGIN
  SET NEW.status = IF(NEW.qty > 0, 'Available', 'Out of Stock');
END//

CREATE TRIGGER `trg_product_status_update`
BEFORE UPDATE ON `products`
FOR EACH ROW
BEGIN
  SET NEW.status = IF(NEW.qty > 0, 'Available', 'Out of Stock');
END//

DELIMITER ;

-- ============================================================
-- 8. ORDERS TABLE (Customer orders)
-- ============================================================
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NOT NULL,
  `merchant_id` INT UNSIGNED NOT NULL,
  `rider_id` INT UNSIGNED,
  `status` ENUM('Pending','Ready for Delivery','Delivering','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `tip_amount` DECIMAL(8,2) DEFAULT 0.00,
  `delivery_fee` DECIMAL(8,2) DEFAULT 50.00,
  `pay_method` ENUM('cod','ewallet','online_banking') DEFAULT 'cod',
  `pay_status` ENUM('Pending','Paid') DEFAULT 'Pending',
  `delivery_address` TEXT,
  `delivery_lat` DECIMAL(10,7),
  `delivery_lng` DECIMAL(10,7),
  `delivery_attempts` TINYINT UNSIGNED DEFAULT 0,
  `stock_deducted` TINYINT(1) DEFAULT 0,
  `notes` TEXT,
  `ordered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `delivered_at` TIMESTAMP NULL,
  `arrived_at` TIMESTAMP NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`merchant_id`) REFERENCES `merchants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE SET NULL,
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_merchant` (`merchant_id`),
  INDEX `idx_rider` (`rider_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_ordered_at` (`ordered_at`),
  INDEX `idx_customer_status` (`customer_id`, `status`),
  INDEX `idx_merchant_status` (`merchant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. ORDER_DETAILS TABLE (Items in each order)
-- ============================================================
CREATE TABLE IF NOT EXISTS `order_details` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `qty` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `status` ENUM('Active','Cancelled') NOT NULL DEFAULT 'Active',
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  INDEX `idx_order` (`order_id`),
  INDEX `idx_product` (`product_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TRIGGER: Calculate subtotal automatically
-- ============================================================
DROP TRIGGER IF EXISTS `trg_order_detail_insert`;
DROP TRIGGER IF EXISTS `trg_order_detail_update`;

DELIMITER //

CREATE TRIGGER `trg_order_detail_insert`
BEFORE INSERT ON `order_details`
FOR EACH ROW
BEGIN
  SET NEW.subtotal = NEW.qty * NEW.unit_price;
END//

CREATE TRIGGER `trg_order_detail_update`
BEFORE UPDATE ON `order_details`
FOR EACH ROW
BEGIN
  SET NEW.subtotal = NEW.qty * NEW.unit_price;
END//

DELIMITER ;

-- ============================================================
-- 10. PASSWORD_RESET_TOKENS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `security_answer` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_token` (`token`),
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. SECURITY_QUESTIONS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `security_questions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL UNIQUE,
  `question_id` TINYINT NOT NULL,
  `answer_hash` VARCHAR(255) NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. RIDER_EARNINGS TABLE (Earnings per delivery)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rider_earnings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rider_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NOT NULL,
  `base_fee` DECIMAL(8,2) DEFAULT 50.00,
  `tip_amount` DECIMAL(8,2) DEFAULT 0.00,
  `total_earned` DECIMAL(8,2) GENERATED ALWAYS AS (`base_fee` + `tip_amount`) STORED,
  `earned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  INDEX `idx_rider` (`rider_id`),
  INDEX `idx_order` (`order_id`),
  INDEX `idx_earned_at` (`earned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. RIDER_CASHOUTS TABLE (Withdrawal requests)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rider_cashouts` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `rider_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `receive_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL,
  FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE CASCADE,
  INDEX `idx_rider` (`rider_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. RIDER_WALLET_LOG TABLE (Transaction history)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rider_wallet_log` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `rider_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `type` ENUM('delivery_fee', 'tip', 'cashout', 'adjustment') DEFAULT 'delivery_fee',
  `description` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_rider_log` (`rider_id`),
  INDEX `idx_order_log` (`order_id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. RIDER_REPORTS TABLE (Customers report riders)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rider_reports` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reporter_id` INT UNSIGNED NOT NULL,
  `rider_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED,
  `reason` ENUM('fake_delivery','rude_behavior','late_delivery','wrong_items','other') NOT NULL,
  `details` TEXT,
  `status` ENUM('pending','reviewed','dismissed') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`reporter_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_rider` (`rider_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. CUSTOMER_REPORTS TABLE (Riders report customers)
-- ============================================================
CREATE TABLE IF NOT EXISTS `customer_reports` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reporter_id` INT UNSIGNED NOT NULL COMMENT 'rider user id',
  `customer_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED,
  `reason` ENUM('fake_address','no_answer','refused_delivery','fraud','other') NOT NULL,
  `details` TEXT,
  `customer_reply` TEXT,
  `customer_replied_at` TIMESTAMP NULL,
  `status` ENUM('pending','reviewed','dismissed') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`reporter_id`) REFERENCES `riders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. DAILY_SALES TABLE (Sales analytics/reporting)
-- ============================================================
CREATE TABLE IF NOT EXISTS `daily_sales` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `merchant_id` INT UNSIGNED NOT NULL,
  `sale_date` DATE NOT NULL,
  `total_orders` INT UNSIGNED DEFAULT 0,
  `total_revenue` DECIMAL(12,2) DEFAULT 0.00,
  UNIQUE KEY `uq_merchant_date` (`merchant_id`, `sale_date`),
  FOREIGN KEY (`merchant_id`) REFERENCES `merchants`(`id`) ON DELETE CASCADE,
  INDEX `idx_merchant` (`merchant_id`),
  INDEX `idx_date` (`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. NOTIFICATIONS TABLE (User notifications)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('order_update','tip_received','report_filed','password_change','system') DEFAULT 'system',
  `title` VARCHAR(120) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_is_read` (`is_read`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. ADMIN_NOTIFICATIONS TABLE (Admin-specific alerts)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type` VARCHAR(50) NOT NULL,
  `rider_id` INT UNSIGNED DEFAULT NULL,
  `order_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(160) NOT NULL,
  `message` TEXT NOT NULL,
  `priority` ENUM('low','medium','high','critical') DEFAULT 'medium',
  `is_read` TINYINT(1) DEFAULT 0,
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_type` (`type`),
  INDEX `idx_rider` (`rider_id`),
  INDEX `idx_order` (`order_id`),
  INDEX `idx_priority` (`priority`),
  INDEX `idx_is_read` (`is_read`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. DELIVERY_CONFIG TABLE (System configuration)
-- ============================================================
CREATE TABLE IF NOT EXISTS `delivery_config` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `base_fee` DECIMAL(8,2) DEFAULT 50.00,
  `per_km_fee` DECIMAL(8,2) DEFAULT 10.00,
  `free_delivery_threshold` DECIMAL(8,2) DEFAULT 500.00,
  `max_delivery_km` DECIMAL(6,2) DEFAULT 15.00,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `delivery_config` (id, base_fee, per_km_fee, free_delivery_threshold, max_delivery_km) 
VALUES (1, 50.00, 10.00, 500.00, 15.00);

-- ============================================================
-- INITIAL ADMIN USER
-- ============================================================
-- Default admin user: admin@metromart.com
-- Password: admin24242844 (bcrypt hash)

INSERT INTO `users` (`email`, `password`, `role`, `status`, `name`)
VALUES (
  'admin@metromart.com',
  '$2y$12$ZFcWfJnVtqTDXQyRirx/Iuzo7dm1CoTBcc5xwT7Ocz.iGVU9WAMO6',
  'admin',
  'active',
  'MetroMart Admin'
)
ON DUPLICATE KEY UPDATE
  `password` = VALUES(`password`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`),
  `name` = VALUES(`name`);

-- ============================================================
-- SCHEMA SETUP COMPLETE ✅
-- ============================================================
-- Tables Created: 20
-- Triggers Created: 4
-- Indexes Created: 45+
-- Auto-insert: 1 admin user + 11 product categories
--
-- Database is ready for:
-- ✅ User authentication (admin, employee, rider, customer)
-- ✅ Multi-vendor order management  
-- ✅ Product catalog with categories
-- ✅ Order tracking and delivery
-- ✅ Rider wallet & earnings system
-- ✅ Reporting system (customer & rider reports)
-- ✅ Notification system (user & admin)
-- ✅ Sales analytics & reporting
-- ✅ Delivery configuration
-- ============================================================
