-- =====================================================
-- HOTSPOT VOUCHER SYSTEM - PRODUCTION MIGRATION
-- =====================================================
-- Version: 1.0.0
-- Date: 2025-12-30
-- SAFE for existing production database!
-- =====================================================

-- Step 1: Create hotspot_profiles table
CREATE TABLE IF NOT EXISTS `hotspot_profiles` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(50) UNIQUE NOT NULL,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `duration` VARCHAR(20) NOT NULL COMMENT 'Format: 3h, 1d, 7d',
    `duration_seconds` INT NOT NULL COMMENT 'Duration in seconds',
    `rate_limit` VARCHAR(50) NULL COMMENT 'Format: 2M/2M',
    `shared_users` INT DEFAULT 1,
    `session_timeout` INT NULL,
    `idle_timeout` INT NULL,
    `validity_type` ENUM('uptime', 'time', 'both') DEFAULT 'uptime',
    `on_login_script` TEXT NULL,
    `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_date` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) DEFAULT 1,
    INDEX `idx_name` (`name`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: Create hotspot_vouchers table
CREATE TABLE IF NOT EXISTS `hotspot_vouchers` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `batch_id` VARCHAR(50) NOT NULL COMMENT 'Format: vc-acslite-YYYYMMDD-HHMMSS',
    `username` VARCHAR(100) UNIQUE NOT NULL,
    `password` VARCHAR(100) NOT NULL,
    `profile` VARCHAR(50) NOT NULL,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `duration` VARCHAR(20) NOT NULL COMMENT 'Format: 3h, 1d, 7d',
    `limit_uptime` INT NULL COMMENT 'Seconds',
    `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sold_date` DATETIME NULL,
    `first_login` DATETIME NULL,
    `last_login` DATETIME NULL,
    `expired_date` DATETIME NULL,
    `status` ENUM('unused', 'sold', 'active', 'expired', 'disabled') DEFAULT 'unused',
    `mac_address` VARCHAR(17) NULL,
    `comment` TEXT NULL,
    `scheduler_name` VARCHAR(100) NULL,
    `mikrotik_comment` TEXT NULL,
    INDEX `idx_batch` (`batch_id`),
    INDEX `idx_profile` (`profile`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_date`),
    INDEX `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: Create voucher_batches table
CREATE TABLE IF NOT EXISTS `voucher_batches` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `batch_id` VARCHAR(50) UNIQUE NOT NULL,
    `profile` VARCHAR(50) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `duration` VARCHAR(20) NOT NULL,
    `prefix` VARCHAR(20) NULL,
    `code_length` INT NOT NULL DEFAULT 6,
    `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` VARCHAR(100) NULL,
    `total_unused` INT DEFAULT 0,
    `total_sold` INT DEFAULT 0,
    `total_active` INT DEFAULT 0,
    `total_expired` INT DEFAULT 0,
    `total_disabled` INT DEFAULT 0,
    `revenue` DECIMAL(10,2) DEFAULT 0,
    `notes` TEXT NULL,
    INDEX `idx_batch` (`batch_id`),
    INDEX `idx_profile` (`profile`),
    INDEX `idx_created` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 4: Create hotspot_sales table
CREATE TABLE IF NOT EXISTS `hotspot_sales` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `voucher_id` INT NOT NULL,
    `batch_id` VARCHAR(50) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `sale_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `price` DECIMAL(10,2) NOT NULL,
    `actual_price` DECIMAL(10,2) NULL COMMENT 'Actual selling price (can be different)',
    `seller` VARCHAR(100) NULL,
    `customer_name` VARCHAR(100) NULL,
    `customer_phone` VARCHAR(20) NULL,
    `payment_method` ENUM('cash', 'transfer', 'qris', 'ewallet', 'other') DEFAULT 'cash',
    `notes` TEXT NULL,
    INDEX `idx_voucher` (`voucher_id`),
    INDEX `idx_batch` (`batch_id`),
    INDEX `idx_sale_date` (`sale_date`),
    INDEX `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 5: Create hotspot_profile_stats table (optional, untuk analytics)
CREATE TABLE IF NOT EXISTS `hotspot_profile_stats` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `profile` VARCHAR(50) NOT NULL,
    `stat_date` DATE NOT NULL,
    `total_generated` INT DEFAULT 0,
    `total_sold` INT DEFAULT 0,
    `total_active` INT DEFAULT 0,
    `total_expired` INT DEFAULT 0,
    `revenue` DECIMAL(10,2) DEFAULT 0,
    `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_date` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_profile_date` (`profile`, `stat_date`),
    INDEX `idx_profile` (`profile`),
    INDEX `idx_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 6: Insert sample hotspot profiles (IGNORE if exists)
INSERT IGNORE INTO `hotspot_profiles` 
(`name`, `price`, `duration`, `duration_seconds`, `rate_limit`, `shared_users`, `validity_type`, `on_login_script`) 
VALUES
('3JAM', 3000.00, '3h', 10800, '2M/2M', 1, 'uptime', ':put ",rem,3000,3h,,,Disable,";'),
('1HARI', 5000.00, '1d', 86400, '2M/2M', 1, 'uptime', ':put ",rem,5000,1d,,,Disable,";'),
('3HARI', 10000.00, '3d', 259200, '2M/2M', 1, 'uptime', ':put ",rem,10000,3d,,,Disable,";'),
('1MINGGU', 20000.00, '7d', 604800, '3M/3M', 1, 'uptime', ':put ",rem,20000,7d,,,Disable,";');

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================

-- Check if tables created successfully
SELECT 
    'hotspot_profiles' as table_name, 
    COUNT(*) as row_count 
FROM hotspot_profiles
UNION ALL
SELECT 
    'hotspot_vouchers', 
    COUNT(*) 
FROM hotspot_vouchers
UNION ALL
SELECT 
    'voucher_batches', 
    COUNT(*) 
FROM voucher_batches
UNION ALL
SELECT 
    'hotspot_sales', 
    COUNT(*) 
FROM hotspot_sales;

-- =====================================================
-- MIGRATION COMPLETE!
-- =====================================================
-- Tables created: 5
-- Sample profiles: 4
-- Status: READY FOR USE
-- =====================================================
