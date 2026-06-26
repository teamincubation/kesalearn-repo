<?php
/**
 * KESA Learn - Admin: Offer & Coupon Management
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage  = 'coupons';
$pageTitle  = 'Offers & Coupons';

// ── Fetch all events for the "specific events" selector ─────────────────────
try {
    $allEvents = $db->query("SELECT id, title, type FROM events WHERE status = 'published' ORDER BY start_date DESC")->fetchAll();
} catch (Exception $e) {
    $allEvents = [];
}

// ── Handle delete ────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && verifyCSRFToken($_GET['token'] ?? '')) {
    $delId = intval($_GET['delete']);
    $db->prepare("DELETE FROM coupons WHERE id = ?")->execute([$delId]);
    $db->prepare("DELETE FROM coupon_events WHERE coupon_id = ?")->execute([$delId]);
    logActivity('coupon_deleted', "Coupon #$delId deleted");
    setFlash('success', 'Coupon deleted successfully.');
    redirect('/admin/coupons/');
}

// ── Handle toggle active ─────────────────────────────────────────────────────
if (isset($_GET['toggle']) && is_numeric($_GET['toggle']) && verifyCSRFToken($_GET['token'] ?? '')) {
    $togId = intval($_GET['toggle']);
    $db->prepare("UPDATE coupons SET is_active = 1 - is_active WHERE id = ?")->execute([$togId]);
    setFlash('success', 'Coupon status updated.');
    redirect('/admin/coupons/');
}

// ── Handle create / edit POST ────────────────────────────────────────────────
$editCoupon      = null;
$editCouponEvents = [];
$formError       = '';

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ?");
    $stmt->execute([intval($_GET['edit'])]);
    $editCoupon = $stmt->fetch();
    if ($editCoupon) {
        $editCouponEvents = $db->prepare("SELECT event_id FROM coupon_events WHERE coupon_id = ?");
        $editCouponEvents->execute([$editCoupon['id']]);
        $editCouponEvents = $editCouponEvents->fetchAll(PDO::FETCH_COLUMN);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create','edit'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $formError = 'Invalid security token. Please refresh and try again.';
    } else {
        // Sanitize all inputs
        $code             = strtoupper(trim(preg_replace('/[^A-Za-z0-9_\-]/', '', $_POST['code'] ?? '')));
        $name             = sanitize(trim($_POST['name'] ?? ''));
        $activeFrom       = sanitize($_POST['active_from'] ?? date('Y-m-d\TH:i'));
        $expireOn         = !empty($_POST['expire_on']) ? sanitize($_POST['expire_on']) : null;
        $maxUsesTotal     = !empty($_POST['max_uses_total']) ? intval($_POST['max_uses_total']) : null;
        $maxUsesPerUser   = max(1, intval($_POST['max_uses_per_user'] ?? 1));
        $applicableTypes  = isset($_POST['applicable_types']) ? implode(',', array_intersect((array)$_POST['applicable_types'], ['webinar','workshop','course','offline','special'])) : '';
        $scope            = in_array($_POST['scope'] ?? '', ['all','specific']) ? $_POST['scope'] : 'all';
        $discountType     = in_array($_POST['discount_type'] ?? '', ['percent','fixed']) ? $_POST['discount_type'] : 'percent';
        $discountValue    = max(0, floatval($_POST['discount_value'] ?? 0));
        $minPurchase      = max(0, floatval($_POST['min_purchase_amount'] ?? 0));
        $visibility       = in_array($_POST['visibility'] ?? '', ['public','private']) ? $_POST['visibility'] : 'public';
        $isActive         = isset($_POST['is_active']) ? 1 : 0;
        $specificEvents   = isset($_POST['specific_events']) ? array_map('intval', (array)$_POST['specific_events']) : [];

        // Validation
        if (empty($code))          $formError = 'Coupon code is required.';
        elseif (empty($name))      $formError = 'Coupon name is required.';
        elseif ($discountValue <= 0) $formError = 'Discount value must be greater than 0.';
        elseif ($discountType === 'percent' && $discountValue > 100) $formError = 'Percentage discount cannot exceed 100%.';

        if (empty($formError)) {
            // Check duplicate code (exclude self on edit)
            $dupStmt = $db->prepare("SELECT id FROM coupons WHERE code = ? AND id != ?");
            $dupStmt->execute([$code, intval($_POST['coupon_id'] ?? 0)]);
            if ($dupStmt->fetch()) $formError = 'Coupon code "' . htmlspecialchars($code) . '" already exists.';
        }

        if (empty($formError)) {
            // Convert datetime-local strings to MySQL format
            $activeFromSQL = date('Y-m-d H:i:s', strtotime($activeFrom));
            $expireOnSQL   = $expireOn ? date('Y-m-d H:i:s', strtotime($expireOn)) : null;

            if ($_POST['action'] === 'create') {
                $ins = $db->prepare("INSERT INTO coupons (code, name, active_from, expire_on, max_uses_total, max_uses_per_user, applicable_types, scope, discount_type, discount_value, min_purchase_amount, visibility, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$code, $name, $activeFromSQL, $expireOnSQL, $maxUsesTotal, $maxUsesPerUser, $applicableTypes, $scope, $discountType, $discountValue, $minPurchase, $visibility, $isActive, $_SESSION['user_id']]);
                $couponId = $db->lastInsertId();
                logActivity('coupon_created', "Coupon '$code' created");
            } else {
                $couponId = intval($_POST['coupon_id']);
                $upd = $db->prepare("UPDATE coupons SET code=?, name=?, active_from=?, expire_on=?, max_uses_total=?, max_uses_per_user=?, applicable_types=?, scope=?, discount_type=?, discount_value=?, min_purchase_amount=?, visibility=?, is_active=?, updated_at=NOW() WHERE id=?");
                $upd->execute([$code, $name, $activeFromSQL, $expireOnSQL, $maxUsesTotal, $maxUsesPerUser, $applicableTypes, $scope, $discountType, $discountValue, $minPurchase, $visibility, $isActive, $couponId]);
                $db->prepare("DELETE FROM coupon_events WHERE coupon_id = ?")->execute([$couponId]);
                logActivity('coupon_updated', "Coupon '$code' updated");
            }

            // Upsert coupon-specific event links
            if ($scope === 'specific' && !empty($specificEvents)) {
                $insEv = $db->prepare("INSERT IGNORE INTO coupon_events (coupon_id, event_id) VALUES (?,?)");
                foreach ($specificEvents as $evId) {
                    if ($evId > 0) $insEv->execute([$couponId, $evId]);
                }
            }

            setFlash('success', $_POST['action'] === 'create' ? "Coupon '$code' created successfully." : "Coupon '$code' updated.");
            redirect('/admin/coupons/');
        }
    }
}

// ── Fetch coupon list ────────────────────────────────────────────────────────
$search     = sanitize($_GET['q'] ?? '');
$filterType = $_GET['type'] ?? '';
$filterVis  = $_GET['vis'] ?? '';
$page       = max(1, intval($_GET['page'] ?? 1));
$perPage    = 20;

$where  = ['1=1'];
$params = [];
if (!empty($search)) { $where[] = "(code LIKE ? OR name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if (!empty($filterType)) { $where[] = "FIND_IN_SET(?, applicable_types)"; $params[] = $filterType; }
if (!empty($filterVis)) { $where[] = "visibility = ?"; $params[] = $filterVis; }
$whereSQL = implode(' AND ', $where);

try {
    $totalCoupons = $db->prepare("SELECT COUNT(*) FROM coupons WHERE $whereSQL");
    $totalCoupons->execute($params);
    $totalCoupons = intval($totalCoupons->fetchColumn());

    $offset = ($page - 1) * $perPage;
    $couponsStmt = $db->prepare("SELECT * FROM coupons WHERE $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $couponsStmt->execute($params);
    $coupons = $couponsStmt->fetchAll();

    // Fetch usage stats for displayed coupons
    $couponIds = array_column($coupons, 'id');
    $usageMap  = [];
    if (!empty($couponIds)) {
        $inList = implode(',', $couponIds);
        $uStmt  = $db->query("SELECT coupon_id, COUNT(*) AS cnt, SUM(discount_amount) AS total_saved FROM coupon_usages WHERE coupon_id IN ($inList) GROUP BY coupon_id");
        foreach ($uStmt->fetchAll() as $row) {
            $usageMap[$row['coupon_id']] = $row;
        }
    }
} catch (Exception $e) {
    $coupons = [];
    $totalCoupons = 0;
    $usageMap = [];
}

// Totals for stats bar
try {
    $totalActive   = $db->query("SELECT COUNT(*) FROM coupons WHERE is_active = 1")->fetchColumn();
    $totalSaved    = $db->query("SELECT COALESCE(SUM(discount_amount),0) FROM coupon_usages")->fetchColumn();
    $totalRedeemed = $db->query("SELECT COALESCE(SUM(uses_count),0) FROM coupons")->fetchColumn();
} catch (Exception $e) {
    $totalActive = $totalSaved = $totalRedeemed = 0;
}

$totalPages = max(1, ceil($totalCoupons / $perPage));

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Coupon page styles ───────────────────────────────────────────────── */
.coupon-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:28px; }
.coupon-stat-card { background:var(--bg-primary); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:20px 22px; }
.coupon-stat-label { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:8px; }
.coupon-stat-value { font-size:1.8rem; font-weight:800; color:var(--text-primary); line-height:1; }
.coupon-stat-sub { font-size:0.78rem; color:var(--text-muted); margin-top:4px; }

