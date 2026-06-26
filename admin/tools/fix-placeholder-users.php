<?php
/**
 * Fix Placeholder Users Script
 * 
 * This script identifies and marks users that were created by admin
 * via the past participants bulk upload. These users can then sign up
 * fresh and their registration history will be preserved.
 * 
 * Run this AFTER executing the migration: add_is_placeholder_column.sql
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'users';
$pageTitle = 'Fix Placeholder Users';

$message = '';
$messageType = '';
$placeholderUsers = [];
$affectedCount = 0;

// Check if is_placeholder column exists
try {
    $checkColumn = $db->query("SHOW COLUMNS FROM users LIKE 'is_placeholder'");
    $columnExists = $checkColumn->fetch();
} catch (Exception $e) {
    $columnExists = false;
}

if (!$columnExists) {
    $message = "The 'is_placeholder' column doesn't exist yet. Please run the migration first: sql/migrations/add_is_placeholder_column.sql";
    $messageType = 'error';
} else {
    // Find potential placeholder users (created by past participants upload)
    // Criteria: no phone, no dob, no state, and has registrations
    $stmt = $db->query("
        SELECT u.id, u.email, u.name, u.created_at, u.is_placeholder,
               COUNT(r.id) as registration_count,
               GROUP_CONCAT(DISTINCT e.title SEPARATOR ', ') as events
        FROM users u
        LEFT JOIN registrations r ON u.id = r.user_id
        LEFT JOIN events e ON r.event_id = e.id
        WHERE u.role = 'user'
          AND (u.phone IS NULL OR u.phone = '')
          AND (u.dob IS NULL)
          AND (u.state IS NULL OR u.state = '')
        GROUP BY u.id
        HAVING registration_count > 0
        ORDER BY u.created_at DESC
    ");
    $placeholderUsers = $stmt->fetchAll();

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token.';
            $messageType = 'error';
        } else {
            $action = $_POST['action'];
            
            if ($action === 'mark_selected') {
                $userIds = $_POST['user_ids'] ?? [];
                if (!empty($userIds)) {
                    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                    $stmt = $db->prepare("UPDATE users SET is_placeholder = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($userIds);
                    $affectedCount = $stmt->rowCount();
                    $message = "$affectedCount user(s) marked as placeholder accounts. They can now sign up fresh.";
                    $messageType = 'success';
                }
            } elseif ($action === 'mark_all') {
                // Mark all identified placeholder users
                $stmt = $db->query("
                    UPDATE users u
                    SET u.is_placeholder = 1
                    WHERE u.role = 'user'
                      AND u.is_placeholder = 0
                      AND (u.phone IS NULL OR u.phone = '')
                      AND (u.dob IS NULL)
                      AND (u.state IS NULL OR u.state = '')
                      AND EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = u.id)
                ");
                $affectedCount = $stmt->rowCount();
                $message = "$affectedCount user(s) marked as placeholder accounts. They can now sign up fresh.";
                $messageType = 'success';
            }
            
            // Refresh the list
            $stmt = $db->query("
                SELECT u.id, u.email, u.name, u.created_at, u.is_placeholder,
                       COUNT(r.id) as registration_count,
                       GROUP_CONCAT(DISTINCT e.title SEPARATOR ', ') as events
                FROM users u
                LEFT JOIN registrations r ON u.id = r.user_id
                LEFT JOIN events e ON r.event_id = e.id
                WHERE u.role = 'user'
                  AND (u.phone IS NULL OR u.phone = '')
                  AND (u.dob IS NULL)
                  AND (u.state IS NULL OR u.state = '')
                GROUP BY u.id
                HAVING registration_count > 0
                ORDER BY u.created_at DESC
            ");
            $placeholderUsers = $stmt->fetchAll();
        }
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1><?php echo $pageTitle; ?></h1>
        <a href="/admin/users/" class="btn btn-secondary">Back to Users</a>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : 'success'; ?>" style="margin-bottom: 20px; padding: 16px; border-radius: 8px; background: <?php echo $messageType === 'error' ? '#fee2e2' : '#dcfce7'; ?>; color: <?php echo $messageType === 'error' ? '#dc2626' : '#16a34a'; ?>;">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <div class="card" style="padding: 24px; margin-bottom: 24px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px;">
        <h3 style="margin: 0 0 12px; color: #1e40af;">How This Works</h3>
        <p style="margin: 0; color: #1e3a8a; line-height: 1.6;">
            When admin uploads past participants, the system creates placeholder user accounts with just an email.
            These users cannot log in because they don't know the random password.<br><br>
            <strong>Solution:</strong> Mark these accounts as "placeholder". When they try to sign up with the same email,
            instead of showing "account exists" error, the system will upgrade their placeholder account with their 
            new profile details and password - <strong>while preserving all their past registration history</strong>.
        </p>
    </div>
    
    <?php if (!$columnExists): ?>
        <div class="card" style="padding: 24px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 12px;">
            <h3 style="margin: 0 0 12px; color: #92400e;">Migration Required</h3>
            <p style="color: #78350f;">Run this SQL command in your database first:</p>
            <pre style="background: #1f2937; color: #f3f4f6; padding: 16px; border-radius: 8px; overflow-x: auto;">ALTER TABLE users ADD COLUMN is_placeholder TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified;</pre>
        </div>
    <?php else: ?>
    
    <?php if (empty($placeholderUsers)): ?>
        <div class="card" style="padding: 40px; text-align: center; background: var(--bg-secondary); border-radius: 12px;">
            <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin: 0 auto 16px; color: #22c55e;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <h3 style="margin: 0 0 8px;">No Placeholder Users Found</h3>
            <p style="color: var(--text-muted); margin: 0;">All users with registrations have complete profiles, or have already been marked as placeholders.</p>
        </div>
    <?php else: ?>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <div class="card" style="background: var(--bg-secondary); border-radius: 12px; overflow: hidden;">
            <div style="padding: 16px 24px; background: var(--bg-tertiary); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Potential Placeholder Users (<?php echo count($placeholderUsers); ?>)</h3>
                <button type="submit" name="action" value="mark_all" class="btn btn-primary" onclick="return confirm('Mark all listed users as placeholder accounts?');">
                    Mark All as Placeholder
                </button>
            </div>
            
            <div style="overflow-x: auto;">
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Registrations</th>
                            <th>Events</th>
                            <th>Created</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($placeholderUsers as $user): ?>
                        <tr>
                            <td>
                                <?php if (!$user['is_placeholder']): ?>
                                <input type="checkbox" name="user_ids[]" value="<?php echo $user['id']; ?>">
                                <?php endif; ?>
                            </td>
                            <td><?php echo sanitize($user['email']); ?></td>
                            <td><?php echo sanitize($user['name']); ?></td>
                            <td><span class="badge badge-blue"><?php echo $user['registration_count']; ?></span></td>
                            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo sanitize($user['events']); ?>">
                                <?php echo sanitize($user['events']); ?>
                            </td>
                            <td><?php echo formatDate($user['created_at']); ?></td>
                            <td>
                                <?php if ($user['is_placeholder']): ?>
                                    <span class="badge badge-green">Marked</span>
                                <?php else: ?>
                                    <span class="badge badge-yellow">Needs Marking</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="padding: 16px 24px; background: var(--bg-tertiary); border-top: 1px solid var(--border-color);">
                <button type="submit" name="action" value="mark_selected" class="btn btn-secondary">
                    Mark Selected as Placeholder
                </button>
            </div>
        </div>
    </form>
    
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function toggleAll(checkbox) {
    document.querySelectorAll('input[name="user_ids[]"]').forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
