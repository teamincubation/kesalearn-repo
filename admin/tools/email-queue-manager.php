<?php
/**
 * Process Verification Email Queue
 * Admin tool to manually process pending emails
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/verification-email-service.php';

// Check admin access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    die('Access denied');
}

$message = '';
$messageType = '';

// Process queue if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $service = new VerificationEmailService();
        $sent = $service->processPendingEmails();
        $message = "Successfully processed $sent emails from the queue.";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Error processing queue: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Get queue stats
try {
    $db = getDB();
    $stats = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
        FROM verification_email_queue
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Get recent emails
    $recent = $db->query("
        SELECT id, user_id, recipient_email, email_type, status, attempts, created_at, sent_at
        FROM verification_email_queue
        ORDER BY created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = [];
    $recent = [];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Process Email Queue - KESA Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0; }
        .stat-card { background: #f9f9f9; padding: 15px; border-radius: 4px; text-align: center; border-left: 4px solid #43d000; }
        .stat-card h3 { margin: 0; color: #666; font-size: 14px; }
        .stat-card .value { font-size: 24px; font-weight: bold; color: #43d000; }
        .message { padding: 12px; margin: 15px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        button { background: #43d000; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #2fa600; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f9f9f9; font-weight: bold; }
        .status { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 12px; }
        .status.pending { background: #fef3c7; color: #92400e; }
        .status.sent { background: #d1fae5; color: #065f46; }
        .status.failed { background: #fee2e2; color: #7f1d1d; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verification Email Queue Manager</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <h3>Total Emails</h3>
                <div class="value"><?php echo $stats['total'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Pending</h3>
                <div class="value" style="color: #f59e0b;"><?php echo $stats['pending'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Sent</h3>
                <div class="value" style="color: #10b981;"><?php echo $stats['sent'] ?? 0; ?></div>
            </div>
            <div class="stat-card">
                <h3>Failed</h3>
                <div class="value" style="color: #ef4444;"><?php echo $stats['failed'] ?? 0; ?></div>
            </div>
        </div>
        
        <form method="POST" style="margin: 20px 0;">
            <button type="submit">Process Pending Emails</button>
        </form>
        
        <h2>Recent Emails</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User ID</th>
                    <th>Email</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $email): ?>
                    <tr>
                        <td><?php echo $email['id']; ?></td>
                        <td><?php echo $email['user_id']; ?></td>
                        <td><?php echo htmlspecialchars($email['recipient_email']); ?></td>
                        <td><?php echo ucwords(str_replace('_', ' ', $email['email_type'])); ?></td>
                        <td><span class="status <?php echo $email['status']; ?>"><?php echo ucfirst($email['status']); ?></span></td>
                        <td><?php echo $email['attempts']; ?>/3</td>
                        <td><?php echo date('M d, Y H:i', strtotime($email['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
