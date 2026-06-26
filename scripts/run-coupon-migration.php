<?php
/**
 * Migration: creates coupons, coupon_events, coupon_usages tables
 * and adds coupon tracking columns to registrations.
 * Run once: php scripts/run-coupon-migration.php
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'u806388046_kesawebsite');
define('DB_USER', 'u806388046_kesaweb');
define('DB_PASS', 'Kesa2026IncKesa#admin');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Connected to database.\n";

    // ── 1. coupons ─────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `coupons` (
        `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `code`                VARCHAR(50)      NOT NULL,
        `name`                VARCHAR(150)     NOT NULL,
        `active_from`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expire_on`           DATETIME         DEFAULT NULL,
        `max_uses_total`      INT UNSIGNED     DEFAULT NULL,
        `max_uses_per_user`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `uses_count`          INT UNSIGNED     NOT NULL DEFAULT 0,
        `applicable_types`    VARCHAR(100)     DEFAULT NULL COMMENT 'CSV: webinar,course,workshop',
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
        KEY `idx_active`     (`is_active`, `active_from`, `expire_on`),
        KEY `idx_visibility` (`visibility`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Discount coupons / offer codes'");
    echo "[OK] coupons table\n";

    // ── 2. coupon_events ───────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `coupon_events` (
        `coupon_id` INT UNSIGNED NOT NULL,
        `event_id`  INT UNSIGNED NOT NULL,
        PRIMARY KEY (`coupon_id`, `event_id`),
        KEY `idx_event` (`event_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Maps coupons to specific events (scope=specific)'");
    echo "[OK] coupon_events table\n";

    // ── 3. coupon_usages ───────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `coupon_usages` (
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
        UNIQUE KEY `uq_coupon_reg`  (`coupon_id`, `registration_id`),
        KEY `idx_user`    (`user_id`),
        KEY `idx_coupon`  (`coupon_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Tracks each coupon redemption per registration'");
    echo "[OK] coupon_usages table\n";

    // ── 4. registrations — add coupon tracking columns ─────────────────────────
    $existingCols = $pdo->query("SHOW COLUMNS FROM `registrations`")->fetchAll(PDO::FETCH_COLUMN);

    $colsToAdd = [];
    if (!in_array('coupon_id', $existingCols))
        $colsToAdd[] = "ADD COLUMN `coupon_id`       INT UNSIGNED  DEFAULT NULL COMMENT 'FK → coupons.id' AFTER `amount`";
    if (!in_array('coupon_code', $existingCols))
        $colsToAdd[] = "ADD COLUMN `coupon_code`     VARCHAR(50)   DEFAULT NULL AFTER `coupon_id`";
    if (!in_array('discount_amount', $existingCols))
        $colsToAdd[] = "ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `coupon_code`";
    if (!in_array('original_amount', $existingCols))
        $colsToAdd[] = "ADD COLUMN `original_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`";

    if (!empty($colsToAdd)) {
        $pdo->exec("ALTER TABLE `registrations` " . implode(', ', $colsToAdd));
        echo "[OK] Added coupon columns to registrations: " . implode(', ', array_map(fn($c) => trim(explode(' ', trim(ltrim($c, 'ADD COLUMN ')))[1], '`'), $colsToAdd)) . "\n";
    } else {
        echo "[SKIP] Coupon columns already exist in registrations\n";
    }

    // ── 5. Verify ──────────────────────────────────────────────────────────────
    $tables = ['coupons', 'coupon_events', 'coupon_usages'];
    echo "\nVerification:\n";
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  $t: $count row(s)\n";
    }

    $regCols = $pdo->query("SHOW COLUMNS FROM `registrations`")->fetchAll(PDO::FETCH_COLUMN);
    $couponCols = array_filter($regCols, fn($c) => str_starts_with($c, 'coupon') || $c === 'discount_amount' || $c === 'original_amount');
    echo "  registrations coupon cols: " . implode(', ', $couponCols) . "\n";

    echo "\nCoupon migration completed successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
