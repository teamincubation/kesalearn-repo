<?php
/**
 * KESA Learn - Admin: Create Event
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'events';
$pageTitle = 'Create Event';
$errors = [];

// Fetch instructors and languages
$instructors = $db->query("SELECT * FROM instructors WHERE is_active = 1 ORDER BY name")->fetchAll();
$languages = $db->query("SELECT * FROM languages WHERE is_active = 1 ORDER BY name")->fetchAll();

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
    $status = $_POST['status'] ?? 'draft';
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
    if (empty($startDate)) $errors[] = 'Start date is required.';
    if (empty($endDate)) $errors[] = 'End date is required.';
    
    // Handle banner upload
    $bannerImage = null;
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
            $stmt = $db->prepare("INSERT INTO events (title, slug, description, short_description, type, banner_image, start_date, end_date, timezone, venue, is_online, meeting_link, communication_languages, max_seats, price, early_bird_price, early_bird_start, early_bird_end, currency, is_free, status, is_new, registration_deadline, support_phone, support_whatsapp, payment_methods, whatsapp_group_link, whatsapp_group_enabled, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, 'temp', $description, $shortDescription, $type, $bannerImage, $startDate, $endDate, $timezone, $venue, $isOnline, $meetingLink, $communicationLanguages, $maxSeats, $price, $earlyBirdPrice, $earlyBirdStart, $earlyBirdEnd, $currency, $isFree, $status, $isNew, $registrationDeadline, $supportPhone, $supportWhatsapp, $paymentMethods, $whatsappGroupLink, $whatsappGroupEnabled, $_SESSION['user_id']]);
            
            $eventId = $db->lastInsertId();
            
            // Update with unique slug based on event ID
            $uniqueSlug = 'event_' . $eventId;
            $db->prepare("UPDATE events SET slug = ? WHERE id = ?")->execute([$uniqueSlug, $eventId]);
            
            // Save instructors
            if (!empty($selectedInstructors)) {
                $sortOrder = 0;
                foreach ($selectedInstructors as $insId) {
                    $db->prepare("INSERT INTO event_instructors (event_id, instructor_id, sort_order) VALUES (?, ?, ?)")->execute([$eventId, intval($insId), $sortOrder++]);
                }
            }
            
            // Handle custom form fields
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
            
            logActivity('event_created', "Created event: $title (ID: $eventId)");
            setFlash('success', 'Event created successfully! Now add instructors and configure details.');
            redirect('/admin/events/edit?id=' . $eventId);
        } catch (Exception $e) {
            error_log("Event creation error: " . $e->getMessage());
            $errors[] = "Error creating event: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* Professional Admin Form Styles */
.admin-form-section {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 28px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.admin-form-section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-light);
}

.admin-form-section-header svg {
    width: 24px;
    height: 24px;
    color: var(--blue);
    flex-shrink: 0;
}

.admin-form-section-header h3 {
    font-size: 1.15rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-primary);
}

