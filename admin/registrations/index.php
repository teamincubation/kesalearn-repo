<?php
/**
 * KESA Learn - Admin: Manage Registrations
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'registrations';
$pageTitle = 'Registrations';

// Handle filter lock/unlock actions
if (isset($_POST['lock_filter']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    try {
        $search = sanitize($_POST['search_query'] ?? '');
        $status = $_POST['status_filter'] ?? '';
        $eventId = intval($_POST['event_filter'] ?? 0);
        
        $lockStmt = $db->prepare("INSERT INTO admin_filter_locks (page, search_query, status_filter, event_filter, locked_by) 
                                 VALUES (?, ?, ?, ?, ?) 
                                 ON DUPLICATE KEY UPDATE search_query = VALUES(search_query), status_filter = VALUES(status_filter), 
                                 event_filter = VALUES(event_filter), locked_by = VALUES(locked_by), locked_at = CURRENT_TIMESTAMP");
        $lockStmt->execute(['registrations', $search ?: null, $status ?: null, $eventId > 0 ? $eventId : null, $_SESSION['user_id']]);
        
        logActivity('filter_locked', "Locked registration filter: search=$search, status=$status, event=$eventId");
        setFlash('success', 'Filter locked successfully. All admins will see this filter.');
    } catch (Exception $e) {
        setFlash('error', 'Filter locking requires database migration. Please run: mysql < sql/migration_filter_lock.sql');
    }
    redirect('/admin/registrations/');
}

if (isset($_POST['unlock_filter']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    try {
        $db->prepare("DELETE FROM admin_filter_locks WHERE page = 'registrations'")->execute();
        logActivity('filter_unlocked', 'Unlocked registration filter');
        setFlash('success', 'Filter unlocked. You can now set a new filter.');
    } catch (Exception $e) {
        setFlash('error', 'Could not unlock filter. Please try again.');
    }
    redirect('/admin/registrations/');
}

// Load locked filter if exists
$lockedFilter = null;
$isFilterLocked = false;

try {
    $lockedStmt = $db->prepare("SELECT * FROM admin_filter_locks WHERE page = 'registrations'");
    $lockedStmt->execute();
    $lockedFilter = $lockedStmt->fetch();
} catch (Exception $e) {
    // Table doesn't exist yet - migration hasn't been run
    $lockedFilter = null;
}

// Use locked filter values if filter is locked, otherwise use URL parameters
if ($lockedFilter && !isset($_GET['unlock_filter'])) {
    $search = sanitize($lockedFilter['search_query'] ?? '');
    $status = $lockedFilter['status_filter'] ?? '';
    $eventId = intval($lockedFilter['event_filter'] ?? 0);
    $isFilterLocked = true;
} else {
    $search = sanitize($_GET['q'] ?? '');
    $status = $_GET['status'] ?? '';
    $eventId = intval($_GET['event'] ?? 0);
    $isFilterLocked = false;
}

// Get pagination page
$page = max(1, intval($_GET['page'] ?? 1));

// Handle payment label update
if (isset($_POST['update_label']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $labelRegId    = intval($_POST['label_reg_id'] ?? 0);
    $labelText     = trim(substr($_POST['payment_label'] ?? '', 0, 80));
    $labelColor    = $_POST['payment_label_color'] ?? '#6366f1';
    $labelColor    = preg_match('/^#[0-9a-fA-F]{6}$/', $labelColor) ? $labelColor : '#6366f1';
    if ($labelRegId > 0) {
        try {
            $lblUpd = $db->prepare("UPDATE registrations SET payment_label = ?, payment_label_color = ? WHERE id = ?");
            $lblUpd->execute([$labelText ?: null, $labelColor, $labelRegId]);
            logActivity('payment_label_updated', "Updated payment label for registration #$labelRegId to: $labelText");
            setFlash('success', 'Payment label updated.');
        } catch (Exception $e) {
            setFlash('error', 'Could not update label. Run migrations first.');
        }
    }
    redirect('/admin/registrations/' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// Handle bulk delete
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM registrations WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        logActivity('registrations_bulk_deleted', "Deleted $deleted registrations");
        setFlash('success', "$deleted registration(s) deleted successfully.");
    }
    redirect('/admin/registrations/');
}

$where = ['1=1'];
$params = [];

if (!empty($search)) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR e.title LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if (!empty($status)) {
    $where[] = "r.payment_status = ?";
    $params[] = $status;
}
if ($eventId > 0) {
    $where[] = "r.event_id = ?";
    $params[] = $eventId;
}

$whereClause = implode(' AND ', $where);
$countStmt = $db->prepare("SELECT COUNT(*) FROM registrations r JOIN users u ON r.user_id = u.id JOIN events e ON r.event_id = e.id WHERE $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

$offset = ($page - 1) * ADMIN_ITEMS_PER_PAGE;
$stmt = $db->prepare("SELECT r.*, u.name as user_name, u.email as user_email, u.phone as user_phone, e.title as event_title FROM registrations r JOIN users u ON r.user_id = u.id JOIN events e ON r.event_id = e.id WHERE $whereClause ORDER BY r.registered_at DESC, r.id DESC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET $offset");
// Note: payment_label and payment_label_color are in r.* — no extra join needed
$stmt->execute($params);
$registrations = $stmt->fetchAll();

// Load available payment account labels for the manual label selector
$paymentAccountLabels = [];
try {
    $palStmt = $db->prepare("SELECT id, setting_type, account_label, label_color, upi_beneficiary_name, gateway_name FROM payment_settings WHERE setting_type IN ('upi','gateway') AND account_label IS NOT NULL AND account_label != '' ORDER BY is_primary DESC, id ASC");
    $palStmt->execute();
    $paymentAccountLabels = $palStmt->fetchAll();
} catch (Exception $e) { /* migration not run yet */ }

