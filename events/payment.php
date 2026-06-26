<?php
/**
 * KESA Learn - Payment Page (Razorpay + Manual UPI)
 * Redesigned: Method selector + UPI sub-options (QR, Link, ID)
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/razorpay.php';

$registrationId = intval($_GET['registration_id'] ?? 0);
if (!$registrationId) {
    setFlash('error', 'Invalid registration.');
    redirect('/user/dashboard');
}

$db = getDB();
$stmt = $db->prepare("
    SELECT r.*, r.id AS registration_id, e.id AS event_id,
           e.type, e.title, e.price, e.currency, e.slug, e.payment_methods,
           e.support_phone, e.support_whatsapp, e.banner_image, e.short_description
    FROM registrations r
    JOIN events e ON r.event_id = e.id
    WHERE r.id = ? AND r.user_id = ?
");
$stmt->execute([$registrationId, $_SESSION['user_id']]);
$registration = $stmt->fetch();

if (!$registration) {
    setFlash('error', 'Registration not found.');
    redirect('/user/dashboard');
}

if (in_array($registration['payment_status'], ['paid', 'verified'])) {
    setFlash('info', 'Payment already completed.');
    redirect('/user/dashboard');
}

// If the user has already submitted a proof and it is pending verification,
// do not show the payment form again until admin rejects it.
if ($registration['payment_status'] === 'pending' && !empty($registration['payment_proof'])) {
    setFlash('info', 'Your payment proof has been submitted and is under review. We will notify you once it is verified.');
    redirect('/user/event-details?event_id=' . intval($registration['event_id']));
}

$user        = getCurrentUser();

// ── True original fee = always from e.price (event table), never from registrations.amount
// registrations.amount gets mutated when a coupon is applied, so it cannot be trusted as the original.
$originalEventFee = floatval($registration['price']); // e.price — immutable event price
$amount           = $registration['amount'];            // registrations.amount — may be discounted
// amountPaise is updated after fee calculation below
$amountPaise = intval($originalEventFee * 100); // placeholder — updated after fees calculated

// ── Fetch payment settings ──────────────────────────────────────────────────
$currentUPI      = UPI_ID ?? '';
$currentUPIName  = UPI_NAME ?? '';
$currentUPILink  = '';
$currentUPIQrUrl = '';
$currentRazorpayKey = RAZORPAY_KEY_ID ?? '';

try {
    $stmtUPI = $db->prepare("
        SELECT upi_id, upi_beneficiary_name, upi_payment_link, upi_qr_code
        FROM payment_settings
        WHERE setting_type = 'upi' AND is_active = 1 AND is_primary = 1
        LIMIT 1
    ");
    $stmtUPI->execute();
    $upiData = $stmtUPI->fetch();
    if ($upiData) {
        $currentUPI      = $upiData['upi_id'];
        $currentUPIName  = $upiData['upi_beneficiary_name'];
        $currentUPILink  = $upiData['upi_payment_link'] ?? '';
        $upiQrPath       = $upiData['upi_qr_code'] ?? '';
        $currentUPIQrUrl = !empty($upiQrPath) ? UPLOAD_URL . $upiQrPath : '';
    }

    $stmtRazorpay = $db->prepare("
        SELECT gateway_key_id
        FROM payment_settings
        WHERE setting_type = 'gateway' AND gateway_name = 'Razorpay' AND is_active = 1 AND is_primary = 1
        LIMIT 1
    ");
    $stmtRazorpay->execute();
    $razorpayData = $stmtRazorpay->fetch();
    if ($razorpayData) {
        $currentRazorpayKey = $razorpayData['gateway_key_id'];
    }
} catch (Exception $e) {
    error_log("[Payment] Error fetching payment settings: " . $e->getMessage());
}

// ── Fetch fee/tax/discount settings from actual upi + gateway rows ──────────
$gstPercent         = 0.0;
$gatewayFeePercent  = 0.0;
$upiDiscountPercent = 0.0;
try {
    // GST and UPI discount stored on the primary UPI row
    $stmtFeesUPI = $db->prepare("SELECT gst_percent, discount_percent FROM payment_settings WHERE setting_type = 'upi' AND is_active = 1 AND is_primary = 1 LIMIT 1");
    $stmtFeesUPI->execute();
    $feesUPIData = $stmtFeesUPI->fetch();
    if ($feesUPIData) {
        $gstPercent         = max(0, floatval($feesUPIData['gst_percent']));
        $upiDiscountPercent = max(0, floatval($feesUPIData['discount_percent']));
    }
    // Gateway fee stored on the primary gateway row
    $stmtFeesGW = $db->prepare("SELECT gateway_fee_percent FROM payment_settings WHERE setting_type = 'gateway' AND is_active = 1 AND is_primary = 1 LIMIT 1");
    $stmtFeesGW->execute();
    $feesGWData = $stmtFeesGW->fetch();
    if ($feesGWData) {
        $gatewayFeePercent = max(0, floatval($feesGWData['gateway_fee_percent']));
    }
} catch (Exception $e) {
    error_log("[Payment] Error fetching fee settings: " . $e->getMessage());
}

// ── Apply coupon if one is stored in session for this registration ──────────
$appliedCoupon     = null;
$couponDiscount    = 0;
if (!empty($_SESSION['applied_coupon'])
    && $_SESSION['applied_coupon']['registration_id'] == $registrationId) {
    $ac = $_SESSION['applied_coupon'];
    // Verify coupon is still valid
    $cvStmt = $db->prepare("SELECT id, code, name, discount_type, discount_value FROM coupons WHERE id = ? AND is_active = 1 AND active_from <= NOW() AND (expire_on IS NULL OR expire_on > NOW())");
    $cvStmt->execute([$ac['coupon_id']]);
    if ($cvStmt->fetch()) {
        $appliedCoupon  = $ac;
        $couponDiscount = floatval($ac['discount_amount']);
    } else {
        unset($_SESSION['applied_coupon']);
    }
}

// Fetch public coupons applicable for this event type (for display hint)
$publicCoupons = [];
try {
    $pcStmt = $db->prepare("
        SELECT c.code, c.name, c.discount_type, c.discount_value
        FROM coupons c
        WHERE c.is_active = 1
          AND c.visibility = 'public'
          AND c.active_from <= NOW()
          AND (c.expire_on IS NULL OR c.expire_on > NOW())
          AND (c.max_uses_total IS NULL OR c.uses_count < c.max_uses_total)
          AND (c.applicable_types = '' OR FIND_IN_SET(?, c.applicable_types))
          AND (c.scope = 'all' OR EXISTS (SELECT 1 FROM coupon_events ce WHERE ce.coupon_id = c.id AND ce.event_id = ?))
        ORDER BY c.discount_value DESC
        LIMIT 3
    ");
    $pcStmt->execute([$registration['type'] ?? 'webinar', $registration['event_id']]);
    $publicCoupons = $pcStmt->fetchAll();
} catch (Exception $e) { /* coupon table may not exist yet */ }

