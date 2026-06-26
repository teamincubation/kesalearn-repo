<?php
/**
 * Database Setup and Migration Script
 * Handles all database schema updates for user tracking, ISP/AS Name, and activity cleanup
 */

// Start output buffering and error handling
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Try to set up database connection
try {
    // Correct path from /admin/tools/ to /includes/
    require_once __DIR__ . '/../../includes/admin_check.php';
    
    if (!isset($db) || !$db) {
        $db = getDB();
    }
    
    if (!$db) {
        throw new Exception("Database connection could not be established");
    }
    
} catch (Exception $e) {
    http_response_code(500);
    die("Error: " . htmlspecialchars($e->getMessage()));
}

$success = true;
$messages = [];

try {
    // ===== STEP 1: Add ISP and AS Name columns to user_visits =====
    $messages[] = "Step 1: Adding ISP and AS Name columns to user_visits...";
    
    $checkIsp = $db->prepare("SHOW COLUMNS FROM `user_visits` LIKE 'isp'");
    $checkIsp->execute();
    
    if ($checkIsp->rowCount() == 0) {
        $db->exec("ALTER TABLE `user_visits` 
                   ADD COLUMN `isp` VARCHAR(255) DEFAULT NULL AFTER `network_type`,
                   ADD COLUMN `as_name` VARCHAR(255) DEFAULT NULL AFTER `isp`,
                   ADD INDEX `idx_isp` (`isp`),
                   ADD INDEX `idx_as_name` (`as_name`)");
        $messages[] = "✓ Successfully added ISP and AS Name columns to user_visits table";
    } else {
        $messages[] = "ℹ ISP and AS Name columns already exist in user_visits table";
    }
    
    // ===== STEP 2: Add deleted_at and deleted_by_admin columns to user_activity_log =====
    $messages[] = "\nStep 2: Adding cleanup columns to user_activity_log...";
    
    $checkDeleted = $db->prepare("SHOW COLUMNS FROM `user_activity_log` LIKE 'deleted_at'");
    $checkDeleted->execute();
    
    if ($checkDeleted->rowCount() == 0) {
        $db->exec("ALTER TABLE `user_activity_log` 
                   ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `created_at`,
                   ADD COLUMN `deleted_by_admin` BOOLEAN DEFAULT FALSE AFTER `deleted_at`,
                   ADD INDEX `idx_deleted_at` (`deleted_at`)");
        $messages[] = "✓ Successfully added cleanup columns to user_activity_log table";
    } else {
        $messages[] = "ℹ Cleanup columns already exist in user_activity_log table";
    }
    
    // ===== STEP 3: Create activity_cleanup_log table =====
    $messages[] = "\nStep 3: Creating activity_cleanup_log table...";
    
    $checkCleanupLog = $db->prepare("SHOW TABLES LIKE 'activity_cleanup_log'");
    $checkCleanupLog->execute();
    
    if ($checkCleanupLog->rowCount() == 0) {
        $db->exec("CREATE TABLE `activity_cleanup_log` (
                   `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                   `user_id` INT UNSIGNED NOT NULL,
                   `activity_count` INT UNSIGNED NOT NULL COMMENT 'Number of activities deleted',
                   `deletion_reason` VARCHAR(100) NOT NULL COMMENT 'auto_cleanup, admin_manual',
                   `deleted_by_admin_id` INT UNSIGNED NULL COMMENT 'Admin user ID if manual deletion',
                   `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   `deleted_from_date` DATE NULL COMMENT 'Activities older than this date were deleted',
                   PRIMARY KEY (`id`),
                   KEY `user_id` (`user_id`),
                   KEY `deleted_at` (`deleted_at`)
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $messages[] = "✓ Successfully created activity_cleanup_log table";
    } else {
        $messages[] = "ℹ activity_cleanup_log table already exists";
    }
    
    // ===== STEP 4: Create activity_retention_policy table =====
    $messages[] = "\nStep 4: Creating activity_retention_policy table...";
    
    $checkPolicy = $db->prepare("SHOW TABLES LIKE 'activity_retention_policy'");
    $checkPolicy->execute();
    
    if ($checkPolicy->rowCount() == 0) {
        $db->exec("CREATE TABLE `activity_retention_policy` (
                   `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                   `retention_days` INT UNSIGNED NOT NULL DEFAULT 90 COMMENT 'Delete activities older than this many days',
                   `enabled` BOOLEAN DEFAULT TRUE,
                   `last_cleanup_at` DATETIME NULL,
                   `next_cleanup_at` DATETIME NULL,
                   `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                   PRIMARY KEY (`id`)
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Insert default retention policy
        $db->exec("INSERT INTO `activity_retention_policy` (`id`, `retention_days`, `enabled`) VALUES (1, 90, TRUE)");
        $messages[] = "✓ Successfully created activity_retention_policy table with default 90-day retention";
    } else {
        $messages[] = "ℹ activity_retention_policy table already exists";
    }
    
    // ===== STEP 5: Add payment fee settings columns to payment_settings =====
    $messages[] = "\nStep 5: Adding fee settings columns to payment_settings...";
    
    $checkGst = $db->prepare("SHOW COLUMNS FROM `payment_settings` LIKE 'gst_percent'");
    $checkGst->execute();
    
    if ($checkGst->rowCount() == 0) {
        $db->exec("ALTER TABLE `payment_settings` 
                   ADD COLUMN `gst_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'GST percentage applied on top of discounted amount (both UPI and Card)',
                   ADD COLUMN `gateway_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Gateway fee % added only for Card/Razorpay payments',
                   ADD COLUMN `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Discount % deducted from base amount before tax (both UPI and Card)'");
        $messages[] = "✓ Successfully added GST %, Gateway Fee %, and Discount % columns to payment_settings table";
        
        // Insert default fees row if not exists
        $checkFees = $db->prepare("SELECT COUNT(*) FROM payment_settings WHERE setting_type = 'fees'");
        $checkFees->execute();
        if ($checkFees->fetchColumn() == 0) {
            $db->exec("INSERT INTO payment_settings (setting_type, gst_percent, gateway_fee_percent, discount_percent, is_active, is_primary) 
                      VALUES ('fees', 0.00, 0.00, 0.00, 1, 1)");
            $messages[] = "✓ Inserted default fees row (all percentages set to 0)";
        }
    } else {
        $messages[] = "ℹ Fee settings columns already exist in payment_settings table";
    }
    

    // ===== STEP 6: Add account_label/label_color to payment_settings + payment_label to registrations =====
    $messages[] = "\nStep 6: Adding account label columns...";

    $chkLabel = $db->prepare("SHOW COLUMNS FROM `payment_settings` LIKE 'account_label'");
    $chkLabel->execute();
    if ($chkLabel->rowCount() == 0) {
        $db->exec("ALTER TABLE `payment_settings`
                   ADD COLUMN `account_label` VARCHAR(80) NULL DEFAULT NULL COMMENT 'Admin label for this payment account',
                   ADD COLUMN `label_color`   VARCHAR(7)  NOT NULL DEFAULT '#6366f1' COMMENT 'Hex colour for label badge'");
        $messages[] = "✓ Added account_label and label_color to payment_settings";
    } else {
        $messages[] = "ℹ account_label column already exists in payment_settings";
    }

    $chkRegLabel = $db->prepare("SHOW COLUMNS FROM `registrations` LIKE 'payment_label'");
    $chkRegLabel->execute();
    if ($chkRegLabel->rowCount() == 0) {
        $db->exec("ALTER TABLE `registrations`
                   ADD COLUMN `payment_label`       VARCHAR(80) NULL DEFAULT NULL COMMENT 'Label of payment account used',
                   ADD COLUMN `payment_label_color` VARCHAR(7)  NULL DEFAULT NULL COMMENT 'Hex colour of label at assignment'");
        $messages[] = "✓ Added payment_label and payment_label_color to registrations";
    } else {
        $messages[] = "ℹ payment_label column already exists in registrations";
    }

    // ===== STEP 7: OTP Auth — mobile_number, whatsapp_number, phone_otps table =====
    $messages[] = "\nStep 7: Adding OTP authentication columns and table...";

    $chkMobile = $db->prepare("SHOW COLUMNS FROM `users` LIKE 'mobile_number'");
    $chkMobile->execute();
    if ($chkMobile->rowCount() == 0) {
        // NOTE: phone column = WhatsApp number. whatsapp_number column intentionally NOT created.
        $db->exec("ALTER TABLE `users`
                   ADD COLUMN `mobile_number`      VARCHAR(20) DEFAULT NULL COMMENT 'Primary mobile/login number (OTP auth)' AFTER `phone`,
                   ADD COLUMN `auth_method`        ENUM('google','otp','both') NOT NULL DEFAULT 'google' COMMENT 'Auth method used' AFTER `mobile_number`,
                   ADD COLUMN `whatsapp_collected` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 once WhatsApp popup completed' AFTER `auth_method`");
        $db->exec("ALTER TABLE `users`
                   ADD INDEX `idx_mobile_number` (`mobile_number`)");
        $messages[] = "✓ Added mobile_number, auth_method, whatsapp_collected to users (phone = WhatsApp number)";
    } else {
        $messages[] = "ℹ mobile_number column already exists in users";
    }

    $db->exec("CREATE TABLE IF NOT EXISTS `phone_otps` (
        `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `mobile`      VARCHAR(20)      NOT NULL,
        `otp_hash`    VARCHAR(255)     NOT NULL,
        `purpose`     ENUM('signup','login') NOT NULL DEFAULT 'login',
        `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at`  DATETIME         NOT NULL,
        `used_at`     DATETIME         DEFAULT NULL,
        `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_mobile_purpose` (`mobile`, `purpose`),
        KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✓ phone_otps table created (or already exists)";

    // ===== STEP 8: Coupon / Offer Management =====
    $messages[] = "\nStep 8: Creating coupon management tables...";

    $db->exec("CREATE TABLE IF NOT EXISTS `coupons` (
        `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `code`                VARCHAR(50)      NOT NULL,
        `name`                VARCHAR(150)     NOT NULL,
        `active_from`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expire_on`           DATETIME         DEFAULT NULL,
        `max_uses_total`      INT UNSIGNED     DEFAULT NULL,
        `max_uses_per_user`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `uses_count`          INT UNSIGNED     NOT NULL DEFAULT 0,
        `applicable_types`    VARCHAR(100)     DEFAULT NULL,
        `scope`               ENUM('all','specific') NOT NULL DEFAULT 'all',
        `discount_type`       ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
        `discount_value`      DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
        `min_purchase_amount` DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
        `visibility`          ENUM('public','private') NOT NULL DEFAULT 'public',
        `is_active`           TINYINT(1)       NOT NULL DEFAULT 1,
        `created_by`          INT UNSIGNED     DEFAULT NULL,
        `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_coupon_code` (`code`),
        KEY `idx_active` (`is_active`, `active_from`, `expire_on`),
        KEY `idx_visibility` (`visibility`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✓ coupons table created (or already exists)";

    $db->exec("CREATE TABLE IF NOT EXISTS `coupon_events` (
        `coupon_id`  INT UNSIGNED NOT NULL,
        `event_id`   INT UNSIGNED NOT NULL,
        PRIMARY KEY (`coupon_id`, `event_id`),
        KEY `idx_event` (`event_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✓ coupon_events table created (or already exists)";

    $db->exec("CREATE TABLE IF NOT EXISTS `coupon_usages` (
        `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `coupon_id`       INT UNSIGNED  NOT NULL,
        `user_id`         INT UNSIGNED  NOT NULL,
        `registration_id` INT UNSIGNED  NOT NULL,
        `event_id`        INT UNSIGNED  NOT NULL,
        `original_amount` DECIMAL(10,2) NOT NULL,
        `discount_amount` DECIMAL(10,2) NOT NULL,
        `final_amount`    DECIMAL(10,2) NOT NULL,
        `used_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_coupon`  (`coupon_id`),
        KEY `idx_user`    (`user_id`),
        KEY `idx_reg`     (`registration_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✓ coupon_usages table created (or already exists)";

    // Add coupon columns to registrations if not present
    $chkCouponCol = $db->prepare("SHOW COLUMNS FROM `registrations` LIKE 'coupon_id'");
    $chkCouponCol->execute();
    if ($chkCouponCol->rowCount() == 0) {
        $db->exec("ALTER TABLE `registrations`
            ADD COLUMN `coupon_id`       INT UNSIGNED  DEFAULT NULL AFTER `amount`,
            ADD COLUMN `coupon_code`     VARCHAR(50)   DEFAULT NULL AFTER `coupon_id`,
            ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `coupon_code`,
            ADD COLUMN `original_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`,
            ADD KEY `idx_reg_coupon` (`coupon_id`)");
        $messages[] = "✓ Added coupon columns to registrations table";
    } else {
        $messages[] = "ℹ coupon columns already exist in registrations";
    }

    // ===== STEP 9: Add 'course' type to events and coupons =====
    $messages[] = "\nStep 9: Adding 'course' event type...";

    try {
        // Check if 'course' already exists in events.type ENUM
        $typeCheck = $db->prepare("SHOW COLUMNS FROM `events` LIKE 'type'");
        $typeCheck->execute();
        $typeRow = $typeCheck->fetch();
        $typeEnum = $typeRow['Type'] ?? '';
        
        if (strpos($typeEnum, "'course'") === false) {
            // Need to modify: use safe approach with temp column
            $db->exec("ALTER TABLE `events` ADD COLUMN `type_tmp` ENUM('webinar','workshop','course','offline','special') NOT NULL DEFAULT 'webinar'");
            $db->exec("UPDATE `events` SET `type_tmp` = `type`");
            $db->exec("ALTER TABLE `events` DROP COLUMN `type`");
            $db->exec("ALTER TABLE `events` CHANGE COLUMN `type_tmp` `type` ENUM('webinar','workshop','course','offline','special') NOT NULL DEFAULT 'webinar'");
            $messages[] = "✓ Added 'course' type to events table";
        } else {
            $messages[] = "ℹ 'course' type already exists in events.type ENUM";
        }
    } catch (Exception $courseEx) {
        $messages[] = "⚠ Could not update events type: " . $courseEx->getMessage();
    }

    $messages[] = "All database migrations completed successfully!";
    $messages[] = str_repeat("=", 60);
    
} catch (Exception $e) {
    $success = false;
    $messages[] = "ERROR: " . $e->getMessage();
    $messages[] = "SQL State: " . $e->getCode();
}

// Output HTML
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { background: white; padding: 20px; border-radius: 8px; max-width: 900px; }
        h2 { color: #1565c0; border-bottom: 2px solid #1565c0; padding-bottom: 10px; }
        pre { background: #f0f0f0; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
    </style>
</head>
<body>
    <div class="container">
        <h2>DATABASE MIGRATION REPORT</h2>
        <pre><?php echo htmlspecialchars(implode("\n", $messages)); ?></pre>
        
        <div style="margin-top: 20px; padding: 15px; background: <?php echo $success ? '#d4edda' : '#f8d7da'; ?>; border-radius: 5px;">
            <p class="<?php echo $success ? 'success' : 'error'; ?>">
                <?php echo $success ? '✓ SUCCESS - All migrations completed!' : '✗ FAILED - Please review errors above'; ?>
            </p>
        </div>
        
        <p style="margin-top: 20px; color: #666; font-size: 0.9em;">
            Next Step: <a href="diagnostics.php">Run Diagnostics</a> to verify system health
        </p>
    </div>
</body>
</html>
<?php
ob_end_flush();
?>
