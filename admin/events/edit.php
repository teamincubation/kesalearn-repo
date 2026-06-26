<?php
/**
 * KESA Learn - Admin: Edit Event
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'events';
$eventId = intval($_GET['id'] ?? 0);

if (!$eventId) { redirect('/admin/events/'); }

$stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('error', 'Event not found.');
    redirect('/admin/events/');
}

$pageTitle = 'Edit: ' . $event['title'];
$errors = [];

// Fetch instructors and languages
$instructors = $db->query("SELECT * FROM instructors WHERE is_active = 1 ORDER BY name")->fetchAll();
$languages = $db->query("SELECT * FROM languages WHERE is_active = 1 ORDER BY name")->fetchAll();

// Fetch existing event instructors
$eventInstructorsStmt = $db->prepare("SELECT instructor_id FROM event_instructors WHERE event_id = ?");
$eventInstructorsStmt->execute([$eventId]);
$eventInstructorIds = $eventInstructorsStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch existing form fields
$fieldsStmt = $db->prepare("SELECT * FROM event_form_fields WHERE event_id = ? ORDER BY sort_order");
$fieldsStmt->execute([$eventId]);
$existingFields = $fieldsStmt->fetchAll();

// Parse communication languages
$eventCommLanguages = $event['communication_languages'] ? explode(',', $event['communication_languages']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    }
    
    $title = sanitize($_POST['title'] ?? '');
    $description = $_POST['description'] ?? '';
    $shortDescription = sanitize($_POST['short_description'] ?? '');
    $type = $_POST['type'] ?? 'webinar';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $timezone = sanitize($_POST['timezone'] ?? 'Asia/Kolkata');
    $venue = sanitize($_POST['venue'] ?? '');
    $isOnline = isset($_POST['is_online']) ? 1 : 0;
    $meetingLink = sanitize($_POST['meeting_link'] ?? '');
    $maxSeats = !empty($_POST['max_seats']) ? intval($_POST['max_seats']) : null;
    $price = floatval($_POST['price'] ?? 0);
    $currency = sanitize($_POST['currency'] ?? 'INR');
    $isFree = isset($_POST['is_free']) ? 1 : 0;
    $status = sanitize($_POST['status'] ?? 'draft');
    $registrationDeadline = !empty($_POST['registration_deadline']) ? $_POST['registration_deadline'] : null;
    
    // New fields
    $selectedInstructors = $_POST['instructors'] ?? [];
    $communicationLanguages = isset($_POST['comm_languages']) ? implode(',', $_POST['comm_languages']) : '';
    $earlyBirdPrice = !empty($_POST['early_bird_price']) ? floatval($_POST['early_bird_price']) : null;
    $earlyBirdStart = !empty($_POST['early_bird_start']) ? $_POST['early_bird_start'] : null;
    $earlyBirdEnd = !empty($_POST['early_bird_end']) ? $_POST['early_bird_end'] : null;
    $supportPhone = sanitize($_POST['support_phone'] ?? '');
    $supportWhatsapp = sanitize($_POST['support_whatsapp'] ?? '');
    $paymentMethods = $_POST['payment_methods'] ?? 'both';
    $isNew = isset($_POST['is_new']) ? 1 : 0;
    
    // WhatsApp group link
    $whatsappGroupLink = sanitize($_POST['whatsapp_group_link'] ?? '');
    $whatsappGroupEnabled = !empty($whatsappGroupLink) ? 1 : 0;
    
    if (empty($title)) $errors[] = 'Event title is required.';
    if (empty($description)) $errors[] = 'Description is required.';
    
    $bannerImage = $event['banner_image'];
    if (!empty($_FILES['banner_image']['name'])) {
        $upload = uploadFile($_FILES['banner_image'], 'banners');
        if ($upload['success']) {
            $bannerImage = $upload['filename'];
        } else {
            $errors[] = $upload['error'];
        }
    }
    
    if (empty($errors)) {
        try {
            // Generate unique slug based on event ID to satisfy UNIQUE constraint
            $uniqueSlug = 'event_' . $eventId;
            
            $stmt = $db->prepare("UPDATE events SET title = ?, description = ?, short_description = ?, type = ?, banner_image = ?, start_date = ?, end_date = ?, timezone = ?, venue = ?, is_online = ?, meeting_link = ?, communication_languages = ?, max_seats = ?, price = ?, early_bird_price = ?, early_bird_start = ?, early_bird_end = ?, currency = ?, is_free = ?, status = ?, is_new = ?, registration_deadline = ?, support_phone = ?, support_whatsapp = ?, payment_methods = ?, slug = ?, whatsapp_group_link = ?, whatsapp_group_enabled = ? WHERE id = ?");
            $stmt->execute([$title, $description, $shortDescription, $type, $bannerImage, $startDate, $endDate, $timezone, $venue, $isOnline, $meetingLink, $communicationLanguages, $maxSeats, $price, $earlyBirdPrice, $earlyBirdStart, $earlyBirdEnd, $currency, $isFree, $status, $isNew, $registrationDeadline, $supportPhone, $supportWhatsapp, $paymentMethods, $uniqueSlug, $whatsappGroupLink, $whatsappGroupEnabled, $eventId]);
            
            // Update instructors
            $db->prepare("DELETE FROM event_instructors WHERE event_id = ?")->execute([$eventId]);
            if (!empty($selectedInstructors)) {
                $sortOrder = 0;
                foreach ($selectedInstructors as $insId) {
                    $db->prepare("INSERT INTO event_instructors (event_id, instructor_id, sort_order) VALUES (?, ?, ?)")->execute([$eventId, intval($insId), $sortOrder++]);
                }
            }
            
            // Update form fields
            $db->prepare("DELETE FROM event_form_fields WHERE event_id = ?")->execute([$eventId]);
            if (!empty($_POST['fields'])) {
                $sortOrder = 0;
                foreach ($_POST['fields'] as $field) {
                    if (empty($field['label'])) continue;
                    $fieldName = generateSlug($field['label']);
                    $fieldType = $field['type'] ?? 'text';
                    $fieldDescription = sanitize($field['description'] ?? '');
                    $isRequired = isset($field['required']) ? 1 : 0;
                    $options = null;
                    
                    // Handle options for select, multiple_choice, checkboxes
                    if (in_array($fieldType, ['select', 'multiple_choice', 'checkboxes']) && !empty($field['options'])) {
                        $optionLines = explode("\n", $field['options']);
                        $cleanOptions = array_filter(array_map('trim', $optionLines));
                        $options = json_encode(array_values($cleanOptions));
                    }
                    
                    $stmt = $db->prepare("INSERT INTO event_form_fields (event_id, field_name, field_label, field_description, field_type, options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$eventId, $fieldName, sanitize($field['label']), $fieldDescription, $fieldType, $options, $isRequired, $sortOrder++]);
                }
            }
            
            logActivity('event_updated', "Updated event: $title (ID: $eventId)");
            setFlash('success', 'Event updated successfully!');
            redirect('/admin/events/');
        } catch (Exception $e) {
            error_log("Event update error: " . $e->getMessage());
            $errors[] = "Error updating event: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<?php if (!empty($errors)): ?>
    <div class="flash-message flash-error">
        <ul style="list-style: none; margin: 0; padding: 0;">
            <?php foreach ($errors as $error): ?><li><?php echo sanitize($error); ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="admin-form">
    <?php echo csrfField(); ?>
    
    <div class="event-edit-wrapper">
        <!-- Sidebar Navigation -->
        <div class="event-edit-sidebar">
            <div class="sidebar-header">
                <h2>Event Settings</h2>
                <p>Configure your event details</p>
            </div>
            
            <nav class="sidebar-nav">
                <a href="#basic-info" class="sidebar-nav-item active" data-section="basic-info">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Basic Info
                </a>
                <a href="#event-details" class="sidebar-nav-item" data-section="event-details">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Event Details
                </a>
                <a href="#pricing" class="sidebar-nav-item" data-section="pricing">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Pricing & Early Bird
                </a>
                <a href="#faculty" class="sidebar-nav-item" data-section="faculty">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3.545M3.545 21H21v-2a6 6 0 00-6-6h-3a6 6 0 00-6 6v2z"/></svg>
                    Faculty
                </a>
                <a href="#support" class="sidebar-nav-item" data-section="support">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    Support
                </a>
                <a href="#form-fields" class="sidebar-nav-item" data-section="form-fields">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Registration Form
                </a>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="event-edit-content">
            <!-- Basic Info Section -->
            <section id="basic-info" class="event-section active">
                <div class="section-header">
                    <h2>Basic Information</h2>
                    <p>Core details about your event</p>
                </div>
                
                <div class="form-grid-2">
                    <!-- Status Field at Top for Quick Access -->
                    <div class="form-group">
                        <label for="status" style="font-weight: 600; color: var(--text-primary);">Event Status</label>
                        <select id="status" name="status" class="form-control" style="border: 2px solid var(--blue); background: var(--bg-secondary);">
                            <option value="draft" <?php echo $event['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo $event['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="completed" <?php echo $event['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $event['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                        <p class="form-text">Change the event visibility and registration status</p>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="title">Event Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" class="form-control" value="<?php echo sanitize($event['title']); ?>" required>
                        <p class="form-text">This is the main title displayed on the event page</p>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="short_description">Short Description</label>
                        <input type="text" id="short_description" name="short_description" class="form-control" value="<?php echo sanitize($event['short_description'] ?? ''); ?>" placeholder="Brief summary for listings">
                        <p class="form-text">2-3 line summary displayed in event listings</p>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="description">Full Description <span class="required">*</span></label>
                        <textarea id="description" name="description" class="form-control tinymce-editor" required><?php echo $event['description']; ?></textarea>
                        <p class="form-text">Detailed event description with formatting, bullets, highlights, and more</p>
                    </div>
                </div>
            </section>
            
            <!-- Event Details Section -->
            <section id="event-details" class="event-section">
                <div class="section-header">
                    <h2>Event Details</h2>
                    <p>Format, timing, and location information</p>
                </div>
                
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="type">Event Type</label>
                        <select id="type" name="type" class="form-control">
                            <option value="webinar" <?php echo $event['type'] === 'webinar' ? 'selected' : ''; ?>>Webinar</option>
                            <option value="workshop" <?php echo $event['type'] === 'workshop' ? 'selected' : ''; ?>>Workshop</option>
                            <option value="offline" <?php echo $event['type'] === 'offline' ? 'selected' : ''; ?>>Offline Program</option>
                            <option value="course" <?php echo $event['type'] === 'course' ? 'selected' : ''; ?>>Course</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="start_date">Start Date & Time</label>
                        <input type="datetime-local" id="start_date" name="start_date" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($event['start_date'])); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">End Date & Time</label>
                        <input type="datetime-local" id="end_date" name="end_date" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($event['end_date'])); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" class="form-control">
                            <option value="Asia/Kolkata" <?php echo $event['timezone'] === 'Asia/Kolkata' ? 'selected' : ''; ?>>IST (Asia/Kolkata)</option>
                            <option value="UTC" <?php echo $event['timezone'] === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                            <option value="America/New_York" <?php echo $event['timezone'] === 'America/New_York' ? 'selected' : ''; ?>>EST (New York)</option>
                            <option value="Europe/London" <?php echo $event['timezone'] === 'Europe/London' ? 'selected' : ''; ?>>GMT (London)</option>
                            <option value="Asia/Dubai" <?php echo $event['timezone'] === 'Asia/Dubai' ? 'selected' : ''; ?>>GST (Dubai)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="registration_deadline">Registration Deadline</label>
                        <input type="datetime-local" id="registration_deadline" name="registration_deadline" class="form-control" value="<?php echo $event['registration_deadline'] ? date('Y-m-d\TH:i', strtotime($event['registration_deadline'])) : ''; ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label><input type="checkbox" name="is_online" value="1" <?php echo $event['is_online'] ? 'checked' : ''; ?>> Online Event</label>
                        <p class="form-text">Check if this event is online</p>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="meeting_link">Meeting Link (for online events)</label>
                        <input type="url" id="meeting_link" name="meeting_link" class="form-control" placeholder="Zoom / Google Meet link" value="<?php echo sanitize($event['meeting_link'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="venue">Venue (for offline events)</label>
                        <input type="text" id="venue" name="venue" class="form-control" placeholder="Event location" value="<?php echo sanitize($event['venue'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="max_seats">Max Seats</label>
                        <input type="number" id="max_seats" name="max_seats" class="form-control" value="<?php echo $event['max_seats'] ?? ''; ?>" min="1">
                        <p class="form-text">Leave empty for unlimited</p>
                    </div>
                    
                    <div class="form-group">
                        <label><input type="checkbox" name="is_new" value="1" <?php echo !empty($event['is_new']) ? 'checked' : ''; ?>> Show "New" Badge</label>
                        <p class="form-text">Display animated "New" label</p>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="banner_image">Banner Image</label>
                        <?php if ($event['banner_image']): ?>
                            <div style="margin-bottom: 12px;">
                                <img src="/uploads/banners/<?php echo sanitize($event['banner_image']); ?>" alt="Current banner" style="max-height: 150px; border-radius: var(--radius-md); box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            </div>
                        <?php endif; ?>
                        <input type="file" id="banner_image" name="banner_image" class="form-control" accept="image/*">
                        <p class="form-text">Recommended: 1200x630px for social media preview</p>
                    </div>
                </div>
            </section>
            
            <!-- Pricing Section -->
            <section id="pricing" class="event-section">
                <div class="section-header">
                    <h2>Pricing & Early Bird</h2>
                    <p>Set pricing structure and special offers</p>
                </div>
                
                <div class="form-grid-2">
                    <div class="form-group">
                        <label><input type="checkbox" name="is_free" value="1" <?php echo !empty($event['is_free']) ? 'checked' : ''; ?>> Free Event</label>
                        <p class="form-text">Leave unchecked for paid events</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="price">Price</label>
                        <input type="number" id="price" name="price" class="form-control" value="<?php echo $event['price']; ?>" min="0" step="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label for="currency">Currency</label>
                        <select id="currency" name="currency" class="form-control">
                            <option value="INR" <?php echo $event['currency'] === 'INR' ? 'selected' : ''; ?>>INR (₹)</option>
                            <option value="USD" <?php echo $event['currency'] === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                            <option value="EUR" <?php echo $event['currency'] === 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_methods">Payment Methods</label>
                        <select id="payment_methods" name="payment_methods" class="form-control">
                            <option value="both" <?php echo $event['payment_methods'] === 'both' ? 'selected' : ''; ?>>Both (Razorpay & UPI)</option>
                            <option value="razorpay" <?php echo $event['payment_methods'] === 'razorpay' ? 'selected' : ''; ?>>Razorpay Only</option>
                            <option value="upi" <?php echo $event['payment_methods'] === 'upi' ? 'selected' : ''; ?>>UPI Only</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <h4 style="margin-bottom: 12px; font-size: 0.95rem;">Early Bird Pricing</h4>
                    </div>
                    
                    <div class="form-group">
                        <label for="early_bird_price">Early Bird Price</label>
                        <input type="number" id="early_bird_price" name="early_bird_price" class="form-control" value="<?php echo $event['early_bird_price'] ?? ''; ?>" min="0" step="0.01">
                        <p class="form-text">Leave empty to disable</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="early_bird_start">Early Bird Starts</label>
                        <input type="datetime-local" id="early_bird_start" name="early_bird_start" class="form-control" value="<?php echo $event['early_bird_start'] ? date('Y-m-d\TH:i', strtotime($event['early_bird_start'])) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="early_bird_end">Early Bird Ends</label>
                        <input type="datetime-local" id="early_bird_end" name="early_bird_end" class="form-control" value="<?php echo $event['early_bird_end'] ? date('Y-m-d\TH:i', strtotime($event['early_bird_end'])) : ''; ?>">
                    </div>
                </div>
            </section>
            
            <!-- Faculty Section -->
            <section id="faculty" class="event-section">
                <div class="section-header">
                    <h2>Faculty & Instructors</h2>
                    <p>Select instructors for this event</p>
                </div>
                
                <div class="form-group">
                    <?php if (empty($instructors)): ?>
                        <p style="color: var(--text-muted); font-size: 0.9rem; padding: 16px; background: var(--bg-secondary); border-radius: var(--radius-md);">No instructors available. <a href="/admin/instructors/">Add instructors first</a>.</p>
                    <?php else: ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px;">
                            <?php foreach ($instructors as $ins): ?>
                                <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm); cursor: pointer; border: 2px solid transparent; transition: all 0.2s; hover:border-color: var(--primary);">
                                    <input type="checkbox" name="instructors[]" value="<?php echo $ins['id']; ?>" <?php echo in_array($ins['id'], $eventInstructorIds) ? 'checked' : ''; ?>>
                                    <?php if (!empty($ins['photo'])): ?>
                                        <img src="/uploads/instructors/<?php echo sanitize($ins['photo']); ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                                    <?php else: ?>
                                        <div style="width:36px;height:36px;border-radius:50%;background:var(--blue-light);display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--blue);font-size:0.8rem;"><?php echo strtoupper(substr($ins['name'], 0, 2)); ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.9rem;"><?php echo sanitize($ins['name']); ?></div>
                                        <div style="font-size: 0.78rem; color: var(--text-muted);"><?php echo sanitize($ins['designation'] ?? ''); ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            
            <!-- Support Section -->
            <section id="support" class="event-section">
                <div class="section-header">
                    <h2>Support & Communication</h2>
                    <p>Contact info and WhatsApp integration</p>
                </div>
                
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="support_phone">Support Phone</label>
                        <input type="tel" id="support_phone" name="support_phone" class="form-control" placeholder="+91 98765 43210" value="<?php echo sanitize($event['support_phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="support_whatsapp">Support WhatsApp</label>
                        <input type="tel" id="support_whatsapp" name="support_whatsapp" class="form-control" placeholder="+91 98765 43210" value="<?php echo sanitize($event['support_whatsapp'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="whatsapp_group_link">WhatsApp Group Invite Link</label>
                        <input type="url" id="whatsapp_group_link" name="whatsapp_group_link" class="form-control" placeholder="https://chat.whatsapp.com/..." value="<?php echo sanitize($event['whatsapp_group_link'] ?? ''); ?>">
                        <p class="form-text">Users will receive this link after successful registration/payment</p>
                    </div>
                </div>
            </section>
            
            <!-- Registration Form Fields Section -->
            <section id="form-fields" class="event-section">
                <div class="section-header">
                    <h2>Registration Form Fields</h2>
                    <p>Add custom fields to collect additional information from attendees</p>
                </div>
                
                <div style="background: #f0f8ff; border: 1px solid #b3d9ff; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; color: #0066cc;">
                    <strong>Note:</strong> Name and Email are automatically included. Add any additional fields you need below.
                </div>
                
                <div id="fieldsContainer" style="margin-bottom: 20px;">
                    <?php if (!empty($existingFields)): ?>
                        <?php foreach ($existingFields as $idx => $field): ?>
                            <div class="form-field-item" style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                                <div class="form-grid-2">
                                    <input type="text" name="fields[<?php echo $idx; ?>][label]" placeholder="Field Label" class="form-control" value="<?php echo sanitize($field['field_label']); ?>" required>
                                    <select name="fields[<?php echo $idx; ?>][type]" class="form-control" onchange="updateFieldType(this)">
                                        <option value="text" <?php echo $field['field_type'] === 'text' ? 'selected' : ''; ?>>Short Text</option>
                                        <option value="textarea" <?php echo $field['field_type'] === 'textarea' ? 'selected' : ''; ?>>Long Text</option>
                                        <option value="email" <?php echo $field['field_type'] === 'email' ? 'selected' : ''; ?>>Email</option>
                                        <option value="phone" <?php echo $field['field_type'] === 'phone' ? 'selected' : ''; ?>>Phone</option>
                                        <option value="select" <?php echo $field['field_type'] === 'select' ? 'selected' : ''; ?>>Dropdown</option>
                                        <option value="multiple_choice" <?php echo $field['field_type'] === 'multiple_choice' ? 'selected' : ''; ?>>Multiple Choice</option>
                                        <option value="checkboxes" <?php echo $field['field_type'] === 'checkboxes' ? 'selected' : ''; ?>>Checkboxes</option>
                                        <option value="date" <?php echo $field['field_type'] === 'date' ? 'selected' : ''; ?>>Date</option>
                                    </select>
                                </div>
                                
                                <div class="form-group" style="margin-top: 12px;">
                                    <input type="text" name="fields[<?php echo $idx; ?>][description]" placeholder="Description (optional)" class="form-control" value="<?php echo sanitize($field['field_description'] ?? ''); ?>">
                                </div>
                                
                                <?php if (in_array($field['field_type'], ['select', 'multiple_choice', 'checkboxes'])): ?>
                                    <div class="form-group">
                                        <label>Options (one per line)</label>
                                        <textarea name="fields[<?php echo $idx; ?>][options]" class="form-control" placeholder="Option 1&#10;Option 2&#10;Option 3" style="min-height: 100px;"><?php echo sanitize($field['options'] ? implode("\n", json_decode($field['options'], true)) : ''); ?></textarea>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="form-group" style="display: flex; gap: 12px; align-items: center;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                                        <input type="checkbox" name="fields[<?php echo $idx; ?>][required]" value="1" <?php echo $field['is_required'] ? 'checked' : ''; ?>>
                                        <span>Required Field</span>
                                    </label>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeField(this)">Remove</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; background: var(--bg-secondary); border: 1px dashed var(--border-color); border-radius: 8px; color: var(--text-muted);">
                            No custom fields added. Click "Add Field" to create one.
                        </div>
                    <?php endif; ?>
                </div>
                
                <button type="button" class="btn btn-primary" onclick="addField()" style="margin-bottom: 20px;">
                    <svg style="width: 18px; height: 18px; display: inline; margin-right: 6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Field
                </button>
            </section>
            
            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Save Changes</button>
                <a href="/admin/events/" class="btn btn-secondary btn-lg">Cancel</a>
            </div>
        </div>
    </div>
</form>

<style>
.event-edit-wrapper {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 24px;
    max-width: 1400px;
    margin: 0 auto;
}

.event-edit-sidebar {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.sidebar-header {
    margin-bottom: 24px;
}

.sidebar-header h2 {
    font-size: 1.3rem;
    margin: 0 0 4px 0;
    color: var(--text-primary);
}

.sidebar-header p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.sidebar-nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    text-decoration: none;
    transition: all 0.2s;
    border-left: 3px solid transparent;
    font-weight: 500;
    cursor: pointer;
}

.sidebar-nav-item:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
}

.sidebar-nav-item.active {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
    color: var(--primary);
    border-left-color: var(--primary);
}

.sidebar-nav-item svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

.event-edit-content {
    padding-bottom: 40px;
}

.event-section {
    display: none;
    padding: 32px;
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-color);
    margin-bottom: 24px;
    animation: fadeIn 0.3s ease-out;
}

.event-section.active {
    display: block;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.section-header {
    margin-bottom: 28px;
}

.section-header h2 {
    font-size: 1.4rem;
    margin: 0 0 6px 0;
    color: var(--text-primary);
}

.section-header p {
    margin: 0;
    font-size: 0.95rem;
    color: var(--text-secondary);
}

.form-actions {
    display: flex;
    gap: 12px;
    padding: 24px 32px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-color);
    margin-top: 32px;
}

.btn-lg {
    padding: 12px 32px !important;
    font-size: 1rem;
}

@media (max-width: 1024px) {
    .event-edit-wrapper {
        grid-template-columns: 240px 1fr;
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .event-edit-wrapper {
        grid-template-columns: 1fr;
    }
    
    .event-edit-sidebar {
        position: static;
    }
    
    .sidebar-nav {
        flex-direction: row;
        overflow-x: auto;
        padding-bottom: 8px;
    }
    
    .sidebar-nav-item {
        flex-shrink: 0;
    }
    
    .event-section {
        padding: 20px;
    }
}
</style>

<script>
document.querySelectorAll('.sidebar-nav-item').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const sectionId = this.getAttribute('data-section');
        
        // Hide all sections
        document.querySelectorAll('.event-section').forEach(section => {
            section.classList.remove('active');
        });
        
        // Remove active from all nav items
        document.querySelectorAll('.sidebar-nav-item').forEach(item => {
            item.classList.remove('active');
        });
        
        // Show selected section
        document.getElementById(sectionId).classList.add('active');
        this.classList.add('active');
    });
});

// Form Fields Management
let fieldCount = <?php echo !empty($existingFields) ? count($existingFields) : 0; ?>;

function addField() {
    const container = document.getElementById('fieldsContainer');
    
    // If this is the first field, clear the empty message
    if (fieldCount === 0 && container.querySelector('[style*="text-align: center"]')) {
        container.innerHTML = '';
    }
    
    const fieldHTML = `
        <div class="form-field-item" style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 16px; border-radius: 8px; margin-bottom: 12px;">
            <div class="form-grid-2">
                <input type="text" name="fields[${fieldCount}][label]" placeholder="Field Label" class="form-control" required>
                <select name="fields[${fieldCount}][type]" class="form-control" onchange="updateFieldType(this)">
                    <option value="text">Short Text</option>
                    <option value="textarea">Long Text</option>
                    <option value="email">Email</option>
                    <option value="phone">Phone</option>
                    <option value="select">Dropdown</option>
                    <option value="multiple_choice">Multiple Choice</option>
                    <option value="checkboxes">Checkboxes</option>
                    <option value="date">Date</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-top: 12px;">
                <input type="text" name="fields[${fieldCount}][description]" placeholder="Description (optional)" class="form-control">
            </div>
            
            <div class="options-section" style="display: none;">
                <div class="form-group">
                    <label>Options (one per line)</label>
                    <textarea name="fields[${fieldCount}][options]" class="form-control" placeholder="Option 1&#10;Option 2&#10;Option 3" style="min-height: 100px;"></textarea>
                </div>
            </div>
            
            <div class="form-group" style="display: flex; gap: 12px; align-items: center;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                    <input type="checkbox" name="fields[${fieldCount}][required]" value="1">
                    <span>Required Field</span>
                </label>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeField(this)">Remove</button>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', fieldHTML);
    fieldCount++;
}

function removeField(button) {
    const container = document.getElementById('fieldsContainer');
    button.closest('.form-field-item').remove();
    
    if (container.querySelectorAll('.form-field-item').length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; background: var(--bg-secondary); border: 1px dashed var(--border-color); border-radius: 8px; color: var(--text-muted);">
                No custom fields added. Click "Add Field" to create one.
            </div>
        `;
    }
}

function updateFieldType(select) {
    const fieldItem = select.closest('.form-field-item');
    const optionsSection = fieldItem.querySelector('.options-section');
    const fieldType = select.value;
    
    if (['select', 'multiple_choice', 'checkboxes'].includes(fieldType)) {
        optionsSection.style.display = 'block';
    } else {
        optionsSection.style.display = 'none';
    }
}

// Initialize options display for existing fields
document.querySelectorAll('.form-field-item').forEach(item => {
    const select = item.querySelector('select[name*="[type]"]');
    if (select) {
        updateFieldType(select);
    }
});
</script>

<!-- TinyMCE Rich Text Editor -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '.tinymce-editor',
    license_key: 'gpl',
    height: 400,
    menubar: true,
    plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount linkchecker',
    toolbar: 'undo redo | formatselect | bold italic underline strikethrough | forecolor backcolor | link image media table | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat',
    font_formats: 'Segoe UI=Segoe UI,Arial; Arial=arial,helvetica,sans-serif; Courier New=courier new,courier,monospace; Georgia=georgia,palatino; Times New Roman=times new roman,times',
    content_style: 'body { font-family:Montserrat,Arial,sans-serif; font-size:14px; } p { margin: 8px 0; line-height: 1.6; } h1 { font-size: 28px; margin: 16px 0 8px 0; } h2 { font-size: 24px; margin: 14px 0 6px 0; } h3 { font-size: 20px; margin: 12px 0 4px 0; } ul, ol { margin: 8px 0; padding-left: 20px; } li { margin: 4px 0; }',
    setup: function(editor) {
        editor.on('change', function() {
            tinymce.triggerSave();
        });
    },
    mobile: {
        toolbar: 'undo redo | formatselect | bold italic | bullist numlist'
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
