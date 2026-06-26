<?php
/**
 * KESA Learn - Admin: Activity Logs
 * Only accessible by super admin
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'logs';
$pageTitle = 'Activity Logs';

// Check permission - Activity logs are super admin only
checkSectionPermission('logs');

// Handle bulk delete - super admin only
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    if (!isSuperAdmin()) {
        setFlash('error', 'Only super admin can delete logs.');
        redirect('/admin/logs/');
    }
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM activity_logs WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        setFlash('success', "$deleted log(s) deleted successfully.");
    }
    redirect('/admin/logs/');
}

// Handle clear all logs - super admin only
if (isset($_POST['clear_all']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    if (!isSuperAdmin()) {
        setFlash('error', 'Only super admin can clear logs.');
        redirect('/admin/logs/');
    }
    $db->exec("DELETE FROM activity_logs");
    setFlash('success', 'All logs cleared.');
    redirect('/admin/logs/');
}

$page = max(1, intval($_GET['page'] ?? 1));
$action = sanitize($_GET['action'] ?? '');
$search = sanitize($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];

if (!empty($action)) {
    $where[] = "al.action = ?";
    $params[] = $action;
}
if (!empty($search)) {
    $where[] = "(al.details LIKE ? OR u.name LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%"]);
}

$whereClause = implode(' AND ', $where);
$total = $db->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id WHERE $whereClause");
$total->execute($params);
$totalCount = $total->fetchColumn();

$offset = ($page - 1) * ADMIN_ITEMS_PER_PAGE;
$stmt = $db->prepare("SELECT al.*, u.name, u.email FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id WHERE $whereClause ORDER BY al.created_at DESC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Handle Excel export - super admin only - MUST be at top before any output
if (isset($_GET['export']) && $_GET['export'] === 'excel' && isSuperAdmin()) {
    try {
        // Set headers BEFORE any output
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="activity-logs-' . date('Y-m-d-H-i-s') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        // Get all activity logs for export (respecting filters)
        $exportQuery = "SELECT al.id, al.action, al.details, al.user_ip, al.created_at, u.name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id WHERE $whereClause ORDER BY al.created_at DESC";
        $exportStmt = $db->prepare($exportQuery);
        $exportStmt->execute($params);
        $exportLogs = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create CSV output
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write header
        $headers = ['Log ID', 'Action', 'Details', 'User', 'IP Address', 'Timestamp'];
        fputcsv($output, $headers);
        
        // Write data rows
        foreach ($exportLogs as $log) {
            $row = [
                $log['id'],
                $log['action'],
                $log['details'],
                $log['name'] ?? 'System',
                $log['user_ip'],
                date('Y-m-d H:i:s', strtotime($log['created_at']))
            ];
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        error_log("Logs export error: " . $e->getMessage());
        setFlash('error', 'Failed to export logs. Please try again.');
    }
}

// Get unique actions for filter
$actions = $db->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/sidebar.php';
?>

<div class="table-header">
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <form method="GET" class="table-search">
            <input type="text" name="q" placeholder="Search logs..." value="<?php echo sanitize($search); ?>" class="admin-search">
            <select name="action" class="form-control" style="width: auto; padding: 10px 16px; font-size: 0.9rem;">
                <option value="">All Actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?php echo sanitize($a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>><?php echo sanitize($a); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-blue">Filter</button>
        </form>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <span style="color: var(--text-muted); font-size: 0.9rem;"><?php echo number_format($totalCount); ?> total logs</span>
        <?php if (isSuperAdmin()): ?>
            <a href="?export=excel<?php echo !empty($action) ? '&action=' . urlencode($action) : ''; ?><?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>" class="btn btn-sm" style="background: #3b82f6; color: white; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                Export
            </a>
        <form method="POST" style="display: inline;" onsubmit="return confirm('Clear ALL logs? This cannot be undone.');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="clear_all" value="1">
            <button type="submit" class="btn btn-sm btn-danger">Clear All</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr><th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th><th>User</th><th>Action</th><th>Details</th><th>IP</th><th>Time</th></tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><input type="checkbox" class="row-checkbox" value="<?php echo $log['id']; ?>"></td>
                    <td>
                        <?php if ($log['name']): ?>
                            <div style="font-weight: 600;">
                                <?php if (!empty($log['user_id'])): ?>
                                    <a href="/admin/users/view?id=<?php echo intval($log['user_id']); ?>" style="color: #3b82f6; text-decoration: none; cursor: pointer;">
                                        <?php echo sanitize($log['name']); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo sanitize($log['name']); ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo sanitize($log['email']); ?></div>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">System</span>
                        <?php endif; ?>
                    </td>
                    <td><code style="font-size: 0.8rem; background: var(--bg-secondary); padding: 2px 8px; border-radius: 4px;"><?php echo sanitize($log['action']); ?></code></td>
                    <td style="max-width: 300px; word-wrap: break-word; font-size: 0.85rem;">
                        <?php 
                            $details = sanitize(truncateText($log['details'] ?? '', 80));
                            // Make registration IDs and user IDs clickable
                            // Check if details contains "registration" keyword to identify registration links
                            if (strpos($log['details'], 'registration') !== false) {
                                // This is a registration-related action, linkify registration #IDs
                                $details = preg_replace_callback('/#(\d+)/', function($matches) {
                                    return '<a href="/admin/registrations/edit?id=' . $matches[1] . '" style="color: var(--blue); text-decoration: none; font-weight: 600;">#' . $matches[1] . '</a>';
                                }, $details);
                            } elseif (preg_match('/#(\d+)/', $details)) {
                                // Check action types to determine if it's user or registration
                                $registrationActions = ['payment_success', 'payment_verified', 'payment_failed', 'payment_rejected'];
                                if (in_array($log['action'], $registrationActions)) {
                                    // Registration-related action
                                    $details = preg_replace_callback('/#(\d+)/', function($matches) {
                                        return '<a href="/admin/registrations/edit?id=' . $matches[1] . '" style="color: var(--blue); text-decoration: none; font-weight: 600;">#' . $matches[1] . '</a>';
                                    }, $details);
                                } else {
                                    // User-related action
                                    $details = preg_replace_callback('/#(\d+)/', function($matches) {
                                        return '<a href="/admin/users/view?id=' . $matches[1] . '" style="color: var(--blue); text-decoration: none; font-weight: 600;">#' . $matches[1] . '</a>';
                                    }, $details);
                                }
                            }
                            echo $details;
                        ?>
                    </td>
                    <td style="font-family: monospace; font-size: 0.8rem;"><?php echo sanitize($log['ip_address'] ?? '-'); ?></td>
                    <td style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap;"><?php echo formatDateTime($log['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php echo paginate($totalCount, ADMIN_ITEMS_PER_PAGE, $page, '/admin/logs/'); ?>

<!-- Bulk Actions Bar - Super Admin Only -->
<?php if (isSuperAdmin()): ?>
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>log(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="logs">
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
<?php else: ?>
    <!-- Disable checkbox selection for non-super admins -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('selectAll');
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            if (selectAll) selectAll.disabled = true;
            rowCheckboxes.forEach(cb => cb.disabled = true);
        });
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
