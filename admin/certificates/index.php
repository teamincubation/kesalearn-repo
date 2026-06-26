<?php
/**
 * KESA Learn - Admin: Certificates Management (Redesigned)
 * Manual upload with individual and bulk CSV+ZIP support
 */
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();

$db = getDB();
$adminPage = 'certificates';
$pageTitle = 'Certificates';

// Ensure certificates table has new columns
try {
    $db->exec("ALTER TABLE certificates ADD COLUMN IF NOT EXISTS certificate_number VARCHAR(100)");
    $db->exec("ALTER TABLE certificates ADD COLUMN IF NOT EXISTS user_email VARCHAR(255)");
    $db->exec("ALTER TABLE certificates ADD COLUMN IF NOT EXISTS recipient_name VARCHAR(255)");
    $db->exec("ALTER TABLE certificates ADD COLUMN IF NOT EXISTS certificate_file VARCHAR(500)");
    $db->exec("ALTER TABLE certificates ADD COLUMN IF NOT EXISTS issue_date DATE");
    $db->exec("ALTER TABLE certificates ADD COLUMN IF NOT EXISTS description TEXT");
    $db->exec("ALTER TABLE certificates ADD COLUMN IF NOT EXISTS uploaded_by INT UNSIGNED");
    $db->exec("ALTER TABLE certificates MODIFY COLUMN user_id INT UNSIGNED DEFAULT NULL");
} catch (PDOException $e) {
    // Columns might already exist
}

