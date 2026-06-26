#!/usr/bin/env php
<?php
/**
 * Activity Cleanup Maintenance Script
 * Automatically deletes user activities older than 90 days (3 months)
 * 
 * Run this script via cron job:
 * 0 2 * * * /usr/bin/php /path/to/kesa-learn/scripts/cleanup-old-activities.php
 * 
 * This runs at 2 AM every day
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user-tracking.php';

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting activity cleanup...\n";
    
    // Get retention policy
    $stmt = $db->prepare("SELECT retention_days, enabled FROM activity_retention_policy WHERE enabled = TRUE ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $policy = $stmt->fetch();
    
    if (!$policy) {
        echo "No active retention policy found. Using default: 90 days\n";
        $retentionDays = 90;
    } else {
        $retentionDays = $policy['retention_days'];
        echo "Using retention policy: Delete activities older than {$retentionDays} days\n";
    }
    
    // Run cleanup
    $result = deleteOldUserActivity($retentionDays);
    
    if ($result) {
        // Update last cleanup timestamp
        $updateStmt = $db->prepare("
            UPDATE activity_retention_policy 
            SET last_cleanup_at = NOW(),
                next_cleanup_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
            WHERE enabled = TRUE
        ");
        $updateStmt->execute();
        
        echo "[" . date('Y-m-d H:i:s') . "] Activity cleanup completed successfully\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Activity cleanup failed\n";
    }
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
