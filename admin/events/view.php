<?php
/**
 * KESA Learn - Admin: View Event Details with Participants
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'events';

$eventId = intval($_GET['id'] ?? 0);
if (!$eventId) {
    setFlash('error', 'Event not found.');
    redirect('/admin/events/');
}

// Fetch event
$eventStmt = $db->prepare("SELECT * FROM events WHERE id = ?");
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    setFlash('error', 'Event not found.');
    redirect('/admin/events/');
}

$pageTitle = 'View: ' . ($event['title'] ?? 'Event');

// Handle delete participant
if (isset($_POST['action']) && $_POST['action'] === 'delete_participant') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $regId = intval($_POST['registration_id'] ?? 0);
        if ($regId) {
            $db->prepare("DELETE FROM registrations WHERE id = ? AND event_id = ?")->execute([$regId, $eventId]);
            $db->prepare("UPDATE events SET seats_taken = GREATEST(seats_taken - 1, 0) WHERE id = ?")->execute([$eventId]);
            logActivity('registration_deleted', "Admin deleted registration #$regId from event #$eventId");
            setFlash('success', 'Participant removed successfully.');
        }
    }
    redirect('/admin/events/view?id=' . $eventId);
}

// Fetch registrations
$participants = [];
try {
    $participantsStmt = $db->prepare("
        SELECT r.*, u.name as user_name, u.email as user_email, u.phone as user_phone, u.location as user_location, u.profile_image
        FROM registrations r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.event_id = ?
        ORDER BY r.registered_at DESC
    ");
    $participantsStmt->execute([$eventId]);
    $participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $participants = [];
}

// Calculate totals
$totalFee = 0;
$paidCount = 0;
$pendingCount = 0;

foreach ($participants as $p) {
    $amount = floatval($p['amount'] ?? 0);
    $status = $p['payment_status'] ?? '';
    
    if ($status === 'paid' || $status === 'verified') {
        $totalFee += $amount;
        $paidCount++;
    } elseif ($amount > 0) {
        $pendingCount++;
    }
}

$participantCount = count($participants);
$seatsTaken = $participantCount > 0 ? $participantCount : intval($event['seats_taken'] ?? 0);
$currency = $event['currency'] ?? 'INR';

// Event status
$eventStatus = 'upcoming';
if (!empty($event['start_date']) && !empty($event['end_date'])) {
    $now = time();
    $start = strtotime($event['start_date']);
    $end = strtotime($event['end_date']);
    if ($now < $start) {
        $eventStatus = 'upcoming';
    } elseif ($now >= $start && $now <= $end) {
        $eventStatus = 'ongoing';
    } else {
        $eventStatus = 'completed';
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; margin-bottom: 16px; }
.back-link:hover { color: var(--primary); }
.event-view-header { background: var(--bg-primary); border-radius: var(--radius-xl); overflow: hidden; margin-bottom: 24px; box-shadow: var(--shadow-sm); }
.event-banner { width: 100%; height: 200px; object-fit: cover; }
.event-banner-placeholder { width: 100%; height: 200px; display: flex; align-items: center; justify-content: center; }
.event-header-content { padding: 24px; }
.event-header-info h1 { font-size: 1.4rem; margin: 0 0 12px; color: var(--text-primary); }
.event-meta { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }
.event-meta-item { display: flex; align-items: center; gap: 6px; font-size: 0.9rem; color: var(--text-secondary); }
.event-meta-item svg { width: 16px; height: 16px; color: var(--text-muted); }
.status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
.status-badge.upcoming { background: #dbeafe; color: #1d4ed8; }
.status-badge.ongoing { background: #dcfce7; color: #16a34a; }
.status-badge.completed { background: #f3f4f6; color: #6b7280; }
.event-actions { display: flex; gap: 12px; flex-wrap: wrap; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--bg-primary); border-radius: var(--radius-lg); padding: 20px; text-align: center; box-shadow: var(--shadow-sm); }
.stat-card.highlight { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; }
.stat-value { font-size: 1.8rem; font-weight: 700; margin-bottom: 4px; }
.stat-label { font-size: 0.85rem; opacity: 0.8; }
.participants-section { background: var(--bg-primary); border-radius: var(--radius-xl); padding: 24px; box-shadow: var(--shadow-sm); }
.participants-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.participants-header h2 { font-size: 1.1rem; margin: 0; display: flex; align-items: center; gap: 8px; }
.count-badge { background: var(--primary); color: white; padding: 2px 10px; border-radius: 20px; font-size: 0.8rem; }
.table-wrapper { overflow-x: auto; }
.participants-table { width: 100%; border-collapse: collapse; }
.participants-table th, .participants-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-color); }
.participants-table th { font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; background: var(--bg-tertiary); }
.participant-name { font-weight: 600; color: var(--text-primary); }
.participant-email { font-size: 0.85rem; color: var(--text-muted); }
.payment-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
.payment-badge.paid, .payment-badge.verified { background: #dcfce7; color: #16a34a; }
.payment-badge.pending { background: #fef3c7; color: #d97706; }
.payment-badge.free { background: #e0e7ff; color: #4f46e5; }
.action-btn { padding: 6px; border: none; background: none; cursor: pointer; color: var(--text-muted); border-radius: var(--radius-sm); transition: all 0.2s; }
.action-btn:hover { background: var(--bg-tertiary); color: var(--primary); }
.action-btn.danger:hover { color: var(--red); }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state svg { width: 64px; height: 64px; margin-bottom: 16px; opacity: 0.5; }
.empty-state h3 { color: var(--text-primary); margin-bottom: 8px; }
.data-issue { background: #fef3c7; border: 1px solid #fcd34d; border-radius: var(--radius-lg); padding: 16px; margin-bottom: 20px; }
.data-issue p { margin: 0; color: #92400e; }
</style>

<a href="/admin/events/" class="back-link">
    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    Back to Events
</a>

<div class="event-view-header">
    <?php if (!empty($event['banner_image'])): ?>
        <img src="/uploads/banners/<?php echo htmlspecialchars($event['banner_image']); ?>" alt="" class="event-banner">
    <?php else: ?>
        <div class="event-banner-placeholder" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <svg width="80" height="80" fill="white" opacity="0.3" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5-7l-3 3.72L9 13l-3 4h12l-4-5z"/></svg>
        </div>
    <?php endif; ?>
    
    <div class="event-header-content">
        <div class="event-header-info">
            <h1><?php echo htmlspecialchars($event['title'] ?? ''); ?></h1>
            <div class="event-meta">
                <span class="event-meta-item">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <?php echo formatDate($event['start_date'] ?? ''); ?>
                </span>
                <span class="event-meta-item">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    <?php echo ucfirst($event['type'] ?? 'Event'); ?>
                </span>
                <span class="event-meta-item">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo formatPrice($event['fee'] ?? 0, $currency); ?>
                </span>
                <span class="status-badge <?php echo $eventStatus; ?>"><?php echo strtoupper($eventStatus); ?></span>
            </div>
            <div class="event-actions">
                <a href="/events/detail?id=<?php echo $eventId; ?>" class="btn btn-secondary" target="_blank">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    View Public Page
                </a>
                <a href="/admin/events/edit?id=<?php echo $eventId; ?>" class="btn btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit Event
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card highlight">
        <div class="stat-value"><?php echo $totalFee > 0 ? formatPrice($totalFee, $currency) : formatPrice(0, $currency); ?></div>
        <div class="stat-label">Total Fee Received</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $seatsTaken; ?>/<?php echo $event['max_seats'] ?: '&infin;'; ?></div>
        <div class="stat-label">Seats Occupied</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $paidCount; ?></div>
        <div class="stat-label">Paid Registrations</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $pendingCount; ?></div>
        <div class="stat-label">Pending Payments</div>
    </div>
</div>

<!-- Participants Section -->
<div class="participants-section">
    <div class="participants-header">
        <h2>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Registered Participants
            <span class="count-badge"><?php echo $seatsTaken; ?></span>
        </h2>
        <a href="/admin/registrations/?event=<?php echo $eventId; ?>" class="btn btn-secondary btn-sm">View in Registrations</a>
    </div>
    
    <?php if ($seatsTaken > 0 && empty($participants)): ?>
    <div class="data-issue">
        <p><strong>Note:</strong> There are <?php echo $seatsTaken; ?> registered participants but user details could not be loaded. This may happen if some user accounts were deleted. <a href="/admin/registrations/?event=<?php echo $eventId; ?>">View raw registration data</a>.</p>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($participants)): ?>
    <div class="table-wrapper">
        <table class="participants-table">
            <thead>
                <tr>
                    <th>Participant</th>
                    <th>Phone</th>
                    <th>Registered</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $p): ?>
                <tr>
                    <td>
                        <div class="participant-name"><?php echo htmlspecialchars($p['user_name'] ?? 'Unknown'); ?></div>
                        <div class="participant-email"><?php echo htmlspecialchars($p['user_email'] ?? ''); ?></div>
                    </td>
                    <td><?php echo htmlspecialchars($p['user_phone'] ?? '-'); ?></td>
                    <td><?php echo formatDate($p['registered_at'] ?? ''); ?></td>
                    <td><?php echo formatPrice($p['amount'] ?? 0, $currency); ?></td>
                    <td>
                        <?php 
                        $pStatus = $p['payment_status'] ?? 'pending';
                        $pMethod = $p['payment_method'] ?? '';
                        if ($pMethod === 'free' || ($p['amount'] ?? 0) == 0) {
                            echo '<span class="payment-badge free">FREE</span>';
                        } elseif ($pStatus === 'paid' || $pStatus === 'verified') {
                            echo '<span class="payment-badge paid">PAID</span>';
                        } else {
                            echo '<span class="payment-badge pending">PENDING</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="/admin/users/view?id=<?php echo $p['user_id']; ?>" class="action-btn" title="View User">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </a>
                            <?php if (!empty($p['payment_proof'])): ?>
                            <a href="/uploads/<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="action-btn" title="View Payment Proof">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </a>
                            <?php endif; ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this participant?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_participant">
                                <input type="hidden" name="registration_id" value="<?php echo $p['id']; ?>">
                                <button type="submit" class="action-btn danger" title="Remove">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($seatsTaken == 0): ?>
    <div class="empty-state">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <h3>No Participants Yet</h3>
        <p>No one has registered for this event yet.</p>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
