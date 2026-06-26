<?php
/**
 * KESA Learn - Admin: Verify/Reject Manual Payment
 * Sends appropriate email notifications based on payment status
 */
require_once __DIR__ . '/../../includes/admin_check.php';
require_once __DIR__ . '/../../includes/mailer.php';

$id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
$token = $_GET['token'] ?? '';

if (!$id || !in_array($action, ['verify', 'reject']) || !verifyCSRFToken($token)) {
    setFlash('error', 'Invalid request.');
    redirect('/admin/registrations/');
}

$db = getDB();

// Fetch registration details for email
$stmt = $db->prepare("SELECT r.amount, e.title, e.currency, e.whatsapp_group_link, u.name, u.email FROM registrations r JOIN events e ON r.event_id = e.id JOIN users u ON r.user_id = u.id WHERE r.id = ?");
$stmt->execute([$id]);
$regData = $stmt->fetch();

if (!$regData) {
    setFlash('error', 'Registration not found.');
    redirect('/admin/registrations/');
}

if ($action === 'verify') {
    // Determine payment method to pick correct account label
    $regMethodStmt = $db->prepare("SELECT payment_method FROM registrations WHERE id = ?");
    $regMethodStmt->execute([$id]);
    $regMethod = strtolower($regMethodStmt->fetchColumn() ?? '');
    $labelType = (strpos($regMethod, 'razor') !== false || strpos($regMethod, 'card') !== false || strpos($regMethod, 'gateway') !== false) ? 'gateway' : 'upi';

    // Fetch active account label for this method type
    $activeLabel = null;
    $activeLabelColor = null;
    try {
        $lblStmt = $db->prepare("SELECT account_label, label_color FROM payment_settings WHERE setting_type = ? AND is_active = 1 AND is_primary = 1 AND account_label IS NOT NULL LIMIT 1");
        $lblStmt->execute([$labelType]);
        $lblRow = $lblStmt->fetch();
        if ($lblRow) {
            $activeLabel      = $lblRow['account_label'];
            $activeLabelColor = $lblRow['label_color'] ?: '#6366f1';
        }
    } catch (Exception $eLbl) { /* label columns may not exist yet — migration pending */ }

    try {
        $stmt = $db->prepare("UPDATE registrations SET payment_status = 'verified', verified_at = NOW(), verified_by = ?, payment_label = ?, payment_label_color = ? WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $activeLabel, $activeLabelColor, $id]);
    } catch (Exception $eFallback) {
        $stmt = $db->prepare("UPDATE registrations SET payment_status = 'verified', verified_at = NOW(), verified_by = ? WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $id]);
    }
    logActivity('payment_verified', "Verified payment for registration #$id" . ($activeLabel ? " [label: $activeLabel]" : ''));

    // Record coupon usage on verification (UPI path)
    try {
        $couponRow = $db->prepare("SELECT coupon_id, coupon_code, discount_amount, original_amount, amount, user_id, event_id FROM registrations WHERE id = ? AND coupon_id IS NOT NULL");
        $couponRow->execute([$id]);
        $cr = $couponRow->fetch();
        if ($cr && $cr['coupon_id']) {
            $insUse = $db->prepare("INSERT IGNORE INTO coupon_usages (coupon_id, user_id, registration_id, event_id, original_amount, discount_amount, final_amount) VALUES (?,?,?,?,?,?,?)");
            $insUse->execute([$cr['coupon_id'], $cr['user_id'], $id, $cr['event_id'], $cr['original_amount'], $cr['discount_amount'], $cr['amount']]);
            $db->prepare("UPDATE coupons SET uses_count = uses_count + 1 WHERE id = ? AND NOT EXISTS (SELECT 1 FROM coupon_usages WHERE coupon_id = ? AND registration_id = ? LIMIT 1 OFFSET 1)")->execute([$cr['coupon_id'], $cr['coupon_id'], $id]);
        }
    } catch (Exception $couponEx) {
        error_log("[Verify] Coupon usage record error: " . $couponEx->getMessage());
    }
    
    // Send payment success email
    sendPaymentSuccessEmail($regData['email'], $regData['name'], $regData['title'], $regData['amount'], $regData['currency']);
    
    // Send WhatsApp group invitation if link exists
    if (!empty($regData['whatsapp_group_link'])) {
        sendWhatsAppGroupInvitation($regData['email'], $regData['name'], $regData['title'], $regData['whatsapp_group_link']);
        
        // Track WhatsApp invitation sent
        $inviteStmt = $db->prepare("INSERT INTO whatsapp_invitations (user_id, event_id, invitation_sent_at) SELECT r.user_id, r.event_id, NOW() FROM registrations r WHERE r.id = ? ON DUPLICATE KEY UPDATE invitation_sent_at = NOW()");
        $inviteStmt->execute([$id]);
    }
    
    setFlash('success', 'Payment verified successfully. Confirmation email sent to user.');
} else {
    $stmt = $db->prepare("UPDATE registrations SET payment_status = 'rejected', verified_at = NOW(), verified_by = ? WHERE id = ?");
    $stmt->execute([$_SESSION['user_id'], $id]);
    logActivity('payment_rejected', "Rejected payment for registration #$id");
    
    // Send payment failed/rejected email
    sendPaymentFailedEmail($regData['email'], $regData['name'], $regData['title'], 'Your payment could not be verified. Please contact support or try again.');
    
    setFlash('warning', 'Payment has been rejected. Notification email sent to user.');
}

redirect('/admin/registrations/');
