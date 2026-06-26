<?php
/**
 * KESA Learn - Admin Dashboard
 */
require_once __DIR__ . '/../includes/admin_check.php';

$db = getDB();
$adminPage = 'dashboard';
$pageTitle = 'Dashboard';

// Stats
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalEvents = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
$totalRegistrations = $db->query("SELECT COUNT(*) FROM registrations")->fetchColumn();
$totalRevenue = $db->query("SELECT COALESCE(SUM(COALESCE(final_amount, amount)), 0) FROM registrations WHERE payment_status IN ('paid','verified')")->fetchColumn();
$pendingPayments = $db->query("SELECT COUNT(*) FROM registrations WHERE payment_status = 'pending' AND payment_method = 'upi'")->fetchColumn();

// Feedback stats - check if table exists first
$avgRating = 0;
$totalFeedbacks = 0;
$recentFeedbacks = [];
try {
    $db->query("SELECT 1 FROM event_ratings LIMIT 1");
    $avgRating = $db->query("SELECT COALESCE(AVG(rating), 0) FROM event_ratings WHERE rating > 0")->fetchColumn();
    $totalFeedbacks = $db->query("SELECT COUNT(*) FROM event_ratings WHERE rating > 0")->fetchColumn();
    $recentFeedbacks = $db->query("SELECT er.*, u.name as user_name, e.title as event_title FROM event_ratings er JOIN users u ON er.user_id = u.id JOIN events e ON er.event_id = e.id WHERE er.rating > 0 ORDER BY er.created_at DESC LIMIT 5")->fetchAll();
} catch (PDOException $e) {
    // Table doesn't exist yet
}

// Monthly registrations for chart
$monthlyRegs = $db->query("SELECT MONTH(registered_at) as month, COUNT(*) as count FROM registrations WHERE YEAR(registered_at) = YEAR(NOW()) GROUP BY MONTH(registered_at)")->fetchAll();
$regData = array_fill(0, 12, 0);
foreach ($monthlyRegs as $m) { $regData[$m['month'] - 1] = $m['count']; }

// Monthly revenue for chart
$monthlyRev = $db->query("SELECT MONTH(registered_at) as month, COALESCE(SUM(COALESCE(final_amount, amount)), 0) as total FROM registrations WHERE YEAR(registered_at) = YEAR(NOW()) AND payment_status IN ('paid','verified') GROUP BY MONTH(registered_at)")->fetchAll();
$revData = array_fill(0, 12, 0);
foreach ($monthlyRev as $m) { $revData[$m['month'] - 1] = floatval($m['total']); }

// Event types
$eventTypes = $db->query("SELECT type, COUNT(*) as count FROM events GROUP BY type")->fetchAll();
$typeLabels = []; $typeValues = [];
foreach ($eventTypes as $t) { $typeLabels[] = ucfirst($t['type']); $typeValues[] = $t['count']; }

// Recent activity
$recentLogs = $db->query("SELECT al.*, u.name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 10")->fetchAll();

// Recent registrations
$recentRegs = $db->query("SELECT r.*, u.name as user_name, e.title as event_title FROM registrations r JOIN users u ON r.user_id = u.id JOIN events e ON r.event_id = e.id ORDER BY r.registered_at DESC LIMIT 5")->fetchAll();

$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>';

include __DIR__ . '/includes/sidebar.php';
?>

<!-- Stats Grid -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Total Users</span>
            <div class="admin-stat-icon" style="background: var(--blue);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
            </div>
        </div>
        <h3><?php echo number_format($totalUsers); ?></h3>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Total Events</span>
            <div class="admin-stat-icon" style="background: var(--purple);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
        </div>
        <h3><?php echo number_format($totalEvents); ?></h3>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Registrations</span>
            <div class="admin-stat-icon" style="background: var(--red);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
        </div>
        <h3><?php echo number_format($totalRegistrations); ?></h3>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Revenue</span>
            <div class="admin-stat-icon" style="background: var(--yellow);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
        <h3><?php echo formatPrice($totalRevenue); ?></h3>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Avg. Rating</span>
            <div class="admin-stat-icon" style="background: #f59e0b;">
                <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            </div>
        </div>
        <h3><?php echo number_format($avgRating, 1); ?> <span style="font-size: 0.8rem; color: var(--text-muted);">/ 5</span></h3>
    </div>