// ── Amount logic: NEVER mutate the original event fee ──────────────────────
// $originalEventFee = e.price from events table — always the true displayed course fee
// $couponDiscount   = discount calculated by coupon.php on the original price
// $amountAfterCoupon = what the user actually pays before GST/fees

$baseAmount        = $originalEventFee; // always the real, unchanged course fee for display
$amountAfterCoupon = ($appliedCoupon) ? max(0, $originalEventFee - $couponDiscount) : $originalEventFee;

// ── Only apply UPI discount if NO coupon is applied (one discount at a time) ──
$effectiveUPIDiscountPercent = ($appliedCoupon) ? 0 : $upiDiscountPercent;
$upiDiscountAmount = ($effectiveUPIDiscountPercent > 0) ? round($amountAfterCoupon * $effectiveUPIDiscountPercent / 100, 2) : 0;
$upiDiscountedBase = $amountAfterCoupon - $upiDiscountAmount;
$upiGstAmount      = ($gstPercent > 0) ? round($upiDiscountedBase * $gstPercent / 100, 2) : 0;
$finalUPIAmount    = $upiDiscountedBase + $upiGstAmount;

// Card path: amountAfterCoupon + GST + Gateway Fee
$cardGstAmount    = ($gstPercent > 0)        ? round($amountAfterCoupon * $gstPercent / 100, 2)        : 0;
$gatewayFeeAmount = ($gatewayFeePercent > 0) ? round($amountAfterCoupon * $gatewayFeePercent / 100, 2) : 0;
$finalCardAmount  = $amountAfterCoupon + $cardGstAmount + $gatewayFeeAmount;

$amountPaise = intval(round($finalCardAmount) * 100); // Razorpay uses paise

// ── Build dynamic UPI payment link (must come AFTER $finalUPIAmount is set) ──
// Uses rawurlencode() so spaces become %20, not +. UPI apps require %20 in tn=.
$dynamicUPILink = '';
if (!empty($currentUPI)) {
    $upiAmount  = round($finalUPIAmount);          // integer, no fractions
    $eventId    = intval($registration['event_id'] ?? 0);
    // tn= exactly: "KESA Payment" + optional event id suffix using %20 not +
    $tnValue    = 'KESA%20Payment' . ($eventId > 0 ? '%20Event%23' . $eventId : '');

    if (!empty($currentUPILink)) {
        // Strip any stale am/cu/tn params the admin may have already stored
        $baseLink = preg_replace('/[&?](am|cu|tn)=[^&]*/i', '', $currentUPILink);
        $baseLink = rtrim($baseLink, '?&');
        $sep      = (strpos($baseLink, '?') === false) ? '?' : '&';
        $dynamicUPILink = $baseLink . $sep . 'am=' . $upiAmount . '&cu=INR&tn=' . $tnValue;
    } else {
        // Build full UPI deep-link from scratch
        $dynamicUPILink = 'upi://pay?pa=' . rawurlencode($currentUPI)
                        . '&pn=' . rawurlencode($currentUPIName)
                        . '&am=' . $upiAmount
                        . '&cu=INR'
                        . '&tn=' . $tnValue;
    }
}

// ── Determine available methods ─────────────────────────────────────────────
$paymentMethods = $registration['payment_methods'] ?? 'both';
$showRazorpay   = in_array($paymentMethods, ['razorpay', 'both']);
$showUPI        = in_array($paymentMethods, ['upi', 'both']);
$onlyOneMethod  = ($showRazorpay XOR $showUPI);   // exactly one enabled

// ── Handle UPI proof upload ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_method']) && $_POST['payment_method'] === 'upi') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please try again.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if (empty($_FILES['payment_proof']['name'])) {
        setFlash('error', 'Please upload your payment screenshot before submitting.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $upload = uploadFile($_FILES['payment_proof'], 'payment_proofs', ALLOWED_IMAGE_TYPES, MAX_PROOF_SIZE);

    if ($upload['success']) {
        // $finalUPIAmount is already calculated above — this is the exact amount the user was shown and paid.
        // Store it as final_amount so admin always sees the real charged amount.
        $stmt = $db->prepare("
            UPDATE registrations
            SET payment_method = 'upi',
                payment_proof  = ?,
                payment_status = 'pending',
                final_amount   = ?
            WHERE id = ?
        ");
        $stmt->execute([$upload['path'], round($finalUPIAmount, 2), $registrationId]);
        logActivity('payment_proof_uploaded', "Payment proof uploaded for registration #$registrationId. Final UPI amount: " . round($finalUPIAmount, 2));
        setFlash('success', 'Payment proof submitted! Our team will verify it within 30 minutes to 6 hours.');
        redirect('/user/dashboard');
    } else {
        setFlash('error', $upload['error']);
        redirect($_SERVER['REQUEST_URI']);
    }
}

// ── Meta / page setup ───────────────────────────────────────────────────────
$pageTitle = 'Complete Payment — ' . $registration['title'];
$baseUrl   = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

$bannerImage = $registration['banner_image'] ?? '';
if (!empty($bannerImage) && strpos($bannerImage, 'http') !== 0) {
    $bannerImage = $baseUrl . (str_starts_with($bannerImage, '/') ? $bannerImage : '/' . $bannerImage);
} elseif (empty($bannerImage)) {
    $bannerImage = $baseUrl . '/assets/images/default-event-banner.png';
}

$ogDescription = !empty($registration['short_description'])
    ? substr(sanitize($registration['short_description']), 0, 160)
    : 'Complete your registration payment on KESA Learn';

$extraHead = '
<meta name="razorpay-key" content="' . htmlspecialchars($currentRazorpayKey) . '">
<meta property="og:title" content="' . htmlspecialchars($registration['title']) . '">
<meta property="og:description" content="' . htmlspecialchars($ogDescription) . '">
<meta property="og:image" content="' . htmlspecialchars($bannerImage) . '">
<meta property="og:type" content="website">
<meta property="og:site_name" content="KESA Learn">
<meta name="twitter:card" content="summary_large_image">
';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Payment Page Styles ─────────────────────────────────────── */
.pay-page {
    min-height: 80vh;
    background: #f4f6fb;
    padding: 40px 16px 60px;
}

.pay-wrap {
    max-width: 560px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Back link */
.pay-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.875rem;
    color: var(--text-muted);
    text-decoration: none;
    transition: color 0.2s;
}
.pay-back:hover { color: var(--text-primary); }

/* Card base */
.pay-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    overflow: hidden;
}

