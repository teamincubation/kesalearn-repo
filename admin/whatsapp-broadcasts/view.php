<?php
/**
 * KESA Learn - Admin: View WhatsApp Broadcast
 * Display broadcast details and send queue status
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'whatsapp-broadcasts';
$pageTitle = 'View Broadcast';

// Only super admin can access
if (!isSuperAdmin()) {
    setFlash('error', 'Only super admin can access this.');
    redirect('/admin/');
}

$broadcastId = intval($_GET['id'] ?? 0);
if (!$broadcastId) redirect('/admin/whatsapp-broadcasts/');

// Get broadcast details
try {
    $broadcastStmt = $db->prepare("
        SELECT wb.*, u.name as admin_name, u.email as admin_email, e.title as event_name
        FROM whatsapp_broadcasts wb
        LEFT JOIN users u ON wb.admin_id = u.id
        LEFT JOIN events e ON wb.event_id = e.id
        WHERE wb.id = ?
    ");
    $broadcastStmt->execute([$broadcastId]);
    $broadcast = $broadcastStmt->fetch();
} catch (Exception $e) {
    // Tables don't exist yet
    setFlash('error', 'WhatsApp broadcasting requires database setup. Please run the migration.');
    redirect('/admin/whatsapp-broadcasts/');
}

if (!$broadcast) {
    setFlash('error', 'Broadcast not found.');
    redirect('/admin/whatsapp-broadcasts/');
}

// Handle send action (requires super admin approval)
if (isset($_POST['send_broadcast']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    if ($broadcast['status'] !== 'draft') {
        setFlash('error', 'Only draft broadcasts can be sent.');
        redirect('/admin/whatsapp-broadcasts/view?id=' . $broadcastId);
    }
    
    // Start sending (in production, this would be handled by a queue worker)
    $db->prepare("UPDATE whatsapp_broadcasts SET status = 'sending', started_at = NOW() WHERE id = ?")->execute([$broadcastId]);
    logActivity('whatsapp_broadcast_sent', "Started sending broadcast #$broadcastId");
    setFlash('success', 'Broadcast sending started. Messages will be queued and sent with time gaps.');
    redirect('/admin/whatsapp-broadcasts/view?id=' . $broadcastId);
}

// Get queue statistics
$queueStatsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error
    FROM whatsapp_queue WHERE broadcast_id = ?
");
$queueStatsStmt->execute([$broadcastId]);
$stats = $queueStatsStmt->fetch();

// Get queue items
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * 50;
try {
    $queueStmt = $db->prepare("
        SELECT * FROM whatsapp_queue 
        WHERE broadcast_id = ? 
        ORDER BY 
            CASE 
                WHEN status = 'pending' THEN 1
                WHEN status = 'sent' THEN 2
                WHEN status = 'failed' THEN 3
                ELSE 4
            END,
            created_at DESC
        LIMIT 50 OFFSET ?
    ");
    $queueStmt->execute([$broadcastId, $offset]);
    $queueItems = $queueStmt->fetchAll();
} catch (Exception $e) {
    $queueItems = [];
}

include __DIR__ . '/../includes/sidebar.php';
?>

<div style="padding: 24px; max-width: 1200px;">
    <!-- Broadcast Header -->
    <div style="background: white; border-radius: 8px; border: 1px solid var(--border-color); padding: 24px; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
            <div>
                <h1 style="margin: 0; font-size: 1.8rem;"><?php echo sanitize($broadcast['title']); ?></h1>
                <p style="margin: 8px 0 0 0; color: var(--text-muted);">Created by <?php echo sanitize($broadcast['admin_name']); ?> • <?php echo formatDate($broadcast['created_at']); ?></p>
            </div>
            <span class="badge <?php echo match($broadcast['status']) {
                'draft' => 'badge-gray',
                'sending' => 'badge-blue',
                'completed' => 'badge-success',
                'failed' => 'badge-red',
                default => 'badge-gray'
            }; ?>" style="font-size: 1rem; padding: 8px 16px;">
                <?php echo ucfirst($broadcast['status']); ?>
            </span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px;">Type</div>
                <div style="font-size: 1.3rem; font-weight: 700;">
                    <?php echo ucfirst($broadcast['message_type']); ?>
                    <?php if ($broadcast['message_type'] === 'event' && $broadcast['event_name']): ?>
                        <br><span style="font-size: 0.9rem; color: var(--text-muted);"><?php echo sanitize($broadcast['event_name']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px;">Total Recipients</div>
                <div style="font-size: 1.3rem; font-weight: 700;"><?php echo $broadcast['recipient_count']; ?></div>
            </div>
            
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px;">Time Gap</div>
                <div style="font-size: 1.3rem; font-weight: 700;"><?php echo $broadcast['time_gap_seconds']; ?>s</div>
            </div>
        </div>

        <div style="background: #f3f4f6; padding: 16px; border-radius: 6px; margin-bottom: 20px;">
            <div style="font-weight: 600; margin-bottom: 8px;">Message Preview:</div>
            <div style="white-space: pre-wrap; font-family: monospace; font-size: 0.9rem; line-height: 1.6; color: #333;">
                <?php echo sanitize(substr($broadcast['message'], 0, 300)); ?>
                <?php if (strlen($broadcast['message']) > 300): ?>...(view full message below)<?php endif; ?>
            </div>
        </div>

        <?php if ($broadcast['status'] === 'draft'): ?>
        <form method="POST" style="display: inline;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="send_broadcast" value="1">
            <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); border: none; cursor: pointer;" onclick="return confirm('Send this broadcast to ' + <?php echo $broadcast['recipient_count']; ?> + ' recipients?');">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 8px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8m0 8l-6-4m6 4l6-4"/></svg>
                Send Broadcast
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Queue Statistics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.9rem; opacity: 0.9;">Pending</div>
            <div style="font-size: 2rem; font-weight: 700; margin-top: 4px;"><?php echo $stats['pending'] ?? 0; ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #10b981 0%, #047857 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.9rem; opacity: 0.9;">Sent</div>
            <div style="font-size: 2rem; font-weight: 700; margin-top: 4px;"><?php echo $stats['sent'] ?? 0; ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.9rem; opacity: 0.9;">Failed</div>
            <div style="font-size: 2rem; font-weight: 700; margin-top: 4px;"><?php echo ($stats['failed'] ?? 0) + ($stats['error'] ?? 0); ?></div>
        </div>
    </div>

    <!-- Queue Items Table -->
    <div style="background: white; border-radius: 8px; border: 1px solid var(--border-color); overflow: hidden;">
        <div style="padding: 16px; border-bottom: 1px solid var(--border-color); background: var(--bg-secondary);">
            <h3 style="margin: 0;">Message Queue (<?php echo count($queueItems); ?> items)</h3>
        </div>
        <table class="table" style="margin: 0;">
            <thead>
                <tr style="background: var(--bg-secondary);">
                    <th style="padding: 12px; text-align: left;">Recipient</th>
                    <th style="padding: 12px; text-align: left;">Phone</th>
                    <th style="padding: 12px; text-align: left;">Status</th>
                    <th style="padding: 12px; text-align: left;">Sent At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queueItems as $item): ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 12px;">
                        <strong><?php echo sanitize($item['recipient_name']); ?></strong>
                        <?php if ($item['event_name']): ?>
                            <br><small style="color: var(--text-muted);">Event: <?php echo sanitize($item['event_name']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; font-family: monospace;"><?php echo sanitize($item['phone']); ?></td>
                    <td style="padding: 12px;">
                        <span class="badge <?php echo match($item['status']) {
                            'pending' => 'badge-blue',
                            'sent' => 'badge-success',
                            'failed', 'error' => 'badge-red',
                            default => 'badge-gray'
                        }; ?>">
                            <?php echo ucfirst($item['status']); ?>
                        </span>
                        <?php if ($item['error_message']): ?>
                            <br><small style="color: #dc2626; margin-top: 4px; display: block;"><?php echo sanitize($item['error_message']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; font-size: 0.85rem; color: var(--text-muted);">
                        <?php echo $item['sent_at'] ? formatDate($item['sent_at']) : '-'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
