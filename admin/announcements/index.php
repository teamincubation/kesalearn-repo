<?php
/**
 * KESA Learn - Admin Announcements Management
 */
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();

$db = getDB();
$adminPage = 'announcements';
$pageTitle = 'Announcements';

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM announcements LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {
    $tableExists = false;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Invalid security token.');
        redirect('/admin/announcements/');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('success', 'Announcement deleted successfully.');
        redirect('/admin/announcements/');
    }
    
    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE announcements SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('success', 'Status updated.');
        redirect('/admin/announcements/');
    }
}

// Fetch all announcements
$announcements = [];
if ($tableExists) {
    $announcements = $db->query("SELECT * FROM announcements ORDER BY sort_order ASC, created_at DESC")->fetchAll();
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.announcements-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.ann-table {
    width: 100%;
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.ann-table th, .ann-table td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid var(--border-light);
}

.ann-table th {
    background: var(--bg-secondary);
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ann-table tr:hover {
    background: var(--bg-secondary);
}

.label-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.label-new { background: #dcfce7; color: #166534; }
.label-important { background: #fee2e2; color: #991b1b; }
.label-download { background: #dbeafe; color: #1e40af; }
.label-register { background: #fef3c7; color: #92400e; }
.label-free { background: #f3e8ff; color: #7c3aed; }
.label-update { background: #e0e7ff; color: #4338ca; }
.label-info { background: #f1f5f9; color: #475569; }

.status-active { color: var(--green); }
.status-inactive { color: var(--text-muted); }
.status-scheduled { color: var(--blue); }
.status-expired { color: var(--red); }

.ann-actions {
    display: flex;
    gap: 8px;
}

.ann-content-preview {
    max-width: 250px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.9rem;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-primary);
    border-radius: var(--radius-md);
}

.empty-state svg {
    width: 64px;
    height: 64px;
    color: var(--text-light);
    margin-bottom: 16px;
}

.empty-state h3 {
    margin-bottom: 8px;
    color: var(--text-primary);
}

.empty-state p {
    color: var(--text-muted);
    margin-bottom: 20px;
}

.table-responsive {
    overflow-x: auto;
}
</style>

<div class="admin-content">
    <div class="announcements-header">
        <div>
            <h1 style="margin: 0;">Announcements</h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">Manage homepage marquee notifications</p>
        </div>
        <?php if ($tableExists): ?>
        <a href="/admin/announcements/create" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Announcement
        </a>
        <?php endif; ?>
    </div>
    
    <?php if (!$tableExists): ?>
    <div class="empty-state">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <h3>Database Setup Required</h3>
        <p>The announcements table has not been created yet. Please run the migration script.</p>
        <code style="display: block; background: var(--bg-tertiary); padding: 12px; border-radius: var(--radius-md); font-size: 0.85rem;">
            sql/migrations/add_notifications_table.sql
        </code>
    </div>
    <?php elseif (empty($announcements)): ?>
    <div class="empty-state">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
        <h3>No Announcements Yet</h3>
        <p>Create your first announcement to display on the homepage marquee.</p>
        <a href="/admin/announcements/create" class="btn btn-primary">Create Announcement</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="ann-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Label</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                foreach ($announcements as $ann): 
                    $startDt = new DateTime($ann['start_date'], new DateTimeZone('Asia/Kolkata'));
                    $endDt = new DateTime($ann['end_date'], new DateTimeZone('Asia/Kolkata'));
                    
                    $status = 'inactive';
                    $statusLabel = 'Inactive';
                    if ($ann['is_active']) {
                        if ($now < $startDt) {
                            $status = 'scheduled';
                            $statusLabel = 'Scheduled';
                        } elseif ($now > $endDt) {
                            $status = 'expired';
                            $statusLabel = 'Expired';
                        } else {
                            $status = 'active';
                            $statusLabel = 'Active';
                        }
                    }
                ?>
                <tr>
                    <td>
                        <strong><?php echo sanitize($ann['title']); ?></strong>
                        <div class="ann-content-preview"><?php echo sanitize(truncateText($ann['content'], 50)); ?></div>
                    </td>
                    <td>
                        <span class="label-badge label-<?php echo $ann['label']; ?>">
                            <?php echo ucfirst($ann['label']); ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-size: 0.85rem;">
                            <div><?php echo $startDt->format('M d, Y H:i'); ?></div>
                            <div style="color: var(--text-muted);">to <?php echo $endDt->format('M d, Y H:i'); ?></div>
                        </div>
                    </td>
                    <td>
                        <span class="status-<?php echo $status; ?>" style="font-weight: 500;">
                            <?php echo $statusLabel; ?>
                        </span>
                    </td>
                    <td>
                        <div class="ann-actions">
                            <a href="/admin/announcements/edit?id=<?php echo $ann['id']; ?>" class="btn btn-sm btn-secondary" title="Edit">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-secondary" title="<?php echo $ann['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                    <?php if ($ann['is_active']): ?>
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    <?php else: ?>
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background: #fee2e2; color: #991b1b;" title="Delete">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