/* Order summary */
.pay-summary {
    padding: 22px 24px;
}
.pay-summary-title {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 14px;
}
.pay-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 9px 0;
    border-top: 1px solid #f3f4f6;
}
/* First visible row after the event title row — no top border */
.pay-summary-row:first-of-type {
    border-top: none;
}
/* Discount row */
.pay-summary-row--discount {
    border-top: 1px solid #f3f4f6;
}
/* Small muted extra-charge rows */
.pay-summary-row--muted {
    border-top: none;
    padding: 3px 0;
}
/* Total row — bold divider above */
.pay-summary-row--total {
    border-top: 2px solid #e5e7eb;
    margin-top: 6px;
    padding-top: 12px;
    padding-bottom: 4px;
}
/* Row label styles */
.pay-row-label {
    font-size: 0.88rem;
    color: var(--text-muted);
}
.pay-row-label--discount {
    font-size: 0.88rem;
    font-weight: 600;
    color: #059669;
    display: flex;
    align-items: center;
    gap: 5px;
}
.pay-row-label--muted {
    font-size: 0.78rem;
    color: #9ca3af;
}
.pay-row-label--savings {
    font-size: 0.88rem;
    font-weight: 700;
    color: #059669;
}
/* Row value styles */
.pay-row-value {
    font-size: 0.95rem;
    font-weight: 500;
    color: #374151;
    white-space: nowrap;
}
.pay-row-value--discount {
    font-size: 0.95rem;
    font-weight: 700;
    color: #059669;
    white-space: nowrap;
}
.pay-row-value--muted {
    font-size: 0.78rem;
    color: #9ca3af;
    white-space: nowrap;
}
.pay-summary-event {
    font-size: 0.95rem;
    font-weight: 500;
    color: var(--text-primary);
    max-width: 320px;
    line-height: 1.4;
}
.pay-summary-amount {
    font-size: 1.45rem;
    font-weight: 800;
    color: #111827;
    white-space: nowrap;
}

/* Method selector */
.pay-methods-label {
    padding: 20px 24px 0;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.pay-methods-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding: 14px 24px 20px;
}
.pay-methods-grid.single-method {
    grid-template-columns: 1fr;
}

.pay-method-option {
    position: relative;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    background: #fafafa;
    user-select: none;
}
.pay-method-option:hover:not(.pay-method-disabled) {
    border-color: #6366f1;
    background: #f5f3ff;
}
.pay-method-option.selected {
    border-color: #6366f1;
    background: #f5f3ff;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
}
.pay-method-option.pay-method-disabled {
    cursor: default;
    opacity: 0.7;
}

.pay-method-radio {
    position: absolute;
    top: 14px;
    right: 14px;
    width: 18px;
    height: 18px;
    border: 2px solid #d1d5db;
    border-radius: 50%;
    background: #fff;
    transition: border-color 0.2s, background 0.2s;
    flex-shrink: 0;
}
.pay-method-option.selected .pay-method-radio {
    border-color: #6366f1;
    background: #6366f1;
    box-shadow: inset 0 0 0 3px #fff;
}

.pay-method-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}
.icon-razorpay { background: #eff6ff; }
.icon-upi      { background: #f0fdf4; }

.pay-method-name {
    font-size: 0.9rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 3px;
}
.pay-method-desc {
    font-size: 0.78rem;
    color: var(--text-muted);
    line-height: 1.4;
}
.pay-method-instant {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    color: #059669;
    background: #ecfdf5;
    border-radius: 20px;
    padding: 2px 8px;
    margin-top: 6px;
}
.pay-method-manual {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    color: #d97706;
    background: #fffbeb;
    border-radius: 20px;
    padding: 2px 8px;
    margin-top: 6px;
}

/* ── UPI Panel ────────── */
.pay-upi-panel {
    border-top: 1px solid #f3f4f6;
    padding: 0 24px 24px;
    display: none;
}
.pay-upi-panel.visible { display: block; }

.upi-panel-title {
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--text-muted);
    padding: 18px 0 12px;
}

/* UPI options: QR, Link, ID */
.upi-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 20px;
}

/* QR option */
.upi-qr-block {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    text-align: center;
}
.upi-qr-img {
    width: 170px;
    height: 170px;
    object-fit: contain;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    background: #fff;
    padding: 6px;
}
.upi-qr-label {
    font-size: 0.82rem;
    color: var(--text-muted);
    line-height: 1.5;
}
.upi-qr-label strong {
    display: block;
    font-size: 0.92rem;
    color: #111827;
    margin-bottom: 2px;
}