// Get list of events for dropdown filter
$eventsStmt = $db->prepare("SELECT DISTINCT e.id, e.title FROM registrations r JOIN events e ON r.event_id = e.id ORDER BY e.title ASC");
$eventsStmt->execute();
$events = $eventsStmt->fetchAll();

// Calculate stats based on current filters
$statsWhere = ['1=1'];
$statsParams = [];
if (!empty($search)) {
    $statsWhere[] = "(u.name LIKE ? OR u.email LIKE ? OR e.title LIKE ?)";
    $statsParams = array_merge($statsParams, ["%$search%", "%$search%", "%$search%"]);
}
if ($eventId > 0) {
    $statsWhere[] = "r.event_id = ?";
    $statsParams[] = $eventId;
}
$statsWhereClause = implode(' AND ', $statsWhere);

// Get stats - use final_amount (actual amount paid) with fallback to amount
$statsStmt = $db->prepare("
    SELECT 
        COUNT(r.id) as total_registrations,
        COALESCE(SUM(CASE WHEN r.payment_status IN ('paid','verified') THEN COALESCE(r.final_amount, r.amount) ELSE 0 END), 0) as total_amount,
        SUM(CASE WHEN r.payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN r.payment_status IN ('paid', 'verified') THEN 1 ELSE 0 END) as paid_verified_count
    FROM registrations r 
    JOIN users u ON r.user_id = u.id 
    JOIN events e ON r.event_id = e.id 
    WHERE $statsWhereClause
");
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch();

include __DIR__ . '/../includes/sidebar.php';

// Get display settings
$showMaxSeats = getSetting('show_max_seats', '1');
?>

<!-- Display Settings Section -->
<div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; margin-bottom: 24px;">
    <form method="POST" action="/admin/settings/" style="margin: 0;">
        <?php echo csrfField(); ?>
        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; margin: 0;">
            <input type="checkbox" name="show_max_seats" value="1" <?php echo $showMaxSeats === '1' ? 'checked' : ''; ?> style="width: 18px; height: 18px;" onchange="this.form.submit();">
            <span>
                <strong style="font-size: 0.95rem;">Show maximum seat capacity on events</strong><br>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Users can see total seat capacity and remaining seats on public website</span>
            </span>
        </label>
    </form>
</div>

<!-- Registration Stats Dashboard -->
<?php if ($stats && $stats['total_registrations'] > 0): ?>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px;">
    <div style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border-radius: var(--radius-md); padding: 24px; color: white;">
        <div style="font-size: 0.9rem; opacity: 0.9;">Paid &amp; Verified</div>
        <div style="font-size: 2.5rem; font-weight: 700; margin-top: 8px;"><?php echo number_format($stats['paid_verified_count'] ?? 0); ?></div>
    </div>

    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: var(--radius-md); padding: 24px; color: white;">
        <div style="font-size: 0.9rem; opacity: 0.9;">Revenue Collected <span style="font-size:0.72rem; opacity:0.8;">(actual amt paid)</span></div>
        <div style="font-size: 2.5rem; font-weight: 700; margin-top: 8px;"><?php echo formatPrice($stats['total_amount'] ?? 0); ?></div>
    </div>

    <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: var(--radius-md); padding: 24px; color: white;">
        <div style="font-size: 0.9rem; opacity: 0.9;">Pending Payments</div>
        <div style="font-size: 2.5rem; font-weight: 700; margin-top: 8px;"><?php echo number_format($stats['pending_count'] ?? 0); ?></div>
    </div>

    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: var(--radius-md); padding: 24px; color: white;">
        <div style="font-size: 0.9rem; opacity: 0.9;">Total Registrations</div>
        <div style="font-size: 2.5rem; font-weight: 700; margin-top: 8px;"><?php echo number_format($stats['total_registrations']); ?></div>
    </div>
</div>
<?php endif; ?>

<div class="table-header">
    <!-- Filter Lock Indicator -->
    <?php if ($isFilterLocked && $lockedFilter): ?>
    <div style="background: #fef3c7; border: 1px solid #fcd34d; border-radius: var(--radius-md); padding: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1C6.48 1 2 5.48 2 11s4.48 10 10 10 10-4.48 10-10S17.52 1 12 1zm-2 15l-5-5 1.41-1.41L10 13.17l7.59-7.59L19 7l-9 9z"/></svg>
                <strong style="color: #92400e;">Filter is LOCKED</strong>
            </div>
            <div style="font-size: 0.9rem; color: #78350f;">
                <?php 
                    $filterParts = [];
                    if (!empty($lockedFilter['search_query'])) $filterParts[] = "Search: " . $lockedFilter['search_query'];
                    if (!empty($lockedFilter['status_filter'])) $filterParts[] = "Status: " . ucfirst($lockedFilter['status_filter']);
                    if (!empty($lockedFilter['event_filter'])) {
                        $e = $db->prepare("SELECT title FROM events WHERE id = ?");
                        $e->execute([$lockedFilter['event_filter']]);
                        $ev = $e->fetch();
                        if ($ev) $filterParts[] = "Event: " . $ev['title'];
                    }
                    echo implode(" | ", $filterParts);
                ?>
                <br><small>Locked by <?php 
                    $adminStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                    $adminStmt->execute([$lockedFilter['locked_by']]);
                    $admin = $adminStmt->fetch();
                    echo ($admin ? sanitize($admin['name']) : 'Admin');
                ?> on <?php echo date('M d, Y H:i', strtotime($lockedFilter['locked_at'])); ?></small>
            </div>
        </div>
        <form method="POST" style="display: inline;">
            <?php echo csrfField(); ?>
            <button type="submit" name="unlock_filter" value="1" class="btn btn-sm btn-warning">Unlock & Change Filter</button>
        </form>
    </div>
    <?php endif; ?>

    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <form method="GET" class="table-search">
            <input type="text" name="q" placeholder="Search by name, email, event..." value="<?php echo sanitize($search); ?>" class="admin-search" <?php echo $isFilterLocked ? 'disabled' : ''; ?>>
            <button type="submit" class="btn btn-sm btn-blue" <?php echo $isFilterLocked ? 'disabled' : ''; ?>>Search</button>
        </form>
        <div class="filter-group">
            <a href="?status=" class="filter-btn <?php echo empty($status) ? 'active' : ''; ?>" <?php echo $isFilterLocked ? 'onclick="return false;" style="opacity: 0.6; cursor: not-allowed;"' : ''; ?>>All</a>
            <a href="?status=pending" class="filter-btn <?php echo $status === 'pending' ? 'active' : ''; ?>" <?php echo $isFilterLocked ? 'onclick="return false;" style="opacity: 0.6; cursor: not-allowed;"' : ''; ?>>Pending</a>
            <a href="?status=paid" class="filter-btn <?php echo $status === 'paid' ? 'active' : ''; ?>" <?php echo $isFilterLocked ? 'onclick="return false;" style="opacity: 0.6; cursor: not-allowed;"' : ''; ?>>Paid</a>
            <a href="?status=verified" class="filter-btn <?php echo $status === 'verified' ? 'active' : ''; ?>" <?php echo $isFilterLocked ? 'onclick="return false;" style="opacity: 0.6; cursor: not-allowed;"' : ''; ?>>Verified</a>
            <a href="?status=rejected" class="filter-btn <?php echo $status === 'rejected' ? 'active' : ''; ?>" <?php echo $isFilterLocked ? 'onclick="return false;" style="opacity: 0.6; cursor: not-allowed;"' : ''; ?>>Rejected</a>
        </div>
        
        <?php if (!empty($events)): ?>
        <select name="event" class="admin-filter-select" onchange="window.location.href = '?event=' + this.value + '&status=<?php echo urlencode($status); ?>&q=<?php echo urlencode($search); ?>';" style="padding: 8px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-primary); color: var(--text-primary);" <?php echo $isFilterLocked ? 'disabled' : ''; ?>>
            <option value="">All Events</option>
            <?php foreach ($events as $event): ?>
                <option value="<?php echo $event['id']; ?>" <?php echo $eventId === intval($event['id']) ? 'selected' : ''; ?>>
                    <?php echo sanitize($event['title']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- Lock Filter Button -->
        <?php if (!$isFilterLocked && ($search || $status || $eventId > 0)): ?>
        <form method="POST" style="display: inline;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="search_query" value="<?php echo sanitize($search); ?>">
            <input type="hidden" name="status_filter" value="<?php echo $status; ?>">
            <input type="hidden" name="event_filter" value="<?php echo $eventId; ?>">
            <button type="submit" name="lock_filter" value="1" class="btn btn-sm btn-success" title="Lock this filter for all admins">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline; margin-right: 4px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Lock Filter
            </button>
        </form>
        <?php endif; ?>
    </div>
    <a href="/admin/registrations/export?q=<?php echo urlencode($search); ?>&payment_status=<?php echo urlencode($status); ?>&event_id=<?php echo $eventId; ?>" class="btn btn-secondary">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        Export CSV (with filters)
    </a>
</div>

<?php if (empty($registrations)): ?>
    <div class="empty-state" style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
        <h3>No registrations found</h3>
        <p>Registrations will appear here when users sign up for events.</p>
    </div>
<?php else: ?>
    <div class="table-wrapper">
        <table class="table">
        <thead>
            <tr><th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th><th>ID</th><th>User</th><th>Event</th><th>Amount</th><th>Method</th><th>Status</th><th>Label</th><th>Proof</th><th>Date</th><th>Actions</th></tr>
        </thead>
            <tbody>
                <?php foreach ($registrations as $reg): ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" value="<?php echo $reg['id']; ?>"></td>
                        <td>
                            <a href="/admin/registrations/edit?id=<?php echo $reg['id']; ?>" style="color: var(--blue); text-decoration: none; cursor: pointer; font-family: monospace; font-weight: 600;">
                                #<?php echo $reg['id']; ?>
                            </a>
                        </td>
                        <td>
                            <div style="font-weight: 600;">
                                <a href="/admin/users/view?id=<?php echo $reg['user_id']; ?>" style="color: var(--blue); text-decoration: none; cursor: pointer;">
                                    <?php echo sanitize($reg['user_name']); ?>
                                </a>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                <a href="mailto:<?php echo sanitize($reg['user_email']); ?>" style="color: var(--text-muted); text-decoration: none;">
                                    <?php echo sanitize($reg['user_email']); ?>
                                </a>
                            </div>
                        </td>
                        <td><?php echo sanitize(truncateText($reg['event_title'], 30)); ?></td>
                        <td>
                            <?php
                            // Show final_amount (what user actually paid including all fees/discounts/GST).
                            // Fall back to amount only if final_amount not yet recorded (legacy rows).
                            $displayAmt = ($reg['final_amount'] !== null && $reg['final_amount'] > 0)
                                ? $reg['final_amount']
                                : $reg['amount'];
                            echo formatPrice($displayAmt);
                            ?>
                        </td>
                        <td>
                            <?php
                            // Show accurate payment method; never show 'free' as a badge for paid/pending rows.
                            $pm = $reg['payment_method'] ?? '';
                            if ($pm === 'razorpay') {
                                echo '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;font-size:0.75rem;font-weight:700;text-transform:uppercase;">Razorpay</span>';
                            } elseif ($pm === 'upi') {
                                echo '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:20px;font-size:0.75rem;font-weight:700;text-transform:uppercase;">UPI</span>';
                            } elseif ($pm === 'free') {
                                echo '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;border-radius:20px;font-size:0.75rem;font-weight:600;">Free</span>';
                            } else {
                                echo '<span style="color:var(--text-muted);font-size:0.8rem;">—</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo getPaymentStatusBadge($reg['payment_status']); ?></td>
                        <td>
                            <?php
                                $lbl   = $reg['payment_label'] ?? null;
                                $lclr  = $reg['payment_label_color'] ?? '#6366f1';
                                $lclr  = preg_match('/^#[0-9a-fA-F]{6}$/', $lclr) ? $lclr : '#6366f1';
                            ?>
                            <div style="position:relative; display:inline-block;">
                                <button type="button"
                                    onclick="toggleLabelPopover(<?php echo $reg['id']; ?>)"
                                    title="<?php echo $lbl ? 'Change label' : 'Assign label'; ?>"
                                    style="border:none; background:none; cursor:pointer; padding:0; display:inline-flex; align-items:center;">
                                    <?php if ($lbl): ?>
                                    <span style="display:inline-flex; align-items:center; gap:5px; padding:3px 10px; background:<?php echo $lclr; ?>22; color:<?php echo $lclr; ?>; border:1px solid <?php echo $lclr; ?>66; border-radius:20px; font-size:0.75rem; font-weight:600; white-space:nowrap; max-width:130px; overflow:hidden; text-overflow:ellipsis;">
                                        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $lclr; ?>;flex-shrink:0;display:inline-block;"></span>
                                        <?php echo htmlspecialchars($lbl); ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="display:inline-flex; align-items:center; gap:4px; padding:3px 9px; background:var(--bg-secondary); color:var(--text-muted); border:1px dashed var(--border-color); border-radius:20px; font-size:0.73rem;">
                                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                        Label
                                    </span>
                                    <?php endif; ?>
                                </button>
                                <!-- Label edit popover -->
                                <div id="lp-<?php echo $reg['id']; ?>" style="display:none; position:absolute; top:calc(100% + 6px); left:0; z-index:999; background:var(--bg-primary); border:1px solid var(--border-color); border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.15); padding:14px; min-width:260px;">
                                    <form method="POST" style="margin:0;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="update_label" value="1">
                                        <input type="hidden" name="label_reg_id" value="<?php echo $reg['id']; ?>">
                                        <div style="font-size:0.78rem; font-weight:700; color:var(--text-primary); margin-bottom:10px; text-transform:uppercase; letter-spacing:0.04em;">Assign Payment Label</div>
                                        <?php if (!empty($paymentAccountLabels)): ?>
                                        <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:10px;">
                                            <?php foreach ($paymentAccountLabels as $pal): ?>
                                            <?php $palColor = $pal['label_color'] ?: '#6366f1'; ?>
                                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:6px 8px; border-radius:6px; border:1px solid var(--border-color); transition:background 0.15s;" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='transparent'">
                                                <input type="radio" name="payment_label" value="<?php echo htmlspecialchars($pal['account_label']); ?>"
                                                       <?php echo ($lbl === $pal['account_label']) ? 'checked' : ''; ?>
                                                       onchange="document.getElementById('lclr-<?php echo $reg['id']; ?>').value='<?php echo $palColor; ?>'">
                                                <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $palColor; ?>;flex-shrink:0;display:inline-block;"></span>
                                                <span style="font-size:0.82rem; font-weight:600; color:<?php echo $palColor; ?>;"><?php echo htmlspecialchars($pal['account_label']); ?></span>
                                                <span style="font-size:0.72rem; color:var(--text-muted); margin-left:auto;"><?php echo $pal['setting_type'] === 'upi' ? 'UPI' : 'GW'; ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:6px 8px; border-radius:6px; border:1px solid var(--border-color);">
                                                <input type="radio" name="payment_label" value="" <?php echo !$lbl ? 'checked' : ''; ?>>
                                                <span style="font-size:0.82rem; color:var(--text-muted);">No label (clear)</span>
                                            </label>
                                        </div>
                                        <?php else: ?>
                                        <div style="margin-bottom:8px;">
                                            <input type="text" name="payment_label" value="<?php echo htmlspecialchars($lbl ?? ''); ?>" placeholder="Type label text..." maxlength="80"
                                                   style="width:100%; border:1px solid var(--border-color); border-radius:6px; padding:7px 10px; font-size:0.83rem; background:var(--bg-primary); color:var(--text-primary);">
                                        </div>
                                        <?php endif; ?>
                                        <input type="hidden" id="lclr-<?php echo $reg['id']; ?>" name="payment_label_color" value="<?php echo htmlspecialchars($lclr); ?>">
                                        <div style="display:flex; gap:8px; margin-top:4px;">
                                            <button type="submit" style="flex:1; padding:7px 0; background:#4f46e5; color:#fff; border:none; border-radius:6px; font-size:0.82rem; font-weight:600; cursor:pointer;">Save</button>
                                            <button type="button" onclick="toggleLabelPopover(<?php echo $reg['id']; ?>)" style="padding:7px 12px; background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color); border-radius:6px; font-size:0.82rem; cursor:pointer;">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($reg['payment_proof']): ?>
                                <a href="/uploads/<?php echo sanitize($reg['payment_proof']); ?>" target="_blank" class="btn btn-sm btn-secondary" style="font-size: 0.75rem;">View</a>
                            <?php else: ?>
                                <span style="color: var(--text-light);">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo timeAgo($reg['registered_at']); ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="/admin/registrations/edit?id=<?php echo $reg['id']; ?>" class="btn-icon" title="Edit Registration">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                <?php if (!empty($reg['user_phone'])): ?>
                                <?php $whatsappMessage = urlencode("Hi " . sanitize($reg['user_name']) . ",\n\n"); ?>
                                <a href="https://wa.me/<?php echo preg_replace('/\D/', '', $reg['user_phone']); ?>?text=<?php echo $whatsappMessage; ?>" target="_blank" rel="noopener noreferrer" class="btn-icon" title="Send WhatsApp Message" style="color: #25D366; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                                    <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M19.07 4.93c-1.97-1.97-4.6-3.06-7.39-3.06-5.75 0-10.45 4.7-10.45 10.45 0 1.84.48 3.64 1.39 5.24l-1.48 5.41 5.54-1.45c1.53.83 3.26 1.27 5.01 1.27 5.75 0 10.45-4.7 10.45-10.45 0-2.79-1.09-5.42-3.07-7.41zM11.68 19.48c-1.56 0-3.09-.41-4.44-1.19l-.32-.19-3.3.86.88-3.21-.21-.33c-.83-1.32-1.27-2.85-1.27-4.42 0-4.79 3.9-8.68 8.68-8.68 2.32 0 4.49.9 6.13 2.55 1.64 1.64 2.55 3.82 2.55 6.13 0 4.79-3.9 8.68-8.68 8.68zm4.71-6.49c-.26-.13-1.53-.75-1.77-.84-.24-.09-.41-.13-.58.13-.17.26-.64.84-.79 1.01-.15.17-.29.19-.55.06-.26-.13-1.1-.41-2.09-1.29-.77-.69-1.29-1.54-1.44-1.8-.15-.26-.02-.4.11-.53.11-.11.26-.29.39-.44.13-.15.17-.26.26-.43.09-.17.04-.31-.02-.44-.06-.13-.58-1.39-.79-1.9-.21-.51-.42-.44-.58-.44-.15 0-.32-.02-.49-.02-.17 0-.44.06-.67.31-.24.26-.91.89-.91 2.17 0 1.28.93 2.51 1.06 2.68.13.17 1.84 2.81 4.46 3.95.62.27 1.11.43 1.49.56.62.2 1.19.17 1.64.1.5-.07 1.53-.63 1.75-1.23.22-.6.22-1.12.15-1.23-.06-.11-.23-.17-.49-.3z"/></svg>
                                </a>
                                <?php endif; ?>
                                <?php if ($reg['payment_status'] === 'pending'): ?>
                                    <a href="/admin/registrations/verify?id=<?php echo $reg['id']; ?>&action=verify&token=<?php echo generateCSRFToken(); ?>" class="btn-icon success" title="Verify Payment">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    </a>
                                    <a href="/admin/registrations/verify?id=<?php echo $reg['id']; ?>&action=reject&token=<?php echo generateCSRFToken(); ?>" class="btn-icon danger" title="Reject" data-confirm="Reject this payment?">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginate($total, ADMIN_ITEMS_PER_PAGE, $page, '/admin/registrations/'); ?>
<?php endif; ?>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>registration(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="registrations">
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
<script>
var _openLabelPopover = null;
function toggleLabelPopover(id) {
    var el = document.getElementById('lp-' + id);
    if (!el) return;
    var isOpen = el.style.display !== 'none';
    // Close any other open popover
    if (_openLabelPopover && _openLabelPopover !== el) {
        _openLabelPopover.style.display = 'none';
    }
    el.style.display = isOpen ? 'none' : 'block';
    _openLabelPopover = isOpen ? null : el;
}
// Close popovers when clicking outside
document.addEventListener('click', function(e) {
    if (_openLabelPopover && !_openLabelPopover.contains(e.target) && !e.target.closest('button[onclick^="toggleLabelPopover"]')) {
        _openLabelPopover.style.display = 'none';
        _openLabelPopover = null;
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
