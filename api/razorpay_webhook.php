<?php
/**
 * KESA Learn - Razorpay API Handler
 * Handles order creation, payment verification, and webhooks
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tracking.php';
require_once __DIR__ . '/../config/razorpay.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$action = $input['action'] ?? '';

/* =====================================================
   Create Razorpay Order
   ===================================================== */
if ($action === 'create_order') {
    $registrationId = intval($input['registration_id'] ?? 0);
    $amount = intval($input['amount'] ?? 0);
    
    if (!$registrationId || !$amount) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
        exit;
    }
    
    // Verify registration exists
    $db = getDB();
    $stmt = $db->prepare("SELECT r.*, e.currency FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.id = ?");
    $stmt->execute([$registrationId]);
    $registration = $stmt->fetch();
    
    if (!$registration) {
        echo json_encode(['success' => false, 'message' => 'Registration not found.']);
        exit;
    }
    
    // Create Razorpay order via API
    $orderData = [
        'amount' => $amount,
        'currency' => $registration['currency'],
        'receipt' => 'reg_' . $registrationId,
        'notes' => [
            'registration_id' => $registrationId,
            'user_id' => $registration['user_id']
        ]
    ];
    
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $order = json_decode($response, true);
    
    if ($httpCode !== 200 || empty($order['id'])) {
        echo json_encode(['success' => false, 'message' => 'Failed to create order.']);
        exit;
    }
    
    // Save order in database
    $stmt = $db->prepare("INSERT INTO razorpay_payments (registration_id, razorpay_order_id, amount, status) VALUES (?, ?, ?, 'created')");
    $stmt->execute([$registrationId, $order['id'], $amount / 100]);
    
    // Update registration with payment method
    $db->prepare("UPDATE registrations SET payment_method = 'razorpay' WHERE id = ?")->execute([$registrationId]);
    
    echo json_encode(['success' => true, 'order_id' => $order['id']]);
    exit;
}

/* =====================================================
   Verify Payment
   ===================================================== */