/* Click to Pay (link) */
.upi-link-block {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.upi-link-info { flex: 1; }
.upi-link-info strong { font-size: 0.9rem; color: #111827; display: block; margin-bottom: 2px; }
.upi-link-info span  { font-size: 0.78rem; color: var(--text-muted); }
.upi-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    background: #6366f1;
    color: #fff;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.2s, transform 0.15s;
}
.upi-link-btn:hover { background: #4f46e5; transform: translateY(-1px); }

/* Send to UPI ID */
.upi-id-block {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px 20px;
}
.upi-id-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.upi-id-info strong { font-size: 0.9rem; color: #111827; display: block; margin-bottom: 2px; }
.upi-id-info span   { font-size: 0.78rem; color: var(--text-muted); }
.upi-id-value {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #fff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 8px 14px;
    font-family: monospace;
    font-size: 0.9rem;
    font-weight: 700;
    color: #111827;
    cursor: pointer;
    transition: border-color 0.2s;
    width: 100%;
    margin-top: 10px;
    justify-content: space-between;
}
.upi-id-value:hover { border-color: #6366f1; }
.upi-copy-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #6366f1;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
}
.upi-copy-btn.copied { color: #059669; }

/* Divider between UPI options */
.upi-or-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.75rem;
    color: var(--text-muted);
}
.upi-or-divider::before,
.upi-or-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e5e7eb;
}

/* Proof upload */
.pay-proof-section { padding-top: 4px; }
.pay-proof-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.pay-proof-required { color: #ef4444; }
.pay-proof-drop {
    border: 2px dashed #d1d5db;
    border-radius: 10px;
    padding: 28px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    background: #fafafa;
    position: relative;
    overflow: hidden;
}
.pay-proof-drop:hover, .pay-proof-drop.drag-over {
    border-color: #6366f1;
    background: #f5f3ff;
}
.pay-proof-drop input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
}
.pay-proof-drop-icon {
    width: 44px;
    height: 44px;
    background: #ede9fe;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
}
.pay-proof-drop-text {
    font-size: 0.88rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 4px;
}
.pay-proof-drop-hint {
    font-size: 0.75rem;
    color: var(--text-muted);
}
.pay-proof-preview {
    display: none;
    margin-top: 12px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}
.pay-proof-preview img {
    max-width: 100%;
    max-height: 220px;
    object-fit: contain;
    display: block;
    margin: 0 auto;
}

/* Razorpay panel */
.pay-razorpay-panel {
    border-top: 1px solid #f3f4f6;
    padding: 20px 24px 24px;
    display: none;
}
.pay-razorpay-panel.visible { display: block; }

/* Submit / action row */
.pay-action {
    padding: 0 24px 24px;
}
.pay-submit-btn {
    width: 100%;
    padding: 15px;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
    letter-spacing: 0.01em;
}
.pay-submit-btn:active { transform: scale(0.98); }
.pay-submit-razorpay {
    background: #4f46e5;
    color: #fff;
    box-shadow: 0 4px 14px rgba(79,70,229,0.3);
}
.pay-submit-razorpay:hover { background: #4338ca; box-shadow: 0 6px 18px rgba(79,70,229,0.35); }
.pay-submit-upi {
    background: #059669;
    color: #fff;
    box-shadow: 0 4px 14px rgba(5,150,105,0.3);
}
.pay-submit-upi:hover { background: #047857; box-shadow: 0 6px 18px rgba(5,150,105,0.35); }

/* Security note */
.pay-secure-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    font-size: 0.78rem;
    color: var(--text-muted);
    padding: 14px 24px;
    border-top: 1px solid #f3f4f6;
}

/* Support */
.pay-support {
    padding: 20px 24px;
    background: #fafafa;
    border-top: 1px solid #f3f4f6;
    border-radius: 0 0 16px 16px;
    text-align: center;
}
.pay-support p {
    font-size: 0.82rem;
    color: var(--text-muted);
    margin-bottom: 10px;
}
.pay-support-btns {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}
.pay-support-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    text-decoration: none;
    background: #fff;
    color: #374151;
    transition: border-color 0.2s, background 0.2s;
}
.pay-support-link:hover { border-color: #6366f1; color: #6366f1; background: #f5f3ff; }
.pay-support-link.whatsapp {
    background: #25D366;
    border-color: #25D366;
    color: #fff;
}
.pay-support-link.whatsapp:hover {
    background: #128C7E;
    border-color: #128C7E;
    color: #fff;
}

/* Discount badge on method card */
.pay-method-badge-discount {
    position: absolute;
    top: -1px;
    left: -1px;
    background: #059669;
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    padding: 3px 9px 3px 7px;
    border-radius: 11px 0 10px 0;
    display: flex;
    align-items: center;
    gap: 4px;
    letter-spacing: 0.02em;
}

/* Final amount pill inside method card */
.pay-method-final-amt {
    font-size: 0.78rem;
    font-weight: 700;
    color: #111827;
    background: #f3f4f6;
    border-radius: 20px;
    padding: 1px 8px;
}
.pay-method-option.selected .pay-method-final-amt {
    background: rgba(99,102,241,0.1);
    color: #4f46e5;
}

/* Flash messages */
.pay-flash {
    padding: 14px 18px;
    border-radius: 10px;
    font-size: 0.88rem;
    font-weight: 500;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.pay-flash.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.pay-flash.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.pay-flash.info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

/* Desktop: hide UPI Link (Click to Pay) — QR + UPI ID only */
@media (min-width: 481px) {
    .upi-link-block        { display: none !important; }
    .upi-link-or-divider   { display: none !important; }
}

/* Mobile: hide QR code and its divider — UPI ID + UPI Link only */
@media (max-width: 480px) {
    .pay-methods-grid    { grid-template-columns: 1fr; }
    .pay-page            { padding: 24px 12px 48px; }
    .upi-qr-block        { display: none !important; }
    .upi-qr-or-divider   { display: none !important; }
    .upi-link-block      { flex-direction: column; align-items: flex-start; }
    .upi-link-btn        { width: 100%; justify-content: center; }
}
</style>

<div class="pay-page">
<div class="pay-wrap">

    <!-- Back -->
    <a href="/user/event-details?event_id=<?php echo intval($registration['event_id']); ?>" class="pay-back">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to event
    </a>

    <?php
    // Flash messages
    $flash = getFlash();
    if ($flash):
        $ftype = $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : 'info');
    ?>
    <div class="pay-flash <?php echo $ftype; ?>">
        <?php if ($ftype === 'error'): ?>
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?php elseif ($ftype === 'success'): ?>
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?php else: ?>
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?php endif; ?>
        <?php echo sanitize($flash['message']); ?>
    </div>
    <?php endif; ?>

    <!-- Order Summary -->
    <div class="pay-card">
        <div class="pay-summary">
            <div class="pay-summary-title">Order Summary</div>
            <div class="pay-summary-row">
                <span class="pay-summary-event"><?php echo sanitize($registration['title']); ?></span>
                <span style="font-size:0.82rem;color:var(--text-muted);white-space:nowrap;">Reg #<?php echo $registrationId; ?></span>
            </div>
            <!-- Price breakdown rows — always shows original event fee, never the discounted amount -->
            <div class="pay-summary-row">
                <span class="pay-row-label"><?php echo ucfirst($registration['type'] ?? 'Event'); ?> Fee</span>
                <span class="pay-row-value"><?php echo formatPrice($originalEventFee, $registration['currency']); ?></span>
            </div>

            <!-- Coupon discount row (shown when coupon applied) -->
            <?php if ($appliedCoupon): ?>
            <div class="pay-summary-row pay-summary-row--discount" id="couponDiscountRow">
                <span class="pay-row-label pay-row-label--discount">
                    <svg width="11" height="11" fill="#7c3aed" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                    Coupon: <?php echo htmlspecialchars($appliedCoupon['code']); ?>
                </span>
                <span class="pay-row-value" style="color:#7c3aed;font-weight:700;">&minus; <?php echo formatPrice($couponDiscount, $registration['currency']); ?></span>
            </div>
            <?php endif; ?>

            <!-- UPI breakdown (shown when UPI selected, hidden when Card selected) -->
            <div id="summaryTotalUPI">
                <!-- UPI Discount row - hidden if coupon applied or no UPI discount available -->
                <?php if ($upiDiscountAmount > 0 && !$appliedCoupon): ?>
                <div class="pay-summary-row pay-summary-row--discount">
                    <span class="pay-row-label pay-row-label--discount">
                        <svg width="11" height="11" fill="#059669" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                        UPI Discount
                    </span>
                    <span class="pay-row-value pay-row-value--discount">&minus; <?php echo formatPrice($upiDiscountAmount, $registration['currency']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($upiGstAmount > 0): ?>
                <div class="pay-summary-row pay-summary-row--muted">
                    <span class="pay-row-label pay-row-label--muted">+ GST (<?php echo rtrim(rtrim(number_format($gstPercent, 2), '0'), '.'); ?>%)</span>
                    <span class="pay-row-value pay-row-value--muted"><?php echo formatPrice($upiGstAmount, $registration['currency']); ?></span>
                </div>
                <?php endif; ?>
                <div class="pay-summary-row pay-summary-row--total">
                    <?php if ($appliedCoupon): ?>
                    <!-- Coupon applied - show savings from coupon -->
                    <span class="pay-row-label pay-row-label--savings">You save <?php echo formatPrice($couponDiscount, $registration['currency']); ?> with coupon</span>
                    <?php elseif ($upiDiscountAmount > 0): ?>
                    <!-- UPI discount applied -->
                    <span class="pay-row-label pay-row-label--savings">You save <?php echo formatPrice($upiDiscountAmount, $registration['currency']); ?> with UPI</span>
                    <?php else: ?>
                    <span class="pay-row-label pay-row-label--muted">UPI / Bank Transfer</span>
                    <?php endif; ?>
                    <span class="pay-summary-amount"><?php echo formatPrice(round($finalUPIAmount), $registration['currency']); ?></span>
                </div>
            </div>

            <!-- Card breakdown (hidden when UPI selected, shown when Card selected) -->
            <div id="summaryTotalCard" style="display:none;">
                <?php if ($cardGstAmount > 0): ?>
                <div class="pay-summary-row pay-summary-row--muted">
                    <span class="pay-row-label pay-row-label--muted">+ GST (<?php echo rtrim(rtrim(number_format($gstPercent, 2), '0'), '.'); ?>%)</span>
                    <span class="pay-row-value pay-row-value--muted"><?php echo formatPrice($cardGstAmount, $registration['currency']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($gatewayFeeAmount > 0): ?>
                <div class="pay-summary-row pay-summary-row--muted">
                    <span class="pay-row-label pay-row-label--muted">+ Gateway Fee (<?php echo rtrim(rtrim(number_format($gatewayFeePercent, 2), '0'), '.'); ?>%)</span>
                    <span class="pay-row-value pay-row-value--muted"><?php echo formatPrice($gatewayFeeAmount, $registration['currency']); ?></span>
                </div>
                <?php endif; ?>
                <div class="pay-summary-row pay-summary-row--total">
                    <span class="pay-row-label pay-row-label--muted">Card Payment total</span>
                    <span class="pay-summary-amount"><?php echo formatPrice(round($finalCardAmount), $registration['currency']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Coupon / Offer Card -->
    <div class="pay-card" id="couponCard" style="padding:0; overflow:visible;">
        <div style="padding:18px 24px 0;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="width:32px;height:32px;background:#f5f3ff;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                        <svg width="16" height="16" fill="none" stroke="#7c3aed" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                    <span style="font-size:0.95rem;font-weight:700;color:var(--text-primary);">Have a coupon?</span>
                </div>
                <button type="button" id="couponToggleBtn" onclick="toggleCouponBox()"
                        style="font-size:0.82rem;font-weight:600;color:#7c3aed;background:none;border:none;cursor:pointer;padding:4px 0;">
                    <?php echo $appliedCoupon ? 'Change' : 'Apply'; ?>
                </button>
            </div>

            <?php if ($appliedCoupon): ?>
            <!-- Applied state -->
            <div id="couponAppliedState" style="display:flex;align-items:center;justify-content:space-between;background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:10px;padding:12px 16px;margin-bottom:18px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:36px;height:36px;background:#7c3aed;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="18" height="18" fill="none" stroke="#fff" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:0.9rem;color:#4c1d95;font-family:monospace;letter-spacing:0.06em;"><?php echo htmlspecialchars($appliedCoupon['code']); ?></div>
                        <div style="font-size:0.78rem;color:#6d28d9;"><?php echo htmlspecialchars($appliedCoupon['name']); ?></div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:0.95rem;font-weight:800;color:#7c3aed;">&minus;<?php echo formatPrice($couponDiscount, $registration['currency']); ?></div>
                    <button type="button" onclick="removeCoupon()" style="font-size:0.75rem;color:#dc2626;background:none;border:none;cursor:pointer;font-weight:600;padding:0;margin-top:2px;">Remove</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Input box (hidden if coupon already applied) -->
            <div id="couponInputBox" style="<?php echo $appliedCoupon ? 'display:none;' : ''; ?>margin-bottom:18px;">
                <?php if (!empty($publicCoupons)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px;">
                    <?php foreach ($publicCoupons as $pc): ?>
                    <button type="button" onclick="setCouponCode('<?php echo htmlspecialchars($pc['code']); ?>')"
                            style="padding:5px 12px;background:#f5f3ff;color:#6d28d9;border:1.5px solid #ddd6fe;border-radius:20px;font-size:0.78rem;font-weight:700;cursor:pointer;font-family:monospace;letter-spacing:0.04em;transition:all 0.15s;"
                            onmouseover="this.style.background='#ede9fe'" onmouseout="this.style.background='#f5f3ff'">
                        <?php echo htmlspecialchars($pc['code']); ?>
                        &mdash;
                        <?php echo $pc['discount_type']==='percent' ? floatval($pc['discount_value']).'% off' : formatPrice($pc['discount_value']).' off'; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div style="display:flex;gap:0;border:1.5px solid #d1d5db;border-radius:10px;overflow:hidden;transition:border-color 0.2s;" id="couponInputWrap">
                    <input type="text" id="couponCodeInput" placeholder="Enter coupon code"
                           style="flex:1;border:none;outline:none;padding:12px 16px;font-size:0.9rem;font-weight:600;font-family:monospace;letter-spacing:0.08em;text-transform:uppercase;background:#fff;color:#1f2937;"
                           onfocus="document.getElementById('couponInputWrap').style.borderColor='#7c3aed'"
                           onblur="document.getElementById('couponInputWrap').style.borderColor='#d1d5db'"
                           oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9_\-]/g,'')">
                    <button type="button" id="couponApplyBtn" onclick="applyCoupon()"
                            style="padding:12px 20px;background:#7c3aed;color:#fff;border:none;cursor:pointer;font-size:0.875rem;font-weight:700;transition:background 0.15s;white-space:nowrap;"
                            onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'">
                        Apply
                    </button>
                </div>
                <div id="couponMsg" style="display:none;margin-top:8px;padding:9px 12px;border-radius:8px;font-size:0.82rem;font-weight:600;"></div>
            </div>
        </div>
    </div>

    <!-- Payment Method Card -->
    <form method="POST" enctype="multipart/form-data" id="paymentForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="payment_method" value="upi" id="paymentMethodInput">

        <div class="pay-card">

            <!-- Method selector — UPI first (promoted), Card second -->
            <?php if ($showRazorpay || $showUPI): ?>
            <div class="pay-methods-label">Payment Method</div>
            <div class="pay-methods-grid <?php echo $onlyOneMethod ? 'single-method' : ''; ?>">

                <?php if ($showUPI): ?>
                <!-- UPI first — always promoted -->
                <div class="pay-method-option <?php echo $showUPI ? 'selected' : ''; ?> <?php echo $onlyOneMethod ? 'pay-method-disabled' : ''; ?>"
                     id="methodUPI"
                     onclick="<?php echo !$onlyOneMethod ? 'selectMethod(\'upi\')' : ''; ?>">
                    <div class="pay-method-radio" id="radioUPI"></div>
                    <?php if ($upiDiscountAmount > 0): ?>
                    <div class="pay-method-badge-discount">
                        <svg width="10" height="10" fill="#fff" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                        Save <?php echo formatPrice($upiDiscountAmount, $registration['currency']); ?>
                    </div>
                    <?php endif; ?>
                    <div class="pay-method-icon icon-upi">
                        <svg width="22" height="22" fill="none" stroke="#059669" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    </div>
                    <div class="pay-method-name">UPI / Bank Transfer</div>
                    <div class="pay-method-desc">NEFT, IMPS, UPI &mdash; upload proof</div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:6px;">
                        <span class="pay-method-manual">
                            <svg width="10" height="10" fill="#d97706" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                            Verified within 30m&ndash;6h
                        </span>
                        <span class="pay-method-final-amt" id="upiMethodAmt"><?php echo formatPrice(round($finalUPIAmount), $registration['currency']); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($showRazorpay): ?>
                <!-- Card Payment second -->
                <div class="pay-method-option <?php echo (!$showUPI && $showRazorpay) ? 'selected' : ''; ?> <?php echo $onlyOneMethod ? 'pay-method-disabled' : ''; ?>"
                     id="methodRazorpay"
                     onclick="<?php echo !$onlyOneMethod ? 'selectMethod(\'razorpay\')' : ''; ?>">
                    <div class="pay-method-radio" id="radioRazorpay"></div>
                    <div class="pay-method-icon icon-razorpay">
                        <svg width="22" height="22" fill="none" stroke="#3b82f6" viewBox="0 0 24 24" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" stroke-linecap="round"/><line x1="1" y1="10" x2="23" y2="10" stroke-linecap="round"/></svg>
                    </div>
                    <div class="pay-method-name">Card Payment</div>
                    <div class="pay-method-desc">Debit / Credit Card, Net Banking</div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:6px;">
                        <span class="pay-method-instant">
                            <svg width="10" height="10" fill="#059669" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Instant confirmation
                        </span>
                        <span class="pay-method-final-amt" id="cardMethodAmt"><?php echo formatPrice(round($finalCardAmount), $registration['currency']); ?></span>
                    </div>
                </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

            <!-- ── Razorpay Panel ───────────────────────── -->
            <?php if ($showRazorpay): ?>
            <!-- When both shown, default to UPI so razorpay starts hidden -->
            <div class="pay-razorpay-panel <?php echo (!$showUPI && $showRazorpay) ? 'visible' : ''; ?>" id="razorpayPanel">
                <button type="button"
                    id="razorpay-btn"
                    class="pay-submit-btn pay-submit-razorpay"
                    data-amount="<?php echo $amountPaise; ?>"
                    data-currency="<?php echo sanitize($registration['currency']); ?>"
                    data-name="KESA Learn"
                    data-description="<?php echo sanitize($registration['title']); ?>"
                    data-registration-id="<?php echo $registrationId; ?>"
                    data-event-id="<?php echo intval($registration['event_id']); ?>"
                    data-email="<?php echo sanitize($user['email']); ?>"
                    data-phone="<?php echo sanitize($user['phone'] ?? ''); ?>"
                    data-user-name="<?php echo sanitize($user['name']); ?>">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    Pay <?php echo formatPrice(round($finalCardAmount), $registration['currency']); ?> Securely
                </button>
            </div>
            <?php endif; ?>

            <!-- ── UPI Panel ───────────────────────────── -->
            <?php if ($showUPI): ?>
            <!-- UPI panel visible by default (promoted) when available -->
            <div class="pay-upi-panel <?php echo $showUPI ? 'visible' : ''; ?>" id="upiPanel">

                <div class="upi-panel-title">Complete your UPI payment using any of the options below</div>

                <div class="upi-options">

                    <?php if (!empty($currentUPIQrUrl)): ?>
                    <!-- QR Code -->
                    <div class="upi-qr-block">
                        <img src="<?php echo htmlspecialchars($currentUPIQrUrl); ?>" alt="UPI QR Code" class="upi-qr-img">
                        <div class="upi-qr-label">
                            <strong>Scan &amp; Pay <?php echo formatPrice(round($finalUPIAmount), $registration['currency']); ?></strong>
                            Open any UPI app (PhonePe, GPay, Paytm, BHIM) and scan this code
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($currentUPIQrUrl) && !empty($currentUPI)): ?>
                    <div class="upi-or-divider upi-qr-or-divider">or send manually</div>
                    <?php endif; ?>

                    <?php if (!empty($currentUPI)): ?>
                    <!-- Send to UPI ID -->
                    <div class="upi-id-block">
                        <div class="upi-id-info">
                            <strong>Send to UPI ID</strong>
                            <span>Open any UPI app and pay to this ID</span>
                        </div>
                        <div class="upi-id-value" onclick="copyUPIId()">
                            <span id="upiIdText"><?php echo htmlspecialchars($currentUPI); ?></span>
                            <button type="button" class="upi-copy-btn" id="copyBtn" onclick="event.stopPropagation(); copyUPIId()">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                Copy
                            </button>
                        </div>
                        <p style="font-size:0.75rem;color:var(--text-muted);margin-top:8px;line-height:1.4;">
                            Pay to: <strong style="color:#111827;"><?php echo htmlspecialchars($currentUPIName); ?></strong>
                            &nbsp;&middot;&nbsp; Amount: <strong style="color:#111827;"><?php echo formatPrice(round($finalUPIAmount), $registration['currency']); ?></strong>
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($currentUPI) && !empty($dynamicUPILink)): ?>
                    <div class="upi-or-divider upi-link-or-divider">or</div>
                    <?php endif; ?>

                    <?php if (!empty($dynamicUPILink)): ?>
                    <!-- Click to Pay -->
                    <div class="upi-link-block">
                        <div class="upi-link-info">
                            <strong>Click to Pay</strong>
                            <span>Opens your UPI app automatically with the amount <?php echo formatPrice(round($finalUPIAmount), $registration['currency']); ?> pre-filled</span>
                        </div>
                        <a href="<?php echo htmlspecialchars($dynamicUPILink); ?>" class="upi-link-btn" target="_blank" rel="noopener">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            Open UPI App
                        </a>
                    </div>
                    <?php endif; ?>

                </div><!-- /.upi-options -->

                <!-- Upload proof -->
                <div class="pay-proof-section">
                    <div class="pay-proof-label">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Upload Payment Screenshot
                        <span class="pay-proof-required">*</span>
                    </div>
                    <div class="pay-proof-drop" id="proofDropZone">
                        <input type="file" name="payment_proof" id="proofInput" accept="image/*" required
                               onchange="handleProofPreview(this)">
                        <div id="proofDropContent">
                            <div class="pay-proof-drop-icon">
                                <svg width="22" height="22" fill="none" stroke="#7c3aed" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            </div>
                            <div class="pay-proof-drop-text">Drop your screenshot here, or click to select</div>
                            <div class="pay-proof-drop-hint">JPG, PNG or WebP &middot; Max 10 MB</div>
                        </div>
                        <div class="pay-proof-preview" id="proofPreview"></div>
                    </div>
                </div>

            </div><!-- /#upiPanel -->
            <?php endif; ?>

            <!-- ── Submit (UPI) ──────────────────────────── -->
            <?php if ($showUPI): ?>
            <!-- UPI submit row: visible by default when UPI is available (promoted) -->
            <div class="pay-action" id="upiSubmitRow" style="<?php echo $showUPI ? '' : 'display:none;'; ?>">
                <button type="submit" class="pay-submit-btn pay-submit-upi">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Submit Proof &mdash; Pay <?php echo formatPrice(round($finalUPIAmount), $registration['currency']); ?>
                </button>
                <p style="font-size:0.75rem;color:var(--text-muted);text-align:center;margin-top:10px;">
                    Our team verifies payments within 30 minutes to 6 hours. You will receive a confirmation notification.
                </p>
            </div>
            <?php endif; ?>

            <!-- Secure note -->
            <div class="pay-secure-note">
                <svg width="14" height="14" fill="#6b7280" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                Your payment information is secure and encrypted.
            </div>

            <!-- Support -->
            <?php if (!empty($registration['support_phone']) || !empty($registration['support_whatsapp'])): ?>
            <div class="pay-support">
                <p>Need help with payment?</p>
                <div class="pay-support-btns">
                    <?php if (!empty($registration['support_phone'])): ?>
                    <a href="tel:<?php echo sanitize($registration['support_phone']); ?>" class="pay-support-link">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        Call Support
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($registration['support_whatsapp'])): ?>
                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $registration['support_whatsapp']); ?>?text=<?php echo rawurlencode('Hi, I need help with payment for registration #' . $registrationId); ?>"
                       target="_blank" rel="noopener" class="pay-support-link whatsapp">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        WhatsApp Support
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.pay-card -->
    </form>

</div><!-- /.pay-wrap -->
</div><!-- /.pay-page -->

<script>
// ── Method switching ────────────────────────────────────────
// Default to UPI (promoted) when both methods are available
var activeMethod = '<?php echo ($showUPI) ? 'upi' : 'razorpay'; ?>';

function selectMethod(method) {
    activeMethod = method;

    // Update card highlights
    document.querySelectorAll('.pay-method-option').forEach(function(el) {
        el.classList.remove('selected');
    });

    // Toggle summary totals
    var upiTotal  = document.getElementById('summaryTotalUPI');
    var cardTotal = document.getElementById('summaryTotalCard');

    if (method === 'razorpay') {
        var rpEl = document.getElementById('methodRazorpay');
        if (rpEl) rpEl.classList.add('selected');
        var rpPanel = document.getElementById('razorpayPanel');
        if (rpPanel) rpPanel.classList.add('visible');
        var upiPanel = document.getElementById('upiPanel');
        if (upiPanel) upiPanel.classList.remove('visible');
        document.getElementById('upiSubmitRow').style.display = 'none';
        if (upiTotal)  upiTotal.style.display  = 'none';
        if (cardTotal) cardTotal.style.display = 'block';
    } else {
        var upiEl = document.getElementById('methodUPI');
        if (upiEl) upiEl.classList.add('selected');
        var upiPanelEl = document.getElementById('upiPanel');
        if (upiPanelEl) upiPanelEl.classList.add('visible');
        document.getElementById('upiSubmitRow').style.display = 'block';
        var rpPanelEl = document.getElementById('razorpayPanel');
        if (rpPanelEl) rpPanelEl.classList.remove('visible');
        if (upiTotal)  upiTotal.style.display  = 'block';
        if (cardTotal) cardTotal.style.display = 'none';
    }
}

// Init: default to UPI when both methods shown
<?php if ($showRazorpay && $showUPI): ?>
document.addEventListener('DOMContentLoaded', function() {
    // UPI is already selected by default (cards rendered with UPI selected state)
    // just ensure panels are correct
    var upiPanel = document.getElementById('upiPanel');
    if (upiPanel) upiPanel.classList.add('visible');
    var rpPanel = document.getElementById('razorpayPanel');
    if (rpPanel) rpPanel.classList.remove('visible');
    document.getElementById('upiSubmitRow').style.display = 'block';
    var cardTotal = document.getElementById('summaryTotalCard');
    if (cardTotal) cardTotal.style.display = 'none';
});
<?php endif; ?>

// ── Copy UPI ID ─────────────────────────────────────────────
function copyUPIId() {
    var text = document.getElementById('upiIdText').textContent.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            var btn = document.getElementById('copyBtn');
            btn.classList.add('copied');
            btn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Copied!';
            setTimeout(function() {
                btn.classList.remove('copied');
                btn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy';
            }, 2500);
        }).catch(function() { fallbackCopy(text); });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
}