.admin-form-section-header p {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin: 4px 0 0;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

@media (max-width: 768px) {
    .form-grid-2, .form-grid-3 {
        grid-template-columns: 1fr;
    }
}

.form-group label {
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 6px;
    display: block;
    color: var(--text-secondary);
}

.form-control {
    padding: 12px 14px;
    font-size: 0.95rem;
    border-radius: var(--radius-md);
    transition: all 0.2s ease;
}

.form-control:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.admin-form-actions {
    display: flex;
    gap: 12px;
    padding: 24px 0;
    border-top: 1px solid var(--border-color);
    margin-top: 12px;
    position: sticky;
    bottom: 0;
    background: var(--bg-secondary);
    z-index: 10;
}

.checkbox-styled {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
}

.checkbox-styled:hover {
    border-color: var(--blue);
    background: var(--blue-light);
}

.checkbox-styled input:checked ~ span {
    color: var(--blue);
    font-weight: 600;
}

.field-builder-container {
    background: var(--bg-tertiary);
    border: 2px dashed var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    min-height: 120px;
}

.field-builder-container:empty::before {
    content: 'No custom fields added. Click "Add Field" to create one.';
    display: block;
    text-align: center;
    color: var(--text-muted);
    padding: 24px;
    font-size: 0.9rem;
}
</style>

<?php if (!empty($errors)): ?>
    <div class="flash-message flash-error" style="margin-bottom: 24px;">
        <ul style="list-style: none; margin: 0; padding: 0;">
            <?php foreach ($errors as $error): ?><li><?php echo sanitize($error); ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="admin-form" style="max-width: 1000px;">
    <?php echo csrfField(); ?>
    
    <!-- Basic Information Section -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                <h3>Basic Information</h3>
                <p>Event title, description, and key details</p>
            </div>
        </div>
        
        <div class="form-group">
            <label for="title">Event Title <span class="required">*</span></label>
            <input type="text" id="title" name="title" class="form-control" placeholder="Enter a compelling event title" required>
        </div>
        
        <div class="form-group">
            <label for="short_description">Short Description</label>
            <input type="text" id="short_description" name="short_description" class="form-control" placeholder="Brief one-line description for listings" maxlength="500">
        </div>
        
        <div class="form-group">
            <label for="description">Full Description <span class="required">*</span></label>
            <textarea id="description" name="description" class="form-control tinymce-editor" placeholder="Detailed event description with agenda, outcomes, and what participants will learn" required></textarea>
            <p class="form-text">Detailed event description with formatting, bullets, highlights, and more</p>
        </div>
        
        <div class="form-grid-2" style="margin-top: 20px;">
            <div class="form-group">
                <label for="type">Event Type</label>
                <select id="type" name="type" class="form-control">
                    <option value="webinar">Webinar</option>
                    <option value="workshop">Workshop</option>
                    <option value="offline">Offline Program</option>
                    <option value="course">Course</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="draft">Draft - Not visible to users</option>
                    <option value="published">Published - Visible to all users</option>
                </select>
            </div>
        </div>
        
        <div class="form-group" style="margin-top: 16px;">
            <label class="checkbox-styled">
                <input type="checkbox" name="is_new" value="1">
                <span>Show "New" Label - Display an animated badge to highlight this event</span>
            </label>
        </div>
    </div>
    
    <!-- Schedule & Location Section -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <div>
                <h3>Schedule & Location</h3>
                <p>Date, time, venue, and meeting details</p>
            </div>
        </div>
        
        <div class="form-grid-2">
            <div class="form-group">
                <label for="start_date">Start Date & Time <span class="required">*</span></label>
                <input type="datetime-local" id="start_date" name="start_date" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="end_date">End Date & Time <span class="required">*</span></label>
                <input type="datetime-local" id="end_date" name="end_date" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone" class="form-control">
                    <option value="Asia/Kolkata">IST (Asia/Kolkata)</option>
                    <option value="UTC">UTC</option>
                    <option value="America/New_York">EST (New York)</option>
                    <option value="Europe/London">GMT (London)</option>
                    <option value="Asia/Dubai">GST (Dubai)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="registration_deadline">Registration Deadline</label>
                <input type="datetime-local" id="registration_deadline" name="registration_deadline" class="form-control">
            </div>
        </div>
        
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px dashed var(--border-color);">
            <div class="form-group">
                <label class="checkbox-styled" style="margin-bottom: 16px;">
                    <input type="checkbox" name="is_online" value="1" checked id="is_online_check">
                    <span>This is an Online Event</span>
                </label>
            </div>
            
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="meeting_link">Meeting Link</label>
                    <input type="url" id="meeting_link" name="meeting_link" class="form-control" placeholder="https://zoom.us/j/...">
                </div>
                
                <div class="form-group">
                    <label for="venue">Venue (for offline events)</label>
                    <input type="text" id="venue" name="venue" class="form-control" placeholder="Physical address or location">
                </div>
            </div>
        </div>
        
        <div class="form-grid-2" style="margin-top: 16px;">
            <div class="form-group">
                <label for="max_seats">Maximum Seats</label>
                <input type="number" id="max_seats" name="max_seats" class="form-control" placeholder="Leave empty for unlimited" min="1">
            </div>
            
            <div class="form-group">
                <label for="banner_image">Banner Image</label>
                <input type="file" id="banner_image" name="banner_image" class="form-control" accept="image/*">
                <p class="form-text">Recommended: 1200x600px. Max 5MB. JPG/PNG formats.</p>
            </div>
        </div>
    </div>
    
    <!-- Instructors Section -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            <div>
                <h3>Faculty / Instructors</h3>
                <p>Select instructors who will be conducting this event</p>
            </div>
        </div>
        <div class="form-group">
            <?php if (empty($instructors)): ?>
                <p style="color: var(--text-muted); font-size: 0.9rem;">No instructors available. <a href="/admin/instructors/">Add instructors first</a>.</p>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px;">
                    <?php foreach ($instructors as $ins): ?>
                        <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm); cursor: pointer; border: 1px solid var(--border-color);">
                            <input type="checkbox" name="instructors[]" value="<?php echo $ins['id']; ?>">
                            <?php if (!empty($ins['photo'])): ?>
                                <img src="/uploads/instructors/<?php echo sanitize($ins['photo']); ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <div style="width:32px;height:32px;border-radius:50%;background:var(--blue-light);display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--blue);font-size:0.75rem;"><?php echo strtoupper(substr($ins['name'], 0, 2)); ?></div>
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
    </div>
    
    <!-- Communication Languages Section -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
            <div>
                <h3>Communication Languages</h3>
                <p>Select languages in which the event will be conducted</p>
            </div>
        </div>
        <div class="form-group">
            <div style="display: flex; flex-wrap: wrap; gap: 12px;">
                <?php foreach ($languages as $lang): ?>
                    <label style="display: flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--bg-secondary); border-radius: 20px; cursor: pointer; border: 1px solid var(--border-color);">
                        <input type="checkbox" name="comm_languages[]" value="<?php echo sanitize($lang['name']); ?>">
                        <?php echo sanitize($lang['name']); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Pricing & Payment Methods Section -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                <h3>Pricing & Payment Methods</h3>
                <p>Set event pricing, early bird offers, and accepted payment methods</p>
            </div>
        </div>
        
        <div class="form-group">
            <label class="checkbox-styled">
                <input type="checkbox" name="is_free" value="1" checked id="is_free_check">
                <span>This is a Free Event - No payment required</span>
            </label>
        </div>
        
        <div class="form-grid-3" id="pricing-fields" style="margin-top: 20px;">
            <div class="form-group">
                <label for="currency">Currency</label>
                <select id="currency" name="currency" class="form-control">
                    <option value="INR">INR (Indian Rupee)</option>
                    <option value="USD">USD (US Dollar)</option>
                    <option value="EUR">EUR (Euro)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="price">Regular Price</label>
                <input type="number" id="price" name="price" class="form-control" value="0" min="0" step="0.01">
            </div>
            
            <div class="form-group">
                <label for="payment_methods">Payment Methods</label>
                <select id="payment_methods" name="payment_methods" class="form-control">
                    <option value="both">Both (Razorpay + Manual UPI)</option>
                    <option value="razorpay">Razorpay Only</option>
                    <option value="upi">Manual UPI Only</option>
                </select>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px dashed var(--border-color);">
            <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 16px; color: var(--text-secondary);">Early Bird Pricing (Optional)</h4>
            <div class="form-grid-3">
                <div class="form-group">
                    <label for="early_bird_price">Early Bird Price</label>
                    <input type="number" id="early_bird_price" name="early_bird_price" class="form-control" placeholder="Discounted price" min="0" step="0.01">
                </div>
                
                <div class="form-group">
                    <label for="early_bird_start">Start Date</label>
                    <input type="datetime-local" id="early_bird_start" name="early_bird_start" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="early_bird_end">End Date</label>
                    <input type="datetime-local" id="early_bird_end" name="early_bird_end" class="form-control">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Support Contact & WhatsApp Section -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <div>
                <h3>Support Contact & WhatsApp Group</h3>
                <p>Contact information and WhatsApp group link for attendees</p>
            </div>
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label for="support_phone">Support Phone Number</label>
                <input type="tel" id="support_phone" name="support_phone" class="form-control" placeholder="+91 98765 43210">
            </div>
            
            <div class="form-group">
                <label for="support_whatsapp">Support WhatsApp Number</label>
                <input type="tel" id="support_whatsapp" name="support_whatsapp" class="form-control" placeholder="+91 98765 43210">
            </div>
            
            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="whatsapp_group_link">WhatsApp Group Invite Link</label>
                <input type="url" id="whatsapp_group_link" name="whatsapp_group_link" class="form-control" placeholder="https://chat.whatsapp.com/...">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 8px;">Add a WhatsApp group invite link. After successful payment, users will receive an email and popup to join this group.</p>
            </div>
        </div>
    </div>
    
    <!-- Event URL & Slug Section -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            <div>
                <h3>Event URL</h3>
                <p>Custom URL slug for this event</p>
            </div>
        </div>
        <div class="form-group">
    <!-- Custom Form Fields -->
    <div class="admin-form-section">
        <div class="admin-form-section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <div style="flex: 1;">
                <h3>Registration Form Fields</h3>
                <p>Add custom fields to collect additional information from attendees</p>
            </div>
            <button type="button" id="add-field-btn" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Field
            </button>
        </div>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm);">
            <strong>Note:</strong> Name and Email are automatically included. Add any additional fields you need below.
        </p>
        <div id="field-builder" class="field-builder-container"></div>
        
        <script>
        (function() {
            var fieldIndex = 0;
            var builder = document.getElementById('field-builder');
            var addBtn = document.getElementById('add-field-btn');
            
            function createFieldRow() {
                var div = document.createElement('div');
                div.className = 'field-row';
                div.style.cssText = 'background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px;';
                
                div.innerHTML = '<div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: start;">' +
                    '<div class="form-group" style="margin: 0;">' +
                        '<label style="font-size: 0.8rem;">Field Label <span class="required">*</span></label>' +
                        '<input type="text" name="fields[' + fieldIndex + '][label]" class="form-control" placeholder="e.g. Organization Name">' +
                    '</div>' +
                    '<div class="form-group" style="margin: 0;">' +
                        '<label style="font-size: 0.8rem;">Field Type</label>' +
                        '<select name="fields[' + fieldIndex + '][type]" class="form-control field-type-select">' +
                            '<option value="text">Text</option>' +
                            '<option value="textarea">Text Area</option>' +
                            '<option value="number">Number</option>' +
                            '<option value="email">Email</option>' +
                            '<option value="tel">Phone</option>' +
                            '<option value="date">Date</option>' +
                            '<option value="url">URL</option>' +
                            '<option value="select">Dropdown (Single Select)</option>' +
                            '<option value="multiple_choice">Multiple Choice (Radio)</option>' +
                            '<option value="checkboxes">Checkboxes (Multi Select)</option>' +
                        '</select>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-danger remove-field-btn" style="margin-top: 22px;" title="Remove Field">' +
                        '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>' +
                    '</button>' +
                '</div>' +
                '<div style="margin-top: 12px;">' +
                    '<div class="form-group" style="margin: 0;">' +
                        '<label style="font-size: 0.8rem;">Field Description/Instructions (optional)</label>' +
                        '<input type="text" name="fields[' + fieldIndex + '][description]" class="form-control" placeholder="Brief instruction for users filling this field" style="font-size: 0.9rem;">' +
                        '<p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">This will be shown as a small gray helper text below the field label.</p>' +
                    '</div>' +
                '</div>' +
                '<div class="options-container" style="display: none; margin-top: 12px;">' +
                    '<div class="form-group" style="margin: 0;">' +
                        '<label style="font-size: 0.8rem;">Options (one per line) <span class="required">*</span></label>' +
                        '<textarea name="fields[' + fieldIndex + '][options]" class="form-control" rows="4" placeholder="Option 1\nOption 2\nOption 3"></textarea>' +
                    '</div>' +
                '</div>' +
                '<div style="margin-top: 12px;">' +
                    '<label style="font-size: 0.85rem; display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                        '<input type="checkbox" name="fields[' + fieldIndex + '][required]" value="1"> Required Field' +
                    '</label>' +
                '</div>';
                
                fieldIndex++;
                
                // Add event listeners
                var typeSelect = div.querySelector('.field-type-select');
                var optionsContainer = div.querySelector('.options-container');
                var removeBtn = div.querySelector('.remove-field-btn');
                
                typeSelect.addEventListener('change', function() {
                    var needsOptions = ['select', 'multiple_choice', 'checkboxes'].indexOf(this.value) !== -1;
                    optionsContainer.style.display = needsOptions ? 'block' : 'none';
                });
                
                removeBtn.addEventListener('click', function() {
                    div.remove();
                });
                
                return div;
            }
            
            addBtn.addEventListener('click', function() {
                builder.appendChild(createFieldRow());
            });
        })();
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
    </div>
    
    <div class="admin-form-actions" style="margin-top: 32px;">
        <button type="submit" class="btn btn-primary btn-lg" style="display: flex; align-items: center; gap: 8px; padding: 14px 28px;">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            Create Event
        </button>
        <a href="/admin/events/" class="btn btn-secondary btn-lg" style="padding: 14px 28px;">Cancel</a>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
