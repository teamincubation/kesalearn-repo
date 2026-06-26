<?php
/**
 * Incomplete Payments Analytics - Admin View
 * Shows users who started payment but didn't complete
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/tracking.php';

// Check permission
if (!isAdmin() || !hasAdminPermission('logs')) {
    redirect('/admin/');
}

// Mark old abandoned payments
markAbandonedPayments();

$page = intval($_GET['page'] ?? 1);
$perPage = 50;
$filters = [
    'user_id' => intval($_GET['user_id'] ?? 0) ?: null,
    'event_id' => intval($_GET['event_id'] ?? 0) ?: null,
    'status' => sanitize($_GET['status'] ?? '')
];

// Get logs
$logs = getIncompletePaymentsLog($filters, $page, $perPage);

include __DIR__ . '/../includes/header.php';
?>

<style>
    .analytics-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .stat-card {
        background: var(--bg-secondary);
        padding: 20px;
        border-radius: var(--radius-md);
        border-left: 4px solid var(--blue);
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        color: var(--blue);
    }
    
    .stat-label {
        color: var(--text-muted);
        font-size: 0.9rem;
        margin-top: 4px;
    }
    
    .filter-section {
        background: var(--bg-secondary);
        padding: 16px;
        border-radius: var(--radius-md);
        margin-bottom: 20px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }
    
    .filter-input {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .filter-input label {
        font-size: 0.85rem;
        color: var(--text-muted);
        font-weight: 500;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 16px;
    }
    
    .data-table th {
        background: var(--bg-secondary);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid var(--border-color);
    }
    
    .data-table td {
        padding: 12px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .data-table tr:hover {
        background: var(--bg-secondary);
    }
    
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: var(--radius-sm);
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    .badge-initiated {
        background: #fef3c7;
        color: #92400e;
    }
    
    .badge-pending {
        background: #dbeafe;
        color: #1e40af;
    }
    
    .badge-abandoned {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .contact-info {
        font-size: 0.9rem;
        line-height: 1.4;
    }
    
    .amount {
        font-weight: 600;
        color: var(--text-primary);
    }
</style>

<div style="padding: 24px;">
    <div class="analytics-header">
        <h1>Incomplete Payments</h1>
        <div style="color: var(--text-muted); font-size: 0.9rem;">
            <span>Showing <strong><?php echo count($logs['records']); ?></strong> of <strong><?php echo number_format($logs['total']); ?></strong></span>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value">
                <?php
                $db = getDB();
                $stmt = $db->prepare("SELECT SUM(amount) FROM incomplete_payments WHERE status IN ('initiated', 'pending')");
                $stmt->execute();
                $totalAmount = $stmt->fetchColumn() ?? 0;
                echo '₹' . number_format($totalAmount, 2);
                ?>
            </div>
            <div class="stat-label">Potential Revenue Lost</div>
        </div>
        
        <div class="stat-card" style="border-left-color: #f59e0b;">
            <div class="stat-value" style="color: #f59e0b;">
                <?php
                $stmt = $db->prepare("SELECT COUNT(*) FROM incomplete_payments WHERE status = 'initiated'");
                $stmt->execute();
                echo number_format($stmt->fetchColumn());
                ?>
            </div>
            <div class="stat-label">Initiated Payments</div>
        </div>
        
        <div class="stat-card" style="border-left-color: #ef4444;">
            <div class="stat-value" style="color: #ef4444;">
                <?php
                $stmt = $db->prepare("SELECT COUNT(*) FROM incomplete_payments WHERE status = 'abandoned'");
                $stmt->execute();
                echo number_format($stmt->fetchColumn());
                ?>
            </div>
            <div class="stat-label">Abandoned Payments</div>
        </div>
    </div>
    
    <!-- Filter Section -->
    <form method="GET" class="filter-section">
        <div class="filter-input">
            <label>User ID</label>
            <input type="number" name="user_id" class="form-control" value="<?php echo $filters['user_id'] ?? ''; ?>" placeholder="Filter by user">
        </div>
        
        <div class="filter-input">
            <label>Event ID</label>
            <input type="number" name="event_id" class="form-control" value="<?php echo $filters['event_id'] ?? ''; ?>" placeholder="Filter by event">
        </div>
        
        <div class="filter-input">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="">All</option>
                <option value="initiated" <?php echo $filters['status'] === 'initiated' ? 'selected' : ''; ?>>Initiated</option>
                <option value="pending" <?php echo $filters['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="abandoned" <?php echo $filters['status'] === 'abandoned' ? 'selected' : ''; ?>>Abandoned</option>
            </select>
        </div>
        
        <div style="display: flex; gap: 8px; align-items: flex-end;">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="?page=1" class="btn btn-secondary">Reset</a>
        </div>
    </form>
    
    <!-- Payments Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Event</th>
                <th>Amount</th>
                <th>Payment Method</th>
                <th>Status</th>
                <th>Initiated</th>
                <th>Time Since Start</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs['records'] as $record): ?>
                <tr>
                    <td>
                        <div class="contact-info">
                            <strong><?php echo sanitize($record['name'] ?? 'N/A'); ?></strong><br>
                            <small><?php echo sanitize($record['email'] ?? ''); ?></small><br>
                            <?php if (!empty($record['phone'])): ?>
                                <small><?php echo sanitize($record['phone']); ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php echo sanitize(substr($record['event_title'], 0, 40)); ?>
                    </td>
                    <td>
                        <div class="amount">₹<?php echo number_format($record['amount'], 2); ?></div>
                        <small><?php echo sanitize($record['currency']); ?></small>
                    </td>
                    <td>
                        <?php echo sanitize(ucfirst($record['payment_method'] ?? 'N/A')); ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $record['status']; ?>">
                            <?php echo ucfirst($record['status']); ?>
                        </span>
                    </td>
                    <td>
                        <small><?php echo date('M d, Y H:i', strtotime($record['initiated_at'])); ?></small>
                    </td>
                    <td>
                        <?php
                        $initiatedTime = new DateTime($record['initiated_at']);
                        $now = new DateTime();
                        $diff = $now->diff($initiatedTime);
                        
                        if ($diff->days > 0) {
                            echo $diff->days . 'd';
                        } else {
                            echo $diff->h . 'h ' . $diff->i . 'm';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php if (empty($logs['records'])): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <p>No incomplete payments found</p>
        </div>
    <?php endif; ?>
    
    <!-- Pagination -->
    <?php if ($logs['total_pages'] > 1): ?>
        <div style="margin-top: 24px; text-align: center;">
            <?php for ($i = 1; $i <= $logs['total_pages']; $i++): ?>
                <a href="?page=<?php echo $i; ?><?php echo !empty($filters['user_id']) ? '&user_id=' . $filters['user_id'] : ''; ?><?php echo !empty($filters['event_id']) ? '&event_id=' . $filters['event_id'] : ''; ?>" 
                   class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" 
                   style="margin: 4px;">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
