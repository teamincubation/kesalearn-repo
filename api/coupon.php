<?php
/**
 * KESA Learn - Coupon Validation & Application API
 * POST /api/coupon.php
 * Actions: validate, remove
 */
require_once __DIR__ . '/../includes/auth_check.php';
header('Content-Type: application/json');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if (!verifyCSRFToken($input['csrf_token'] ?? null)) {
    echo json_encode(['success' => false, 'message' => 'Security token expired.']);
    exit;
}

$db     = getDB();
$userId = $_SESSION['user_id'];
$action = $input['action'] ?? 'validate';

function jsonOut(bool $success, string $message, array $data = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

// ── Remove coupon from session ───────────────────────────────────────────────
if ($action === 'remove') {
    $ac = $_SESSION['applied_coupon'] ?? null;
    if ($ac && !empty($ac['registration_id'])) {
        try {
            $db = getDB();
            // Restore registrations.amount to e.price (the true original event fee)
            // We join events to get e.price so we never rely on the stored original_amount
            $revert = $db->prepare("
                UPDATE registrations r
                JOIN events e ON r.event_id = e.id
                SET r.amount          = e.price,
                    r.coupon_id       = NULL,
                    r.coupon_code     = NULL,
                    r.discount_amount = 0,
                    r.original_amount = 0
                WHERE r.id = ? AND r.user_id = ?
            ");
            $revert->execute([$ac['registration_id'], $userId]);
        } catch (Exception $e) {
            error_log("[Coupon] Revert error: " . $e->getMessage());
        }
    }
    unset($_SESSION['applied_coupon']);
    jsonOut(true, 'Coupon removed.');
}

// ── Validate & apply ─────────────────────────────────────────────────────────
if ($action !== 'validate') jsonOut(false, 'Unknown action.');

$code           = strtoupper(trim($input['code'] ?? ''));
$registrationId = intval($input['registration_id'] ?? 0);

if (empty($code))         jsonOut(false, 'Please enter a coupon code.');
if (!$registrationId)     jsonOut(false, 'Invalid registration.');

// Load registration + event details
// IMPORTANT: always use e.price as the original fee — registrations.amount may already be
// discounted if a coupon was previously applied.
$stmt = $db->prepare("
    SELECT r.id, r.amount, r.original_amount, r.event_id, e.price, e.type, e.title, e.is_free
    FROM registrations r
    JOIN events e ON r.event_id = e.id
    WHERE r.id = ? AND r.user_id = ?
");
$stmt->execute([$registrationId, $userId]);
$reg = $stmt->fetch();

if (!$reg)           jsonOut(false, 'Registration not found.');
if ($reg['is_free']) jsonOut(false, 'This event is free — no coupon needed.');

// Always use e.price as the base — never registrations.amount which may be mutated
$baseAmount = floatval($reg['price']);
$eventId    = intval($reg['event_id']);
$eventType  = $reg['type'];

// Fetch coupon
$cStmt = $db->prepare("
    SELECT * FROM coupons
    WHERE code = ?
      AND is_active = 1
      AND active_from <= NOW()
      AND (expire_on IS NULL OR expire_on > NOW())
");
$cStmt->execute([$code]);
$coupon = $cStmt->fetch();

if (!$coupon) jsonOut(false, 'Coupon code is invalid or has expired.');

// Check total usage limit
if ($coupon['max_uses_total'] !== null && $coupon['uses_count'] >= $coupon['max_uses_total']) {
    jsonOut(false, 'This coupon has reached its usage limit.');
}

// Check per-user limit
$userUsageStmt = $db->prepare("SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND user_id = ?");
$userUsageStmt->execute([$coupon['id'], $userId]);
$userUsage = intval($userUsageStmt->fetchColumn());
if ($userUsage >= $coupon['max_uses_per_user']) {
    jsonOut(false, 'You have already used this coupon the maximum number of times.');
}

// Check if already applied to this exact registration
$alreadyUsedStmt = $db->prepare("SELECT id FROM coupon_usages WHERE coupon_id = ? AND registration_id = ?");
$alreadyUsedStmt->execute([$coupon['id'], $registrationId]);
if ($alreadyUsedStmt->fetch()) {
    jsonOut(false, 'This coupon has already been applied to this registration.');
}

// Check minimum purchase amount
if ($baseAmount < floatval($coupon['min_purchase_amount'])) {
    jsonOut(false, 'Minimum purchase amount of ' . '₹' . number_format($coupon['min_purchase_amount'], 0) . ' is required to use this coupon.');
}

// Check applicable event types
$applicableTypes = array_filter(explode(',', $coupon['applicable_types'] ?? ''));
if (!empty($applicableTypes) && !in_array($eventType, $applicableTypes)) {
    jsonOut(false, 'This coupon is not valid for ' . ucfirst($eventType) . ' events.');
}

// Check scope: specific events
if ($coupon['scope'] === 'specific') {
    $evStmt = $db->prepare("SELECT 1 FROM coupon_events WHERE coupon_id = ? AND event_id = ?");
    $evStmt->execute([$coupon['id'], $eventId]);
    if (!$evStmt->fetch()) {
        jsonOut(false, 'This coupon is not valid for this event.');
    }
}

// Calculate discount
$discountAmount = 0;
if ($coupon['discount_type'] === 'percent') {
    $discountAmount = round($baseAmount * floatval($coupon['discount_value']) / 100, 2);
} else {
    $discountAmount = min(floatval($coupon['discount_value']), $baseAmount);
}
$discountAmount = max(0, round($discountAmount, 2));
$finalAmount    = max(0, $baseAmount - $discountAmount);

// Persist coupon data to the registration row so webhook can record it
try {
    $updReg = $db->prepare("UPDATE registrations SET coupon_id=?, coupon_code=?, discount_amount=?, original_amount=?, amount=? WHERE id=? AND user_id=?");
    $updReg->execute([$coupon['id'], $coupon['code'], $discountAmount, $baseAmount, $finalAmount, $registrationId, $userId]);
} catch (Exception $e) {
    error_log("[Coupon] Could not update registration row: " . $e->getMessage());
    // Non-fatal — still store in session and return success
}

// Store in session for checkout confirmation
$_SESSION['applied_coupon'] = [
    'coupon_id'       => $coupon['id'],
    'code'            => $coupon['code'],
    'name'            => $coupon['name'],
    'discount_type'   => $coupon['discount_type'],
    'discount_value'  => $coupon['discount_value'],
    'discount_amount' => $discountAmount,
    'final_amount'    => $finalAmount,
    'registration_id' => $registrationId,
];

jsonOut(true, 'Coupon applied successfully!', [
    'coupon_id'       => $coupon['id'],
    'code'            => $coupon['code'],
    'name'            => $coupon['name'],
    'discount_type'   => $coupon['discount_type'],
    'discount_value'  => floatval($coupon['discount_value']),
    'discount_amount' => $discountAmount,
    'final_amount'    => $finalAmount,
    'original_amount' => $baseAmount,
]);
