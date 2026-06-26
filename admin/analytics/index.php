<?php
/**
 * KESA Learn - Admin: Analytics Dashboard
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'analytics';
$pageTitle = 'Analytics';

// Get filter parameters
$filterMonth = isset($_GET['month']) ? intval($_GET['month']) : 0;
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportMonth = isset($_GET['export_month']) ? intval($_GET['export_month']) : 0;
    $exportYear = isset($_GET['export_year']) ? intval($_GET['export_year']) : intval(date('Y'));
    
    $whereClause = "WHERE YEAR(r.registered_at) = ?";
    $params = [$exportYear];
    
    if ($exportMonth > 0) {
        $whereClause .= " AND MONTH(r.registered_at) = ?";
        $params[] = $exportMonth;
    }
    
    $exportQuery = "SELECT e.title as event_name, e.type as event_type, 
                    COUNT(r.id) as registrations, 
                    COALESCE(SUM(CASE WHEN r.payment_status IN ('paid','verified') THEN COALESCE(r.final_amount, r.amount) ELSE 0 END), 0) as revenue
                    FROM events e 
                    LEFT JOIN registrations r ON e.id = r.event_id 
                    $whereClause
                    GROUP BY e.id, e.title, e.type
                    ORDER BY registrations DESC";
    
    $exportData = $db->prepare($exportQuery);
    $exportData->execute($params);
    $results = $exportData->fetchAll(PDO::FETCH_ASSOC);
    
    $monthName = $exportMonth > 0 ? date('F', mktime(0, 0, 0, $exportMonth, 1)) : 'Full_Year';
    $filename = "KESA_Analytics_Report_{$monthName}_{$exportYear}.csv";
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Event Name', 'Event Type', 'Registrations', 'Revenue (INR)']);
    
    foreach ($results as $row) {
        fputcsv($output, [$row['event_name'], ucfirst($row['event_type']), $row['registrations'], $row['revenue']]);
    }
    
    fclose($output);
    exit;
}

// Stats
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalEvents = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
$totalRegistrations = $db->query("SELECT COUNT(*) FROM registrations")->fetchColumn();
$totalRevenue = $db->query("SELECT COALESCE(SUM(COALESCE(final_amount, amount)), 0) FROM registrations WHERE payment_status IN ('paid','verified')")->fetchColumn();

// This month vs last month
$thisMonthUsers = $db->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())")->fetchColumn();
$lastMonthUsers = $db->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(NOW() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(NOW() - INTERVAL 1 MONTH)")->fetchColumn();

$thisMonthRegs = $db->query("SELECT COUNT(*) FROM registrations WHERE MONTH(registered_at) = MONTH(NOW()) AND YEAR(registered_at) = YEAR(NOW())")->fetchColumn();
$lastMonthRegs = $db->query("SELECT COUNT(*) FROM registrations WHERE MONTH(registered_at) = MONTH(NOW() - INTERVAL 1 MONTH) AND YEAR(registered_at) = YEAR(NOW() - INTERVAL 1 MONTH)")->fetchColumn();

// Last Event Details
$lastEvent = $db->query("SELECT e.*, 
    (SELECT COUNT(*) FROM registrations WHERE event_id = e.id) as reg_count,
    (SELECT COALESCE(SUM(COALESCE(final_amount, amount)), 0) FROM registrations WHERE event_id = e.id AND payment_status IN ('paid','verified')) as revenue
    FROM events e ORDER BY e.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Monthly data
$monthlyRegs = $db->query("SELECT MONTH(registered_at) as month, COUNT(*) as count FROM registrations WHERE YEAR(registered_at) = YEAR(NOW()) GROUP BY MONTH(registered_at)")->fetchAll();
$regData = array_fill(0, 12, 0);
foreach ($monthlyRegs as $m) { $regData[$m['month'] - 1] = $m['count']; }

$monthlyRevenue = $db->query("SELECT MONTH(registered_at) as month, COALESCE(SUM(COALESCE(final_amount, amount)), 0) as total FROM registrations WHERE YEAR(registered_at) = YEAR(NOW()) AND payment_status IN ('paid','verified') GROUP BY MONTH(registered_at)")->fetchAll();
$revData = array_fill(0, 12, 0);
foreach ($monthlyRevenue as $m) { $revData[$m['month'] - 1] = floatval($m['total']); }

// Top events with optional filter
$topEventsQuery = "SELECT e.title, e.type, COUNT(r.id) as reg_count, COALESCE(SUM(COALESCE(r.final_amount, r.amount)), 0) as revenue 
                   FROM events e LEFT JOIN registrations r ON e.id = r.event_id";
$topEventsParams = [];

if ($filterMonth > 0 || $filterYear) {
    $topEventsQuery .= " WHERE YEAR(r.registered_at) = ?";
    $topEventsParams[] = $filterYear;
    if ($filterMonth > 0) {
        $topEventsQuery .= " AND MONTH(r.registered_at) = ?";
        $topEventsParams[] = $filterMonth;
    }
}
$topEventsQuery .= " GROUP BY e.id ORDER BY reg_count DESC LIMIT 10";

$topEventsStmt = $db->prepare($topEventsQuery);
$topEventsStmt->execute($topEventsParams);
$topEvents = $topEventsStmt->fetchAll();

// Calculate total registrations and revenue for filtered data
$filteredTotalQuery = "SELECT COUNT(r.id) as total_regs, COALESCE(SUM(CASE WHEN r.payment_status IN ('paid','verified') THEN COALESCE(r.final_amount, r.amount) ELSE 0 END), 0) as total_revenue
                       FROM events e LEFT JOIN registrations r ON e.id = r.event_id";
$filteredParams = [];

if ($filterMonth > 0 || $filterYear) {
    $filteredTotalQuery .= " WHERE YEAR(r.registered_at) = ?";
    $filteredParams[] = $filterYear;
    if ($filterMonth > 0) {
        $filteredTotalQuery .= " AND MONTH(r.registered_at) = ?";
        $filteredParams[] = $filterMonth;
    }
}

$filteredStmt = $db->prepare($filteredTotalQuery);
$filteredStmt->execute($filteredParams);
$filteredTotals = $filteredStmt->fetch(PDO::FETCH_ASSOC);
$filteredRegs = $filteredTotals['total_regs'] ?? 0;
$filteredRevenue = $filteredTotals['total_revenue'] ?? 0;

// Event types
$eventTypes = $db->query("SELECT type, COUNT(*) as count FROM events GROUP BY type")->fetchAll();
$typeLabels = []; $typeValues = [];
foreach ($eventTypes as $t) { $typeLabels[] = ucfirst($t['type']); $typeValues[] = $t['count']; }

// ── Payment Label Analytics ──────────────────────────────────────────────────
// Fetch per-label revenue breakdown with GST split for gateway (Razorpay) labels
// Gateway charge: 2.33% deducted first, then GST 18% calculated on remaining amount
// Formula: 
//   Gateway charge = totalAmount × 0.0233
//   Amount after charge = totalAmount - gateway charge
//   GST = (amount after charge) × gstRate / (100 + gstRate)
//   Amount ex-GST = amount after charge - GST
$labelAnalytics = [];
$gatewayChargePercent = 2.33;
try {
    $gstRateRow = $db->query("SELECT gst_percent FROM payment_settings WHERE setting_type = 'gateway' AND is_active = 1 AND is_primary = 1 LIMIT 1")->fetch();
    $gstRate = floatval($gstRateRow['gst_percent'] ?? 0);

    $labelQuery = "SELECT
                        r.payment_label,
                        r.payment_label_color,
                        ps.setting_type,
                        COUNT(r.id)                                                              AS total_payments,
                        COALESCE(SUM(CASE WHEN r.payment_status IN ('paid','verified') THEN COALESCE(r.final_amount, r.amount) ELSE 0 END), 0) AS total_amount
                   FROM registrations r
                   LEFT JOIN payment_settings ps
                          ON ps.account_label = r.payment_label
                         AND ps.setting_type IN ('upi','gateway')
                   WHERE r.payment_status IN ('paid','verified')
                     AND r.payment_label IS NOT NULL
                     AND r.payment_label != ''";
    $labelParams = [];

    if ($filterYear) {
        $labelQuery .= " AND YEAR(r.registered_at) = ?";
        $labelParams[] = $filterYear;
    }
    if ($filterMonth > 0) {
        $labelQuery .= " AND MONTH(r.registered_at) = ?";
        $labelParams[] = $filterMonth;
    }
    $labelQuery .= " GROUP BY r.payment_label, r.payment_label_color, ps.setting_type
                    ORDER BY total_amount DESC";

    $labelStmt = $db->prepare($labelQuery);
    $labelStmt->execute($labelParams);
    $labelAnalytics = $labelStmt->fetchAll();
} catch (Exception $eLbl) {
    $gstRate = 0;
    $gatewayChargePercent = 2.33;
    $labelAnalytics = [];
}

$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>';

include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Last Event Highlight -->
<?php if ($lastEvent): ?>
<div style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border-radius: 16px; padding: 24px 28px; margin-bottom: 24px; color: #fff; position: relative; overflow: hidden;">
    <div style="position: absolute; top: -20px; right: -20px; width: 150px; height: 150px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
    <div style="position: absolute; bottom: -30px; left: 30%; width: 100px; height: 100px; background: rgba(255,255,255,0.05); border-radius: 50%;"></div>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; position: relative; z-index: 1;">
        <div>
            <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.8; margin-bottom: 6px;">Latest Event</div>
            <h2 style="font-size: 1.4rem; font-weight: 700; margin: 0 0 8px 0;"><?php echo sanitize($lastEvent['title']); ?></h2>
            <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 0.9rem; opacity: 0.9;">
                <span style="display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <?php echo date('M d, Y', strtotime($lastEvent['start_date'])); ?>
                </span>
                <span style="display: flex; align-items: center; gap: 6px;">
                    <span style="padding: 2px 10px; background: <?php echo $lastEvent['status'] === 'published' ? 'rgba(16,185,129,0.3)' : 'rgba(251,191,36,0.3)'; ?>; border-radius: 20px; font-size: 0.75rem; text-transform: capitalize;"><?php echo $lastEvent['status']; ?></span>
                </span>
            </div>
        </div>
        <div style="display: flex; gap: 24px; text-align: center;">
            <div style="background: rgba(255,255,255,0.15); padding: 16px 24px; border-radius: 12px; backdrop-filter: blur(10px);">
                <div style="font-size: 1.8rem; font-weight: 800;"><?php echo number_format($lastEvent['reg_count']); ?></div>
                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.8;">Registrations</div>
            </div>
            <div style="background: rgba(255,255,255,0.15); padding: 16px 24px; border-radius: 12px; backdrop-filter: blur(10px);">
                <div style="font-size: 1.8rem; font-weight: 800;"><?php echo formatPrice($lastEvent['revenue']); ?></div>
                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.8;">Revenue</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Total Users</span>
            <div class="admin-stat-icon" style="background: var(--blue);"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg></div>
        </div>
        <h3><?php echo number_format($totalUsers); ?></h3>
        <div class="admin-stat-change <?php echo $thisMonthUsers >= $lastMonthUsers ? 'positive' : 'negative'; ?>">
            <?php echo $thisMonthUsers; ?> new this month
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Total Events</span>
            <div class="admin-stat-icon" style="background: var(--purple);"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
        </div>
        <h3><?php echo number_format($totalEvents); ?></h3>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Registrations</span>
            <div class="admin-stat-icon" style="background: var(--red);"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
        </div>
        <h3><?php echo number_format($totalRegistrations); ?></h3>
        <div class="admin-stat-change <?php echo $thisMonthRegs >= $lastMonthRegs ? 'positive' : 'negative'; ?>">
            <?php echo $thisMonthRegs; ?> this month
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-header">
            <span>Total Revenue</span>
            <div class="admin-stat-icon" style="background: var(--yellow);"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
        </div>
        <h3><?php echo formatPrice($totalRevenue); ?></h3>
    </div>
</div>

<!-- Charts -->
<div class="admin-chart-grid">
    <div class="admin-chart-card">
        <h3>Monthly Registrations (<?php echo date('Y'); ?>)</h3>
        <div class="chart-container"><canvas id="registrations-chart"></canvas></div>
    </div>
    <div class="admin-chart-card">
        <h3>Event Types Distribution</h3>
        <div class="chart-container"><canvas id="event-types-chart"></canvas></div>
    </div>
</div>

<div class="admin-chart-grid" style="grid-template-columns: 1fr;">
    <div class="admin-chart-card">
        <h3>Monthly Revenue (<?php echo date('Y'); ?>)</h3>
        <div class="chart-container"><canvas id="revenue-chart"></canvas></div>
    </div>
</div>

<!-- Monthly Filter & Export -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px 24px; margin-top: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <form method="GET" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <label style="font-weight: 600; font-size: 0.9rem;">Filter Reports:</label>
        <select name="month" class="form-control" style="width: auto; min-width: 140px;">
            <option value="0">All Months</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>" <?php echo $filterMonth == $m ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
            <?php endfor; ?>
        </select>
        <select name="year" class="form-control" style="width: auto; min-width: 100px;">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Apply Filter
        </button>
    </form>
    <a href="/admin/analytics/?export=csv&export_month=<?php echo $filterMonth; ?>&export_year=<?php echo $filterYear; ?>" class="btn btn-secondary" style="display: flex; align-items: center; gap: 8px;">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        Export Report (CSV)
    </a>
</div>

<!-- Top Events -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; margin-top: 24px;">
    <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <h3 style="font-size: 1.1rem; margin: 0;">Top Events by Registrations</h3>
            <div style="display: flex; gap: 16px; align-items: center;">
                <div style="background: #dcfce7; border-radius: 8px; padding: 8px 14px; display: flex; align-items: center; gap: 8px;">
                    <span style="color: var(--text-muted); font-size: 0.85rem;">Total Registrations:</span>
                    <span style="font-weight: 700; font-size: 1.1rem; color: #16a34a;"><?php echo number_format($filteredRegs); ?></span>
                </div>
                <div style="background: #fef3c7; border-radius: 8px; padding: 8px 14px; display: flex; align-items: center; gap: 8px;">
                    <span style="color: var(--text-muted); font-size: 0.85rem;">Total Revenue:</span>
                    <span style="font-weight: 700; font-size: 1.1rem; color: #d97706;"><?php echo formatPrice($filteredRevenue); ?></span>
                </div>
            </div>
        </div>
        <?php if ($filterMonth > 0): ?>
            <span style="font-size: 0.85rem; color: var(--text-muted); background: var(--bg-secondary); padding: 4px 12px; border-radius: 20px;">
                Filtered: <?php echo date('F', mktime(0,0,0,$filterMonth,1)) . ' ' . $filterYear; ?>
            </span>
        <?php endif; ?>
    </div>
    <table class="table">
        <thead><tr><th>#</th><th>Event</th><th>Type</th><th>Registrations</th><th>Revenue</th></tr></thead>
        <tbody>
            <?php foreach ($topEvents as $i => $te): ?>
                <tr>
                    <td style="font-weight: 600; color: var(--text-muted);"><?php echo $i + 1; ?></td>
                    <td style="font-weight: 600;"><?php echo sanitize($te['title']); ?></td>
                    <td><span class="badge" style="text-transform: capitalize;"><?php echo $te['type'] ?? '-'; ?></span></td>
                    <td><?php echo $te['reg_count']; ?></td>
                    <td><?php echo formatPrice($te['revenue']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Payment Label Analytics Table ── -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-top: 28px;">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div>
            <h3 style="font-size:1.05rem; font-weight:700; margin:0 0 4px 0; display:flex; align-items:center; gap:8px;">
                <svg width="18" height="18" fill="#6366f1" viewBox="0 0 24 24"><path d="M17.63 5.84C17.27 5.33 16.67 5 16 5L5 5.01C3.9 5.01 3 5.9 3 7v10c0 1.1.9 1.99 2 1.99L16 19c.67 0 1.27-.33 1.63-.84L22 12l-4.37-6.16z"/></svg>
                Payment Received by Account Label
            </h3>
            <p style="font-size:0.82rem; color:var(--text-muted); margin:0;">
                <?php echo $filterMonth > 0 ? date('F', mktime(0,0,0,$filterMonth,1)) . ' ' . $filterYear : 'Year ' . $filterYear; ?>
                &nbsp;&middot;&nbsp; Gateway payments: <?php echo $gatewayChargePercent; ?>% charge deducted first, then GST <?php echo $gstRate > 0 ? "@ {$gstRate}% on remaining amount" : '(set GST % in Payment Settings to see split)'; ?>
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <span style="font-size:0.78rem; color:var(--text-muted);">Labels are assigned from active payment accounts</span>
        </div>
    </div>

    <?php if (empty($labelAnalytics)): ?>
    <div style="padding:32px; text-align:center; color:var(--text-muted); border:1px dashed var(--border-color); border-radius:var(--radius-sm);">
        <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="opacity:0.35; margin-bottom:10px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.63 5.84C17.27 5.33 16.67 5 16 5L5 5.01C3.9 5.01 3 5.9 3 7v10c0 1.1.9 1.99 2 1.99L16 19c.67 0 1.27-.33 1.63-.84L22 12l-4.37-6.16z"/></svg>
        <div style="font-weight:600; margin-bottom:4px;">No labelled payments found</div>
        <div style="font-size:0.82rem;">Assign account labels in Settings and verify payments to see data here.</div>
    </div>
    <?php else: ?>
    <?php
        $totalLabelAmount = array_sum(array_column($labelAnalytics, 'total_amount'));
    ?>
    <div style="overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
        <thead>
            <tr style="border-bottom:2px solid var(--border-color);">
                <th style="padding:10px 14px; text-align:left; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Label</th>
                <th style="padding:10px 14px; text-align:left; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Type</th>
                <th style="padding:10px 14px; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Payments</th>
                <th style="padding:10px 14px; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Total Amount</th>
                <th style="padding:10px 14px; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">GW Charge</th>
                <th style="padding:10px 14px; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">After Charge</th>
                <th style="padding:10px 14px; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">GST Payable</th>
                <th style="padding:10px 14px; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Amount ex-GST</th>
                <th style="padding:10px 14px; text-align:right; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Share</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($labelAnalytics as $la): ?>
        <?php
            $lColor    = $la['payment_label_color'] ?: '#6366f1';
            $lColor    = preg_match('/^#[0-9a-fA-F]{6}$/', $lColor) ? $lColor : '#6366f1';
            $isGateway = ($la['setting_type'] === 'gateway');
            $lAmount   = floatval($la['total_amount']);
            
            // Gateway charge calculation: deduct 2.24% first (for gateway payments only)
            $gwCharge    = $isGateway ? round($lAmount * ($gatewayChargePercent / 100), 2) : 0;
            $afterCharge = $lAmount - $gwCharge;
            
            // GST is calculated on amount AFTER gateway charge
            $gstAmt    = ($isGateway && $gstRate > 0) ? round($afterCharge * $gstRate / (100 + $gstRate), 2) : 0;
            $exGstAmt  = $afterCharge - $gstAmt;
            $share     = $totalLabelAmount > 0 ? round(($lAmount / $totalLabelAmount) * 100, 1) : 0;
        ?>
        <tr style="border-bottom:1px solid var(--border-color); transition:background 0.12s;" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='transparent'">
            <td style="padding:12px 14px;">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:4px 12px; background:<?php echo $lColor; ?>18; color:<?php echo $lColor; ?>; border:1px solid <?php echo $lColor; ?>55; border-radius:20px; font-size:0.8rem; font-weight:600; white-space:nowrap;">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?php echo $lColor; ?>;flex-shrink:0;display:inline-block;"></span>
                    <?php echo htmlspecialchars($la['payment_label']); ?>
                </span>
            </td>
            <td style="padding:12px 14px;">
                <?php if ($isGateway): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#f59e0b18;color:#d97706;border:1px solid #f59e0b44;border-radius:20px;font-size:0.72rem;font-weight:700;text-transform:uppercase;">
                    <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>
                    Razorpay
                </span>
                <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#0ea5e918;color:#0284c7;border:1px solid #0ea5e944;border-radius:20px;font-size:0.72rem;font-weight:700;text-transform:uppercase;">
                    <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M11 17c0 .55.45 1 1 1s1-.45 1-1-.45-1-1-1-1 .45-1 1zm6-5c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-6 0c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3z"/></svg>
                    UPI
                </span>
                <?php endif; ?>
            </td>
            <td style="padding:12px 14px; text-align:right; font-weight:600;"><?php echo number_format($la['total_payments']); ?></td>
            <td style="padding:12px 14px; text-align:right; font-weight:700; color:var(--text-primary);"><?php echo formatPrice($lAmount); ?></td>
            <td style="padding:12px 14px; text-align:right;">
                <?php if ($isGateway && $gwCharge > 0): ?>
                <span style="color:#ef4444; font-weight:600;">−<?php echo formatPrice($gwCharge); ?></span>
                <span style="font-size:0.72rem; color:var(--text-muted); margin-left:4px;">(<?php echo $gatewayChargePercent; ?>%)</span>
                <?php else: ?>
                <span style="color:var(--text-muted); font-size:0.82rem;">—</span>
                <?php endif; ?>
            </td>
            <td style="padding:12px 14px; text-align:right; font-weight:700; color:var(--text-primary);">
                <?php echo $isGateway ? formatPrice($afterCharge) : '—'; ?>
            </td>
            <td style="padding:12px 14px; text-align:right;">
                <?php if ($isGateway && $gstAmt > 0): ?>
                <span style="color:#dc2626; font-weight:600;"><?php echo formatPrice($gstAmt); ?></span>
                <span style="font-size:0.72rem; color:var(--text-muted); margin-left:4px;">(<?php echo $gstRate; ?>%)</span>
                <?php else: ?>
                <span style="color:var(--text-muted); font-size:0.82rem;">— N/A</span>
                <?php endif; ?>
            </td>
            <td style="padding:12px 14px; text-align:right; color:var(--text-primary);">
                <?php if ($isGateway && $gstAmt > 0): ?>
                <span style="font-weight:600;"><?php echo formatPrice($exGstAmt); ?></span>
                <?php else: ?>
                <span style="color:var(--text-muted); font-size:0.82rem;">— N/A</span>
                <?php endif; ?>
            </td>
            <td style="padding:12px 14px; text-align:right;">
                <div style="display:flex; align-items:center; gap:8px; justify-content:flex-end;">
                    <div style="width:60px; height:6px; background:var(--border-color); border-radius:3px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $share; ?>%; background:<?php echo $lColor; ?>; border-radius:3px;"></div>
                    </div>
                    <span style="font-size:0.82rem; font-weight:600; color:var(--text-muted); min-width:36px; text-align:right;"><?php echo $share; ?>%</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid var(--border-color); background:var(--bg-secondary);">
                <td colspan="3" style="padding:12px 14px; font-weight:700; font-size:0.88rem;">Total</td>
                <td style="padding:12px 14px; text-align:right; font-weight:800; font-size:0.95rem;"><?php echo formatPrice($totalLabelAmount); ?></td>
                <td style="padding:12px 14px; text-align:right; font-weight:700; color:#ef4444;">
                    <?php
                        $totalGWCharge = 0;
                        foreach ($labelAnalytics as $la) {
                            if (($la['setting_type'] === 'gateway')) {
                                $totalGWCharge += floatval($la['total_amount']) * ($gatewayChargePercent / 100);
                            }
                        }
                        echo $totalGWCharge > 0 ? '−' . formatPrice(round($totalGWCharge, 2)) : '<span style="color:var(--text-muted);font-weight:400;font-size:0.82rem;">—</span>';
                    ?>
                </td>
                <td style="padding:12px 14px; text-align:right; font-weight:700; font-size:0.95rem;">
                    <?php echo formatPrice(round($totalLabelAmount - $totalGWCharge, 2)); ?>
                </td>
                <td style="padding:12px 14px; text-align:right; font-weight:700; color:#dc2626;">
                    <?php
                        $totalGST = 0;
                        foreach ($labelAnalytics as $la) {
                            if (($la['setting_type'] === 'gateway') && $gstRate > 0) {
                                $gwCh = floatval($la['total_amount']) * ($gatewayChargePercent / 100);
                                $afterCh = floatval($la['total_amount']) - $gwCh;
                                $totalGST += $afterCh * $gstRate / (100 + $gstRate);
                            }
                        }
                        echo $totalGST > 0 ? formatPrice(round($totalGST, 2)) : '<span style="color:var(--text-muted);font-weight:400;font-size:0.82rem;">—</span>';
                    ?>
                </td>
                <td style="padding:12px 14px; text-align:right; font-weight:700;">
                    <?php echo formatPrice(round((($totalLabelAmount - $totalGWCharge) - $totalGST), 2)); ?>
                </td>
                <td style="padding:12px 14px;"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
