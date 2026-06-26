<?php
/**
 * KESA Learn - Admin: Edit Registration
 * Admin can edit, update, or delete user registrations
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'registrations';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid registration.');
    redirect('/admin/registrations/');
}

// Fetch registration with user and event info
$stmt = $db->prepare("
    SELECT r.*, u.name as user_name, u.email as user_email, u.phone as user_phone, 
           e.title as event_title, e.price as event_price, e.currency
    FROM registrations r 
    JOIN users u ON r.user_id = u.id 
    JOIN events e ON r.event_id = e.id 
    WHERE r.id = ?
");
$stmt->execute([$id]);
$reg = $stmt->fetch();

if (!$reg) {
    setFlash('error', 'Registration not found.');
    redirect('/admin/registrations/');
}

$pageTitle = 'Edit Registration #' . $id;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect($_SERVER['REQUEST_URI']);
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $amount = floatval($_POST['amount'] ?? 0);
        $paymentStatus = $_POST['payment_status'] ?? 'pending';
        $paymentMethod = sanitize($_POST['payment_method'] ?? '');
        $paymentId = sanitize($_POST['payment_id'] ?? '');
        
        // Update registration
        $stmt = $db->prepare("UPDATE registrations SET amount = ?, payment_status = ?, payment_method = ?, payment_id = ? WHERE id = ?");
        $stmt->execute([$amount, $paymentStatus, $paymentMethod, $paymentId, $id]);
        
        logActivity('registration_updated', "Updated registration #$id. Status: $paymentStatus, Method: $paymentMethod");
        
        // Send email notification based on status change
        if ($paymentStatus === 'paid' || $paymentStatus === 'verified') {
            require_once __DIR__ . '/../../includes/mailer.php';
            $emailSent = sendPaymentSuccessEmail($reg['user_email'], $reg['user_name'], $reg['event_title'], $amount, $reg['currency']);
            logActivity('payment_verified', "Payment verified for registration #$id - Email sent: " . ($emailSent ? 'Yes' : 'No'));
        } elseif ($paymentStatus === 'rejected') {
            require_once __DIR__ . '/../../includes/mailer.php';
            sendPaymentFailedEmail($reg['user_email'], $reg['user_name'], $reg['event_title'], 'Payment was rejected by admin.');
        }
        
        setFlash('success', 'Registration updated successfully! Payment status: ' . ucfirst($paymentStatus));
        redirect('/admin/registrations/');
    }
    
    if ($action === 'delete') {
        $db->prepare("DELETE FROM registrations WHERE id = ?")->execute([$id]);
        logActivity('registration_deleted', "Deleted registration #$id for event: {$reg['event_title']}");
        setFlash('success', 'Registration deleted.');
        redirect('/admin/registrations/');
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<div style="margin-bottom: 24px;">
    <a href="/admin/registrations/" class="btn btn-secondary btn-sm">&larr; Back to Registrations</a>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <!-- Left Column: Registration Details -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 24px;">
        <h3 style="margin-bottom: 20px;">Registration #<?php echo $id; ?> Details</h3>
        
        <div style="margin-bottom: 16px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm);">
            <label style="font-size: 0.85rem; color: var(--text-muted);">Registration ID</label>
            <p style="font-weight: 600; margin: 4px 0; font-family: monospace;"><?php echo $id; ?></p>
        </div>
        
        <div style="margin-bottom: 16px;">
            <label style="font-size: 0.85rem; color: var(--text-muted);">User</label>
            <p style="font-weight: 600; margin: 4px 0;">
                <a href="/admin/users/view?id=<?php echo $reg['user_id']; ?>" style="color: var(--blue); text-decoration: none; cursor: pointer;">
                    <?php echo sanitize($reg['user_name']); ?> (ID: <?php echo $reg['user_id']; ?>)
                </a>
            </p>
            <p style="font-size: 0.9rem; color: var(--text-secondary);">
                <a href="mailto:<?php echo sanitize($reg['user_email']); ?>" style="color: var(--text-secondary); text-decoration: none;">
                    <?php echo sanitize($reg['user_email']); ?>
                </a>
            </p>
            <?php if ($reg['user_phone']): ?>
                <p style="font-size: 0.9rem; color: var(--text-secondary);"><?php echo sanitize($reg['user_phone']); ?></p>
            <?php endif; ?>
        </div>
        
        <div style="margin-bottom: 16px;">
            <label style="font-size: 0.85rem; color: var(--text-muted);">Event</label>
            <p style="font-weight: 600; margin: 4px 0;"><?php echo sanitize($reg['event_title']); ?></p>
        </div>
        
        <div style="margin-bottom: 16px;">
            <label style="font-size: 0.85rem; color: var(--text-muted);">Registered On</label>
            <p style="margin: 4px 0;"><?php echo formatDateTime($reg['registered_at'], 'M d, Y h:i A'); ?></p>
        </div>
        
        <?php if ($reg['payment_proof']): ?>
            <div style="margin-bottom: 16px;">
                <label style="font-size: 0.85rem; color: var(--text-muted);">Payment Proof</label>
                <div style="margin-top: 8px;">
                    <a href="/uploads/<?php echo sanitize($reg['payment_proof']); ?>" target="_blank">
                        <img src="/uploads/<?php echo sanitize($reg['payment_proof']); ?>" alt="Payment Proof" style="max-width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($reg['form_data']): ?>
            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border-color);">
                <label style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 12px;">Registration Form Data</label>
                <?php 
                    $formData = json_decode($reg['form_data'], true);
                    if (is_array($formData)):
                        foreach ($formData as $fieldName => $fieldValue):
                            if (is_array($fieldValue)) {
                                $fieldValue = implode(', ', $fieldValue);
                            }
                ?>
                <div style="margin-bottom: 12px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: capitalize; margin-bottom: 4px;">
                        <?php echo sanitize(str_replace('_', ' ', $fieldName)); ?>
                    </div>
                    <div style="font-size: 0.95rem; color: var(--text-primary); word-break: break-word;">
                        <?php echo sanitize($fieldValue); ?>
                    </div>
                </div>
                <?php 
                        endforeach;
                    endif;
                ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Right Column: Edit Form -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 24px;">
        <h3 style="margin-bottom: 20px;">Edit Registration</h3>
        
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update">
            
            <div class="form-group">
                <label for="amount">Amount (<?php echo $reg['currency']; ?>)</label>
                <input type="number" id="amount" name="amount" class="form-control" value="<?php echo $reg['amount']; ?>" step="0.01" min="0">
            </div>
            
            <div class="form-group">
                <label for="payment_status">Payment Status</label>
                <select id="payment_status" name="payment_status" class="form-control">
                    <option value="pending" <?php echo $reg['payment_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="paid" <?php echo $reg['payment_status'] === 'paid' ? 'selected' : ''; ?>>Paid (Razorpay)</option>
                    <option value="verified" <?php echo $reg['payment_status'] === 'verified' ? 'selected' : ''; ?>>Verified (Manual)</option>
                    <option value="rejected" <?php echo $reg['payment_status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="refunded" <?php echo $reg['payment_status'] === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="payment_method">Payment Method</label>
                <select id="payment_method" name="payment_method" class="form-control">
                    <option value="" <?php echo empty($reg['payment_method']) ? 'selected' : ''; ?>>Not Set</option>
                    <option value="razorpay" <?php echo $reg['payment_method'] === 'razorpay' ? 'selected' : ''; ?>>Razorpay</option>
                    <option value="upi" <?php echo $reg['payment_method'] === 'upi' ? 'selected' : ''; ?>>UPI (Manual)</option>
                    <option value="free" <?php echo $reg['payment_method'] === 'free' ? 'selected' : ''; ?>>Free Event</option>
                    <option value="admin" <?php echo $reg['payment_method'] === 'admin' ? 'selected' : ''; ?>>Admin Override</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="payment_id">Payment/Transaction ID</label>
                <input type="text" id="payment_id" name="payment_id" class="form-control" value="<?php echo sanitize($reg['payment_id'] ?? ''); ?>" placeholder="Razorpay payment ID or UPI ref">
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="/admin/registrations/" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
        
        <!-- Delete Form -->
        <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border-color);">
            <h4 style="color: var(--red); margin-bottom: 12px;">Danger Zone</h4>
            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this registration? This action cannot be undone.');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-danger">Delete Registration</button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
