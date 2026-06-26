<?php
/**
 * KESA Learn - Admin: Manage Events
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'events';
$pageTitle = 'Manage Events';

// Handle bulk delete
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM events WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        logActivity('events_bulk_deleted', "Deleted $deleted events");
        setFlash('success', "$deleted event(s) deleted successfully.");
    }
    redirect('/admin/events/');
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if (verifyCSRFToken($_GET['token'] ?? '')) {
        $db->prepare("DELETE FROM events WHERE id = ?")->execute([$_GET['delete']]);
        logActivity('event_deleted', "Event #" . $_GET['delete'] . " deleted");
        setFlash('success', 'Event deleted successfully.');
    }
    redirect('/admin/events/');
}

$page = max(1, intval($_GET['page'] ?? 1));
$search = sanitize($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where = ['1=1'];
$params = [];

if (!empty($search)) {
    $where[] = "title LIKE ?";
    $params[] = "%$search%";
}
if (!empty($status)) {
    $where[] = "status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);

try {
    $total = $db->prepare("SELECT COUNT(*) FROM events WHERE $whereClause");
    $total->execute($params);
    $totalCount = $total->fetchColumn();

    $offset = ($page - 1) * ADMIN_ITEMS_PER_PAGE;
    $stmt = $db->prepare("SELECT * FROM events WHERE $whereClause ORDER BY created_at DESC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET $offset");
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    // Debug: Log the count
    error_log("[v0] Admin events - Total count: " . $totalCount . ", Showing: " . count($events) . ", Page: " . $page);
} catch (Exception $e) {
    error_log("[v0] Admin events query error: " . $e->getMessage());
    $totalCount = 0;
    $events = [];
    $queryError = "Error loading events: " . $e->getMessage();
}

include __DIR__ . '/../includes/sidebar.php';
?>

<div class="table-header">
    <?php if (!empty($queryError)): ?>
        <div style="background: #fee; border: 1px solid #f88; color: #c00; padding: 12px; border-radius: var(--radius-md); margin-bottom: 16px;">
            <strong>Database Error:</strong> <?php echo sanitize($queryError); ?>
        </div>
    <?php endif; ?>
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <form method="GET" class="table-search">
            <input type="text" name="q" placeholder="Search events..." value="<?php echo sanitize($search); ?>" class="admin-search">
            <button type="submit" class="btn btn-sm btn-blue">Search</button>
        </form>
        <div class="filter-group">
            <a href="?status=" class="filter-btn <?php echo empty($status) ? 'active' : ''; ?>">All</a>
            <a href="?status=draft" class="filter-btn <?php echo $status === 'draft' ? 'active' : ''; ?>">Draft</a>
            <a href="?status=published" class="filter-btn <?php echo $status === 'published' ? 'active' : ''; ?>">Published</a>
            <a href="?status=completed" class="filter-btn <?php echo $status === 'completed' ? 'active' : ''; ?>">Completed</a>
        </div>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="/admin/events/past-participants" class="btn btn-secondary" title="Import past event participants">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Past Participants
        </a>
        <a href="/admin/events/bulk-upload" class="btn btn-secondary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            Bulk Upload
        </a>
        <a href="/admin/events/create" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create Event
        </a>
    </div>
</div>

<?php if (empty($events)): ?>
    <div class="empty-state" style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <h3>No events found</h3>
        <p>Create your first event to get started.</p>
        <a href="/admin/events/create" class="btn btn-primary">Create Event</a>
    </div>
<?php else: ?>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th>
                    <th>ID</th>
                    <th>Event</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Seats</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>New Label</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" value="<?php echo $event['id']; ?>"></td>
                        <td>
                            <code style="background: var(--bg-tertiary); padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">#<?php echo $event['id']; ?></code>
                        </td>
                        <td>
                            <div style="font-weight: 600;"><?php echo sanitize(truncateText($event['title'], 40)); ?></div>
                        </td>
                        <td><?php echo getEventTypeBadge($event['type']); ?></td>
                        <td style="font-size: 0.9rem;"><?php echo formatDate($event['start_date']); ?></td>
                        <td>
                            <?php if ($event['max_seats']): ?>
                                <?php echo $event['seats_taken']; ?>/<?php echo $event['max_seats']; ?>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">Unlimited</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatPrice($event['price'], $event['currency']); ?></td>
                        <td><?php echo getEventStatusBadge($event['status']); ?></td>
                        <td>
                            <label class="toggle-switch" title="Toggle 'New' label">
                                <input type="checkbox" class="new-label-toggle" data-event-id="<?php echo $event['id']; ?>" <?php echo !empty($event['is_new']) ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="/admin/events/messages?event_id=<?php echo $event['id']; ?>" class="btn-icon" title="Messages/Meeting Details" style="color: var(--blue);">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                </a>
                                <a href="/admin/events/view?id=<?php echo $event['id']; ?>" class="btn-icon" title="View Details">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                                <a href="/admin/events/edit?id=<?php echo $event['id']; ?>" class="btn-icon" title="Edit">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                <a href="/admin/events/?delete=<?php echo $event['id']; ?>&token=<?php echo generateCSRFToken(); ?>" class="btn-icon danger" title="Delete" data-confirm="Are you sure you want to delete this event?">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginate($totalCount, ADMIN_ITEMS_PER_PAGE, $page, '/admin/events/'); ?>
<?php endif; ?>

<style>
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    cursor: pointer;
}
.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.toggle-slider {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: var(--bg-tertiary);
    border-radius: 24px;
    transition: all 0.3s ease;
}
.toggle-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background: white;
    border-radius: 50%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.toggle-switch input:checked + .toggle-slider {
    background: var(--blue);
}
.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(20px);
}
</style>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>event(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="events">
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

<script>
document.querySelectorAll('.new-label-toggle').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        var eventId = this.dataset.eventId;
        var isNew = this.checked ? 1 : 0;
        
        fetch('/admin/events/toggle-new-label.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'event_id=' + eventId + '&is_new=' + isNew + '&csrf_token=<?php echo generateCSRFToken(); ?>'
        }).then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert('Failed to update');
                toggle.checked = !toggle.checked;
            }
        }).catch(function() {
            alert('Error updating label');
            toggle.checked = !toggle.checked;
        });
    });
});
</script>
<script src="/assets/js/admin-bulk-actions.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
