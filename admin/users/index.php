<?php
/**
 * KESA Learn - Admin: Manage Users
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'users';
$pageTitle = 'Manage Users';

// Handle bulk delete
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    // Remove current admin from deletion list
    $ids = array_diff($ids, [intval($_SESSION['user_id'])]);
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        logActivity('users_bulk_deleted', "Deleted $deleted users");
        setFlash('success', "$deleted user(s) deleted successfully.");
    }
    redirect('/admin/users/');
}

// Handle admin toggle - super admin only
if (isset($_GET['toggle_admin']) && is_numeric($_GET['toggle_admin']) && verifyCSRFToken($_GET['token'] ?? '')) {
    if (!isSuperAdmin()) {
        setFlash('error', 'Only super admin can assign admin roles.');
        redirect('/admin/users/');
    }
    $uid = intval($_GET['toggle_admin']);
    if ($uid !== intval($_SESSION['user_id'])) {
        $user = getUserById($uid);
        $newRole = $user['role'] === 'admin' ? 'user' : 'admin';
        $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $uid]);
        logActivity('role_changed', "Changed user #$uid role to $newRole");
        setFlash('success', "User role updated to $newRole.");
    }
    redirect('/admin/users/');
}

$page = max(1, intval($_GET['page'] ?? 1));
$search = sanitize($_GET['q'] ?? '');
$filterOnline = isset($_GET['online']) && $_GET['online'] === '1' ? true : false;

$where = '1=1';
$params = [];
if (!empty($search)) {
    $where .= " AND (name LIKE ? OR email LIKE ?)";
    $params = ["%$search%", "%$search%"];
}

// Handle Excel export - MUST be at top before any output
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    try {
        // Get all users for export (respecting search filter)
        $exportQuery = "SELECT id, name, email, phone, role, created_at, updated_at FROM users WHERE $where ORDER BY created_at DESC";
        $exportStmt = $db->prepare($exportQuery);
        $exportStmt->execute($params);
        $exportUsers = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers BEFORE any output
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="users-' . date('Y-m-d-H-i-s') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        // Create CSV output
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write header
        $headers = ['User ID', 'Name', 'Email', 'Phone', 'Role', 'Created At', 'Updated At'];
        fputcsv($output, $headers);
        
        // Write data rows
        foreach ($exportUsers as $user) {
            $row = [
                $user['id'],
                $user['name'],
                $user['email'],
                $user['phone'],
                ucfirst($user['role']),
                date('Y-m-d H:i:s', strtotime($user['created_at'])),
                date('Y-m-d H:i:s', strtotime($user['updated_at']))
            ];
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        error_log("Users export error: " . $e->getMessage());
        setFlash('error', 'Failed to export data. Please try again.');
    }
}

// Check if filtering by online status
if ($filterOnline) {
    $where .= " AND id IN (SELECT user_id FROM user_sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE))";
}

// Get online user count (active in last 15 minutes)
$onlineCount = $db->prepare("
    SELECT COUNT(DISTINCT us.user_id) 
    FROM user_sessions us 
    WHERE us.last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
");
$onlineCount->execute();
$totalOnline = $onlineCount->fetchColumn();

$total = $db->prepare("SELECT COUNT(*) FROM users WHERE $where");
$total->execute($params);
$totalCount = $total->fetchColumn();

$offset = ($page - 1) * ADMIN_ITEMS_PER_PAGE;
$stmt = $db->prepare("SELECT * FROM users WHERE $where ORDER BY created_at DESC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

include __DIR__ . '/../includes/sidebar.php';
?>

<div class="table-header">
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 16px;">
        <!-- Online Users Badge -->
        <a href="<?php echo $filterOnline ? '?q=' . urlencode($search) : '?online=1&q=' . urlencode($search); ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: <?php echo $filterOnline ? '#dcfce7' : 'var(--bg-secondary)'; ?>; border: 2px solid <?php echo $filterOnline ? '#22c55e' : 'var(--border-color)'; ?>; border-radius: var(--radius-md); cursor: pointer; text-decoration: none; font-weight: 600; color: <?php echo $filterOnline ? '#16a34a' : 'var(--text-primary)'; ?>; transition: all 0.2s;">
            <span style="width: 10px; height: 10px; background: #22c55e; border-radius: 50%; animation: pulse 2s infinite;"></span>
            Online Now: <?php echo $totalOnline; ?>
        </a>
        <a href="?export=excel<?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>" class="btn btn-info" style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; background: #3b82f6; color: white; border-radius: var(--radius-md); text-decoration: none; font-weight: 600;">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Export to Excel
        </a>
    </div>
    
    <form method="GET" class="table-search">
        <input type="text" name="q" placeholder="Search users..." value="<?php echo sanitize($search); ?>" class="admin-search">
        <?php if ($filterOnline): ?><input type="hidden" name="online" value="1"><?php endif; ?>
        <button type="submit" class="btn btn-sm btn-blue">Search</button>
    </form>
</div>

<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr><th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th><th>Photo</th><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Location</th><th>Role</th><th>Verified</th><th>Joined</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><input type="checkbox" class="row-checkbox" value="<?php echo $u['id']; ?>" <?php echo $u['id'] === intval($_SESSION['user_id']) ? 'disabled title="Cannot delete yourself"' : ''; ?>></td>
                    <td>
                        <?php if (!empty($u['profile_image'])): ?>
                            <a href="/uploads/<?php echo sanitize($u['profile_image']); ?>" target="_blank" download title="Download photo" style="display:inline-block;">
                                <img src="/uploads/<?php echo sanitize($u['profile_image']); ?>" alt="<?php echo sanitize($u['name']); ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color);">
                            </a>
                        <?php else: ?>
                            <div style="width:36px;height:36px;border-radius:50%;background:var(--bg-tertiary);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--blue);font-size:0.8rem;"><?php echo strtoupper(substr($u['name'], 0, 2)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/admin/users/view?id=<?php echo $u['id']; ?>" style="color: var(--blue); text-decoration: none; cursor: pointer; font-family: monospace; font-weight: 600;">
                            #<?php echo $u['id']; ?>
                        </a>
                    </td>
                    <td style="font-weight: 600;">
                        <?php 
                            // Check if user is online
                            $onlineStmt = $db->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
                            $onlineStmt->execute([$u['id']]);
                            $isOnline = $onlineStmt->fetch() ? true : false;
                        ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?php if ($isOnline): ?>
                                <span style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block;"></span>
                            <?php endif; ?>
                            <?php echo sanitize($u['name']); ?>
                        </div>
                    </td>
                    <td><?php echo sanitize($u['email']); ?></td>
                    <td><?php echo sanitize($u['phone'] ?? '-'); ?></td>
                    <td style="font-size: 0.85rem;"><?php echo sanitize(implode(', ', array_filter([$u['city'] ?? '', $u['state'] ?? '', $u['country'] ?? '']))) ?: '-'; ?></td>
                    <td>
                        <?php if ($u['role'] === 'admin'): ?>
                            <span class="badge badge-red">Admin</span>
                        <?php else: ?>
                            <span class="badge badge-blue">User</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $u['email_verified'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-warning">No</span>'; ?>
                    </td>
                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo formatDate($u['created_at']); ?></td>
                    <td>
                        <div class="table-actions">
                            <a href="/admin/users/view?id=<?php echo $u['id']; ?>" class="btn-icon" title="View User">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </a>
                            <?php if (!empty($u['phone'])): ?>
                            <?php $whatsappMessage = urlencode("Hi " . sanitize($u['name']) . ",\n\n"); ?>
                            <a href="https://wa.me/<?php echo preg_replace('/\D/', '', $u['phone']); ?>?text=<?php echo $whatsappMessage; ?>" target="_blank" rel="noopener noreferrer" class="btn-icon" title="Send WhatsApp Message" style="color: #25D366; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M19.07 4.93c-1.97-1.97-4.6-3.06-7.39-3.06-5.75 0-10.45 4.7-10.45 10.45 0 1.84.48 3.64 1.39 5.24l-1.48 5.41 5.54-1.45c1.53.83 3.26 1.27 5.01 1.27 5.75 0 10.45-4.7 10.45-10.45 0-2.79-1.09-5.42-3.07-7.41zM11.68 19.48c-1.56 0-3.09-.41-4.44-1.19l-.32-.19-3.3.86.88-3.21-.21-.33c-.83-1.32-1.27-2.85-1.27-4.42 0-4.79 3.9-8.68 8.68-8.68 2.32 0 4.49.9 6.13 2.55 1.64 1.64 2.55 3.82 2.55 6.13 0 4.79-3.9 8.68-8.68 8.68zm4.71-6.49c-.26-.13-1.53-.75-1.77-.84-.24-.09-.41-.13-.58.13-.17.26-.64.84-.79 1.01-.15.17-.29.19-.55.06-.26-.13-1.1-.41-2.09-1.29-.77-.69-1.29-1.54-1.44-1.8-.15-.26-.02-.4.11-.53.11-.11.26-.29.39-.44.13-.15.17-.26.26-.43.09-.17.04-.31-.02-.44-.06-.13-.58-1.39-.79-1.9-.21-.51-.42-.44-.58-.44-.15 0-.32-.02-.49-.02-.17 0-.44.06-.67.31-.24.26-.91.89-.91 2.17 0 1.28.93 2.51 1.06 2.68.13.17 1.84 2.81 4.46 3.95.62.27 1.11.43 1.49.56.62.2 1.19.17 1.64.1.5-.07 1.53-.63 1.75-1.23.22-.6.22-1.12.15-1.23-.06-.11-.23-.17-.49-.3z"/></svg>
                            </a>
                            <?php endif; ?>
                            <?php if ($u['id'] !== intval($_SESSION['user_id']) && isSuperAdmin()): ?>
                                <a href="?toggle_admin=<?php echo $u['id']; ?>&token=<?php echo generateCSRFToken(); ?>" class="btn-icon <?php echo $u['role'] === 'admin' ? 'danger' : 'success'; ?>" title="<?php echo $u['role'] === 'admin' ? 'Remove Admin' : 'Make Admin'; ?>" data-confirm="Toggle admin role for this user?">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                </a>
                            <?php else: ?>
                                <span style="color: var(--text-light); font-size: 0.8rem;">You</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php echo paginate($totalCount, ADMIN_ITEMS_PER_PAGE, $page, '/admin/users/'); ?>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>user(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="users">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            Delete Selected
        </button>
    </div>
</div>

<form id="bulkDeleteForm" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="bulk_delete" value="1">
    <input type="hidden" name="bulk_ids" id="bulkDeleteIds" value="">
</form>

<script src="/assets/js/admin-bulk-actions.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
