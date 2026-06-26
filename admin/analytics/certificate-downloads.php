<?php
/**
 * Certificate Downloads Analytics - Admin View
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/tracking.php';

// Check permission
if (!isAdmin() || !hasAdminPermission('logs')) {
    redirect('/admin/');
}

$page = intval($_GET['page'] ?? 1);
$perPage = 50;
$filters = [
    'user_id' => intval($_GET['user_id'] ?? 0) ?: null,
    'ip_address' => sanitize($_GET['ip'] ?? ''),
    'event_id' => intval($_GET['event_id'] ?? 0) ?: null,
    'date_from' => sanitize($_GET['from'] ?? ''),
    'date_to' => sanitize($_GET['to'] ?? '')
];

// Get logs
$logs = getCertificateDownloadsLog($filters, $page, $perPage);

// If filtering by IP, get related users
$relatedUsers = [];
if (!empty($filters['ip_address'])) {
    $relatedUsers = findUsersByIP($filters['ip_address']);
}

include __DIR__ . '/../includes/header.php';
?>

<style>
    .analytics-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
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
    
    .related-users {
        background: var(--bg-secondary);
        padding: 16px;
        border-radius: var(--radius-md);
        margin-bottom: 20px;
        border-left: 4px solid var(--blue);
    }
    
    .user-pill {
        display: inline-block;
        background: var(--blue);
        color: white;
        padding: 8px 12px;
        border-radius: var(--radius-sm);
        margin: 4px;
        font-size: 0.9rem;
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
    
    .ip-link {
        color: var(--blue);
        cursor: pointer;
        text-decoration: underline;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: var(--radius-sm);
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    .badge-user {
        background: #e0f2fe;
        color: #075985;
    }
    
    .badge-guest {
        background: #f3e8ff;
        color: #6b21a8;
    }
</style>

<div style="padding: 24px;">
    <div class="analytics-header">
        <h1>Certificate Downloads</h1>
        <div>
            <span style="color: var(--text-muted); font-size: 0.9rem;">
                Total: <strong><?php echo number_format($logs['total']); ?></strong>
            </span>
        </div>
    </div>
    
    <!-- Filter Section -->
    <form method="GET" class="filter-section">
        <div class="filter-input">
            <label>User ID</label>
            <input type="number" name="user_id" class="form-control" value="<?php echo $filters['user_id'] ?? ''; ?>" placeholder="Filter by user">
        </div>
        
        <div class="filter-input">
            <label>IP Address</label>
            <input type="text" name="ip" class="form-control" value="<?php echo $filters['ip_address'] ?? ''; ?>" placeholder="e.g., 192.168.1.1">
        </div>
        
        <div class="filter-input">
            <label>Event ID</label>
            <input type="number" name="event_id" class="form-control" value="<?php echo $filters['event_id'] ?? ''; ?>" placeholder="Filter by event">
        </div>
        
        <div class="filter-input">
            <label>From Date</label>
            <input type="date" name="from" class="form-control" value="<?php echo $filters['date_from'] ?? ''; ?>">
        </div>
        
        <div class="filter-input">
            <label>To Date</label>
            <input type="date" name="to" class="form-control" value="<?php echo $filters['date_to'] ?? ''; ?>">
        </div>
        
        <div style="display: flex; gap: 8px; align-items: flex-end;">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="?page=1" class="btn btn-secondary">Reset</a>
        </div>
    </form>
    
    <!-- Related Users (if filtering by IP) -->
    <?php if (!empty($relatedUsers) && !empty($filters['ip_address'])): ?>
        <div class="related-users">
            <strong>Users from IP <?php echo sanitize($filters['ip_address']); ?>:</strong>
            <div style="margin-top: 12px;">
                <?php foreach ($relatedUsers as $user): ?>
                    <div class="user-pill">
                        <?php if ($user['id']): ?>
                            <a href="?user_id=<?php echo $user['id']; ?>" style="color: inherit; text-decoration: none;">
                                <?php echo sanitize($user['name']); ?> (<?php echo sanitize($user['email']); ?>)
                            </a>
                        <?php else: ?>
                            <em>Guest from <?php echo date('M d, Y', strtotime($user['first_seen'])); ?></em>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Downloads Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th>Certificate Code</th>
                <th>User / IP</th>
                <th>Event</th>
                <th>Location</th>
                <th>Device</th>
                <th>Downloaded</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs['records'] as $record): ?>
                <tr>
                    <td>
                        <code style="font-size: 0.85rem;"><?php echo sanitize(substr($record['certificate_code'], 0, 20)); ?>...</code>
                    </td>
                    <td>
                        <?php if ($record['user_id']): ?>
                            <span class="badge badge-user">User</span>
                            <a href="?user_id=<?php echo $record['user_id']; ?>" style="text-decoration: none;">
                                <?php echo sanitize($record['name'] ?? 'N/A'); ?><br>
                                <small><?php echo sanitize($record['email'] ?? ''); ?></small>
                            </a>
                        <?php else: ?>
                            <span class="badge badge-guest">Guest</span>
                            <a href="?ip=<?php echo $record['ip_address']; ?>" class="ip-link">
                                <?php echo sanitize($record['ip_address']); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($record['event_title']): ?>
                            <a href="?event_id=<?php echo $record['event_id']; ?>" style="text-decoration: none;">
                                <?php echo sanitize(substr($record['event_title'], 0, 30)); ?>
                            </a>
                        <?php else: ?>
                            <em>Unknown</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($record['city']): ?>
                            <?php echo sanitize($record['city']); ?>, <?php echo sanitize($record['country'] ?? ''); ?>
                        <?php else: ?>
                            <em>N/A</em>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sanitize($record['device_info'] ?? 'Desktop'); ?></td>
                    <td>
                        <small><?php echo date('M d, Y H:i', strtotime($record['downloaded_at'])); ?></small>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <?php if ($logs['total_pages'] > 1): ?>
        <div style="margin-top: 24px; text-align: center;">
            <?php for ($i = 1; $i <= $logs['total_pages']; $i++): ?>
                <a href="?page=<?php echo $i; ?><?php echo !empty($filters['user_id']) ? '&user_id=' . $filters['user_id'] : ''; ?><?php echo !empty($filters['ip_address']) ? '&ip=' . urlencode($filters['ip_address']) : ''; ?>" 
                   class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" 
                   style="margin: 4px;">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