if ($action === 'verify_payment') {
    $registrationId = intval($input['registration_id'] ?? 0);
    $orderId = $input['razorpay_order_id'] ?? '';
    $paymentId = $input['razorpay_payment_id'] ?? '';
    $signature = $input['razorpay_signature'] ?? '';
    
    if (!$registrationId || !$orderId || !$paymentId || !$signature) {
        echo json_encode(['success' => false, 'message' => 'Missing payment details.']);
        exit;
    }
    
    // Verify signature
    $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
    
    if (!hash_equals($expectedSignature, $signature)) {
        echo json_encode(['success' => false, 'message' => 'Payment verification failed.']);
        logActivity('payment_verification_failed', "Invalid signature for registration #$registrationId - Payment ID: $paymentId");
        exit;
    }
    
    $db = getDB();
    
    // Verify registration exists and belongs to user
    $regCheck = $db->prepare("SELECT id, user_id, event_id FROM registrations WHERE id = ?");
    $regCheck->execute([$registrationId]);
    $regExists = $regCheck->fetch();
    
    if (!$regExists) {
        echo json_encode(['success' => false, 'message' => 'Registration not found.']);
        exit;
    }
    
    // Update razorpay_payments table
    $stmt = $db->prepare("UPDATE razorpay_payments SET razorpay_payment_id = ?, razorpay_signature = ?, status = 'paid' WHERE razorpay_order_id = ?");
    $stmt->execute([$paymentId, $signature, $orderId]);
    
    // Update registration with IMMEDIATE status change to 'paid'.
    // Also set final_amount from the razorpay_payments record (amount stored in INR, not paise).
    $rpAmtRow = $db->prepare("SELECT amount FROM razorpay_payments WHERE razorpay_order_id = ? LIMIT 1");
    $rpAmtRow->execute([$orderId]);
    $rpAmt = $rpAmtRow->fetchColumn();  // stored as INR decimal
    $stmt = $db->prepare("UPDATE registrations SET payment_status = 'paid', payment_method = 'razorpay', payment_id = ?, final_amount = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$paymentId, $rpAmt ?: null, $registrationId]);
    
    // Mark incomplete payment as completed
    markPaymentCompleted($regExists['user_id'], $regExists['event_id']);

    // Record coupon usage if one was applied (check registrations table)
    try {
        $couponRow = $db->prepare("SELECT coupon_id, coupon_code, discount_amount, original_amount, amount FROM registrations WHERE id = ? AND coupon_id IS NOT NULL");
        $couponRow->execute([$registrationId]);
        $cr = $couponRow->fetch();
        if ($cr && $cr['coupon_id']) {
            // Insert usage record (ignore duplicate — idempotent)
            $insUse = $db->prepare("INSERT IGNORE INTO coupon_usages (coupon_id, user_id, registration_id, event_id, original_amount, discount_amount, final_amount) VALUES (?,?,?,?,?,?,?)");
            $insUse->execute([$cr['coupon_id'], $regExists['user_id'], $registrationId, $regExists['event_id'], $cr['original_amount'], $cr['discount_amount'], $cr['amount']]);
            $db->prepare("UPDATE coupons SET uses_count = uses_count + 1 WHERE id = ?")->execute([$cr['coupon_id']]);
            logActivity('coupon_used', "Coupon #{$cr['coupon_id']} ({$cr['coupon_code']}) used for registration #$registrationId");
        }
    } catch (Exception $couponEx) {
        error_log("[Payment] Coupon usage record error: " . $couponEx->getMessage());
    }

    logActivity('payment_success', "Razorpay payment verified for registration #$registrationId. Payment ID: $paymentId. Status set to PAID immediately.");
    
    // Send payment success email and WhatsApp group invitation
    $regStmt = $db->prepare("SELECT r.amount, e.title, e.currency, e.whatsapp_group_link, u.name, u.email FROM registrations r JOIN events e ON r.event_id = e.id JOIN users u ON r.user_id = u.id WHERE r.id = ?");
    $regStmt->execute([$registrationId]);
    $regData = $regStmt->fetch();
    
    if ($regData) {
        require_once __DIR__ . '/../includes/mailer.php';
        $emailResult = sendPaymentSuccessEmail($regData['email'], $regData['name'], $regData['title'], $regData['amount'], $regData['currency']);
        logActivity('payment_email_sent', "Payment success email sent to {$regData['email']} - Result: " . ($emailResult ? 'Success' : 'Failed'));
        
        // Send WhatsApp group invitation if link exists
        if (!empty($regData['whatsapp_group_link'])) {
            sendWhatsAppGroupInvitation($regData['email'], $regData['name'], $regData['title'], $regData['whatsapp_group_link']);
            
            // Track WhatsApp invitation sent
            $inviteStmt = $db->prepare("INSERT INTO whatsapp_invitations (user_id, event_id, invitation_sent_at) SELECT r.user_id, r.event_id, NOW() FROM registrations r WHERE r.id = ? ON DUPLICATE KEY UPDATE invitation_sent_at = NOW()");
            $inviteStmt->execute([$registrationId]);
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Payment verified successfully.', 'payment_id' => $paymentId]);
    exit;
}

/* =====================================================
   Razorpay Webhook Handler
   ===================================================== */
if ($action === '' && isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
    $rawBody = file_get_contents('php://input');
    $webhookSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
    
    $expectedSignature = hash_hmac('sha256', $rawBody, RAZORPAY_WEBHOOK_SECRET);
    
    if (!hash_equals($expectedSignature, $webhookSignature)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid webhook signature']);
        exit;
    }
    
    $event = json_decode($rawBody, true);
    $eventType = $event['event'] ?? '';
    
    if ($eventType === 'payment.captured') {
        $payment = $event['payload']['payment']['entity'];
        $orderId = $payment['order_id'];
        $paymentId = $payment['id'];
        
        $db = getDB();
        
        // Find registration
        $stmt = $db->prepare("SELECT registration_id FROM razorpay_payments WHERE razorpay_order_id = ?");
        $stmt->execute([$orderId]);
        $record = $stmt->fetch();
        
        if ($record) {
            $db->prepare("UPDATE razorpay_payments SET razorpay_payment_id = ?, status = 'paid' WHERE razorpay_order_id = ?")
                ->execute([$paymentId, $orderId]);

            // Fetch the stored INR amount from razorpay_payments to record as final_amount
            $webhookAmt = $db->prepare("SELECT amount FROM razorpay_payments WHERE razorpay_order_id = ? LIMIT 1");
            $webhookAmt->execute([$orderId]);
            $webhookAmtVal = $webhookAmt->fetchColumn();

            $db->prepare("UPDATE registrations SET payment_status = 'paid', payment_method = 'razorpay', payment_id = ?, final_amount = ? WHERE id = ?")
                ->execute([$paymentId, $webhookAmtVal ?: null, $record['registration_id']]);
            
            logActivity('webhook_payment_captured', "Webhook: Payment captured for registration #{$record['registration_id']}");
            
            // Send payment success email via webhook
            $regStmt = $db->prepare("SELECT r.amount, e.title, e.currency, u.name, u.email FROM registrations r JOIN events e ON r.event_id = e.id JOIN users u ON r.user_id = u.id WHERE r.id = ?");
            $regStmt->execute([$record['registration_id']]);
            $regData = $regStmt->fetch();
            
            if ($regData) {
                require_once __DIR__ . '/../includes/mailer.php';
                sendPaymentSuccessEmail($regData['email'], $regData['name'], $regData['title'], $regData['amount'], $regData['currency']);
            }
        }
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