/* Table */
.coupon-table { width:100%; border-collapse:collapse; font-size:0.875rem; }
.coupon-table th { padding:10px 14px; text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700; border-bottom:2px solid var(--border-color); white-space:nowrap; }
.coupon-table td { padding:13px 14px; border-bottom:1px solid var(--border-color); vertical-align:middle; }
.coupon-table tr:hover td { background:var(--bg-secondary); }
.coupon-table tr:last-child td { border-bottom:none; }

/* Code chip */
.coupon-code-chip { font-family:monospace; font-size:0.88rem; font-weight:700; letter-spacing:0.08em; background:#f3f4f6; color:#1f2937; border:1.5px dashed #d1d5db; border-radius:7px; padding:5px 12px; display:inline-flex; align-items:center; gap:7px; cursor:pointer; transition:background 0.15s; }
.coupon-code-chip:hover { background:#e5e7eb; }
.coupon-code-chip .copy-icon { color:#6b7280; transition:color 0.15s; }
.coupon-code-chip:hover .copy-icon { color:#374151; }

/* Badges */
.badge-vis { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:uppercase; }
.badge-public  { background:#dcfce7; color:#15803d; }
.badge-private { background:#fef3c7; color:#92400e; }
.badge-active  { background:#dcfce7; color:#166534; }
.badge-inactive{ background:#fee2e2; color:#991b1b; }
.badge-expired { background:#f3f4f6; color:#6b7280; }
.badge-type    { background:#ede9fe; color:#5b21b6; font-size:0.7rem; padding:2px 7px; border-radius:20px; font-weight:600; }

/* Discount display */
.discount-pill { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:8px; font-weight:700; font-size:0.88rem; }
.discount-percent { background:#eff6ff; color:#1d4ed8; }
.discount-fixed   { background:#f0fdf4; color:#15803d; }

/* Progress bar for uses */
.uses-bar { background:#f3f4f6; border-radius:4px; height:5px; margin-top:5px; overflow:hidden; }
.uses-bar-fill { height:100%; background:#6366f1; border-radius:4px; transition:width 0.4s; }

/* Form modal / drawer */
.coupon-form-wrap { background:var(--bg-primary); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:28px; margin-bottom:28px; }
.coupon-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.coupon-form-grid .full { grid-column:1/-1; }
@media(max-width:640px){ .coupon-form-grid { grid-template-columns:1fr; } }

/* Type checkboxes */
.type-check-group { display:flex; flex-wrap:wrap; gap:10px; }
.type-check-label { display:flex; align-items:center; gap:6px; padding:8px 14px; border:1.5px solid #d1d5db; border-radius:8px; cursor:pointer; font-size:0.875rem; font-weight:500; transition:all 0.15s; user-select:none; }
.type-check-label:hover { border-color:#6366f1; background:#f5f3ff; }
.type-check-label input:checked + span { color:#4f46e5; font-weight:700; }
.type-check-label:has(input:checked) { border-color:#6366f1; background:#ede9fe; color:#4f46e5; }

/* Scope toggle */
.scope-radio-group { display:flex; gap:10px; }
.scope-radio-label { display:flex; align-items:center; gap:8px; padding:10px 18px; border:1.5px solid #d1d5db; border-radius:8px; cursor:pointer; font-size:0.875rem; font-weight:500; transition:all 0.15s; flex:1; }
.scope-radio-label:hover { border-color:#6366f1; }
.scope-radio-label:has(input:checked) { border-color:#6366f1; background:#ede9fe; color:#4f46e5; font-weight:700; }

/* Specific events selector */
.event-multiselect { border:1.5px solid #d1d5db; border-radius:8px; padding:8px; max-height:180px; overflow-y:auto; background:#fff; }
.event-multiselect label { display:flex; align-items:center; gap:8px; padding:6px 8px; border-radius:6px; cursor:pointer; font-size:0.85rem; }
.event-multiselect label:hover { background:#f5f3ff; }
.event-multiselect label:has(input:checked) { background:#ede9fe; color:#4f46e5; font-weight:600; }

/* Action toolbar */
.coupon-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:18px; }
.coupon-toolbar-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.filter-select { padding:7px 12px; border:1px solid var(--border-color); border-radius:8px; font-size:0.85rem; background:var(--bg-primary); color:var(--text-primary); }
.search-box { padding:7px 12px; border:1px solid var(--border-color); border-radius:8px; font-size:0.85rem; background:var(--bg-primary); color:var(--text-primary); min-width:180px; }

/* Delete confirm */
.action-btn { background:none; border:none; cursor:pointer; padding:5px 8px; border-radius:6px; transition:background 0.15s; }
.action-btn:hover { background:#f3f4f6; }
.action-btn.danger:hover { background:#fee2e2; }

/* Empty state */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
.empty-state-icon { width:64px; height:64px; background:#f3f4f6; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; }

/* Tooltip copy feedback */
.copied-toast { position:fixed; bottom:24px; right:24px; background:#1f2937; color:#fff; padding:10px 18px; border-radius:10px; font-size:0.88rem; font-weight:600; z-index:9999; opacity:0; transform:translateY(8px); transition:all 0.25s; pointer-events:none; }
.copied-toast.show { opacity:1; transform:translateY(0); }
</style>

<!-- Stats bar -->
<div class="coupon-stats">
    <div class="coupon-stat-card">
        <div class="coupon-stat-label">Total Coupons</div>
        <div class="coupon-stat-value"><?php echo $totalCoupons; ?></div>
        <div class="coupon-stat-sub"><?php echo $totalActive; ?> active</div>
    </div>
    <div class="coupon-stat-card">
        <div class="coupon-stat-label">Total Redemptions</div>
        <div class="coupon-stat-value"><?php echo number_format($totalRedeemed); ?></div>
        <div class="coupon-stat-sub">across all coupons</div>
    </div>
    <div class="coupon-stat-card">
        <div class="coupon-stat-label">Total Savings Given</div>
        <div class="coupon-stat-value" style="font-size:1.4rem;"><?php echo formatPrice(floatval($totalSaved)); ?></div>
        <div class="coupon-stat-sub">discount amount given</div>
    </div>
</div>

<!-- Create / Edit form -->
<?php if (!empty($formError)): ?>
<div class="flash-message flash-error" style="margin-bottom:20px;"><?php echo htmlspecialchars($formError); ?></div>
<?php endif; ?>

<div class="coupon-form-wrap" id="couponFormWrap" style="<?php echo ($editCoupon || !empty($formError)) ? '' : 'display:none;'; ?>">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:22px;">
        <h3 style="font-size:1.1rem; font-weight:700; margin:0; display:flex; align-items:center; gap:10px;">
            <svg width="20" height="20" fill="none" stroke="#6366f1" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
            <?php echo $editCoupon ? 'Edit Coupon — ' . htmlspecialchars($editCoupon['code']) : 'Create New Coupon'; ?>
        </h3>
        <button type="button" onclick="document.getElementById('couponFormWrap').style.display='none'" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--text-muted);">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <form method="POST" action="">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="<?php echo $editCoupon ? 'edit' : 'create'; ?>">
        <?php if ($editCoupon): ?>
        <input type="hidden" name="coupon_id" value="<?php echo $editCoupon['id']; ?>">
        <?php endif; ?>

        <div class="coupon-form-grid">
            <!-- Code -->
            <div>
                <label class="form-label">Coupon Code <span style="color:#e53935;">*</span></label>
                <div style="position:relative;">
                    <input type="text" name="code" class="form-control" placeholder="e.g. KESA50" maxlength="50" style="text-transform:uppercase; font-family:monospace; letter-spacing:0.08em; font-weight:700;" required
                           value="<?php echo htmlspecialchars($editCoupon['code'] ?? ''); ?>">
                    <button type="button" onclick="generateCode()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6366f1;font-size:0.78rem;font-weight:600;white-space:nowrap;">Generate</button>
                </div>
                <p style="font-size:0.76rem;color:var(--text-muted);margin-top:4px;">Letters, numbers, hyphens only. Auto-uppercased.</p>
            </div>

            <!-- Name -->
            <div>
                <label class="form-label">Coupon Name <span style="color:#e53935;">*</span></label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Summer Sale 50% Off" maxlength="150" required
                       value="<?php echo htmlspecialchars($editCoupon['name'] ?? ''); ?>">
            </div>

            <!-- Active From -->
            <div>
                <label class="form-label">Active From</label>
                <input type="datetime-local" name="active_from" class="form-control"
                       value="<?php echo $editCoupon ? date('Y-m-d\TH:i', strtotime($editCoupon['active_from'])) : date('Y-m-d\TH:i'); ?>">
            </div>

            <!-- Expire On -->
            <div>
                <label class="form-label">Expire On <span style="font-size:0.75rem;color:var(--text-muted);font-weight:400;">(optional)</span></label>
                <input type="datetime-local" name="expire_on" class="form-control"
                       value="<?php echo ($editCoupon && $editCoupon['expire_on']) ? date('Y-m-d\TH:i', strtotime($editCoupon['expire_on'])) : ''; ?>">
            </div>

            <!-- Total limit -->
            <div>
                <label class="form-label">Total Coupon Limit <span style="font-size:0.75rem;color:var(--text-muted);font-weight:400;">(leave blank = unlimited)</span></label>
                <input type="number" name="max_uses_total" class="form-control" min="1" placeholder="e.g. 100"
                       value="<?php echo htmlspecialchars($editCoupon['max_uses_total'] ?? ''); ?>">
            </div>

            <!-- Per user limit -->
            <div>
                <label class="form-label">Coupon Limit Per Learner</label>
                <input type="number" name="max_uses_per_user" class="form-control" min="1" max="99" value="<?php echo htmlspecialchars($editCoupon['max_uses_per_user'] ?? 1); ?>">
            </div>

            <!-- Discount type + value -->
            <div>
                <label class="form-label">Discount Type <span style="color:#e53935;">*</span></label>
                <div style="display:flex; gap:0; border:1.5px solid #d1d5db; border-radius:8px; overflow:hidden;">
                    <label style="flex:1; display:flex; align-items:center; justify-content:center; gap:7px; padding:10px; cursor:pointer; font-size:0.875rem; font-weight:600; transition:background 0.15s;"
                           id="lblPercent" for="dtPercent">
                        <input type="radio" name="discount_type" id="dtPercent" value="percent" style="display:none;"
                               <?php echo (!$editCoupon || $editCoupon['discount_type'] === 'percent') ? 'checked' : ''; ?>
                               onchange="updateDiscountLabel()">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="19" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><path stroke-linecap="round" d="M4 20L20 4"/></svg>
                        Percentage %
                    </label>
                    <label style="flex:1; display:flex; align-items:center; justify-content:center; gap:7px; padding:10px; cursor:pointer; font-size:0.875rem; font-weight:600; border-left:1.5px solid #d1d5db; transition:background 0.15s;"
                           id="lblFixed" for="dtFixed">
                        <input type="radio" name="discount_type" id="dtFixed" value="fixed" style="display:none;"
                               <?php echo ($editCoupon && $editCoupon['discount_type'] === 'fixed') ? 'checked' : ''; ?>
                               onchange="updateDiscountLabel()">
                        <svg width="15" height="15" fill="currentColor" viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>
                        Fixed &#8377;
                    </label>
                </div>
            </div>

            <!-- Discount value -->
            <div>
                <label class="form-label">Discount Value <span style="color:#e53935;">*</span></label>
                <div style="position:relative;">
                    <span id="discountPrefix" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-weight:700;color:#374151;font-size:0.95rem;">%</span>
                    <input type="number" name="discount_value" class="form-control" min="0.01" step="0.01" style="padding-left:34px;" required
                           value="<?php echo htmlspecialchars($editCoupon['discount_value'] ?? ''); ?>">
                </div>
            </div>

            <!-- Min purchase -->
            <div>
                <label class="form-label">Minimum Purchase Amount <span style="font-size:0.75rem;color:var(--text-muted);font-weight:400;">(0 = no minimum)</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-weight:700;color:#374151;">&#8377;</span>
                    <input type="number" name="min_purchase_amount" class="form-control" min="0" step="0.01" style="padding-left:28px;"
                           value="<?php echo htmlspecialchars($editCoupon['min_purchase_amount'] ?? 0); ?>">
                </div>
            </div>

            <!-- Visibility -->
            <div>
                <label class="form-label">Coupon Visibility</label>
                <div style="display:flex;gap:10px;">
                    <label class="scope-radio-label" style="flex:1;">
                        <input type="radio" name="visibility" value="public" <?php echo (!$editCoupon || $editCoupon['visibility'] === 'public') ? 'checked' : ''; ?>>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                        Public
                    </label>
                    <label class="scope-radio-label" style="flex:1;">
                        <input type="radio" name="visibility" value="private" <?php echo ($editCoupon && $editCoupon['visibility'] === 'private') ? 'checked' : ''; ?>>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Private
                    </label>
                </div>
                <p style="font-size:0.75rem;color:var(--text-muted);margin-top:5px;">Private coupons are not shown on the payment page but still work when entered.</p>
            </div>

            <!-- Applicable event types -->
            <div class="full">
                <label class="form-label">Applicable For <span style="font-size:0.75rem;color:var(--text-muted);font-weight:400;">(leave all unchecked = any type)</span></label>
                <?php
                $savedTypes = $editCoupon ? explode(',', $editCoupon['applicable_types'] ?? '') : [];
                ?>
                <div class="type-check-group">
                    <?php foreach (['webinar' => 'Webinar', 'workshop' => 'Workshop', 'course' => 'Course', 'offline' => 'Offline / Special', 'special' => 'Special Event'] as $tv => $tl): ?>
                    <label class="type-check-label">
                        <input type="checkbox" name="applicable_types[]" value="<?php echo $tv; ?>" <?php echo in_array($tv, $savedTypes) ? 'checked' : ''; ?>>
                        <span><?php echo $tl; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Scope -->
            <div class="full">
                <label class="form-label">Apply Coupon To</label>
                <div class="scope-radio-group">
                    <label class="scope-radio-label">
                        <input type="radio" name="scope" value="all" id="scopeAll" onchange="toggleScope()"
                               <?php echo (!$editCoupon || $editCoupon['scope'] === 'all') ? 'checked' : ''; ?>>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        All events (based on selected type above)
                    </label>
                    <label class="scope-radio-label">
                        <input type="radio" name="scope" value="specific" id="scopeSpecific" onchange="toggleScope()"
                               <?php echo ($editCoupon && $editCoupon['scope'] === 'specific') ? 'checked' : ''; ?>>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Only specific events
                    </label>
                </div>
            </div>

            <!-- Specific events list -->
            <div class="full" id="specificEventsWrap" style="<?php echo ($editCoupon && $editCoupon['scope'] === 'specific') ? '' : 'display:none;'; ?>">
                <label class="form-label">Select Events</label>
                <div class="event-multiselect">
                    <?php if (empty($allEvents)): ?>
                    <p style="color:var(--text-muted);font-size:0.85rem;padding:8px;">No published events found.</p>
                    <?php else: ?>
                    <?php foreach ($allEvents as $ev): ?>
                    <label>
                        <input type="checkbox" name="specific_events[]" value="<?php echo $ev['id']; ?>"
                               <?php echo in_array($ev['id'], $editCouponEvents) ? 'checked' : ''; ?>>
                        <span><?php echo htmlspecialchars($ev['title']); ?></span>
                        <span class="badge-type" style="margin-left:auto;"><?php echo ucfirst($ev['type']); ?></span>
                    </label>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.75rem;color:var(--text-muted);margin-top:5px;">Check all events this coupon should apply to.</p>
            </div>

            <!-- Active toggle -->
            <div class="full" style="display:flex; align-items:center; gap:12px; padding:14px; background:#f9fafb; border-radius:10px; border:1px solid #e5e7eb;">
                <input type="checkbox" name="is_active" id="isActiveCk" value="1" style="width:18px;height:18px;accent-color:#6366f1;"
                       <?php echo (!$editCoupon || $editCoupon['is_active']) ? 'checked' : ''; ?>>
                <label for="isActiveCk" style="font-size:0.9rem;font-weight:600;cursor:pointer;">
                    Coupon is active &amp; accepting redemptions
                </label>
            </div>
        </div>

        <div style="display:flex; gap:12px; margin-top:24px;">
            <button type="submit" class="btn btn-primary" style="padding:11px 28px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <?php echo $editCoupon ? 'Update Coupon' : 'Create Coupon'; ?>
            </button>
            <a href="/admin/coupons/" class="btn btn-secondary" style="padding:11px 20px;">Cancel</a>
        </div>
    </form>
</div>

<!-- Toolbar -->
<div class="coupon-toolbar">
    <div class="coupon-toolbar-filters">
        <form method="GET" style="display:contents;">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search code or name..." class="search-box" style="flex:1;">
            <select name="type" class="filter-select" onchange="this.form.submit()">
                <option value="">All Types</option>
                <?php foreach (['webinar'=>'Webinar','workshop'=>'Workshop','course'=>'Course','offline'=>'Offline','special'=>'Special'] as $tv=>$tl): ?>
                <option value="<?php echo $tv; ?>" <?php echo $filterType===$tv?'selected':''; ?>><?php echo $tl; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="vis" class="filter-select" onchange="this.form.submit()">
                <option value="">All Visibility</option>
                <option value="public" <?php echo $filterVis==='public'?'selected':''; ?>>Public</option>
                <option value="private" <?php echo $filterVis==='private'?'selected':''; ?>>Private</option>
            </select>
            <button type="submit" class="btn btn-secondary" style="padding:8px 14px;">Filter</button>
        </form>
    </div>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('couponFormWrap').style.display='block'; window.scrollTo({top:0,behavior:'smooth'});">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        New Coupon
    </button>
</div>

<!-- Coupon table -->
<div style="background:var(--bg-primary); border:1px solid var(--border-color); border-radius:var(--radius-md); overflow:hidden;">
    <?php if (empty($coupons)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <svg width="28" height="28" fill="none" stroke="#9ca3af" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
        </div>
        <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:6px;">No coupons yet</h3>
        <p style="font-size:0.875rem;">Create your first offer to give learners a discount on events.</p>
        <button onclick="document.getElementById('couponFormWrap').style.display='block';" class="btn btn-primary" style="margin-top:16px;">Create Coupon</button>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="coupon-table">
        <thead>
            <tr>
                <th>Code / Name</th>
                <th>Discount</th>
                <th>Validity</th>
                <th>Types</th>
                <th>Uses</th>
                <th>Savings Given</th>
                <th>Visibility</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($coupons as $c):
            $now = time();
            $from = strtotime($c['active_from']);
            $exp  = $c['expire_on'] ? strtotime($c['expire_on']) : null;
            $isExpired = $exp && $exp < $now;
            $notStarted = $from > $now;
            $usage = $usageMap[$c['id']] ?? ['cnt' => 0, 'total_saved' => 0];
            $usePct = $c['max_uses_total'] ? min(100, round($c['uses_count'] / $c['max_uses_total'] * 100)) : 0;
            $types = array_filter(explode(',', $c['applicable_types'] ?? ''));
        ?>
        <tr>
            <td>
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <span class="coupon-code-chip" onclick="copyCoupon('<?php echo htmlspecialchars($c['code']); ?>')">
                        <?php echo htmlspecialchars($c['code']); ?>
                        <span class="copy-icon"><svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg></span>
                    </span>
                    <span style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($c['name']); ?></span>
                </div>
            </td>
            <td>
                <span class="discount-pill <?php echo $c['discount_type']==='percent'?'discount-percent':'discount-fixed'; ?>">
                    <?php if ($c['discount_type']==='percent'): ?>
                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><circle cx="19" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><path stroke-linecap="round" d="M4 20L20 4"/></svg>
                        <?php echo floatval($c['discount_value']); ?>% OFF
                    <?php else: ?>
                        <svg width="13" height="13" fill="currentColor" viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>
                        <?php echo formatPrice(floatval($c['discount_value'])); ?> OFF
                    <?php endif; ?>
                </span>
                <?php if ($c['min_purchase_amount'] > 0): ?>
                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;">Min. <?php echo formatPrice($c['min_purchase_amount']); ?></div>
                <?php endif; ?>
            </td>
            <td style="min-width:130px;">
                <div style="font-size:0.8rem;">
                    <div style="color:var(--text-muted);font-size:0.72rem;">From</div>
                    <div style="font-weight:600;"><?php echo date('d M Y', strtotime($c['active_from'])); ?></div>
                    <?php if ($c['expire_on']): ?>
                    <div style="color:var(--text-muted);font-size:0.72rem;margin-top:4px;"><?php echo $isExpired ? 'Expired' : 'Expires'; ?></div>
                    <div style="font-weight:600;<?php echo $isExpired?'color:#dc2626;':($exp - $now < 86400*3?'color:#d97706;':''); ?>">
                        <?php echo date('d M Y', strtotime($c['expire_on'])); ?>
                    </div>
                    <?php else: ?>
                    <div style="color:#6b7280;font-size:0.72rem;margin-top:4px;">No expiry</div>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <?php if (empty($types)): ?>
                <span style="font-size:0.78rem;color:var(--text-muted);">All types</span>
                <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <?php foreach ($types as $t): ?>
                    <span class="badge-type"><?php echo ucfirst($t); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($c['scope']==='specific'): ?>
                <div style="font-size:0.72rem;color:#7c3aed;margin-top:4px;font-weight:600;">Specific events only</div>
                <?php endif; ?>
            </td>
            <td style="min-width:100px;">
                <div style="font-size:0.875rem;font-weight:700;"><?php echo number_format($c['uses_count']); ?><?php echo $c['max_uses_total'] ? '<span style="font-weight:400;color:var(--text-muted);">/' . $c['max_uses_total'] . '</span>' : ''; ?></div>
                <div style="font-size:0.72rem;color:var(--text-muted);"><?php echo $c['max_uses_per_user']; ?>/learner max</div>
                <?php if ($c['max_uses_total']): ?>
                <div class="uses-bar"><div class="uses-bar-fill" style="width:<?php echo $usePct; ?>%;<?php echo $usePct>=90?'background:#ef4444':($usePct>=70?'background:#f59e0b':''); ?>"></div></div>
                <?php endif; ?>
            </td>
            <td>
                <span style="font-weight:700;color:#059669;"><?php echo formatPrice(floatval($usage['total_saved'])); ?></span>
                <div style="font-size:0.72rem;color:var(--text-muted);"><?php echo number_format(intval($usage['cnt'])); ?> uses</div>
            </td>
            <td>
                <span class="badge-vis <?php echo $c['visibility']==='public'?'badge-public':'badge-private'; ?>">
                    <?php echo ucfirst($c['visibility']); ?>
                </span>
            </td>
            <td>
                <?php if ($isExpired): ?>
                <span class="badge-vis badge-expired">Expired</span>
                <?php elseif ($notStarted): ?>
                <span class="badge-vis" style="background:#eff6ff;color:#1e40af;">Scheduled</span>
                <?php elseif ($c['is_active']): ?>
                <span class="badge-vis badge-active">Active</span>
                <?php else: ?>
                <span class="badge-vis badge-inactive">Inactive</span>
                <?php endif; ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:2px;">
                    <!-- Toggle active -->
                    <a href="/admin/coupons/?toggle=<?php echo $c['id']; ?>&token=<?php echo generateCSRFToken(); ?>"
                       class="action-btn" title="<?php echo $c['is_active']?'Deactivate':'Activate'; ?>"
                       style="color:<?php echo $c['is_active']?'#059669':'#6b7280'; ?>;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <?php if ($c['is_active']): ?><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            <?php else: ?><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><?php endif; ?>
                        </svg>
                    </a>
                    <!-- Edit -->
                    <a href="/admin/coupons/?edit=<?php echo $c['id']; ?>" class="action-btn" title="Edit" onclick="window.scrollTo({top:0,behavior:'smooth'})">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </a>
                    <!-- Delete -->
                    <a href="/admin/coupons/?delete=<?php echo $c['id']; ?>&token=<?php echo generateCSRFToken(); ?>"
                       class="action-btn danger" title="Delete"
                       onclick="return confirm('Delete coupon \'<?php echo addslashes($c['code']); ?>\'? This cannot be undone.')">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--border-color);">
        <span style="font-size:0.82rem;color:var(--text-muted);">Showing <?php echo (($page-1)*$perPage)+1; ?>–<?php echo min($page*$perPage,$totalCoupons); ?> of <?php echo $totalCoupons; ?></span>
        <div style="display:flex;gap:6px;">
            <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&type=<?php echo $filterType; ?>&vis=<?php echo $filterVis; ?>" class="btn btn-sm btn-secondary">Prev</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&type=<?php echo $filterType; ?>&vis=<?php echo $filterVis; ?>" class="btn btn-sm btn-secondary">Next</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Copy toast -->
<div class="copied-toast" id="copiedToast">Code copied to clipboard</div>

<script>
function copyCoupon(code) {
    navigator.clipboard.writeText(code).then(function(){
        var t = document.getElementById('copiedToast');
        t.textContent = '"' + code + '" copied!';
        t.classList.add('show');
        setTimeout(function(){ t.classList.remove('show'); }, 2000);
    });
}

function generateCode() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    var code = 'KESA';
    for (var i = 0; i < 6; i++) code += chars[Math.floor(Math.random() * chars.length)];
    var el = document.querySelector('input[name="code"]');
    if (el) { el.value = code; el.dispatchEvent(new Event('input')); }
}

function toggleScope() {
    var specific = document.getElementById('scopeSpecific');
    var wrap = document.getElementById('specificEventsWrap');
    if (wrap) wrap.style.display = specific && specific.checked ? 'block' : 'none';
}

function updateDiscountLabel() {
    var isPercent = document.getElementById('dtPercent').checked;
    var prefix = document.getElementById('discountPrefix');
    if (prefix) prefix.textContent = isPercent ? '%' : '₹';
    var lblP = document.getElementById('lblPercent');
    var lblF = document.getElementById('lblFixed');
    if (lblP) lblP.style.background = isPercent ? '#ede9fe' : '';
    if (lblF) lblF.style.background = !isPercent ? '#ede9fe' : '';
}

// Init
updateDiscountLabel();
document.querySelectorAll('input[name="discount_type"]').forEach(function(r){
    r.addEventListener('change', updateDiscountLabel);
});
document.querySelector('input[name="code"]')?.addEventListener('input', function(){
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9_\-]/g,'');
});

// Auto-scroll to form on edit
<?php if ($editCoupon): ?>
document.getElementById('couponFormWrap').scrollIntoView({behavior:'smooth', block:'start'});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
