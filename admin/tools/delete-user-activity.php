<?php
/**
 * Admin Tool: Delete User Activity
 * Allows super admin to manually delete user activities for privacy or compliance
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/user-tracking.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /admin/users');
    exit;
}

$adminId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Handle deletion request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $deleteOption = isset($_POST['delete_option']) ? $_POST['delete_option'] : 'all'; // all or older
    $daysBefore = isset($_POST['days_before']) ? (int)$_POST['days_before'] : null;
    
    if ($userId > 0) {
        // Verify user exists
        $userStmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        if ($user) {
            // Delete activities
            if ($deleteOption === 'older' && $daysBefore) {
                $result = deleteUserActivityByAdmin($userId, $adminId, $daysBefore);
                if ($result) {
                    $message = "Successfully deleted activities for user '{$user['name']}' older than {$daysBefore} days";
                    $messageType = 'success';
                } else {
                    $message = "Error deleting activities. Please try again.";
                    $messageType = 'error';
                }
            } elseif ($deleteOption === 'all') {
                $result = deleteUserActivityByAdmin($userId, $adminId);
                if ($result) {
                    $message = "Successfully deleted all activities for user '{$user['name']}'";
                    $messageType = 'success';
                } else {
                    $message = "Error deleting activities. Please try again.";
                    $messageType = 'error';
                }
            }
        } else {
            $message = "User not found";
            $messageType = 'error';
        }
    } else {
        $message = "Please select a valid user";
        $messageType = 'error';
    }
}

// Get all users for selection
$usersStmt = $db->prepare("
    SELECT u.id, u.name, u.email, COUNT(al.id) as activity_count
    FROM users u
    LEFT JOIN user_activity_log al ON u.id = al.user_id AND al.deleted_at IS NULL
    WHERE u.role != 'admin'
    GROUP BY u.id
    ORDER BY u.name ASC
");
$usersStmt->execute();
$users = $usersStmt->fetchAll();

// Get deletion history
$historyStmt = $db->prepare("
    SELECT 
        l.id, l.user_id, l.activity_count, l.deletion_reason, 
        l.deleted_by_admin_id, l.deleted_at, 
        u.name as user_name, a.name as admin_name
    FROM activity_cleanup_log l
    LEFT JOIN users u ON l.user_id = u.id
    LEFT JOIN users a ON l.deleted_by_admin_id = a.id
    ORDER BY l.deleted_at DESC
    LIMIT 50
");
$historyStmt->execute();
$deletionHistory = $historyStmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delete User Activity - Admin Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 8px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #999; margin-bottom: 20px; }
        
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; color: #333; margin-bottom: 8px; }
        select, input { display: block; width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        input[type="radio"] { display: inline-block; width: auto; margin-right: 10px; }
        
        .radio-group { margin-bottom: 16px; }
        .radio-option { margin-bottom: 10px; }
        .radio-option label { margin-bottom: 0; display: inline-block; font-weight: normal; }
        
        .input-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        button { background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        button:hover { background: #c82333; }
        
        .warning { background: #fff3cd; color: #856404; padding: 16px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #ffeaa7; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 30px; }
        th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px; border-bottom: 1px solid #dee2e6; }
        tr:hover { background: #f8f9fa; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge.admin { background: #d1ecf1; color: #0c5460; }
        .badge.auto { background: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Delete User Activity</h1>
        <p class="subtitle">Permanently mark user activities as deleted (for privacy or compliance)</p>
        
        <?php if ($message): ?>
        <div class="alert <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="warning">
            <strong>⚠️ Warning:</strong> This action will mark selected user activities as deleted. This is permanent and logged in the activity cleanup log. Only super admins can perform this action.
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label for="user_id">Select User</label>
                <select name="user_id" id="user_id" required>
                    <option value="">-- Choose a user --</option>
                    <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>">
                        <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['email']); ?>) - <?php echo $user['activity_count']; ?> activities
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Delete Option</label>
                <div class="radio-group">
                    <div class="radio-option">
                        <input type="radio" name="delete_option" id="all" value="all" checked>
                        <label for="all">Delete ALL activities for this user</label>
                    </div>
                    <div class="radio-option">
                        <input type="radio" name="delete_option" id="older" value="older">
                        <label for="older">Delete activities older than (days):</label>
                    </div>
                </div>
            </div>
            
            <div class="form-group input-row">
                <div>
                    <input type="number" name="days_before" placeholder="e.g., 30 (optional)" min="1">
                </div>
                <button type="submit">Delete Activities</button>
            </div>
        </form>
        
        <?php if (!empty($deletionHistory)): ?>
        <h2 style="margin-top: 40px; margin-bottom: 20px;">Deletion History</h2>
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Activities Deleted</th>
                    <th>Reason</th>
                    <th>Deleted By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletionHistory as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['user_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo $log['activity_count']; ?></td>
                    <td>
                        <span class="badge <?php echo $log['deletion_reason'] === 'admin_manual' ? 'admin' : 'auto'; ?>">
                            <?php echo $log['deletion_reason'] === 'admin_manual' ? 'Admin Delete' : 'Auto Cleanup'; ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($log['admin_name'] ?? 'System'); ?></td>
                    <td><?php echo formatTime12Hour($log['deleted_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>