</div>

<?php if ($pendingPayments > 0): ?>
    <div style="background: #fff8e1; border: 1px solid #ffeeba; border-radius: var(--radius-md); padding: 16px 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
        <span style="color: #856404; font-weight: 600;"><?php echo $pendingPayments; ?> manual payment(s) awaiting verification</span>
        <a href="/admin/registrations/?status=pending" class="btn btn-sm btn-yellow">Review Now</a>
    </div>
<?php endif; ?>

<!-- Charts -->
<div class="admin-chart-grid">
    <div class="admin-chart-card">
        <h3>Monthly Registrations (<?php echo date('Y'); ?>)</h3>
        <div class="chart-container">
            <canvas id="registrations-chart"></canvas>
        </div>
    </div>
    <div class="admin-chart-card">
        <h3>Event Types</h3>
        <div class="chart-container">
            <canvas id="event-types-chart"></canvas>
        </div>
    </div>
</div>

<!-- Recent Registrations -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; margin-bottom: 24px;">
    <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.1rem;">Recent Registrations</h3>
        <a href="/admin/registrations/" class="btn btn-sm btn-secondary">View All</a>
    </div>
    <table class="table">
        <thead>
            <tr><th>User</th><th>Event</th><th>Amount</th><th>Status</th><th>When</th></tr>
        </thead>
        <tbody>
            <?php foreach ($recentRegs as $reg): ?>
                <tr>
                    <td style="font-weight: 600;"><?php echo sanitize($reg['user_name']); ?></td>
                    <td><?php echo sanitize(truncateText($reg['event_title'], 30)); ?></td>
                    <td><?php echo formatPrice($reg['amount']); ?></td>
                    <td><?php echo getPaymentStatusBadge($reg['payment_status']); ?></td>
                    <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo timeAgo($reg['registered_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Recent Feedback -->
<?php if (!empty($recentFeedbacks)): ?>
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; margin-bottom: 24px;">
    <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.1rem;">Recent Event Feedback</h3>
        <a href="/admin/feedback/" class="btn btn-sm btn-secondary">View All</a>
    </div>
    <div style="padding: 16px 24px;">
        <?php foreach ($recentFeedbacks as $fb): ?>
        <div style="padding: 12px 0; border-bottom: 1px solid var(--border-light);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                <div>
                    <strong style="color: var(--text-primary);"><?php echo sanitize($fb['user_name']); ?></strong>
                    <span style="color: var(--text-muted); font-size: 0.85rem;"> on <?php echo sanitize(truncateText($fb['event_title'], 25)); ?></span>
                </div>
                <div>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span style="color: <?php echo $i <= $fb['rating'] ? '#fbbf24' : '#ddd'; ?>; font-size: 1rem;">&#9733;</span>
                    <?php endfor; ?>
                </div>
            </div>
            <?php if (!empty($fb['feedback'])): ?>
            <p style="margin: 0; font-size: 0.9rem; color: var(--text-secondary);">"<?php echo sanitize(truncateText($fb['feedback'], 100)); ?>"</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent Activity -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 style="font-size: 1.1rem;">Recent Activity</h3>
        <a href="/admin/logs/" class="btn btn-sm btn-secondary">View All</a>
    </div>
    <ul class="activity-list">
        <?php foreach ($recentLogs as $log): ?>
            <li class="activity-item">
                <div class="activity-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="activity-content">
                    <div class="activity-text">
                        <strong><?php echo sanitize($log['name'] ?? 'System'); ?></strong> - <?php echo sanitize($log['action']); ?>
                        <?php if ($log['details']): ?>
                            <span style="color: var(--text-muted);">: <?php echo sanitize(truncateText($log['details'], 60)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="activity-time"><?php echo timeAgo($log['created_at']); ?></div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
window.chartData = {
    labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
    registrations: <?php echo json_encode(array_values($regData)); ?>
};
window.revenueChartData = {
    labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
    values: <?php echo json_encode(array_values($revData)); ?>
};
window.eventTypesData = {
    labels: <?php echo json_encode($typeLabels ?: ['Webinars','Workshops','Offline','Special']); ?>,
    values: <?php echo json_encode($typeValues ?: [0,0,0,0]); ?>
};
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