// ── Proof file preview ──────────────────────────────────────
function handleProofPreview(input) {
    var preview = document.getElementById('proofPreview');
    var dropContent = document.getElementById('proofDropContent');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Payment screenshot preview">';
            preview.style.display = 'block';
            dropContent.style.opacity = '0.4';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── Coupon logic ─────────────────────────────────────────────
var REGISTRATION_ID = <?php echo $registrationId; ?>;
var CURRENCY        = '<?php echo sanitize($registration['currency']); ?>';
var BASE_AMOUNT     = <?php echo floatval($amount); ?>; // original amount before coupon

function toggleCouponBox() {
    var box = document.getElementById('couponInputBox');
    var applied = document.getElementById('couponAppliedState');
    if (box) box.style.display = box.style.display === 'none' ? 'block' : 'none';
    if (applied) applied.style.display = 'none';
}

function setCouponCode(code) {
    var inp = document.getElementById('couponCodeInput');
    if (inp) { inp.value = code; inp.focus(); }
}

function showCouponMsg(type, text) {
    var el = document.getElementById('couponMsg');
    if (!el) return;
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#f0fdf4' : '#fef2f2';
    el.style.color       = type === 'success' ? '#166534' : '#991b1b';
    el.style.border      = type === 'success' ? '1px solid #bbf7d0' : '1px solid #fecaca';
    el.textContent = text;
}

function applyCoupon() {
    var code = document.getElementById('couponCodeInput').value.trim().toUpperCase();
    if (!code) { showCouponMsg('error', 'Please enter a coupon code.'); return; }
    var btn = document.getElementById('couponApplyBtn');
    btn.textContent = 'Checking...';
    btn.disabled = true;

    fetch('/api/coupon.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'validate', code: code, registration_id: REGISTRATION_ID })
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        btn.textContent = 'Apply';
        btn.disabled = false;
        if (data.success) {
            showCouponMsg('success', 'Coupon applied! Refreshing...');
            setTimeout(function(){ location.reload(); }, 800);
        } else {
            showCouponMsg('error', data.message || 'Invalid coupon.');
        }
    })
    .catch(function(){
        btn.textContent = 'Apply';
        btn.disabled = false;
        showCouponMsg('error', 'Something went wrong. Please try again.');
    });
}

function removeCoupon() {
    fetch('/api/coupon.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'remove' })
    }).then(function(){ location.reload(); });
}

// Enter key on coupon input
var couponInput = document.getElementById('couponCodeInput');
if (couponInput) {
    couponInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); }
    });
}

// Drag & drop styling
var dropZone = document.getElementById('proofDropZone');
if (dropZone) {
    ['dragenter', 'dragover'].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });
    });
    ['dragleave', 'drop'].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
        });
    });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        var files = e.dataTransfer.files;
        if (files.length) {
            document.getElementById('proofInput').files = files;
            handleProofPreview(document.getElementById('proofInput'));
        }
    });
}
</script>

<?php if ($showRazorpay): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script src="/assets/js/razorpay.js"></script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