// Handle bulk delete
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    if (!empty($ids)) {
        // Get file paths before deleting
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $filesStmt = $db->prepare("SELECT certificate_file FROM certificates WHERE id IN ($placeholders)");
        $filesStmt->execute($ids);
        $files = $filesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Delete from database
        $stmt = $db->prepare("DELETE FROM certificates WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        
        // Delete physical files
        $uploadDirs = [
            __DIR__ . '/../../uploads/certificates/issued/',
            __DIR__ . '/../../uploads/certificates/'
        ];
        foreach ($files as $file) {
            if ($file) {
                foreach ($uploadDirs as $dir) {
                    $path = $dir . $file;
                    if (file_exists($path)) {
                        @unlink($path);
                        break;
                    }
                }
            }
        }
        
        logActivity('certificates_bulk_deleted', "Deleted $deleted certificates");
        setFlash('success', "$deleted certificate(s) deleted successfully.");
    }
    redirect('/admin/certificates/');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/certificates/');
    }
    
    $action = $_POST['action'] ?? '';
    
    // Single certificate upload
    if ($action === 'upload_single') {
        $certNumber = sanitize($_POST['certificate_number'] ?? '');
        $userEmail = sanitize($_POST['user_email'] ?? '');
        $eventId = intval($_POST['event_id'] ?? 0) ?: null;
        $selectedUserId = intval($_POST['user_id'] ?? 0) ?: null;
        $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
        $description = sanitize($_POST['description'] ?? '');
        
        // Get user details from selected user
        $recipientName = '';
        $userId = null;
        
        if ($selectedUserId) {
            $userStmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $userStmt->execute([$selectedUserId]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($userData) {
                $userId = $userData['id'];
                $recipientName = $userData['name'];
                $userEmail = $userData['email'];
            }
        }
        
        if (empty($certNumber) || empty($userEmail) || empty($recipientName)) {
            setFlash('error', 'Certificate number and recipient selection are required.');
            redirect('/admin/certificates/');
        }
        
        if (empty($eventId)) {
            setFlash('error', 'Please select a completed event.');
            redirect('/admin/certificates/');
        }
        
        // Check for duplicate certificate number
        $check = $db->prepare("SELECT id FROM certificates WHERE certificate_code = ? OR certificate_number = ?");
        $check->execute([$certNumber, $certNumber]);
        if ($check->fetch()) {
            setFlash('error', 'Certificate number already exists.');
            redirect('/admin/certificates/');
        }
        
        // Check if user already has certificate for this event
        $existCheck = $db->prepare("SELECT id FROM certificates WHERE user_id = ? AND event_id = ?");
        $existCheck->execute([$userId, $eventId]);
        if ($existCheck->fetch()) {
            setFlash('error', 'This user already has a certificate for this event.');
            redirect('/admin/certificates/');
        }
        
        // Upload certificate file
        $certFile = '';
        if (!empty($_FILES['certificate_file']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/certificates/issued/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['certificate_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                setFlash('error', 'Invalid file type. Only PDF, JPG, PNG allowed.');
                redirect('/admin/certificates/');
            }
            
            $newFileName = 'cert_' . preg_replace('/[^a-zA-Z0-9]/', '_', $certNumber) . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['certificate_file']['tmp_name'], $uploadDir . $newFileName)) {
                $certFile = $newFileName;
            }
        }
        
        if (empty($certFile)) {
            setFlash('error', 'Certificate file is required.');
            redirect('/admin/certificates/');
        }
        
        $stmt = $db->prepare("INSERT INTO certificates (certificate_code, certificate_number, user_id, user_email, event_id, recipient_name, certificate_file, issue_date, description, uploaded_by, generated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$certNumber, $certNumber, $userId, $userEmail, $eventId, $recipientName, $certFile, $issueDate, $description, $_SESSION['user_id']]);
        
        // Get event title for email
        $eventTitle = 'Your Program';
        if ($eventId) {
            $evStmt = $db->prepare("SELECT title FROM events WHERE id = ?");
            $evStmt->execute([$eventId]);
            $eventTitle = $evStmt->fetchColumn() ?: 'Your Program';
        }
        
        // Send email notification if checkbox is checked
        $sendEmail = isset($_POST['send_email']) && $_POST['send_email'] == '1';
        $emailSent = false;
        if ($sendEmail && !empty($userEmail)) {
            require_once __DIR__ . '/../../includes/mailer.php';
            $emailSent = sendCertificateNotificationEmail($userEmail, $recipientName, $eventTitle, $certNumber);
        }
        
        logActivity('certificate_uploaded', "Certificate $certNumber uploaded for $recipientName" . ($emailSent ? ' (email sent)' : ''));
        $successMsg = 'Certificate uploaded successfully for ' . $recipientName . '!';
        if ($sendEmail && $emailSent) {
            $successMsg .= ' Email notification sent.';
        } elseif ($sendEmail && !$emailSent) {
            $successMsg .= ' (Email notification could not be sent)';
        }
        setFlash('success', $successMsg);
        redirect('/admin/certificates/');
    }
    
    // Bulk upload
    if ($action === 'bulk_upload') {
        // Check if email notifications should be sent
        $sendEmails = isset($_POST['send_email']) && $_POST['send_email'] == '1';
        $emailsSent = 0;
        
        // Check for file upload errors
        $uploadErrors = [];
        
        if (empty($_FILES['csv_file']['name']) || empty($_FILES['zip_file']['name'])) {
            setFlash('error', 'Both CSV and ZIP files are required.');
            redirect('/admin/certificates/');
        }
        
        // Check CSV file upload error
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $csvError = match($_FILES['csv_file']['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'CSV file is too large. Max allowed: ' . ini_get('upload_max_filesize'),
                UPLOAD_ERR_PARTIAL => 'CSV file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No CSV file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write CSV file to disk.',
                default => 'Unknown error uploading CSV file (code: ' . $_FILES['csv_file']['error'] . ')'
            };
            $uploadErrors[] = $csvError;
        }
        
        // Check ZIP file upload error
        if ($_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
            $zipError = match($_FILES['zip_file']['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ZIP file is too large. Max allowed: ' . ini_get('upload_max_filesize') . '. Try uploading fewer certificates or compressing files more.',
                UPLOAD_ERR_PARTIAL => 'ZIP file was only partially uploaded. Check your connection.',
                UPLOAD_ERR_NO_FILE => 'No ZIP file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write ZIP file to disk.',
                default => 'Unknown error uploading ZIP file (code: ' . $_FILES['zip_file']['error'] . ')'
            };
            $uploadErrors[] = $zipError;
        }
        
        if (!empty($uploadErrors)) {
            $_SESSION['bulk_errors'] = $uploadErrors;
            setFlash('error', 'File upload failed. See details below.');
            redirect('/admin/certificates/');
        }
        
        // Process CSV
        $csvFile = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($csvFile, 'r');
        if (!$handle) {
            setFlash('error', 'Failed to read CSV file. Make sure it is a valid CSV.');
            redirect('/admin/certificates/');
        }
        
        // Extract ZIP
        $zipFile = $_FILES['zip_file']['tmp_name'];
        $zip = new ZipArchive();
        $zipResult = $zip->open($zipFile);
        if ($zipResult !== true) {
            $zipErrorMsg = match($zipResult) {
                ZipArchive::ER_NOZIP => 'The file is not a valid ZIP archive.',
                ZipArchive::ER_INCONS => 'ZIP archive is inconsistent or corrupted.',
                ZipArchive::ER_MEMORY => 'Memory allocation failure.',
                ZipArchive::ER_NOENT => 'ZIP file not found.',
                ZipArchive::ER_READ => 'Error reading ZIP file.',
                default => 'Failed to open ZIP file (error code: ' . $zipResult . ')'
            };
            setFlash('error', $zipErrorMsg);
            redirect('/admin/certificates/');
        }
        
        $tempDir = sys_get_temp_dir() . '/kesa_certs_' . time();
        if (!@mkdir($tempDir, 0755, true)) {
            setFlash('error', 'Failed to create temporary directory for extraction.');
            redirect('/admin/certificates/');
        }
        
        if (!$zip->extractTo($tempDir)) {
            setFlash('error', 'Failed to extract ZIP file. It may be corrupted or password-protected.');
            $zip->close();
            redirect('/admin/certificates/');
        }
        $zip->close();
        
        $uploadDir = __DIR__ . '/../../uploads/certificates/issued/';
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                setFlash('error', 'Failed to create upload directory.');
                redirect('/admin/certificates/');
            }
        }
        
        // Read CSV header
        $header = fgetcsv($handle);
        if (!$header) {
            setFlash('error', 'CSV file is empty or invalid.');
            redirect('/admin/certificates/');
        }
        $header = array_map('strtolower', array_map('trim', $header));
        
        $requiredCols = ['certificate_number', 'user_email', 'recipient_name', 'certificate_file'];
        $missingCols = [];
        foreach ($requiredCols as $col) {
            if (!in_array($col, $header)) {
                $missingCols[] = $col;
            }
        }
        if (!empty($missingCols)) {
            setFlash('error', 'Missing required columns in CSV: ' . implode(', ', $missingCols));
            redirect('/admin/certificates/');
        }
        
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $rowNum = 1;
        $headerCount = count($header);
        
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Check column count matches header
            $rowCount = count($row);
            if ($rowCount !== $headerCount) {
                $errors[] = "Row $rowNum: Column count mismatch (expected $headerCount, got $rowCount).";
                $errorCount++;
                continue;
            }
            
            $data = array_combine($header, $row);
            
            $certNumber = trim($data['certificate_number'] ?? '');
            $userEmail = trim($data['user_email'] ?? '');
            $recipientName = trim($data['recipient_name'] ?? '');
            $certFileName = trim($data['certificate_file'] ?? '');
            $eventId = intval($data['event_id'] ?? 0) ?: null;
            // Always use current upload date/time for bulk uploads
            $issueDate = date('Y-m-d');
            $description = $data['description'] ?? '';
            
            if (empty($certNumber) || empty($userEmail) || empty($recipientName) || empty($certFileName)) {
                $errors[] = "Row $rowNum: Missing required fields.";
                $errorCount++;
                continue;
            }
            
            // Check duplicate
            $check = $db->prepare("SELECT id FROM certificates WHERE certificate_code = ? OR certificate_number = ?");
            $check->execute([$certNumber, $certNumber]);
            if ($check->fetch()) {
                $errors[] = "Row $rowNum: Certificate $certNumber already exists.";
                $errorCount++;
                continue;
            }
            
            // Find certificate file in extracted ZIP
            $foundFile = null;
            $searchPatterns = [$certFileName, basename($certFileName)];
            foreach ($searchPatterns as $pattern) {
                $searchPath = $tempDir . '/' . $pattern;
                if (file_exists($searchPath)) {
                    $foundFile = $searchPath;
                    break;
                }
                // Search recursively
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() === $pattern) {
                        $foundFile = $file->getPathname();
                        break 2;
                    }
                }
            }
            
            if (!$foundFile) {
                $errors[] = "Row $rowNum: File '$certFileName' not found in ZIP.";
                $errorCount++;
                continue;
            }
            
            // Copy file to uploads
            $ext = strtolower(pathinfo($foundFile, PATHINFO_EXTENSION));
            // Ensure valid extension
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                $errors[] = "Row $rowNum: Invalid file type '$ext'. Only PDF, JPG, PNG allowed.";
                $errorCount++;
                continue;
            }
            
            $newFileName = 'cert_' . preg_replace('/[^a-zA-Z0-9]/', '_', $certNumber) . '_' . time() . '_' . $rowNum . '.' . $ext;
            $destinationPath = $uploadDir . $newFileName;
            
            // Copy file with verification
            if (!copy($foundFile, $destinationPath)) {
                $errors[] = "Row $rowNum: Failed to copy file to uploads.";
                $errorCount++;
                continue;
            }
            
            // Verify file was actually copied
            if (!file_exists($destinationPath)) {
                $errors[] = "Row $rowNum: File copy verification failed.";
                $errorCount++;
                continue;
            }
            
            // Check if user exists
            $userStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $userStmt->execute([$userEmail]);
            $userId = $userStmt->fetchColumn() ?: null;
            
            try {
                $stmt = $db->prepare("INSERT INTO certificates (certificate_code, certificate_number, user_id, user_email, event_id, recipient_name, certificate_file, issue_date, description, uploaded_by, generated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$certNumber, $certNumber, $userId, $userEmail, $eventId, $recipientName, $newFileName, $issueDate, $description, $_SESSION['user_id']]);
                $successCount++;
                
                // Send email notification if enabled
                if ($sendEmails && !empty($userEmail)) {
                    // Get event title for this certificate
                    $eventTitle = 'Your Program';
                    if ($eventId) {
                        $evStmt = $db->prepare("SELECT title FROM events WHERE id = ?");
                        $evStmt->execute([$eventId]);
                        $eventTitle = $evStmt->fetchColumn() ?: 'Your Program';
                    }
                    
                    require_once __DIR__ . '/../../includes/mailer.php';
                    if (sendCertificateNotificationEmail($userEmail, $recipientName, $eventTitle, $certNumber)) {
                        $emailsSent++;
                    }
                }
            } catch (PDOException $e) {
                // If DB insert fails, remove the copied file
                @unlink($destinationPath);
                $errors[] = "Row $rowNum: Database error - " . $e->getMessage();
                $errorCount++;
            }
        }
        
        fclose($handle);
        
        // Cleanup temp directory
        array_map('unlink', glob("$tempDir/*"));
        rmdir($tempDir);
        
        $emailInfo = $sendEmails ? " ($emailsSent email notifications sent)" : '';
        logActivity('bulk_certificates_uploaded', "Bulk upload: $successCount success, $errorCount errors" . $emailInfo);
        
        if ($successCount > 0) {
            $msg = "$successCount certificates uploaded successfully!";
            if ($sendEmails) {
                $msg .= " $emailsSent email notification(s) sent.";
            }
            if ($errorCount > 0) {
                $msg .= " $errorCount failed.";
            }
            setFlash('success', $msg);
        }
        if (!empty($errors)) {
            $_SESSION['bulk_errors'] = array_slice($errors, 0, 10);
        }
        redirect('/admin/certificates/');
    }
    
    // Delete certificate
    if ($action === 'delete') {
        $certId = intval($_POST['cert_id'] ?? 0);
        $db->prepare("DELETE FROM certificates WHERE id = ?")->execute([$certId]);
        logActivity('certificate_deleted', "Certificate #$certId deleted");
        setFlash('success', 'Certificate deleted.');
        redirect('/admin/certificates/');
    }
}

