<?php
/**
 * KESA Learn - Payment Success Page
 * Shows after Razorpay payment is confirmed
 */
require_once __DIR__ . '/../includes/auth_check.php';

$paymentId = sanitize($_GET['payment_id'] ?? '');
$registrationId = intval($_GET['registration_id'] ?? 0);

if (!$paymentId || !$registrationId) {
    setFlash('error', 'Invalid payment confirmation.');
    redirect('/user/dashboard');
}

$db = getDB();

// Get registration details with event info
$stmt = $db->prepare("
    SELECT r.id, r.user_id, r.amount, r.payment_id, r.payment_status, e.title as event_title, e.start_date, 
           e.whatsapp_group_link, u.name, u.email
    FROM registrations r
    JOIN events e ON r.event_id = e.id
    JOIN users u ON r.user_id = u.id
    WHERE r.id = ? AND r.user_id = ? AND r.payment_id = ?
    LIMIT 1
");
$stmt->execute([$registrationId, $_SESSION['user_id'], $paymentId]);
$registration = $stmt->fetch();

if (!$registration) {
    setFlash('error', 'Payment record not found or invalid payment ID.');
    redirect('/user/dashboard');
}

$pageTitle = 'Payment Successful';
include __DIR__ . '/../includes/header.php';
?>

<style>
.success-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.success-modal {
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    overflow: hidden;
    animation: slideUp 0.4s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.success-header {
    background: linear-gradient(135deg, #0d7a42 0%, #059669 100%);
    padding: 40px 24px;
    text-align: center;
}

.success-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    animation: scaleIn 0.5s ease 0.2s backwards;
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.5);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.success-icon svg {
    width: 48px;
    height: 48px;
    color: white;
    stroke-width: 2;
}

.success-header h1 {
    color: white;
    margin: 0;
    font-size: 28px;
    font-weight: 700;
}

.success-header p {
    color: rgba(255, 255, 255, 0.9);
    margin: 8px 0 0 0;
    font-size: 14px;
}

.success-content {
    padding: 32px 24px;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: #6b7280;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    color: #1a1a2e;
    font-size: 15px;
    font-weight: 500;
    text-align: right;
    max-width: 55%;
    word-break: break-word;
}

.detail-value.highlight {
    color: #0d7a42;
    font-weight: 700;
    font-size: 18px;
}

.success-footer {
    padding: 0 24px 24px;
    display: flex;
    gap: 12px;
}

.btn-primary {
    flex: 1;
    padding: 12px 24px;
    background: #0d7a42;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-size: 14px;
}

.btn-primary:hover {
    background: #065f35;
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(13, 122, 66, 0.3);
}

.btn-secondary {
    flex: 1;
    padding: 12px 24px;
    background: #f3f4f6;
    color: #1a1a2e;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-size: 14px;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.info-banner {
    background: #e0f2fe;
    border-left: 4px solid #0284c7;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #0369a1;
    line-height: 1.5;
}

.info-banner svg {
    width: 16px;
    height: 16px;
    margin-right: 8px;
    vertical-align: text-bottom;
}

.receipt-info {
    background: #f9fafb;
    padding: 12px;
    border-radius: 6px;
    margin: 16px 0;
    font-size: 12px;
    color: #6b7280;
}

.receipt-id {
    font-weight: 600;
    color: #1a1a2e;
    word-break: break-all;
}
</style>

<div class="success-overlay">
    <div class="success-modal">
        <!-- Success Header -->
        <div class="success-header">
            <div class="success-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <h1>Payment Successful!</h1>
            <p>Your registration is now complete</p>
        </div>

        <!-- Payment Details -->
        <div class="success-content">
            <!-- Info Banner -->
            <div class="info-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="display: inline; margin-right: 8px;">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
                A confirmation email has been sent to your registered email address
            </div>

            <!-- Receipt Info -->
            <div class="receipt-info">
                <div style="margin-bottom: 8px;">
                    <strong>Transaction ID:</strong>
                </div>
                <div class="receipt-id"><?php echo $paymentId; ?></div>
            </div>

            <!-- Details -->
            <div class="detail-row">
                <span class="detail-label">Name</span>
                <span class="detail-value"><?php echo sanitize($registration['name']); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Event</span>
                <span class="detail-value"><?php echo sanitize($registration['event_title']); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Event Date</span>
                <span class="detail-value"><?php echo formatDate($registration['start_date']); ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Amount Paid</span>
                <span class="detail-value highlight"><?php echo formatPrice($registration['amount']); ?></span>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="success-footer">
            <a href="/user/dashboard" class="btn-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                    <path d="M21 3v5h-5"/>
                    <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                    <path d="M3 21v-5h5"/>
                </svg>
                Go to Dashboard
            </a>
            <?php if (!empty($registration['whatsapp_group_link'])): ?>
            <a href="<?php echo sanitize($registration['whatsapp_group_link']); ?>" target="_blank" class="btn-secondary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.67-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421-7.403h-.006a9.87 9.87 0 00-5.031 1.378c-3.055 2.083-5.005 5.29-5.005 8.659 0 1.059.174 2.095.5 3.077L1.666 23.15l3.12-.973c.859.525 1.83.839 2.884.839h.005c4.29 0 7.787-3.422 7.787-7.632 0-2.04-.779-3.958-2.198-5.398-1.418-1.44-3.356-2.233-5.396-2.233"/>
                </svg>
                Join WhatsApp
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../includes/footer.php';
?>
