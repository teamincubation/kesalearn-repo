<?php
/**
 * Verification Email System - Setup & Test Page
 * 
 * This page helps set up and test the verification email system.
 * Only accessible to admins.
 */

session_start();

// Check if user is admin
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Access denied - admin only');
}

require_once __DIR__ . '/../../config/database.php';

try {
    $db = getDB();
    
    // Create table if needed
    $db->exec("
        CREATE TABLE IF NOT EXISTS verification_emails (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            email_type ENUM('badge_approved', 'badge_removed') NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_name VARCHAR(255),
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            attempts INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL,
            error_message TEXT,
            KEY (status),
            KEY (created_at)
        )
    ");
    
    $tableExists = true;
} catch (Exception $e) {
    $tableExists = false;
    $tableError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verification Email System Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .box { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { border-left: 4px solid #28a745; }
        .error { border-left: 4px solid #dc3545; }
        .warning { border-left: 4px solid #ffc107; }
        .info { border-left: 4px solid #17a2b8; }
        h2 { margin-bottom: 15px; color: #333; }
        p { margin-bottom: 10px; line-height: 1.6; color: #666; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f9f9f9; font-weight: bold; }
        tr:hover { background: #f5f5f5; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; }
        button:hover { background: #0056b3; }
        input { padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .stat { display: inline-block; margin-right: 30px; }
        .stat-number { font-size: 24px; font-weight: bold; color: #007bff; }
        .stat-label { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Verification Email System</h1>
            <p>Setup and test the automatic verification badge email system</p>
        </div>
        
        <!-- Status Box -->
        <div class="box <?php echo $tableExists ? 'success' : 'error'; ?>">
            <h2>Database Status</h2>
            <?php if ($tableExists): ?>
                <p>✓ verification_emails table is ready</p>
                <p>The database table has been created successfully.</p>
            <?php else: ?>
                <p>✗ Error: <?php echo htmlspecialchars($tableError); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Queue Statistics -->
        <?php if ($tableExists):
            $pending = $db->query("SELECT COUNT(*) as count FROM verification_emails WHERE status = 'pending'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            $sent = $db->query("SELECT COUNT(*) as count FROM verification_emails WHERE status = 'sent'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            $failed = $db->query("SELECT COUNT(*) as count FROM verification_emails WHERE status = 'failed'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        ?>
        <div class="box info">
            <h2>Queue Statistics</h2>
            <div>
                <div class="stat">
                    <div class="stat-number"><?php echo $pending; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat">
                    <div class="stat-number"><?php echo $sent; ?></div>
                    <div class="stat-label">Sent</div>
                </div>
                <div class="stat">
                    <div class="stat-number"><?php echo $failed; ?></div>
                    <div class="stat-label">Failed</div>
                </div>
            </div>
        </div>
        
        <!-- Process Queue -->
        <div class="box warning">
            <h2>Process Email Queue</h2>
            <p>Click the button below to immediately send all pending verification emails:</p>
            <button onclick="processQueue()">Process All Pending Emails</button>
            <div id="processResult"></div>
        </div>
        
        <!-- Recent Emails -->
        <div class="box">
            <h2>Recent Emails in Queue</h2>
            <?php
            $emails = $db->query("SELECT * FROM verification_emails ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            if (count($emails) > 0):
            ?>
                <table>
                    <tr>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Created</th>
                    </tr>
                    <?php foreach ($emails as $email): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($email['recipient_email']); ?></td>
                        <td><?php echo $email['email_type'] === 'badge_approved' ? 'Verified' : 'Removed'; ?></td>
                        <td>
                            <span style="padding: 4px 8px; border-radius: 3px; 
                                <?php echo $email['status'] === 'sent' ? 'background: #d4edda; color: #155724;' : 
                                      ($email['status'] === 'pending' ? 'background: #fff3cd; color: #856404;' : 
                                       'background: #f8d7da; color: #721c24;'); ?>">
                                <?php echo ucfirst($email['status']); ?>
                            </span>
                        </td>
                        <td><?php echo $email['attempts']; ?></td>
                        <td><?php echo date('M d, Y H:i', strtotime($email['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p>No emails in queue yet. They will appear here when verification events occur.</p>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
    </div>
    
    <script>
        function processQueue() {
            const btn = document.querySelector('button');
            const result = document.getElementById('processResult');
            
            btn.disabled = true;
            btn.textContent = 'Processing...';
            result.innerHTML = '';
            
            fetch('/worker/send-verification-emails.php?cron_key=test')
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.textContent = 'Process All Pending Emails';
                    
                    if (data.success) {
                        result.innerHTML = '<p style="color: green; margin-top: 15px;">✓ Processed: ' + data.processed + ' | Sent: ' + data.sent + ' | Failed: ' + data.failed + '</p>';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        result.innerHTML = '<p style="color: red; margin-top: 15px;">✗ Error: ' + (data.error || 'Unknown error') + '</p>';
                    }
                })
                .catch(e => {
                    btn.disabled = false;
                    btn.textContent = 'Process All Pending Emails';
                    result.innerHTML = '<p style="color: red; margin-top: 15px;">✗ Error: ' + e.message + '</p>';
                });
        }
    </script>
</body>
</html>