// Download CSV template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="certificate_upload_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['certificate_number', 'user_email', 'recipient_name', 'certificate_file', 'event_id', 'issue_date', 'description']);
    fputcsv($output, ['CERT-2026-001', 'john@example.com', 'John Doe', 'cert_john.pdf', '5', '2026-03-01', 'Completion of Web Development Workshop']);
    fputcsv($output, ['CERT-2026-002', 'jane@example.com', 'Jane Smith', 'cert_jane.pdf', '5', '2026-03-01', 'Completion of Web Development Workshop']);
    fclose($output);
    exit;
}

// Fetch ALL events for dropdown with certificate stats
$eventsQuery = $db->query("
    SELECT e.id, e.title, e.status,
        (SELECT COUNT(DISTINCT r.user_id) FROM registrations r WHERE r.event_id = e.id AND r.payment_status IN ('paid', 'verified', 'free')) as total_registered,
        (SELECT COUNT(DISTINCT c.user_id) FROM certificates c WHERE c.event_id = e.id AND c.user_id IS NOT NULL) as certified_count
    FROM events e 
    ORDER BY e.created_at DESC
");
$events = $eventsQuery->fetchAll(PDO::FETCH_ASSOC);

// Prepare events for dropdown with stats
$eventsForDropdown = [];
foreach ($events as $ev) {
    $pending = $ev['total_registered'] - $ev['certified_count'];
    $eventsForDropdown[] = [
        'id' => $ev['id'],
        'title' => $ev['title'],
        'status' => $ev['status'],
        'total' => $ev['total_registered'],
        'certified' => $ev['certified_count'],
        'pending' => max(0, $pending),
        'all_certified' => ($pending <= 0 && $ev['total_registered'] > 0)
    ];
}

// Fetch certificates with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$search = sanitize($_GET['q'] ?? '');
$where = '1=1';
$params = [];

if (!empty($search)) {
    $where .= " AND (certificate_code LIKE ? OR certificate_number LIKE ? OR user_email LIKE ? OR recipient_name LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

$total = $db->prepare("SELECT COUNT(*) FROM certificates WHERE $where");
$total->execute($params);
$totalCount = $total->fetchColumn();

$offset = ($page - 1) * ADMIN_ITEMS_PER_PAGE;
$stmt = $db->prepare("SELECT c.*, e.title as event_title, u.name as linked_user_name, (SELECT COUNT(*) FROM certificate_downloads WHERE certificate_code = c.certificate_code) as download_count FROM certificates c LEFT JOIN events e ON c.event_id = e.id LEFT JOIN users u ON c.user_id = u.id WHERE $where ORDER BY c.generated_at DESC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET $offset");
$stmt->execute($params);
$certificates = $stmt->fetchAll();

// Check for bulk errors
$bulkErrors = $_SESSION['bulk_errors'] ?? [];
unset($_SESSION['bulk_errors']);

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.upload-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 24px;
    border-bottom: 2px solid var(--border-color);
}
.upload-tab {
    padding: 12px 24px;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}
.upload-tab:hover { color: var(--text-primary); }
.upload-tab.active {
    color: var(--blue);
    border-bottom-color: var(--blue);
}
.upload-panel {
    display: none;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 24px;
}
.upload-panel.active { display: block; }
.bulk-info {
    background: linear-gradient(135deg, #dbeafe 0%, #ede9fe 100%);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
}
.bulk-info h4 { color: #1e40af; margin-bottom: 12px; }
.bulk-info ol { margin: 0; padding-left: 20px; color: #374151; }
.bulk-info li { margin-bottom: 8px; }
.template-download {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #1e40af;
    color: #fff;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    margin-top: 12px;
    transition: all 0.2s;
}
.template-download:hover { background: #1e3a8a; }
.error-list {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius-md);
    padding: 16px;
    margin-bottom: 20px;
}
.error-list h4 { color: #991b1b; margin-bottom: 8px; }
.error-list ul { margin: 0; padding-left: 20px; color: #7f1d1d; font-size: 0.9rem; }
.cert-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}
.cert-badge.linked { background: #dcfce7; color: #166534; }
.cert-badge.pending { background: #fef3c7; color: #92400e; }
</style>

<div class="table-header">
    <form method="GET" class="table-search">
        <input type="text" name="q" placeholder="Search certificates..." value="<?php echo sanitize($search); ?>" class="admin-search">
        <button type="submit" class="btn btn-sm btn-blue">Search</button>
    </form>
    <div style="display: flex; align-items: center; gap: 16px;">
        <a href="/admin/certificates/repair.php" class="btn btn-sm btn-secondary" style="display: flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Repair Tool
        </a>
        <span style="font-size: 0.9rem; color: var(--text-muted);">
            Total: <?php echo $totalCount; ?> certificates
        </span>
    </div>
</div>

<?php if (!empty($bulkErrors)): ?>
<div class="error-list">
    <h4>
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align: -3px; margin-right: 6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        Bulk Upload Errors
    </h4>
    <ul>
        <?php foreach ($bulkErrors as $err): ?>
            <li><?php echo sanitize($err); ?></li>
        <?php endforeach; ?>
    </ul>
    <p style="margin-top: 12px; font-size: 0.85rem; color: #666;">
        <strong>Tips:</strong> Check that your CSV columns match exactly: certificate_number, user_email, recipient_name, certificate_file. 
        File names in CSV must match files in ZIP exactly (case-sensitive).
    </p>
</div>
<?php endif; ?>

<!-- Upload Tabs -->
<div class="upload-tabs">
    <div class="upload-tab active" data-tab="single">Single Upload</div>
    <div class="upload-tab" data-tab="bulk">Bulk Upload (CSV + ZIP)</div>
</div>

<!-- Single Upload Panel -->
<div id="panel-single" class="upload-panel active">
    <h3 style="margin-bottom: 20px;">Upload Single Certificate</h3>
    <form method="POST" enctype="multipart/form-data" id="singleUploadForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="upload_single">
        <input type="hidden" name="user_id" id="selectedUserId" value="">
        
        <!-- Step 1: Select Event -->
        <div class="form-group" style="margin-bottom: 20px;">
            <label>Select Event <span class="required">*</span></label>
            <select name="event_id" id="eventSelect" class="form-control" required>
                <option value="">-- Select an event --</option>
                <?php foreach ($eventsForDropdown as $ev): ?>
                    <?php 
                        $statusBadge = '';
                        if ($ev['status'] === 'completed') $statusBadge = '[Completed]';
                        elseif ($ev['status'] === 'ongoing') $statusBadge = '[Ongoing]';
                        elseif ($ev['status'] === 'upcoming') $statusBadge = '[Upcoming]';
                        
                        $certInfo = '';
                        if ($ev['total'] > 0) {
                            if ($ev['pending'] > 0) {
                                $certInfo = ' - ' . $ev['pending'] . ' pending';
                            } else {
                                $certInfo = ' - All certified';
                            }
                        }
                    ?>
                    <option value="<?php echo $ev['id']; ?>">
                        <?php echo $statusBadge . ' ' . sanitize($ev['title']) . $certInfo; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-text">Select an event to upload certificate for registered users.</p>
        </div>
        
        <!-- Event Stats (shown after selection) -->
        <div id="eventStats" style="display: none; margin-bottom: 20px;">
            <div style="display: flex; gap: 16px; padding: 16px; background: linear-gradient(135deg, #dbeafe 0%, #ede9fe 100%); border-radius: 8px;">
                <div style="flex: 1; text-align: center; padding: 12px; background: #fff; border-radius: 6px;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #059669;" id="statCertified">0</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">Certificates Issued</div>
                </div>
                <div style="flex: 1; text-align: center; padding: 12px; background: #fff; border-radius: 6px;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #dc2626;" id="statPending">0</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">Pending</div>
                </div>
                <div style="flex: 1; text-align: center; padding: 12px; background: #fff; border-radius: 6px;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #3b82f6;" id="statTotal">0</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">Total Registered</div>
                </div>
            </div>
        </div>
        
        <!-- Recipient Selection (shown after event selection) -->
        <div id="recipientSection" style="display: none;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Recipient Name <span class="required">*</span></label>
                    <select name="recipient_user" id="recipientSelect" class="form-control" required>
                        <option value="">-- Select a user --</option>
                    </select>
                    <p class="form-text">Only users pending certificates are shown.</p>
                </div>
                <div class="form-group">
                    <label>Recipient Email</label>
                    <input type="email" name="user_email" id="recipientEmail" class="form-control" readonly style="background: #f3f4f6; cursor: not-allowed;">
                    <p class="form-text">Auto-filled based on selected user.</p>
                </div>
            </div>
        </div>
        
        <!-- Certificate Details (shown after recipient selection) -->
        <div id="certificateDetails" style="display: none;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Certificate Number <span class="required">*</span></label>
                    <input type="text" name="certificate_number" id="certNumber" class="form-control" placeholder="e.g., CERT-2026-001" required>
                </div>
                <div class="form-group">
                    <label>Issue Date</label>
                    <input type="date" name="issue_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Certificate File <span class="required">*</span></label>
                    <input type="file" name="certificate_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                    <p class="form-text">PDF, JPG, or PNG. Max 5MB.</p>
                </div>
                <div class="form-group">
                    <label>Description (Optional)</label>
                    <input type="text" name="description" class="form-control" placeholder="e.g., Completion of Python Workshop">
                </div>
            </div>
            
            <!-- Email Notification Option -->
            <div style="margin-top: 20px; padding: 16px; background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%); border-radius: 8px; border: 1px solid #bfdbfe;">
                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin: 0;">
                    <input type="checkbox" name="send_email" value="1" checked style="width: 20px; height: 20px; margin-top: 2px; accent-color: #059669;">
                    <div>
                        <strong style="color: #1e40af;">Send Email Notification</strong>
                        <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;">
                            Notify the recipient via email that their certificate is ready for download.
                        </p>
                    </div>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" style="margin-top: 16px;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align: -3px; margin-right: 6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Upload Certificate
            </button>
        </div>
        
        <!-- Loading indicator -->
        <div id="loadingIndicator" style="display: none; text-align: center; padding: 20px;">
            <div style="display: inline-block; width: 30px; height: 30px; border: 3px solid #e5e7eb; border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p style="margin-top: 8px; color: #6b7280;">Loading users...</p>
        </div>
    </form>
</div>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
#eventSelect option:disabled { color: #9ca3af; }
</style>

<!-- Bulk Upload Panel -->
<div id="panel-bulk" class="upload-panel">
    <h3 style="margin-bottom: 20px;">Bulk Upload Certificates</h3>
    
    <div class="bulk-info">
        <h4>How to use bulk upload:</h4>
        <ol>
            <li>Download the CSV template below and fill in certificate details</li>
            <li>Create a ZIP file containing all certificate files (PDF/JPG/PNG)</li>
            <li>Make sure the <code>certificate_file</code> column in CSV matches the file names in ZIP</li>
            <li>Upload both files together</li>
        </ol>
        <a href="?download_template=1" class="template-download">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Download CSV Template
        </a>
    </div>
    
    <form method="POST" enctype="multipart/form-data" id="bulkUploadForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="bulk_upload">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>CSV File <span class="required">*</span></label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required id="csvFileInput">
                <p class="form-text">Contains certificate details. Max size: <?php echo ini_get('upload_max_filesize'); ?></p>
            </div>
            <div class="form-group">
                <label>ZIP File <span class="required">*</span></label>
                <input type="file" name="zip_file" class="form-control" accept=".zip" required id="zipFileInput">
                <p class="form-text">Contains all certificate files (PDF/JPG/PNG)</p>
            </div>
        </div>
        
        <!-- Email Notification Option for Bulk -->
        <div style="margin: 20px 0; padding: 16px; background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%); border-radius: 8px; border: 1px solid #bfdbfe;">
            <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin: 0;">
                <input type="checkbox" name="send_email" value="1" checked style="width: 20px; height: 20px; margin-top: 2px; accent-color: #059669;">
                <div>
                    <strong style="color: #1e40af;">Send Email Notifications</strong>
                    <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.85rem;">
                        Notify each recipient via email that their certificate is ready for download.
                    </p>
                </div>
            </label>
        </div>
        
        <div id="uploadProgress" style="display: none; margin-bottom: 16px;">
            <div style="background: #e5e7eb; border-radius: 8px; height: 8px; overflow: hidden;">
                <div id="progressBar" style="background: linear-gradient(90deg, #3b82f6, #8b5cf6); height: 100%; width: 0%; transition: width 0.3s;"></div>
            </div>
            <p id="uploadStatus" style="font-size: 0.85rem; color: var(--text-muted); margin-top: 8px;">Uploading...</p>
        </div>
        
        <button type="submit" class="btn btn-primary" id="bulkSubmitBtn">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            Upload Certificates
        </button>
    </form>
    
    <script>
    document.getElementById('bulkUploadForm').addEventListener('submit', function(e) {
        // Show loading state - no file size restrictions
        document.getElementById('bulkSubmitBtn').disabled = true;
        document.getElementById('bulkSubmitBtn').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" style="margin-right: 6px; animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="32" stroke-dashoffset="32"></circle></svg> Uploading...';
        document.getElementById('uploadProgress').style.display = 'block';
        
        // Simulate progress (since actual XHR progress not available with form submit)
        var progress = 0;
        var interval = setInterval(function() {
            progress += Math.random() * 15;
            if (progress > 90) progress = 90;
            document.getElementById('progressBar').style.width = progress + '%';
        }, 500);
    });
    </script>
</div>

<!-- Certificates Table -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; margin-top: 32px;">
    <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-light);">
        <h3 style="font-size: 1.1rem;">Uploaded Certificates</h3>
    </div>
    <?php if (empty($certificates)): ?>
        <div style="padding: 40px; text-align: center; color: var(--text-muted);">
            No certificates uploaded yet. Use the form above to upload certificates.
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
            <th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th>
            <th>Certificate #</th>
            <th>Recipient</th>
            <th>Email</th>
            <th>Event</th>
            <th>Status</th>
            <th>Downloads</th>
            <th>Issued</th>
            <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certificates as $c): ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" value="<?php echo $c['id']; ?>"></td>
                        <td>
                            <code style="font-size: 0.85rem;"><?php echo sanitize($c['certificate_number'] ?: $c['certificate_code']); ?></code>
                        </td>
                        <td style="font-weight: 600;"><?php echo sanitize($c['recipient_name'] ?? $c['linked_user_name'] ?? 'N/A'); ?></td>
                        <td style="font-size: 0.9rem;"><?php echo sanitize($c['user_email'] ?? 'N/A'); ?></td>
                        <td style="font-size: 0.9rem;"><?php echo sanitize(truncateText($c['event_title'] ?? 'General', 25)); ?></td>
                        <td>
                            <?php if ($c['user_id']): ?>
                                <span class="cert-badge linked">Linked</span>
                            <?php else: ?>
                                <span class="cert-badge pending">Pending Link</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; font-weight: 600;">
                            <span style="display: inline-block; background: var(--blue-light, #dbeafe); color: var(--blue, #3b82f6); padding: 4px 12px; border-radius: 12px; font-size: 0.9rem;">
                                <?php echo intval($c['download_count'] ?? 0); ?>
                            </span>
                        </td>
                        <td style="font-size: 0.85rem; color: var(--text-muted);">
                            <?php echo $c['issue_date'] ? formatDate($c['issue_date']) : timeAgo($c['generated_at']); ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="/certificate/preview?id=<?php echo $c['id']; ?>" target="_blank" class="btn-icon" title="View Certificate">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                                <a href="/certificate/verify/<?php echo urlencode($c['certificate_number'] ?: $c['certificate_code']); ?>" target="_blank" class="btn-icon" title="Public Page" style="color: var(--blue);">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this certificate?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="cert_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="btn-icon danger" title="Delete">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php echo paginate($totalCount, ADMIN_ITEMS_PER_PAGE, $page, '/admin/certificates/'); ?>
    <?php endif; ?>
</div>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>certificate(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="certificates">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            Delete Selected
        </button>
    </div>
</div>

<!-- Bulk Delete Form -->
<form id="bulkDeleteForm" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="bulk_delete" value="1">
    <input type="hidden" name="bulk_ids" id="bulkDeleteIds" value="">
</form>

<script>
// Tab switching
document.querySelectorAll('.upload-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.upload-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.upload-panel').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('panel-' + this.dataset.tab).classList.add('active');
    });
});

// Certificate upload - Event selection handling
var eventSelect = document.getElementById('eventSelect');
var recipientSelect = document.getElementById('recipientSelect');
var recipientEmail = document.getElementById('recipientEmail');
var selectedUserId = document.getElementById('selectedUserId');
var eventStats = document.getElementById('eventStats');
var recipientSection = document.getElementById('recipientSection');
var certificateDetails = document.getElementById('certificateDetails');
var loadingIndicator = document.getElementById('loadingIndicator');

var pendingUsersCache = {};

eventSelect.addEventListener('change', function() {
    var eventId = this.value;
    
    // Reset all sections
    eventStats.style.display = 'none';
    recipientSection.style.display = 'none';
    certificateDetails.style.display = 'none';
    recipientSelect.innerHTML = '<option value="">-- Select a user --</option>';
    recipientEmail.value = '';
    selectedUserId.value = '';
    
    // Remove any existing messages
    var noUsersMsg = document.getElementById('noUsersMessage');
    if (noUsersMsg) noUsersMsg.remove();
    var allCertMsg = document.getElementById('allCertifiedMessage');
    if (allCertMsg) allCertMsg.remove();
    
    if (!eventId) return;
    
    // Show loading
    loadingIndicator.style.display = 'block';
    
    // Fetch pending users for this event
    fetch('/admin/certificates/get-pending-users.php?event_id=' + eventId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loadingIndicator.style.display = 'none';
            
            if (!data.success) {
                alert('Error: ' + (data.error || 'Could not load users'));
                return;
            }
            
            // Update stats
            document.getElementById('statTotal').textContent = data.stats.total_registered;
            document.getElementById('statCertified').textContent = data.stats.already_certified;
            document.getElementById('statPending').textContent = data.stats.pending;
            eventStats.style.display = 'block';
            
            // Populate pending users dropdown
            if (data.pending_users.length > 0) {
                data.pending_users.forEach(function(user) {
                    var opt = document.createElement('option');
                    opt.value = user.id;
                    opt.textContent = user.name + ' (' + user.email + ')';
                    opt.dataset.email = user.email;
                    opt.dataset.name = user.name;
                    recipientSelect.appendChild(opt);
                });
                recipientSection.style.display = 'block';
                pendingUsersCache[eventId] = data.pending_users;
            } else if (data.stats.total_registered === 0) {
                // No registrations for this event - show message
                var noUsersMsg = document.createElement('div');
                noUsersMsg.id = 'noUsersMessage';
                noUsersMsg.style.cssText = 'padding: 16px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; color: #92400e; margin-bottom: 16px;';
                noUsersMsg.innerHTML = '<strong>No Registrations:</strong> This event has no registered users yet. Certificates can be uploaded after users register.';
                eventStats.insertAdjacentElement('afterend', noUsersMsg);
            } else {
                // All users certified
                var allCertifiedMsg = document.createElement('div');
                allCertifiedMsg.id = 'allCertifiedMessage';
                allCertifiedMsg.style.cssText = 'padding: 16px; background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; color: #065f46; margin-bottom: 16px;';
                allCertifiedMsg.innerHTML = '<strong>All Done!</strong> All ' + data.stats.total_registered + ' registered users have already received their certificates for this event.';
                eventStats.insertAdjacentElement('afterend', allCertifiedMsg);
            }
        })
        .catch(function(err) {
            loadingIndicator.style.display = 'none';
            alert('Error loading users. Please try again.');
            console.error(err);
        });
});

// Recipient selection - auto-fill email
recipientSelect.addEventListener('change', function() {
    var selectedOption = this.options[this.selectedIndex];
    
    if (this.value) {
        recipientEmail.value = selectedOption.dataset.email || '';
        selectedUserId.value = this.value;
        certificateDetails.style.display = 'block';
    } else {
        recipientEmail.value = '';
        selectedUserId.value = '';
        certificateDetails.style.display = 'none';
    }
});

// Form validation before submit
document.getElementById('singleUploadForm').addEventListener('submit', function(e) {
    if (!eventSelect.value) {
        e.preventDefault();
        alert('Please select an event first.');
        return false;
    }
    if (!recipientSelect.value) {
        e.preventDefault();
        alert('Please select a recipient.');
        return false;
    }
    if (!document.getElementById('certNumber').value.trim()) {
        e.preventDefault();
        alert('Please enter a certificate number.');
        return false;
    }
    return true;
});
</script>
<script src="/assets/js/admin-bulk-actions.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
