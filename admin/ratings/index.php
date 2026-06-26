<?php
/**
 * KESA Learn - Admin: Event Ratings
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'ratings';
$pageTitle = 'Event Ratings';

// Handle bulk delete
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM event_ratings WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        setFlash('success', "$deleted rating(s) deleted successfully.");
    }
    redirect('/admin/ratings/');
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '') && $_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $db->prepare("DELETE FROM event_ratings WHERE id = ?")->execute([intval($_POST['id'])]);
        setFlash('success', 'Rating deleted.');
        redirect('/admin/ratings/');
    }
}

// Get all ratings with user and event info
$ratings = $db->query("
    SELECT er.*, u.name as user_name, u.email as user_email, e.title as event_title, e.type as event_type
    FROM event_ratings er
    JOIN users u ON er.user_id = u.id
    JOIN events e ON er.event_id = e.id
    ORDER BY er.created_at DESC
")->fetchAll();

// Get average ratings per event
$eventAverages = $db->query("
    SELECT e.id, e.title, e.type, COUNT(er.id) as total_ratings, AVG(er.rating) as avg_rating
    FROM events e
    JOIN event_ratings er ON e.id = er.event_id
    GROUP BY e.id
    ORDER BY avg_rating DESC
")->fetchAll();

include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Average by Event -->
<?php if (!empty($eventAverages)): ?>
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 24px; margin-bottom: 24px;">
    <h3 style="font-size: 1.05rem; margin-bottom: 16px;">Average Ratings by Event</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
        <?php foreach ($eventAverages as $ea): ?>
            <div style="border: 1px solid var(--border-light); border-radius: var(--radius-sm); padding: 16px;">
                <div style="font-weight: 600; margin-bottom: 4px;"><?php echo sanitize($ea['title']); ?></div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <?php echo getEventTypeBadge($ea['type']); ?>
                    <div class="star-display">
                        <?php $avg = round($ea['avg_rating']); for ($i = 1; $i <= 5; $i++): ?>
                            <span <?php echo $i > $avg ? 'class="empty"' : ''; ?>>&#9733;</span>
                        <?php endfor; ?>
                    </div>
                    <span style="font-weight: 700; color: var(--yellow-dark);"><?php echo number_format($ea['avg_rating'], 1); ?></span>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">(<?php echo $ea['total_ratings']; ?> reviews)</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- All Ratings Table -->
<?php if (empty($ratings)): ?>
    <div class="empty-state">
        <h3>No ratings yet</h3>
        <p>Users will rate events after completing them.</p>
    </div>
<?php else: ?>
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden;">
        <table class="table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th>
                    <th>User</th>
                    <th>Event</th>
                    <th>Rating</th>
                    <th>Review</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ratings as $r): ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" value="<?php echo $r['id']; ?>"></td>
                        <td>
                            <strong><?php echo sanitize($r['user_name']); ?></strong><br>
                            <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo sanitize($r['user_email']); ?></span>
                        </td>
                        <td>
                            <?php echo sanitize(truncateText($r['event_title'], 35)); ?><br>
                            <?php echo getEventTypeBadge($r['event_type']); ?>
                        </td>
                        <td>
                            <div class="star-display">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span <?php echo $i > $r['rating'] ? 'class="empty"' : ''; ?>>&#9733;</span>
                                <?php endfor; ?>
                            </div>
                        </td>
                        <td style="max-width: 300px;">
                            <?php echo $r['review'] ? sanitize(truncateText($r['review'], 100)) : '<span style="color: var(--text-muted); font-style: italic;">No review text</span>'; ?>
                        </td>
                        <td><?php echo formatDate($r['created_at']); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this rating?')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background: var(--red); color: #fff;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>rating(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="ratings">
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
