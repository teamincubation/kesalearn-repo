<?php
/**
 * KESA Learn - Admin Past Participants Bulk Upload
 * Upload CSV file to add past event participants
 */
require_once __DIR__ . '/../../includes/admin_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$db = getDB();

// Handle template download BEFORE any output
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="past_participants_template.csv"');
    echo "name,email,event_id,registration_date\n";
    echo "John Doe,example@email.com,1,2024-01-15\n";
    echo "Jane Smith,another@email.com,2,2024-02-20\n";
    exit;
}

$adminPage = 'events';
$pageTitle = 'Past Participants Upload';

$successMessage = '';
$errorMessage = '';
$importResults = null;

// Get all events for reference
$eventsStmt = $db->query("SELECT id, title, start_date FROM events ORDER BY start_date DESC");
$events = $eventsStmt->fetchAll();

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    try {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Invalid security token. Please try again.';
    } else {
        $file = $_FILES['csv_file'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = 'File upload failed. Error code: ' . $file['error'];
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errorMessage = 'File too large. Maximum size is 5MB.';
        } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
            $errorMessage = 'Invalid file type. Only CSV files are allowed.';
        } else {
            // Process CSV
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $header = fgetcsv($handle);
                
                // Normalize header
                $header = array_map(function($h) {
                    return strtolower(trim($h));
                }, $header);
                
                // Check required columns
                $nameIdx = array_search('name', $header);
                $emailIdx = array_search('email', $header);
                $eventIdx = array_search('event_id', $header);
                $dateIdx = array_search('registration_date', $header);
                
                if ($emailIdx === false || $eventIdx === false || $dateIdx === false) {
                    $errorMessage = 'Invalid CSV format. Required columns: name, email, event_id, registration_date';
                } else {
                    $imported = 0;
                    $skipped = 0;
                    $errors = [];
                    $row = 1;
                    
                    while (($data = fgetcsv($handle)) !== false) {
                        $row++;
                        
                        if (count($data) < 3 || empty(trim($data[$emailIdx]))) {
                            continue; // Skip empty rows
                        }
                        
                        $name = $nameIdx !== false ? sanitize(trim($data[$nameIdx] ?? '')) : '';
                        $email = trim(strtolower($data[$emailIdx]));
                        $eventId = intval($data[$eventIdx]);
                        $regDate = trim($data[$dateIdx]);
                        
                        // Validate email
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $errors[] = "Row $row: Invalid email '$email'";
                            $skipped++;
                            continue;
                        }
                        
                        // Validate event exists
                        $checkEventStmt = $db->prepare("SELECT id, title FROM events WHERE id = ?");
                        $checkEventStmt->execute([$eventId]);
                        $event = $checkEventStmt->fetch();
                        if (!$event) {
                            $errors[] = "Row $row: Event ID $eventId not found";
                            $skipped++;
                            continue;
                        }
                        
                        // Parse and validate date - support multiple formats
                        $formattedDate = null;
                        $dateFormats = ['Y-m-d', 'd-m-Y', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'Y-m-d H:i:s'];
                        foreach ($dateFormats as $format) {
                            $parsed = DateTime::createFromFormat($format, $regDate);
                            if ($parsed !== false) {
                                $formattedDate = $parsed->format('Y-m-d H:i:s');
                                break;
                            }
                        }
                        
                        if (!$formattedDate) {
                            $errors[] = "Row $row: Invalid date format '$regDate'. Use YYYY-MM-DD";
                            $skipped++;
                            continue;
                        }
                        
                        // Check if user exists
                        $getUserStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $getUserStmt->execute([$email]);
                        $user = $getUserStmt->fetch();
                        
                        if (!$user) {
                            // Create a placeholder user account (marked so they can sign up fresh later)
                            $tempPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                            $createUserStmt = $db->prepare("
                                INSERT INTO users (email, password_hash, name, role, email_verified, is_placeholder, created_at)
                                VALUES (?, ?, ?, 'user', 1, 1, NOW())
                            ");
                            // Use name from CSV if available, otherwise use email prefix
                            $userName = !empty($name) ? $name : ucfirst(explode('@', $email)[0]);
                            $createUserStmt->execute([$email, $tempPassword, $userName]);
                            $userId = $db->lastInsertId();
                        } else {
                            $userId = $user['id'];
                            // Update user name if provided and user exists but has placeholder name
                            if (!empty($name)) {
                                $updateNameStmt = $db->prepare("UPDATE users SET name = ? WHERE id = ? AND (name = '' OR name IS NULL OR name = SUBSTRING_INDEX(email, '@', 1))");
                                $updateNameStmt->execute([$name, $userId]);
                            }
                        }
                        
                        // Check for existing registration
                        $checkRegStmt = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ?");
                        $checkRegStmt->execute([$userId, $eventId]);
                        if ($checkRegStmt->fetch()) {
                            $skipped++;
                            continue; // Already registered
                        }
                        
                        // Get event price to determine payment details
                        $eventStmt = $db->prepare("SELECT price, is_free FROM events WHERE id = ?");
                        $eventStmt->execute([$eventId]);
                        $eventData = $eventStmt->fetch();
                        
                        // Set payment details based on event type
                        // Past participants are assumed to have paid via UPI for paid events
                        $isFreeEvent = $eventData && ($eventData['is_free'] == 1 || floatval($eventData['price']) == 0);
                        $amount = $isFreeEvent ? 0 : floatval($eventData['price'] ?? 0);
                        $paymentMethod = $isFreeEvent ? 'free' : 'upi';
                        $paymentStatus = 'verified'; // Past participants are already verified
                        
                        // Insert registration
                        try {
                            $insertRegStmt = $db->prepare("
                                INSERT INTO registrations (user_id, event_id, payment_method, payment_status, amount, registered_at)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $insertRegStmt->execute([$userId, $eventId, $paymentMethod, $paymentStatus, $amount, $formattedDate]);
                            
                            // Update seats taken
                            $db->prepare("UPDATE events SET seats_taken = seats_taken + 1 WHERE id = ?")->execute([$eventId]);
                            
                            $imported++;
                        } catch (PDOException $e) {
                            $errors[] = "Row $row: Database error - " . $e->getMessage();
                            $skipped++;
                        }
                    }
                    
                    fclose($handle);
                    
                    $importResults = [
                        'imported' => $imported,
                        'skipped' => $skipped,
                        'errors' => $errors
                    ];
                    
                    if ($imported > 0) {
                        $successMessage = "Successfully imported $imported participant(s).";
                    }
                }
            } else {
                $errorMessage = 'Could not read the CSV file.';
            }
        }
    }
    } catch (Exception $e) {
        $errorMessage = 'Error processing CSV: ' . $e->getMessage();
    } catch (Error $e) {
        $errorMessage = 'System error: ' . $e->getMessage();
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.upload-container {
    max-width: 900px;
    margin: 0 auto;
}

.hero-section {
    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 50%, #1e3a5f 100%);
    border-radius: 16px;
    padding: 40px;
    margin-bottom: 32px;
    color: #fff;
    position: relative;
    overflow: hidden;
}

.hero-section::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    transform: translate(30%, -30%);
}

