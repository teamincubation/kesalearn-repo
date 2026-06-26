<?php
/**
 * KESA Learn - Event Registration Form
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/tracking.php';

$eventId = intval($_GET['id'] ?? 0);
if (!$eventId) {
    setFlash('error', 'Event not found.');
    redirect('/events/');
}

$db = getDB();

// First check if event exists
$stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('error', 'Event not found.');
    redirect('/events/');
}

// Check if event is published
if ($event['status'] !== 'published') {
    setFlash('error', 'This event is not available for registration at this time.');
    redirect('/events/');
}

// Check if event has already ended
$eventEndDateTime = strtotime($event['end_date']);
if ($eventEndDateTime < time()) {
    setFlash('error', 'This event has already ended.');
    redirect('/events/detail?id=' . $eventId);
}

if (!isEventRegistrationOpen($event)) {
    setFlash('error', 'Registration for this event is closed.');
    redirect('/events/detail?id=' . $eventId);
}

// Check if already registered
$regCheck = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ?");
$regCheck->execute([$_SESSION['user_id'], $eventId]);
if ($regCheck->fetch()) {
    setFlash('info', 'You are already registered for this event.');
    redirect('/events/detail?id=' . $eventId);
}

// Log that user clicked register button
logRegisterClick($eventId, $_SESSION['user_id']);

// Fetch custom form fields
$fieldsStmt = $db->prepare("SELECT * FROM event_form_fields WHERE event_id = ? ORDER BY sort_order ASC");
$fieldsStmt->execute([$eventId]);
$customFields = $fieldsStmt->fetchAll();

// Early bird price check
$isEarlyBird = false;
$currentPrice = $event['price'];
if (!empty($event['early_bird_price']) && !empty($event['early_bird_start']) && !empty($event['early_bird_end'])) {
    $now = time();
    $ebStart = strtotime($event['early_bird_start']);
    $ebEnd = strtotime($event['early_bird_end']);
    if ($now >= $ebStart && $now <= $ebEnd) {
        $isEarlyBird = true;
        $currentPrice = $event['early_bird_price'];
    }
}

$user = getCurrentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    }
    
    // Collect custom field data and user name
    $formData = [
        'full_name' => sanitize($_POST['full_name'] ?? $user['name']),
        'email' => $user['email']
    ];
    foreach ($customFields as $field) {
        $fieldKey = 'field_' . $field['id'];
        
        // Handle checkboxes (array values)
        if ($field['field_type'] === 'checkboxes') {
            $values = $_POST[$fieldKey] ?? [];
            if (is_array($values)) {
                $values = array_map('sanitize', $values);
                $value = implode(', ', $values);
            } else {
                $value = '';
            }
        } else {
            $value = sanitize($_POST[$fieldKey] ?? '');
        }
        
        if ($field['is_required'] && empty($value)) {
            $errors[] = $field['field_label'] . ' is required.';
        }
        $formData[$field['field_name']] = $value;
    }
    
    if (empty($errors)) {
        // Check seat availability again
        $seatCheck = $db->prepare("SELECT max_seats, seats_taken FROM events WHERE id = ? FOR UPDATE");
        $seatCheck->execute([$eventId]);
        $seats = $seatCheck->fetch();
        
        if ($seats['max_seats'] && $seats['seats_taken'] >= $seats['max_seats']) {
            $errors[] = 'Sorry, this event is now fully booked.';
        }
    }
    
    if (empty($errors)) {
        $paymentMethod = $event['is_free'] ? 'free' : 'razorpay';
        $paymentStatus = $event['is_free'] ? 'verified' : 'pending';
        
        // Get the updated name from form submission
        $updatedName = sanitize($_POST['full_name'] ?? $user['name']);
        
        // Update user's name in database if it has changed
        if ($updatedName !== $user['name']) {
            $db->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$updatedName, $_SESSION['user_id']]);
            logActivity('profile_updated', "Updated name during event registration for event: {$event['title']}", $_SESSION['user_id']);
        }
        
        $stmt = $db->prepare("INSERT INTO registrations (user_id, event_id, form_data, payment_method, payment_status, amount) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $eventId,
            json_encode($formData),
            $paymentMethod,
            $paymentStatus,
            $currentPrice // Use current price (early bird or regular)
        ]);
        
        $registrationId = $db->lastInsertId();
        
        // Update seat count
        $db->prepare("UPDATE events SET seats_taken = seats_taken + 1 WHERE id = ?")->execute([$eventId]);
        
        logActivity('event_registration', "Registered for event: {$event['title']}", $_SESSION['user_id']);
        
        // Send confirmation email (don't suppress errors, just log them)
        require_once __DIR__ . '/../includes/mailer.php';
        $emailSent = sendRegistrationEmail($user['email'], $updatedName, $event['title']);
        if (!$emailSent) {
            error_log("[Registration] Failed to send confirmation email to " . $user['email']);
        }
        
        // Prepare response data for success popup
        $registrationData = [
            'name' => $updatedName,
            'event_title' => $event['title'],
            'event_date' => formatDate($event['start_date']),
            'is_free' => $event['is_free'],
            'amount' => $currentPrice,
            'whatsapp_link' => $event['whatsapp_group_link'] ?? null,
            'registration_id' => $registrationId
        ];
        
        if ($event['is_free']) {
            // Show success popup then redirect
            $_SESSION['registration_success'] = $registrationData;
            redirect('/events/register-success.php?event_id=' . $eventId);
        } else {
            // Log incomplete payment (user started payment process)
            logIncompletePayment($_SESSION['user_id'], $eventId, $event['price'], 'razorpay', $registrationId);
            // Store registration data for after payment
            $_SESSION['registration_data'] = $registrationData;
            redirect('/events/payment?registration_id=' . $registrationId);
        }
    }
}

$pageTitle = 'Register - ' . $event['title'];
include __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-layout">
    <div class="container" style="max-width: 700px;">
        <div class="dashboard-header">
            <a href="/events/detail?id=<?php echo $eventId; ?>" style="font-size: 0.9rem; color: var(--blue); display: inline-flex; align-items: center; gap: 4px; margin-bottom: 12px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Event
            </a>
            <h1>Register for Event</h1>
            <p><?php echo sanitize($event['title']); ?></p>
        </div>
        
        <!-- Event Summary -->
        <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; gap: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <h3 style="font-size: 1.1rem; margin-bottom: 8px;"><?php echo sanitize($event['title']); ?></h3>
                    <div style="display: flex; flex-wrap: wrap; gap: 16px; font-size: 0.9rem; color: var(--text-secondary);">
                        <span><?php echo formatDate($event['start_date']); ?></span>
                        <span><?php echo formatDateTime($event['start_date'], 'h:i A'); ?></span>
                        <span><?php echo $event['is_online'] ? 'Online' : sanitize($event['venue']); ?></span>
                    </div>
                </div>
                <div style="text-align: right;">
                    <?php if ($isEarlyBird): ?>
                        <div style="font-size: 0.9rem; text-decoration: line-through; color: var(--text-muted);">
                            <?php echo formatPrice($event['price'], $event['currency']); ?>
                        </div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #0d7a42;">
                            <?php echo formatPrice($currentPrice, $event['currency']); ?>
                        </div>
                        <span style="background: #fef9e7; color: #d4ad1e; font-size: 0.75rem; padding: 4px 10px; border-radius: 12px; font-weight: 600;">Early Bird Offer!</span>
                    <?php else: ?>
                        <div style="font-size: 1.5rem; font-weight: 800; color: <?php echo $event['is_free'] ? '#0d7a42' : 'var(--text-primary)'; ?>;">
                            <?php echo formatPrice($currentPrice, $event['currency']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Early Bird Timer -->
            <?php if ($isEarlyBird): ?>
                <div style="margin-top: 16px; padding: 12px; background: #fef9e7; border-radius: var(--radius-sm); text-align: center;">
                    <p style="font-size: 0.85rem; color: #d4ad1e; font-weight: 500;">
                        Early bird offer ends: <?php echo formatDateTime($event['early_bird_end'], 'M d, Y h:i A'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="flash-message flash-error">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- Registration Form -->
        <form method="POST" action="" style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 32px;">
            <?php echo csrfField(); ?>
            
            <h3 style="margin-bottom: 24px;">Registration Details</h3>
            
            <!-- Pre-filled user info -->
            <div class="form-group">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <label style="margin: 0;">Full Name</label>
                    <span style="font-size: 0.85rem; color: #d97706; background: #fef3c7; padding: 4px 10px; border-radius: 12px; font-style: italic;">This name will appear on your certificate</span>
                </div>
                <input type="text" name="full_name" class="form-control" value="<?php echo sanitize($user['name']); ?>" required style="background: #fffacd; border: 2px solid #ffd700;">
            </div>
            
            <div class="form-group">
                <label>Email</label>
                <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" disabled style="background: #f5f5f5; color: #666;">
            </div>
            
            <!-- Custom Fields -->
            <?php foreach ($customFields as $field): ?>
                <div class="form-group">
                    <label for="field_<?php echo $field['id']; ?>">
                        <?php echo sanitize($field['field_label']); ?>
                        <?php if ($field['is_required']): ?><span class="required">*</span><?php endif; ?>
                    </label>
                    <?php if (!empty($field['field_description'])): ?>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin: 4px 0 8px 0;"><?php echo sanitize($field['field_description']); ?></p>
                    <?php endif; ?>
                    
                    <?php if ($field['field_type'] === 'textarea'): ?>
                        <textarea id="field_<?php echo $field['id']; ?>" name="field_<?php echo $field['id']; ?>" class="form-control" <?php echo $field['is_required'] ? 'required' : ''; ?>></textarea>
                    
                    <?php elseif ($field['field_type'] === 'select'): ?>
                        <select id="field_<?php echo $field['id']; ?>" name="field_<?php echo $field['id']; ?>" class="form-control" <?php echo $field['is_required'] ? 'required' : ''; ?>>
                            <option value="">Select...</option>
                            <?php 
                            $options = json_decode($field['options'], true) ?? [];
                            foreach ($options as $opt): ?>
                                <option value="<?php echo sanitize($opt); ?>"><?php echo sanitize($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    
                    <?php elseif ($field['field_type'] === 'multiple_choice'): ?>
                        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 8px;">
                            <?php 
                            $options = json_decode($field['options'], true) ?? [];
                            foreach ($options as $index => $opt): ?>
                                <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm); cursor: pointer; border: 1px solid var(--border-color);">
                                    <input type="radio" name="field_<?php echo $field['id']; ?>" value="<?php echo sanitize($opt); ?>" <?php echo $field['is_required'] && $index === 0 ? 'checked' : ''; ?> <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                    <span><?php echo sanitize($opt); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    
                    <?php elseif ($field['field_type'] === 'checkboxes'): ?>
                        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 8px;">
                            <?php 
                            $options = json_decode($field['options'], true) ?? [];
                            foreach ($options as $opt): ?>
                                <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm); cursor: pointer; border: 1px solid var(--border-color);">
                                    <input type="checkbox" name="field_<?php echo $field['id']; ?>[]" value="<?php echo sanitize($opt); ?>">
                                    <span><?php echo sanitize($opt); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    
                    <?php else: ?>
                        <input type="<?php echo sanitize($field['field_type']); ?>" id="field_<?php echo $field['id']; ?>" name="field_<?php echo $field['id']; ?>" class="form-control" <?php echo $field['is_required'] ? 'required' : ''; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <button type="submit" class="btn btn-primary btn-full btn-lg mt-3">
                <?php echo $event['is_free'] ? 'Register (Free)' : 'Continue to Payment'; ?>
            </button>
        </form>
        
        <!-- Support Contact -->
        <?php if (!empty($event['support_phone']) || !empty($event['support_whatsapp'])): ?>
            <div style="margin-top: 24px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; text-align: center;">
                <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 12px;">Need help with registration?</p>
                <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                    <?php if (!empty($event['support_phone'])): ?>
                        <a href="tel:<?php echo sanitize($event['support_phone']); ?>" class="btn btn-sm btn-secondary">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align: -2px; margin-right: 4px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            Call
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($event['support_whatsapp'])): ?>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $event['support_whatsapp']); ?>?text=Hi,%20I%20need%20help%20registering%20for%20<?php echo urlencode($event['title']); ?>" target="_blank" class="btn btn-sm" style="background: #e6f9f0; color: #0d7a42;">
                            <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24" style="vertical-align: -2px; margin-right: 4px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
