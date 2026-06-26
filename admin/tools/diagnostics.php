<?php
/**
 * Comprehensive User Tracking Diagnostic & Fix Script
 * Diagnoses and fixes all user tracking display issues
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
    
    // Try to include user-tracking functions
    $trackingPath = __DIR__ . '/../../includes/user-tracking.php';
    $trackingIncluded = false;
    
    if (file_exists($trackingPath)) {
        require_once $trackingPath;
        $trackingIncluded = true;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    die("Error: " . htmlspecialchars($e->getMessage()));
}

$testUserId = intval($_GET['uid'] ?? 3209);
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Tracking System - Diagnostics</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { background: white; padding: 20px; border-radius: 8px; max-width: 900px; }
        h2 { color: #1565c0; border-bottom: 2px solid #1565c0; padding-bottom: 10px; }
        h3 { color: #333; margin-top: 20px; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .info { color: #17a2b8; }
        pre { background: #f0f0f0; padding: 15px; border-radius: 5px; overflow-x: auto; max-height: 300px; }
        .status { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .status.ok { background: #d4edda; border: 1px solid #c3e6cb; }
        .status.error { background: #f8d7da; border: 1px solid #f5c6cb; }
        .status.warning { background: #fff3cd; border: 1px solid #ffeaa7; }
    </style>
</head>
<body>
    <div class="container">
        <h2>User Tracking System - Diagnostic Report</h2>
        
        <h3>Testing User ID: <?php echo $testUserId; ?> (use ?uid=XXXX to test different user)</h3>
        
        <!-- Check 1: user_visits table structure -->
        <h3>1. Checking user_visits table structure...</h3>
        <?php
        try {
            $stmt = $db->prepare("DESCRIBE user_visits");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            $requiredColumns = ['id', 'user_id', 'ip_address', 'country', 'device_type', 'browser', 'isp', 'as_name', 'visited_at'];
            $missingColumns = array_diff($requiredColumns, $columns);
            
            if (empty($missingColumns)) {
                echo "<div class='status ok'>";
                echo "<p class='success'>✓ All required columns present in user_visits</p>";
                echo "<p>Columns: " . htmlspecialchars(implode(", ", $columns)) . "</p>";
                echo "</div>";
            } else {
                echo "<div class='status warning'>";
                echo "<p class='warning'>⚠ Missing columns: " . htmlspecialchars(implode(", ", $missingColumns)) . "</p>";
                echo "<p>Available: " . htmlspecialchars(implode(", ", $columns)) . "</p>";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='status error'>";
            echo "<p class='error'>✗ Error checking user_visits: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
        ?>
        
        <!-- Check 2: user_activity_log table structure -->
        <h3>2. Checking user_activity_log table structure...</h3>
        <?php
        try {
            $stmt = $db->prepare("DESCRIBE user_activity_log");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            $requiredColumns = ['id', 'user_id', 'action_type', 'action_details', 'page_url', 'created_at'];
            $missingColumns = array_diff($requiredColumns, $columns);
            
            if (empty($missingColumns)) {
                echo "<div class='status ok'>";
                echo "<p class='success'>✓ All required columns present in user_activity_log</p>";
                echo "</div>";
            } else {
                echo "<div class='status warning'>";
                echo "<p class='warning'>⚠ Some columns missing: " . htmlspecialchars(implode(", ", $missingColumns)) . "</p>";
                echo "<p>Available: " . htmlspecialchars(implode(", ", $columns)) . "</p>";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='status error'>";
            echo "<p class='error'>✗ Error checking user_activity_log: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
        ?>
        
        <!-- Check 3: Test data retrieval functions -->
        <h3>3. Testing data retrieval functions...</h3>
        <?php
        if ($trackingIncluded) {
            try {
                $visits = getUserVisitHistory($testUserId, 5);
                echo "<div class='status ok'>";
                echo "<p class='success'>✓ getUserVisitHistory() works - returned " . count($visits) . " records</p>";
                if (count($visits) > 0) {
                    echo "<pre>" . htmlspecialchars(json_encode(array_slice($visits, 0, 1), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "</pre>";
                }
                echo "</div>";
            } catch (Exception $e) {
                echo "<div class='status error'>";
                echo "<p class='error'>✗ getUserVisitHistory() error: " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
            
            try {
                $activities = getUserActivityHistory($testUserId, 5);
                echo "<div class='status ok'>";
                echo "<p class='success'>✓ getUserActivityHistory() works - returned " . count($activities) . " records</p>";
                echo "</div>";
            } catch (Exception $e) {
                echo "<div class='status error'>";
                echo "<p class='error'>✗ getUserActivityHistory() error: " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
            
            try {
                $stats = getUserActivityStats($testUserId);
                echo "<div class='status ok'>";
                echo "<p class='success'>✓ getUserActivityStats() works</p>";
                if ($stats) {
                    echo "<pre>" . htmlspecialchars(json_encode($stats, JSON_PRETTY_PRINT)) . "</pre>";
                }
                echo "</div>";
            } catch (Exception $e) {
                echo "<div class='status error'>";
                echo "<p class='error'>✗ getUserActivityStats() error: " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
        } else {
            echo "<div class='status warning'>";
            echo "<p class='warning'>⚠ Could not load user-tracking.php functions</p>";
            echo "</div>";
        }
        ?>
        
        <!-- Check 4: ISP and AS Name data -->
        <h3>4. Checking for ISP and AS Name data in user_visits...</h3>
        <?php
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) as total,
                       COUNT(CASE WHEN isp IS NOT NULL THEN 1 END) as with_isp,
                       COUNT(CASE WHEN as_name IS NOT NULL THEN 1 END) as with_as_name
                FROM user_visits
                WHERE user_id = ?
            ");
            $stmt->execute([$testUserId]);
            $result = $stmt->fetch();
            
            if ($result['total'] > 0) {
                echo "<div class='status " . ($result['with_isp'] > 0 ? 'ok' : 'warning') . "'>";
                echo "<p>Total visits for user: <strong>" . $result['total'] . "</strong></p>";
                echo "<p>Visits with ISP data: <strong>" . $result['with_isp'] . "</strong></p>";
                echo "<p>Visits with AS Name data: <strong>" . $result['with_as_name'] . "</strong></p>";
                
                if ($result['with_isp'] == 0 && $result['total'] > 0) {
                    echo "<p class='warning'>⚠ ISP and AS Name not being captured yet. Run migration and new visits will capture this data.</p>";
                }
                echo "</div>";
            } else {
                echo "<div class='status warning'>";
                echo "<p class='warning'>ℹ No visits recorded for this user yet</p>";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='status error'>";
            echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
        ?>
        
        <!-- Summary -->
        <div style="margin-top: 30px; padding: 15px; background: #e3f2fd; border-radius: 5px;">
            <h3 style="margin-top: 0;">Next Steps:</h3>
            <ol>
                <li><a href="run-migrations.php" style="color: #1565c0; font-weight: bold;">Run Database Migrations</a> - Adds missing columns and tables</li>
                <li>New user visits will automatically capture ISP and AS Name</li>
                <li>Return to <a href="../users/view.php?id=<?php echo $testUserId; ?>" style="color: #1565c0; font-weight: bold;">User View Page</a> to verify data displays</li>
            </ol>
        </div>
    </div>
</body>
</html>
<?php
ob_end_flush();
?>