.hero-title {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.hero-desc {
    opacity: 0.9;
    font-size: 0.95rem;
    max-width: 600px;
}

.card-section {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 28px;
    margin-bottom: 24px;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.section-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.section-icon.download { background: linear-gradient(135deg, #059669 0%, #10b981 100%); }
.section-icon.upload { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
.section-icon.events { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.section-subtitle {
    font-size: 0.85rem;
    color: var(--text-muted);
}

.template-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    border: 1px solid #a7f3d0;
    border-radius: 10px;
    padding: 20px 24px;
}

.template-info {
    display: flex;
    align-items: center;
    gap: 16px;
}

.template-icon {
    width: 48px;
    height: 48px;
    background: #fff;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #059669;
}

.template-text h4 {
    font-size: 0.95rem;
    font-weight: 600;
    color: #065f46;
    margin-bottom: 4px;
}

.template-text p {
    font-size: 0.8rem;
    color: #047857;
}

.btn-download {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #059669;
    color: #fff;
    padding: 12px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-download:hover {
    background: #047857;
    transform: translateY(-1px);
}

.drop-zone {
    border: 2px dashed var(--border-color);
    border-radius: 12px;
    padding: 48px 24px;
    text-align: center;
    transition: all 0.3s;
    cursor: pointer;
    background: var(--bg-secondary);
}

.drop-zone:hover, .drop-zone.dragover {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.05);
}

.drop-zone-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 16px;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3b82f6;
}

.drop-zone-text {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.drop-zone-hint {
    font-size: 0.85rem;
    color: var(--text-muted);
}

.file-selected {
    display: none;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    margin-top: 16px;
}

.file-selected.show {
    display: flex;
}

.file-icon {
    width: 40px;
    height: 40px;
    background: #3b82f6;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
}

.file-info {
    flex: 1;
}

.file-name {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.9rem;
}

.file-size {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.btn-remove-file {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    padding: 8px;
}

.btn-upload {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 14px 24px;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    margin-top: 20px;
    transition: all 0.2s;
}

.btn-upload:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.btn-upload:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.events-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.events-table th,
.events-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.events-table th {
    background: var(--bg-secondary);
    font-weight: 600;
    color: var(--text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
}

.events-table tr:hover td {
    background: var(--bg-secondary);
}

.event-id-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    padding: 4px 10px;
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: #fff;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.8rem;
}

.results-card {
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}

.results-card.success {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    border: 1px solid #a7f3d0;
}

.results-card.error {
    background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%);
    border: 1px solid #fca5a5;
}

.results-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.results-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.results-card.success .results-icon {
    background: #059669;
    color: #fff;
}

.results-card.error .results-icon {
    background: #dc2626;
    color: #fff;
}

.results-title {
    font-size: 1.1rem;
    font-weight: 600;
}

.results-card.success .results-title { color: #065f46; }
.results-card.error .results-title { color: #991b1b; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.stat-item {
    background: rgba(255,255,255,0.7);
    border-radius: 8px;
    padding: 16px;
    text-align: center;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.error-list {
    background: rgba(255,255,255,0.7);
    border-radius: 8px;
    padding: 12px 16px;
    max-height: 150px;
    overflow-y: auto;
}

.error-list-item {
    font-size: 0.85rem;
    color: #991b1b;
    padding: 4px 0;
    border-bottom: 1px solid rgba(239, 68, 68, 0.1);
}

.error-list-item:last-child {
    border-bottom: none;
}

@media (max-width: 768px) {
    .hero-section {
        padding: 24px;
    }
    
    .hero-title {
        font-size: 1.3rem;
    }
    
    .template-box {
        flex-direction: column;
        gap: 16px;
        text-align: center;
    }
    
    .template-info {
        flex-direction: column;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="upload-container">
    <!-- Hero Section -->
    <div class="hero-section">
        <h1 class="hero-title">
            <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Past Participants Bulk Upload
        </h1>
        <p class="hero-desc">Import historical event participants from a CSV file. The system will create user accounts if needed and automatically link registrations.</p>
        <div style="margin-top: 16px;">
            <a href="/admin/events/test-csv-upload" class="btn btn-secondary btn-sm" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Debug Upload Issues
            </a>
        </div>
    </div>
    
    <?php if ($successMessage): ?>
    <div class="results-card success">
        <div class="results-header">
            <div class="results-icon">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h3 class="results-title"><?php echo sanitize($successMessage); ?></h3>
        </div>
        <?php if ($importResults): ?>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-value"><?php echo $importResults['imported']; ?></div>
                <div class="stat-label">Successfully Imported</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $importResults['skipped']; ?></div>
                <div class="stat-label">Skipped / Duplicates</div>
            </div>
        </div>
        <?php if (!empty($importResults['errors'])): ?>
        <div class="error-list">
            <?php foreach (array_slice($importResults['errors'], 0, 10) as $err): ?>
            <div class="error-list-item"><?php echo sanitize($err); ?></div>
            <?php endforeach; ?>
            <?php if (count($importResults['errors']) > 10): ?>
            <div class="error-list-item" style="font-style: italic;">...and <?php echo count($importResults['errors']) - 10; ?> more errors</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div style="margin-top: 20px; display: flex; gap: 12px;">
            <a href="/admin/" class="btn btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Back to Dashboard
            </a>
            <a href="/admin/events/" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                View Events
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
    <div class="results-card error">
        <div class="results-header">
            <div class="results-icon">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </div>
            <h3 class="results-title"><?php echo sanitize($errorMessage); ?></h3>
        </div>
        <div style="margin-top: 16px;">
            <a href="/admin/" class="btn btn-secondary" style="margin-right: 8px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Back to Dashboard
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Download Template -->
    <div class="card-section">
        <div class="section-header">
            <div class="section-icon download">
                <svg width="22" height="22" fill="none" stroke="#fff" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
            </div>
            <div>
                <h2 class="section-title">Step 1: Download Template</h2>
                <p class="section-subtitle">Get the CSV template with the correct format</p>
            </div>
        </div>
        <div class="template-box">
            <div class="template-info">
                <div class="template-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="template-text">
                    <h4>past_participants_template.csv</h4>
                    <p>Columns: name, email, event_id, registration_date (YYYY-MM-DD)</p>
                </div>
            </div>
            <a href="?download_template=1" class="btn-download">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download Template
            </a>
        </div>
    </div>
    
    <!-- Upload Section -->
    <div class="card-section">
        <div class="section-header">
            <div class="section-icon upload">
                <svg width="22" height="22" fill="none" stroke="#fff" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
            </div>
            <div>
                <h2 class="section-title">Step 2: Upload CSV File</h2>
                <p class="section-subtitle">Import your filled-in CSV file</p>
            </div>
        </div>
        
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="drop-zone" id="dropZone">
                <div class="drop-zone-icon">
                    <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                </div>
                <div class="drop-zone-text">Drag & drop your CSV file here</div>
                <div class="drop-zone-hint">or click to browse files (Max 5MB)</div>
                <input type="file" name="csv_file" id="csvFile" accept=".csv" style="display: none;">
            </div>
            
            <div class="file-selected" id="fileSelected">
                <div class="file-icon">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="file-info">
                    <div class="file-name" id="fileName"></div>
                    <div class="file-size" id="fileSize"></div>
                </div>
                <button type="button" class="btn-remove-file" id="removeFile">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            
            <button type="submit" class="btn-upload" id="uploadBtn" disabled>
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Upload & Import Participants
            </button>
        </form>
    </div>
    
    <!-- Events Reference -->
    <div class="card-section">
        <div class="section-header">
            <div class="section-icon events">
                <svg width="22" height="22" fill="none" stroke="#fff" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div>
                <h2 class="section-title">Event ID Reference</h2>
                <p class="section-subtitle">Use these IDs in your CSV file</p>
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table class="events-table">
                <thead>
                    <tr>
                        <th>Event ID</th>
                        <th>Event Title</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                    <tr>
                        <td><span class="event-id-badge"><?php echo $event['id']; ?></span></td>
                        <td><?php echo sanitize($event['title']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($event['start_date'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                    <tr>
                        <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 24px;">No events found. Create events first.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const dropZone = document.getElementById('dropZone');
const csvFile = document.getElementById('csvFile');
const fileSelected = document.getElementById('fileSelected');
const fileName = document.getElementById('fileName');
const fileSize = document.getElementById('fileSize');
const removeFile = document.getElementById('removeFile');
const uploadBtn = document.getElementById('uploadBtn');

// Click to browse
dropZone.addEventListener('click', () => csvFile.click());

// Drag and drop
dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('dragover');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('dragover');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    const files = e.dataTransfer.files;
    if (files.length > 0 && files[0].name.endsWith('.csv')) {
        csvFile.files = files;
        showFile(files[0]);
    }
});

// File selected
csvFile.addEventListener('change', () => {
    if (csvFile.files.length > 0) {
        showFile(csvFile.files[0]);
    }
});

function showFile(file) {
    fileName.textContent = file.name;
    fileSize.textContent = formatFileSize(file.size);
    fileSelected.classList.add('show');
    dropZone.style.display = 'none';
    uploadBtn.disabled = false;
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

removeFile.addEventListener('click', () => {
    csvFile.value = '';
    fileSelected.classList.remove('show');
    dropZone.style.display = 'block';
    uploadBtn.disabled = true;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
