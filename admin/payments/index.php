<?php
/**
 * KESA Learn - Admin: Razorpay Payments
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'payments';
$pageTitle = 'Razorpay Payments';

$page = max(1, intval($_GET['page'] ?? 1));
$search = sanitize($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where = ["payment_method = 'razorpay'"];
$params = [];

if (!empty($search)) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR r.payment_id LIKE ? OR e.title LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if (!empty($status)) {
    $where[] = "r.payment_status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);
$countStmt = $db->prepare("SELECT COUNT(*) FROM registrations r JOIN users u ON r.user_id = u.id JOIN events e ON r.event_id = e.id WHERE $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

$offset = ($page - 1) * ADMIN_ITEMS_PER_PAGE;
$stmt = $db->prepare("SELECT r.id, r.user_id, r.event_id, r.amount, r.payment_id, r.payment_status, r.payment_method, r.registered_at, u.name as user_name, u.email as user_email, e.title as event_title FROM registrations r JOIN users u ON r.user_id = u.id JOIN events e ON r.event_id = e.id WHERE $whereClause ORDER BY r.registered_at DESC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET $offset");
$stmt->execute($params);
$payments = $stmt->fetchAll();

include __DIR__ . '/../includes/sidebar.php';
?>

<div class="table-header">
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <form method="GET" class="table-search">
            <input type="text" name="q" placeholder="Search by name, email, payment ID, event..." value="<?php echo sanitize($search); ?>" class="admin-search">
            <button type="submit" class="btn btn-sm btn-blue">Search</button>
        </form>
        <div class="filter-group">
            <a href="?status=" class="filter-btn <?php echo empty($status) ? 'active' : ''; ?>">All</a>
            <a href="?status=pending" class="filter-btn <?php echo $status === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="?status=paid" class="filter-btn <?php echo $status === 'paid' ? 'active' : ''; ?>">Paid</a>
            <a href="?status=verified" class="filter-btn <?php echo $status === 'verified' ? 'active' : ''; ?>">Verified</a>
            <a href="?status=rejected" class="filter-btn <?php echo $status === 'rejected' ? 'active' : ''; ?>">Rejected</a>
        </div>
    </div>
</div>

<?php if (empty($payments)): ?>
    <div class="empty-state" style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
        <h3>No Razorpay payments found</h3>
        <p>Razorpay transactions will appear here when users pay via Razorpay.</p>
    </div>
<?php else: ?>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr><th>ID</th><th>User</th><th>Event</th><th>Amount</th><th>Method</th><th>Payment ID</th><th>Status</th><th>Date & Time</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td>
                            <a href="/admin/registrations/edit?id=<?php echo $pay['id']; ?>" style="color: var(--blue); text-decoration: none; cursor: pointer; font-family: monospace; font-weight: 600;">
                                #<?php echo $pay['id']; ?>
                            </a>
                        </td>
                        <td>
                            <div style="font-weight: 600;">
                                <a href="/admin/users/view?id=<?php echo $pay['user_id']; ?>" style="color: var(--blue); text-decoration: none; cursor: pointer;">
                                    <?php echo sanitize($pay['user_name']); ?>
                                </a>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                <a href="mailto:<?php echo sanitize($pay['user_email']); ?>" style="color: var(--text-muted); text-decoration: none;">
                                    <?php echo sanitize($pay['user_email']); ?>
                                </a>
                            </div>
                        </td>
                        <td><?php echo sanitize(truncateText($pay['event_title'], 30)); ?></td>
                        <td><?php echo formatPrice($pay['amount']); ?></td>
                        <td><span style="font-size: 0.85rem; background: #f3f4f6; padding: 4px 8px; border-radius: 4px;"><?php echo sanitize(ucfirst($pay['payment_method'] ?? 'N/A')); ?></span></td>
                        <td style="font-family: monospace; font-size: 0.8rem; word-break: break-all;"><?php echo sanitize($pay['payment_id'] ?? '-'); ?></td>
                        <td><?php echo getPaymentStatusBadge($pay['payment_status']); ?></td>
                        <td style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap;">
                            <?php echo formatDateTime($pay['registered_at']); ?>
                        </td>
                        <td>
                            <a href="/admin/registrations/edit?id=<?php echo $pay['id']; ?>" class="btn-icon" title="View/Edit Payment">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginate($total, ADMIN_ITEMS_PER_PAGE, $page, '/admin/payments/'); ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
