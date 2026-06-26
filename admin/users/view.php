<?php
/**
 * KESA Learn - Admin: View/Edit User
 * Clean, professional user details page
 */
require_once __DIR__ . '/../../includes/admin_check.php';
require_once __DIR__ . '/../../includes/image-processor.php';

$db = getDB();
$adminPage = 'users';
$userId = intval($_GET['id'] ?? 0);

if (!$userId) {
    setFlash('error', 'User not found.');
    redirect('/admin/users/');
}

$viewUser = getUserById($userId);
if (!$viewUser) {
    setFlash('error', 'User not found.');
    redirect('/admin/users/');
}

// Handle user update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/users/view?id=' . $userId);
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $dob = $_POST['dob'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $country = sanitize($_POST['country'] ?? '');
        $state = sanitize($_POST['state'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $emailVerified = isset($_POST['email_verified']) ? 1 : 0;
        
        $stmt = $db->prepare("UPDATE users SET name = ?, phone = ?, dob = ?, gender = ?, country = ?, state = ?, city = ?, role = ?, email_verified = ? WHERE id = ?");
        $stmt->execute([$name, $phone, $dob ?: null, $gender ?: null, $country, $state, $city, $role, $emailVerified, $userId]);
    
    logActivity('user_updated', "Admin updated user #$userId");
        setFlash('success', 'User updated successfully.');
        redirect('/admin/users/view?id=' . $userId);
    }
}

// Handle photo download - MUST be before any HTML output
if (isset($_GET['download_photo'])) {
    $photoPath = $viewUser['profile_image'] ?? null;
    
    if (!$photoPath) {
        setFlash('error', 'No profile photo found for this user.');
        redirect('/admin/users/view?id=' . $userId);
    }
    
    // Try multiple path combinations
    $possiblePaths = [
        __DIR__ . '/../../' . $photoPath,
        __DIR__ . '/../../uploads/' . $photoPath,
        __DIR__ . '/../../' . ltrim($photoPath, '/')
    ];
    
    $fullPath = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $fullPath = $path;
            break;
        }
    }
    
    if (!$fullPath) {
        setFlash('error', 'Profile photo file not found on server.');
        redirect('/admin/users/view?id=' . $userId);
    }
    
    // Get file info
    $fileSize = filesize($fullPath);
    $fileExtension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];
    $mimeType = $mimeTypes[$fileExtension] ?? 'application/octet-stream';
    $fileName = 'profile_' . $viewUser['id'] . '.' . $fileExtension;
    
    // Clear any output buffering
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Send download headers
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Pragma: public');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    
    // Read and output the file
    if (readfile($fullPath) === false) {
        http_response_code(500);
        echo 'Error reading file';
        exit;
    }
    exit;
    
    if ($action === 'delete') {
        if ($userId === intval($_SESSION['user_id'])) {
            setFlash('error', 'You cannot delete yourself.');
            redirect('/admin/users/view?id=' . $userId);
        }
        
        // Force logout user before deletion
        $forceLogoutStmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $forceLogoutStmt->execute([$userId]);
        
        $db->prepare("DELETE FROM registrations WHERE user_id = ?")->execute([$userId]);
        $db->prepare("DELETE FROM certificates WHERE user_id = ?")->execute([$userId]);
        $db->prepare("DELETE FROM feedbacks WHERE user_id = ?")->execute([$userId]);
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        
        logActivity('user_deleted', "Admin deleted user #$userId");
        setFlash('success', 'User deleted successfully.');
        redirect('/admin/users/');
    }
    
    // Handle force logout
    if ($action === 'force_logout' && verifyCSRFToken($_GET['token'] ?? '')) {
        $forceLogoutStmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $forceLogoutStmt->execute([$userId]);
        logActivity('user_force_logout', "Admin force logged out user #$userId");
        setFlash('success', 'User logged out successfully.');
        redirect('/admin/users/view?id=' . $userId);
    }
    
    if ($action === 'assign_event') {
        $eventId = intval($_POST['event_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $paymentStatus = sanitize($_POST['payment_status'] ?? 'pending');
        $paymentMethod = sanitize($_POST['payment_method'] ?? 'free');
        // Ensure payment_method is valid for ENUM
        if (!in_array($paymentMethod, ['razorpay', 'upi', 'free'])) {
            $paymentMethod = 'free';
        }
        $paymentId = sanitize($_POST['payment_id'] ?? '');
        
        if (!$eventId) {
            setFlash('error', 'Please select an event.');
            redirect('/admin/users/view?id=' . $userId);
        }
        
        // Check if already registered
        $checkStmt = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ?");
        $checkStmt->execute([$userId, $eventId]);
        if ($checkStmt->fetch()) {
            setFlash('error', 'User is already registered for this event.');
            redirect('/admin/users/view?id=' . $userId);
        }
        
        // Handle payment proof upload
        $paymentProof = null;
        if (!empty($_FILES['payment_proof']['name'])) {
            $upload = uploadFile($_FILES['payment_proof'], 'payment_proofs');
            if ($upload['success']) {
                $paymentProof = $upload['filename'];
            }
        }
        
        // Insert registration with payment proof
        $insertStmt = $db->prepare("
            INSERT INTO registrations (user_id, event_id, amount, payment_status, payment_method, payment_id, payment_proof, registered_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([$userId, $eventId, $amount, $paymentStatus, $paymentMethod, $paymentId, $paymentProof]);
        
        // Update seats taken
        $db->prepare("UPDATE events SET seats_taken = seats_taken + 1 WHERE id = ?")->execute([$eventId]);
        
        logActivity('registration_created', "Admin assigned event #$eventId to user #$userId");
        setFlash('success', 'Event assigned to user successfully.');
        redirect('/admin/users/view?id=' . $userId);
    }
    
    // Delete registration
    if ($action === 'delete_registration') {
        $regId = intval($_POST['registration_id'] ?? 0);
        if ($regId) {
            // Get event_id to update seats
            $regStmt = $db->prepare("SELECT event_id FROM registrations WHERE id = ? AND user_id = ?");
            $regStmt->execute([$regId, $userId]);
            $reg = $regStmt->fetch();
            
            if ($reg) {
                $db->prepare("DELETE FROM registrations WHERE id = ?")->execute([$regId]);
                $db->prepare("UPDATE events SET seats_taken = GREATEST(seats_taken - 1, 0) WHERE id = ?")->execute([$reg['event_id']]);
                logActivity('registration_deleted', "Admin deleted registration #$regId for user #$userId");
                setFlash('success', 'Registration deleted successfully.');
            }
        }
        redirect('/admin/users/view?id=' . $userId);
    }
    
    // Upload certificate for user
    if ($action === 'upload_certificate') {
        $eventId = intval($_POST['event_id'] ?? 0);
        
        if (!$eventId) {
            setFlash('error', 'Please select an event.');
            redirect('/admin/users/view?id=' . $userId);
        }
        
        // Check if certificate already exists
        $checkStmt = $db->prepare("SELECT id FROM certificates WHERE user_id = ? AND event_id = ?");
        $checkStmt->execute([$userId, $eventId]);
        if ($checkStmt->fetch()) {
            setFlash('error', 'Certificate already exists for this event.');
            redirect('/admin/users/view?id=' . $userId);
        }
        
        // Generate certificate code
        $certCode = 'KESA-' . date('Y') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
        
        $insertStmt = $db->prepare("INSERT INTO certificates (user_id, event_id, certificate_code, generated_at) VALUES (?, ?, ?, NOW())");
        $insertStmt->execute([$userId, $eventId, $certCode]);
        
        logActivity('certificate_created', "Admin issued certificate for user #$userId event #$eventId");
        setFlash('success', 'Certificate issued successfully. Code: ' . $certCode);
        redirect('/admin/users/view?id=' . $userId);
    }
    
    // Delete certificate
    if ($action === 'delete_certificate') {
        $certId = intval($_POST['certificate_id'] ?? 0);
        if ($certId) {
            $db->prepare("DELETE FROM certificates WHERE id = ? AND user_id = ?")->execute([$certId, $userId]);
            logActivity('certificate_deleted', "Admin deleted certificate #$certId for user #$userId");
            setFlash('success', 'Certificate deleted successfully.');
        }
        redirect('/admin/users/view?id=' . $userId);
    }
}

// Get user stats
$totalRegs = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
$totalRegs->execute([$userId]);
$totalRegistrations = $totalRegs->fetchColumn();

$paidRegs = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND payment_status = 'paid'");
$paidRegs->execute([$userId]);
$paidRegistrations = $paidRegs->fetchColumn();

$totalSpent = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM registrations WHERE user_id = ? AND payment_status = 'paid'");
$totalSpent->execute([$userId]);
$totalSpentAmount = $totalSpent->fetchColumn();

$totalCerts = $db->prepare("SELECT COUNT(*) FROM certificates WHERE user_id = ?");
$totalCerts->execute([$userId]);
$certificateCount = $totalCerts->fetchColumn();

// Get recent registrations
$recentRegs = $db->prepare("SELECT r.*, e.title, e.start_date, e.type FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = ? ORDER BY r.registered_at DESC LIMIT 10");
$recentRegs->execute([$userId]);
$registrations = $recentRegs->fetchAll();

// Get certificates
$certs = $db->prepare("SELECT c.*, e.title FROM certificates c LEFT JOIN events e ON c.event_id = e.id WHERE c.user_id = ? ORDER BY c.generated_at DESC LIMIT 10");
$certs->execute([$userId]);
$certificates = $certs->fetchAll();

// Get activity logs for this user
$activityStmt = $db->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$activityStmt->execute([$userId]);
$activityLogs = $activityStmt->fetchAll();

// Get user visits (check if table exists first)
$userVisits = [];
$visitStats = ['total' => 0, 'last_visit' => null, 'last_ip' => null];
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'user_visits'")->rowCount();
    if ($tableCheck > 0) {
        $visitsStmt = $db->prepare("SELECT * FROM user_visits WHERE user_id = ? ORDER BY visited_at DESC LIMIT 50");
        $visitsStmt->execute([$userId]);
        $userVisits = $visitsStmt->fetchAll();
        
        // Get visit stats
        $statsStmt = $db->prepare("SELECT COUNT(*) as total, MAX(visited_at) as last_visit FROM user_visits WHERE user_id = ?");
        $statsStmt->execute([$userId]);
        $visitStats = $statsStmt->fetch();
    }
    // Also get from users table
    if (!empty($viewUser['last_visit_at'])) {
        $visitStats['last_visit'] = $viewUser['last_visit_at'];
    }
    if (!empty($viewUser['last_ip'])) {
        $visitStats['last_ip'] = $viewUser['last_ip'];
    }
} catch (Exception $e) {
    // Table doesn't exist yet
}

// Get registrations with payment proofs
$proofRegs = $db->prepare("SELECT r.*, e.title, e.start_date FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = ? AND r.payment_proof IS NOT NULL ORDER BY r.registered_at DESC");
$proofRegs->execute([$userId]);
$paymentProofs = $proofRegs->fetchAll();

// Get all events for assign modal (exclude already registered)
$allEventsStmt = $db->prepare("
    SELECT e.id, e.title, e.start_date, e.price, e.currency, e.is_free
    FROM events e
    WHERE e.id NOT IN (SELECT event_id FROM registrations WHERE user_id = ?)
    ORDER BY e.start_date DESC
");
$allEventsStmt->execute([$userId]);
$availableEvents = $allEventsStmt->fetchAll();

$pageTitle = 'View User: ' . $viewUser['name'];
include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 0.875rem;
    margin-bottom: 12px;
    transition: color 0.2s;
}

.back-link:hover {
    color: var(--blue);
}

.profile-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 28px;
    margin-bottom: 24px;
    display: flex;
    gap: 24px;
    align-items: flex-start;
}

.profile-avatar {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--blue), #6366f1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2rem;
    font-weight: 700;
    flex-shrink: 0;
    overflow: hidden;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-info {
    flex: 1;
}

.profile-name {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 6px 0;
}

.profile-email {
    color: var(--text-muted);
    margin: 0 0 14px 0;
    font-size: 0.95rem;
}

.profile-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.profile-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 0.85rem;
    color: var(--text-muted);
}

.profile-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    text-align: center;
}

.stat-card .value {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
}

.stat-card .label {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-top: 4px;
}

.stat-card.highlight {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(99, 102, 241, 0.05));
    border-color: var(--blue);
}

.stat-card.highlight .value {
    color: var(--blue);
}

.tabs-container {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.tabs-nav {
    display: flex;
    border-bottom: 1px solid var(--border-color);
    overflow-x: auto;
    background: var(--bg-secondary);
}

.tab-btn {
    padding: 14px 24px;
    border: none;
    background: transparent;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all 0.2s;
}

.tab-btn:hover {
    color: var(--text-primary);
}

.tab-btn.active {
    color: var(--blue);
    border-bottom-color: var(--blue);
    background: var(--bg-primary);
}

.tab-content {
    display: none;
    padding: 24px;
}

.tab-content.active {
    display: block;
}

.section-card {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
}

.section-card:last-child {
    margin-bottom: 0;
}

.section-card h4 {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-light);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-item .label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-item .value {
    font-weight: 600;
    color: var(--text-primary);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.form-group input,
.form-group select {
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 0.95rem;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--blue);
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

.danger-zone {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius-md);
    padding: 20px;
    margin-top: 24px;
}

.danger-zone h4 {
    color: #dc2626;
    margin: 0 0 8px 0;
    font-size: 0.95rem;
}

.danger-zone p {
    color: #7f1d1d;
    margin: 0 0 16px 0;
    font-size: 0.875rem;
}

.table-wrapper {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table th {
    text-align: left;
    padding: 12px;
    background: var(--bg-tertiary);
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table td {
    padding: 14px 12px;
    border-bottom: 1px solid var(--border-light);
    color: var(--text-primary);
}

.data-table tr:hover td {
    background: var(--bg-tertiary);
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: var(--text-muted);
}

.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 16px;
    opacity: 0.4;
}

.empty-state h4 {
    margin: 0 0 8px 0;
    color: var(--text-secondary);
}

.empty-state p {
    margin: 0;
    font-size: 0.875rem;
}

@media (max-width: 640px) {
    .profile-card {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .profile-meta, .profile-badges {
        justify-content: center;
    }
}
</style>

<a href="/admin/users" class="back-link">
    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    Back to Users
</a>

<!-- Profile Card -->
<div class="profile-card">
    <div class="profile-avatar">
        <?php if (!empty($viewUser['profile_image'])): ?>
            <img id="userProfilePhoto" src="/uploads/<?php echo htmlspecialchars($viewUser['profile_image']); ?>" 
                 alt="<?php echo sanitize($viewUser['name']); ?>" style="border-radius: 50%; object-fit: cover; width: 140px; height: 140px;" onerror="this.style.display='none'">
        <?php else: ?>
            <div style="display: flex; align-items: center; justify-content: center; width: 140px; height: 140px; background: #e0e0e0; border-radius: 50%; font-size: 32px; font-weight: bold; color: #666;">
                <?php echo strtoupper(substr($viewUser['name'], 0, 2)); ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="profile-info">
        <h1 class="profile-name"><?php echo sanitize($viewUser['name']); ?></h1>
        <p class="profile-email"><?php echo sanitize($viewUser['email']); ?></p>
        <div class="profile-badges">
            <?php if ($viewUser['role'] === 'admin'): ?>
                <span class="badge badge-red">Admin</span>
            <?php else: ?>
                <span class="badge badge-blue">User</span>
            <?php endif; ?>
            <?php if ($viewUser['email_verified']): ?>
                <span class="badge badge-success">Email Verified</span>
            <?php else: ?>
                <span class="badge badge-warning">Not Verified</span>
            <?php endif; ?>
        </div>
        <div class="profile-meta">
            <span class="profile-meta-item">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Joined <?php echo date('M d, Y', strtotime($viewUser['created_at'])); ?>
            </span>
            <?php if (!empty($viewUser['phone'])): ?>
            <span class="profile-meta-item">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                <?php echo sanitize($viewUser['phone']); ?>
            </span>
            <?php endif; ?>
            <?php if (!empty($viewUser['city']) || !empty($viewUser['country'])): ?>
            <span class="profile-meta-item">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <?php echo sanitize(trim(($viewUser['city'] ?? '') . ', ' . ($viewUser['country'] ?? ''), ', ')); ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($viewUser['profile_image'])): ?>
    <div style="display: flex; flex-direction: row; gap: 8px; margin-top: 16px;">
        <a href="javascript:void(0);" onclick="viewProfilePhoto(<?php echo $userId; ?>)" class="btn btn-sm btn-outline" style="white-space: nowrap;">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline; margin-right: 6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            View Photo
        </a>
        <a href="?id=<?php echo $userId; ?>&download_photo=1" class="btn btn-sm btn-primary" style="white-space: nowrap;">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline; margin-right: 6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Download Photo
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card highlight">
        <div class="value"><?php echo formatPrice($totalSpentAmount); ?></div>
        <div class="label">Total Spent</div>
    </div>
    <div class="stat-card">
        <div class="value"><?php echo $totalRegistrations; ?></div>
        <div class="label">Registrations</div>
    </div>
    <div class="stat-card">
        <div class="value"><?php echo $paidRegistrations; ?></div>
        <div class="label">Paid Events</div>
    </div>
    <div class="stat-card">
        <div class="value"><?php echo $certificateCount; ?></div>
        <div class="label">Certificates</div>
    </div>
</div>

<!-- COMPREHENSIVE USER TRACKING & ACTIVITY SECTION -->
<?php
require_once __DIR__ . '/../../includes/user-tracking.php';

// Fetch all tracking data
$trackingInfo = getUserTrackingInfo($userId);
$activityHistory = getUserActivityHistory($userId, 100);
$activityStats = getUserActivityStats($userId);
$userSessions = getUserSessions($userId, 10);
$userLoginHistory = getUserLoginHistory($userId, 10);

// Override userVisits with function call to ensure fresh data with ISP/AS Name
$userVisits = getUserVisitHistory($userId, 50);

// Get latest visit for consolidated info
$latestVisit = !empty($userVisits) ? $userVisits[0] : null;

// Debug: Log if data is being fetched
if (count($userVisits) == 0) {
    error_log("[DEBUG] No user visits found for user $userId");
}
if (count($activityHistory) == 0) {
    error_log("[DEBUG] No activity history found for user $userId");
}
?>

<!-- Location & Device Information Card -->
<div style="background: linear-gradient(135deg, #e8f4f8 0%, #d0e8f2 100%); border: 1px solid #4db8e8; border-radius: 12px; padding: 24px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 16px 0; color: #1565c0; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2v20M2 12h20"/></svg>
        Location & Network Information
    </h3>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
        <div>
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">IP Address</div>
            <div style="font-size: 0.95rem; color: #333; font-family: 'Courier New', monospace;"><?php echo $trackingInfo['ip_address'] ?? ($latestVisit['ip_address'] ?? 'Not recorded'); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Country</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $trackingInfo['country'] ?? ($latestVisit['country'] ?? 'Unknown'); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Region/State</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $trackingInfo['region'] ?? ($latestVisit['state'] ?? 'Unknown'); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">City</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $latestVisit['city'] ?? 'Unknown'; ?></div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding-top: 12px; border-top: 1px solid rgba(21, 101, 192, 0.2);">
        <div>
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">ISP</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $trackingInfo['isp'] ?? 'Not recorded'; ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">AS Name</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $trackingInfo['as_name'] ?? 'Not recorded'; ?></div>
        </div>
    </div>
</div>

<!-- Device & Browser Information Card -->
<div style="background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); border: 1px solid #ffb74d; border-radius: 12px; padding: 24px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 16px 0; color: #e65100; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><path d="M8 21h8"/></svg>
        Device & Browser Information
    </h3>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
        <div>
            <div style="font-size: 0.7rem; color: #e65100; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Device Type</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $trackingInfo['device_type'] ?? ($latestVisit['device_type'] ?? 'Unknown'); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #e65100; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Operating System</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $trackingInfo['os'] ?? ($latestVisit['os'] ?? 'Unknown'); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #e65100; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Browser</div>
            <div style="font-size: 0.95rem; color: #333;"><?php echo $trackingInfo['browser'] ?? ($latestVisit['browser'] ?? 'Unknown'); ?></div>
        </div>
    </div>
</div>

<!-- Activity Summary Card -->
<div style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border: 1px solid #64b5f6; border-radius: 12px; padding: 24px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 16px 0; color: #1565c0; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
        Activity Summary
    </h3>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px;">
        <div style="background: white; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid rgba(21, 101, 192, 0.1);">
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700;">Registration</div>
            <div style="font-size: 0.9rem; color: #333; margin-top: 4px;"><?php echo $trackingInfo['registered_date'] ? date('M d, Y', strtotime($trackingInfo['registered_date'])) : 'N/A'; ?></div>
        </div>
        <div style="background: white; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid rgba(21, 101, 192, 0.1);">
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700;">Total Visits</div>
            <div style="font-size: 1.4rem; color: #1565c0; font-weight: 700; margin-top: 4px;"><?php echo count($userVisits); ?></div>
        </div>
        <div style="background: white; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid rgba(21, 101, 192, 0.1);">
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700;">Last Visit</div>
            <div style="font-size: 0.9rem; color: #333; margin-top: 4px;"><?php echo $latestVisit ? formatTime12Hour($latestVisit['visited_at']) : 'N/A'; ?></div>
        </div>
        <div style="background: white; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid rgba(21, 101, 192, 0.1);">
            <div style="font-size: 0.7rem; color: #1565c0; font-weight: 700;">Total Logins</div>
            <div style="font-size: 1.4rem; color: #1565c0; font-weight: 700; margin-top: 4px;"><?php echo count($userLoginHistory); ?></div>
        </div>
    </div>
</div>

<!-- User Activity & Clicks Log -->
<div style="background: linear-gradient(135deg, #f0f4c3 0%, #dcedc8 100%); border: 1px solid #9ccc65; border-radius: 12px; padding: 24px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 16px 0; color: #558b2f; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/></svg>
        User Activity Log
    </h3>
    
    <?php if ($activityStats): ?>
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(85, 139, 47, 0.2);">
        <div style="text-align: center;">
            <div style="font-size: 0.7rem; color: #558b2f; font-weight: 700;">Total Activities</div>
            <div style="font-size: 1.6rem; color: #558b2f; font-weight: 700;"><?php echo $activityStats['total_activities'] ?? 0; ?></div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 0.7rem; color: #558b2f; font-weight: 700;">Total Clicks</div>
            <div style="font-size: 1.6rem; color: #558b2f; font-weight: 700;"><?php echo $activityStats['total_clicks'] ?? 0; ?></div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 0.7rem; color: #558b2f; font-weight: 700;">Active Days</div>
            <div style="font-size: 1.6rem; color: #558b2f; font-weight: 700;"><?php echo $activityStats['active_days'] ?? 0; ?></div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Activities Table -->
    <?php if (!empty($activityHistory)): ?>
    <div style="max-height: 300px; overflow-y: auto; border: 1px solid rgba(85, 139, 47, 0.2); border-radius: 8px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: #f9fbe7; border-bottom: 1px solid rgba(85, 139, 47, 0.2); position: sticky; top: 0;">
                    <th style="padding: 10px 12px; text-align: left; font-weight: 700; color: #558b2f;">Time</th>
                    <th style="padding: 10px 12px; text-align: left; font-weight: 700; color: #558b2f;">Activity</th>
                    <th style="padding: 10px 12px; text-align: left; font-weight: 700; color: #558b2f;">Element Clicked</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($activityHistory, 0, 20) as $activity): ?>
                <tr style="border-bottom: 1px solid rgba(85, 139, 47, 0.1);">
                    <td style="padding: 10px 12px; color: #333; font-size: 0.8rem; font-family: monospace; white-space: nowrap;"><?php echo formatTime12Hour($activity['timestamp']); ?></td>
                    <td style="padding: 10px 12px; color: #333;"><span style="background: #dcedc8; color: #558b2f; padding: 2px 6px; border-radius: 3px; font-weight: 500; font-size: 0.8rem;"><?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?></span></td>
                    <td style="padding: 10px 12px; color: #666; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($activity['element_text'] ?? $activity['element_type'] ?? 'N/A'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="background: white; padding: 16px; text-align: center; color: #999; border-radius: 8px;">
        No activity records yet. Activities will appear as the user interacts with the website.
    </div>
    <?php endif; ?>
</div>

<!-- User Visits Tracking -->
<div style="background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%); border: 1px solid #ba68c8; border-radius: 12px; padding: 24px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 16px 0; color: #6a1b9a; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        User Visits (<?php echo count($userVisits); ?> total)
    </h3>
    
    <?php if (!empty($userVisits)): ?>
    <div style="max-height: 350px; overflow-y: auto;">
        <?php foreach (array_slice($userVisits, 0, 15) as $visit): ?>
        <div style="background: white; padding: 12px; border-radius: 8px; margin-bottom: 10px; border-left: 3px solid #ba68c8;">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; font-size: 0.85rem; margin-bottom: 8px;">
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">Visited At</div>
                    <div style="color: #333; font-family: monospace;"><?php echo formatTime12Hour($visit['visited_at']); ?></div>
                </div>
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">IP Address</div>
                    <div style="color: #333; font-family: monospace;"><?php echo $visit['ip_address']; ?></div>
                </div>
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">Location</div>
                    <div style="color: #333;"><?php echo htmlspecialchars(($visit['city'] ? $visit['city'] . ', ' : '') . ($visit['country'] ?? 'Unknown')); ?></div>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; font-size: 0.85rem; margin-bottom: 8px;">
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">Device</div>
                    <div style="color: #333;"><?php echo ucfirst($visit['device_type'] ?? 'Unknown'); ?></div>
                </div>
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">OS</div>
                    <div style="color: #333;"><?php echo $visit['os'] ?? 'Unknown'; ?></div>
                </div>
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">Browser</div>
                    <div style="color: #333;"><?php echo $visit['browser'] ?? 'Unknown'; ?></div>
                </div>
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">Network</div>
                    <div style="color: #333;"><?php echo $visit['network_type'] ?? 'Unknown'; ?></div>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 0.85rem; padding-top: 8px; border-top: 1px solid #eee;">
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">ISP</div>
                    <div style="color: #333;"><?php echo $visit['isp'] ?? 'Not recorded'; ?></div>
                </div>
                <div>
                    <div style="color: #999; font-weight: 600; font-size: 0.75rem;">AS Name</div>
                    <div style="color: #333;"><?php echo $visit['as_name'] ?? 'Not recorded'; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="background: white; padding: 16px; text-align: center; color: #999; border-radius: 8px;">
        No visit records yet.
    </div>
    <?php endif; ?>
</div>

<!-- Login History -->
<div style="background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%); border: 1px solid #4db6ac; border-radius: 12px; padding: 24px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 16px 0; color: #00695c; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Login History (<?php echo count($userLoginHistory); ?> total)
    </h3>
    
    <?php if (!empty($userLoginHistory)): ?>
    <div style="max-height: 300px; overflow-y: auto; border: 1px solid rgba(0, 105, 92, 0.2); border-radius: 8px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: #e0f2f1; border-bottom: 1px solid rgba(0, 105, 92, 0.2); position: sticky; top: 0;">
                    <th style="padding: 10px 12px; text-align: left; font-weight: 700; color: #00695c;">Login Time</th>
                    <th style="padding: 10px 12px; text-align: left; font-weight: 700; color: #00695c;">IP Address</th>
                    <th style="padding: 10px 12px; text-align: left; font-weight: 700; color: #00695c;">Location</th>
                    <th style="padding: 10px 12px; text-align: center; font-weight: 700; color: #00695c;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($userLoginHistory as $login): ?>
                <tr style="border-bottom: 1px solid rgba(0, 105, 92, 0.1);">
                    <td style="padding: 10px 12px; color: #333; font-family: monospace; font-size: 0.8rem;"><?php echo formatTime12Hour($login['created_at']); ?></td>
                    <td style="padding: 10px 12px; color: #333; font-family: monospace; font-size: 0.8rem;"><?php echo $login['ip_address'] ?? 'N/A'; ?></td>
                    <td style="padding: 10px 12px; color: #666;"><?php echo htmlspecialchars(($login['city'] ? $login['city'] . ', ' : '') . ($login['country'] ?? 'Unknown')); ?></td>
                    <td style="padding: 10px 12px; text-align: center;">
                        <?php if ($login['success']): ?>
                            <span style="background: #c8e6c9; color: #2e7d32; padding: 3px 10px; border-radius: 12px; font-weight: 600; font-size: 0.8rem;">✓ Success</span>
                        <?php else: ?>
                            <span style="background: #ffcdd2; color: #c62828; padding: 3px 10px; border-radius: 12px; font-weight: 600; font-size: 0.8rem;">✗ Failed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="background: white; padding: 16px; text-align: center; color: #999; border-radius: 8px;">
        No login history available.
    </div>
    <?php endif; ?>
</div>

<!-- Action Buttons -->
<div style="margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
    <button type="button" class="btn btn-primary" onclick="openAssignModal()">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
        Assign Event
    </button>
    <button type="button" class="btn btn-secondary" onclick="openCertModal()">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
        Issue Certificate
    </button>
    <?php if (!empty($viewUser['phone'])): ?>
    <div style="display: flex; gap: 8px; margin-left: 8px;">
        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $viewUser['phone']); ?>" target="_blank" class="quick-action-btn whatsapp" title="WhatsApp">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        </a>
        <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $viewUser['phone']); ?>" class="quick-action-btn call" title="Call">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        </a>
    </div>
    <?php endif; ?>
</div>

<style>
.quick-action-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    text-decoration: none;
}
.quick-action-btn.whatsapp {
    background: #25D366;
    color: white;
}
.quick-action-btn.whatsapp:hover {
    background: #128C7E;
    transform: scale(1.1);
}
.quick-action-btn.call {
    background: var(--primary);
    color: white;
}
.quick-action-btn.call:hover {
    background: var(--primary-hover);
    transform: scale(1.1);
}
</style>

<!-- Tabs -->
<div class="tabs-container">
    <div class="tabs-nav">
        <button type="button" class="tab-btn active" data-tab="details">Details</button>
        <button type="button" class="tab-btn" data-tab="registrations">Registrations</button>
        <button type="button" class="tab-btn" data-tab="certificates">Certificates</button>
        <button type="button" class="tab-btn" data-tab="activity">Activity Log</button>
        <button type="button" class="tab-btn" data-tab="payments">Payment Proofs</button>
        <button type="button" class="tab-btn" data-tab="edit">Edit User</button>
    </div>
    
    <!-- Tab: Details -->
    <div id="tab-details" class="tab-content active">
        <div class="section-card">
            <h4>Personal Information</h4>
            <div class="info-grid">
                <div class="info-item">
                    <span class="label">Full Name</span>
                    <span class="value"><?php echo sanitize($viewUser['name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Email Address</span>
                    <span class="value"><?php echo sanitize($viewUser['email']); ?></span>
                </div>
                <div class="info-item">
                    <span class="label">WhatsApp Number</span>
                    <span class="value"><?php echo !empty($viewUser['phone']) ? sanitize($viewUser['phone']) : 'Not provided'; ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Date of Birth</span>
                    <span class="value"><?php echo !empty($viewUser['dob']) ? formatDate($viewUser['dob']) : 'Not provided'; ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Gender</span>
                    <span class="value"><?php echo !empty($viewUser['gender']) ? ucfirst($viewUser['gender']) : 'Not provided'; ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Account Created</span>
                    <span class="value"><?php echo formatDateTime($viewUser['created_at']); ?></span>
                </div>
            </div>
        </div>
        
        <div class="section-card">
            <h4>Location</h4>
            <div class="info-grid">
                <div class="info-item">
                    <span class="label">Country</span>
                    <span class="value"><?php echo !empty($viewUser['country']) ? sanitize($viewUser['country']) : 'Not provided'; ?></span>
                </div>
                <div class="info-item">
                    <span class="label">State / Region</span>
                    <span class="value"><?php echo !empty($viewUser['state']) ? sanitize($viewUser['state']) : 'Not provided'; ?></span>
                </div>
                <div class="info-item">
                    <span class="label">City</span>
                    <span class="value"><?php echo !empty($viewUser['city']) ? sanitize($viewUser['city']) : 'Not provided'; ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tab: Registrations -->
    <div id="tab-registrations" class="tab-content">
        <?php if (!empty($registrations)): ?>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $reg): ?>
                    <tr>
                        <td><strong><?php echo sanitize($reg['title']); ?></strong></td>
                        <td><?php echo ucfirst($reg['type']); ?></td>
                        <td>
                            <?php if ($reg['payment_status'] === 'paid'): ?>
                                <span class="badge badge-success">Paid</span>
                            <?php elseif ($reg['payment_status'] === 'pending'): ?>
                                <span class="badge badge-warning">Pending</span>
                            <?php elseif ($reg['payment_status'] === 'verified'): ?>
                                <span class="badge badge-success">Verified</span>
                            <?php else: ?>
                                <span class="badge"><?php echo ucfirst($reg['payment_status']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $reg['amount'] > 0 ? formatPrice($reg['amount']) : 'Free'; ?></td>
                        <td><?php echo date('M d, Y', strtotime($reg['registered_at'])); ?></td>
                        <td>
                            <div style="display: flex; gap: 6px;">
                                <a href="/admin/registrations/edit?id=<?php echo $reg['id']; ?>" class="btn-icon" title="Edit">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this registration? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="delete_registration">
                                    <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                    <button type="submit" class="btn-icon btn-icon-danger" title="Delete">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <style>
        .btn-icon {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: var(--bg-secondary);
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-icon:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }
        .btn-icon-danger:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fecaca;
        }
        </style>
        <?php else: ?>
        <div class="empty-state">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <h4>No Registrations</h4>
            <p>This user hasn't registered for any events yet.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Tab: Certificates -->
    <div id="tab-certificates" class="tab-content">
        <?php if (!empty($certificates)): ?>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Certificate ID</th>
                        <th>Event</th>
                        <th>Issued Date</th>
                        <th>Downloads</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($certificates as $cert): ?>
                    <tr>
                        <td><code style="background: var(--bg-tertiary); padding: 4px 8px; border-radius: 4px; font-size: 0.8rem;"><?php echo sanitize($cert['certificate_code'] ?? 'CERT-' . $cert['id']); ?></code></td>
                        <td><?php echo sanitize($cert['title'] ?? 'N/A'); ?></td>
                        <td><?php echo date('M d, Y', strtotime($cert['generated_at'])); ?></td>
                        <td><span class="badge"><?php echo intval($cert['download_count'] ?? 0); ?></span></td>
                        <td>
                            <div style="display: flex; gap: 6px;">
                                <a href="/certificate/download?code=<?php echo sanitize($cert['certificate_code']); ?>" class="btn-icon" title="Download" target="_blank">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this certificate? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="delete_certificate">
                                    <input type="hidden" name="certificate_id" value="<?php echo $cert['id']; ?>">
                                    <button type="submit" class="btn-icon btn-icon-danger" title="Delete">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            <h4>No Certificates</h4>
            <p>This user hasn't earned any certificates yet. Click "Issue Certificate" above to create one.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Tab: Activity Log -->
    <div id="tab-activity" class="tab-content">
        <!-- Visit Statistics -->
        <div class="section-card" style="margin-bottom: 20px;">
            <h4 style="margin-bottom: 16px;">Visit Statistics</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <div style="background: var(--bg-tertiary); padding: 16px; border-radius: var(--radius-md); text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);"><?php echo intval($visitStats['total'] ?? $viewUser['visit_count'] ?? 0); ?></div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">Total Visits</div>
                </div>
                <div style="background: var(--bg-tertiary); padding: 16px; border-radius: var(--radius-md); text-align: center;">
                    <div style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);"><?php echo $visitStats['last_visit'] ? formatDateTime($visitStats['last_visit']) : 'Never'; ?></div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">Last Visit</div>
                </div>
                <div style="background: var(--bg-tertiary); padding: 16px; border-radius: var(--radius-md); text-align: center;">
                    <div style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);"><code><?php echo sanitize($visitStats['last_ip'] ?? $viewUser['last_ip'] ?? '-'); ?></code></div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">Last IP</div>
                </div>
            </div>
        </div>
        
        <!-- Visit History -->
        <?php if (!empty($userVisits)): ?>
        <div class="section-card" style="margin-bottom: 20px;">
            <h4 style="margin-bottom: 16px;">Visit History</h4>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Location</th>
                            <th>Device</th>
                            <th>Browser / OS</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userVisits as $visit): ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <div style="font-weight: 500;"><?php echo date('M d, Y', strtotime($visit['visited_at'])); ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('h:i A', strtotime($visit['visited_at'])); ?></div>
                            </td>
                            <td>
                                <?php if ($visit['country'] || $visit['state'] || $visit['city']): ?>
                                <div style="font-size: 0.85rem;">
                                    <?php if ($visit['city']): ?><span><?php echo sanitize($visit['city']); ?></span><?php endif; ?>
                                    <?php if ($visit['state']): ?><span style="color: var(--text-muted);">, <?php echo sanitize($visit['state']); ?></span><?php endif; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo sanitize($visit['country'] ?? ''); ?></div>
                                <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size: 0.85rem;"><?php echo sanitize($visit['device_name'] ?? 'Unknown'); ?></div>
                                <span class="badge badge-<?php echo $visit['device_type'] === 'mobile' ? 'green' : ($visit['device_type'] === 'tablet' ? 'orange' : 'blue'); ?>" style="font-size: 0.7rem;"><?php echo ucfirst($visit['device_type'] ?? 'desktop'); ?></span>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <div><?php echo sanitize($visit['browser'] ?? 'Unknown'); ?></div>
                                <div style="color: var(--text-muted);"><?php echo sanitize($visit['os'] ?? 'Unknown'); ?></div>
                            </td>
                            <td><code style="font-size: 0.75rem; background: var(--bg-tertiary); padding: 2px 6px; border-radius: 3px;"><?php echo sanitize($visit['ip_address']); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Activity Logs -->
        <div class="section-card">
            <h4 style="margin-bottom: 16px;">Activity Log</h4>
            <?php if (!empty($activityLogs)): ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr><th>Action</th><th>Details</th><th>IP Address</th><th>Time</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activityLogs as $log): ?>
                        <tr>
                            <td><span class="badge badge-blue"><?php echo sanitize($log['action']); ?></span></td>
                            <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;"><?php echo sanitize($log['details'] ?? '-'); ?></td>
                            <td><code style="font-size: 0.8rem;"><?php echo sanitize($log['ip_address'] ?? '-'); ?></code></td>
                            <td style="white-space: nowrap; color: var(--text-muted);"><?php echo timeAgo($log['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom: 16px; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                <p>No activity logged for this user yet.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tab: Payment Proofs -->
    <div id="tab-payments" class="tab-content">
        <div class="section-card">
            <h4>Uploaded Payment Proofs</h4>
            <?php if (!empty($paymentProofs)): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
                <?php foreach ($paymentProofs as $proof): ?>
                <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden;">
                    <?php if (!empty($proof['payment_proof'])): ?>
                    <a href="/uploads/payment_proofs/<?php echo sanitize($proof['payment_proof']); ?>" target="_blank" style="display: block;">
                        <img src="/uploads/payment_proofs/<?php echo sanitize($proof['payment_proof']); ?>" alt="Payment Proof" style="width: 100%; height: 200px; object-fit: cover; background: var(--bg-tertiary);">
                    </a>
                    <?php endif; ?>
                    <div style="padding: 12px;">
                        <strong style="display: block; margin-bottom: 4px;"><?php echo sanitize($proof['title']); ?></strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">
                            <?php echo formatPrice($proof['amount']); ?> - <?php echo getPaymentStatusBadge($proof['payment_status']); ?>
                        </span>
                        <div style="margin-top: 8px; font-size: 0.8rem; color: var(--text-muted);">
                            Uploaded: <?php echo formatDateTime($proof['registered_at']); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom: 16px; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <p>No payment proofs uploaded by this user.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tab: Edit -->
    <div id="tab-edit" class="tab-content">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="update">
            
            <div class="section-card">
                <h4>Edit User Information</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?php echo sanitize($viewUser['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">WhatsApp Number</label>
                        <input type="text" id="phone" name="phone" value="<?php echo sanitize($viewUser['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="dob">Date of Birth</label>
                        <input type="date" id="dob" name="dob" value="<?php echo $viewUser['dob'] ?? ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">Not specified</option>
                            <option value="male" <?php echo ($viewUser['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($viewUser['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($viewUser['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" value="<?php echo sanitize($viewUser['country'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="state">State / Region</label>
                        <input type="text" id="state" name="state" value="<?php echo sanitize($viewUser['state'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" value="<?php echo sanitize($viewUser['city'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <option value="user" <?php echo $viewUser['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="admin" <?php echo $viewUser['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <div class="checkbox-group">
                        <input type="checkbox" id="email_verified" name="email_verified" <?php echo $viewUser['email_verified'] ? 'checked' : ''; ?>>
                        <label for="email_verified">Email Verified</label>
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Changes
                    </button>
                </div>
            </div>
        </form>
        
        <div class="danger-zone">
            <h4>Danger Zone</h4>
            <p>Permanently delete this user account. This action cannot be undone and will remove all associated data.</p>
            
            <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                <a href="?id=<?php echo $userId; ?>&action=force_logout&token=<?php echo generateCSRFToken(); ?>" class="btn btn-warning" onclick="return confirm('Force logout this user from all devices?');">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    Force Logout User
                </a>
            </div>
            
            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this user? All their registrations and certificates will be permanently removed.');">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-danger">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete User
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Assign Event Modal -->
<div id="assignModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Assign Event to <?php echo sanitize($viewUser['name']); ?></h3>
            <button type="button" class="modal-close" onclick="closeAssignModal()">&times;</button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="assign_event">
            
            <div class="modal-body">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="event_id">Select Event *</label>
                    <select name="event_id" id="event_id" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                        <option value="">-- Select Event --</option>
                        <?php foreach ($availableEvents as $ev): ?>
                        <option value="<?php echo $ev['id']; ?>" data-price="<?php echo $ev['price']; ?>" data-free="<?php echo $ev['is_free']; ?>">
                            <?php echo sanitize($ev['title']); ?> (<?php echo formatDate($ev['start_date']); ?>) - <?php echo $ev['is_free'] ? 'Free' : formatPrice($ev['price'], $ev['currency']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="amount">Amount</label>
                        <input type="number" step="0.01" name="amount" id="amount" value="0" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_status">Payment Status *</label>
                        <select name="payment_status" id="payment_status" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="verified">Verified</option>
                            <option value="free">Free</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select name="payment_method" id="payment_method" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="">-- Select --</option>
                            <option value="razorpay">Razorpay</option>
                            <option value="upi">UPI</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="free">Free</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_id">Payment / Transaction ID</label>
                        <input type="text" name="payment_id" id="payment_id" placeholder="e.g., TXN12345" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label for="payment_proof">Payment Screenshot / Reference</label>
                    <div style="border: 2px dashed var(--border-color); border-radius: var(--radius-sm); padding: 20px; text-align: center; cursor: pointer; transition: all 0.2s;" id="proofDropZone" onclick="document.getElementById('payment_proof').click()">
                        <input type="file" name="payment_proof" id="payment_proof" accept="image/*,.pdf" style="display: none;" onchange="previewProofFile(this)">
                        <div id="proofPreview" style="display: none; margin-bottom: 10px;">
                            <img id="proofPreviewImg" src="" style="max-width: 200px; max-height: 150px; border-radius: 4px;">
                        </div>
                        <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--text-muted); margin-bottom: 8px;" id="proofIcon"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;" id="proofText">Click or drag to upload payment proof (optional)</p>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Event</button>
            </div>
        </form>
    </div>
</div>

<!-- Issue Certificate Modal -->
<div id="certModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Issue Certificate to <?php echo sanitize($viewUser['name']); ?></h3>
            <button type="button" class="modal-close" onclick="closeCertModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="upload_certificate">
            
            <div class="modal-body">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 16px; border-radius: var(--radius-md); margin-bottom: 20px;">
                    <p style="margin: 0; font-size: 0.9rem;">A unique certificate code will be automatically generated for this user.</p>
                </div>
                
                <div class="form-group">
                    <label for="cert_event_id">Select Event *</label>
                    <select name="event_id" id="cert_event_id" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                        <option value="">-- Select Event --</option>
                        <?php 
                        // Only show events user is registered for but doesn't have certificate
                        $regEvents = $db->prepare("
                            SELECT e.id, e.title, e.start_date 
                            FROM registrations r 
                            JOIN events e ON r.event_id = e.id 
                            LEFT JOIN certificates c ON c.user_id = r.user_id AND c.event_id = e.id
                            WHERE r.user_id = ? AND c.id IS NULL
                            ORDER BY e.start_date DESC
                        ");
                        $regEvents->execute([$userId]);
                        while ($ev = $regEvents->fetch()): ?>
                        <option value="<?php echo $ev['id']; ?>">
                            <?php echo sanitize($ev['title']); ?> (<?php echo formatDate($ev['start_date']); ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">Only showing events the user registered for without a certificate.</small>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCertModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Issue Certificate</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}
.modal-content {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 50px rgba(0,0,0,0.3);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
}
.modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
}
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    line-height: 1;
}
.modal-close:hover {
    color: var(--text-primary);
}
.modal-body {
    padding: 24px;
}
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}
</style>

<script>

// View profile photo in modal
function viewProfilePhoto(userId) {
    const photoImg = document.getElementById('userProfilePhoto');
    if (!photoImg || photoImg.style.display === 'none') {
        alert('No profile photo available for this user.');
        return;
    }
    
    const photoSrc = photoImg.src;
    
    // Create modal
    const modal = document.createElement('div');
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 20px;
    `;
    
    const content = document.createElement('div');
    content.style.cssText = `
        position: relative;
        max-width: 90vw;
        max-height: 90vh;
        background: white;
        border-radius: 8px;
        overflow: hidden;
    `;
    
    const img = document.createElement('img');
    img.src = photoSrc;
    img.style.cssText = `
        max-width: 100%;
        max-height: 85vh;
        object-fit: contain;
    `;
    
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '×';
    closeBtn.style.cssText = `
        position: absolute;
        top: 10px;
        right: 10px;
        width: 40px;
        height: 40px;
        border: none;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        font-size: 28px;
        cursor: pointer;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        transition: background 0.2s;
    `;
    
    closeBtn.onmouseover = () => { closeBtn.style.background = 'rgba(0, 0, 0, 0.9)'; };
    closeBtn.onmouseout = () => { closeBtn.style.background = 'rgba(0, 0, 0, 0.7)'; };
    
    const closeModal = () => {
        modal.remove();
    };
    
    closeBtn.onclick = closeModal;
    modal.onclick = (e) => {
        if (e.target === modal) closeModal();
    };
    
    content.appendChild(img);
    content.appendChild(closeBtn);
    modal.appendChild(content);
    document.body.appendChild(modal);
}

// Tab switching - using data attributes for reliability
document.addEventListener('DOMContentLoaded', function() {
    // Add click handlers to all tab buttons
    var tabBtns = document.querySelectorAll('.tab-btn');
    
    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var tabId = this.getAttribute('data-tab');
            showTab(tabId, this);
        });
    });
    
    // Event select auto-fill
    var eventSelect = document.getElementById('event_id');
    if (eventSelect) {
        eventSelect.addEventListener('change', function() {
            var selected = this.options[this.selectedIndex];
            var price = selected.dataset.price || 0;
            var isFree = selected.dataset.free == '1';
            
            document.getElementById('amount').value = isFree ? 0 : price;
            document.getElementById('payment_status').value = isFree ? 'free' : 'pending';
            document.getElementById('payment_method').value = isFree ? 'free' : 'razorpay';
        });
    }
    
    // Modal outside click
    var assignModal = document.getElementById('assignModal');
    if (assignModal) {
        assignModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignModal();
            }
        });
    }
});

function showTab(tabId, clickedBtn) {
    // Remove active from all tabs
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    document.querySelectorAll('.tab-content').forEach(function(content) {
        content.classList.remove('active');
    });
    
    // Activate clicked tab
    if (clickedBtn) {
        clickedBtn.classList.add('active');
    }
    
    // Show target content
    var targetContent = document.getElementById('tab-' + tabId);
    if (targetContent) {
        targetContent.classList.add('active');
    }
}

function openAssignModal() {
    var modal = document.getElementById('assignModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeAssignModal() {
    var modal = document.getElementById('assignModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function openCertModal() {
    var modal = document.getElementById('certModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeCertModal() {
    var modal = document.getElementById('certModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Close cert modal on outside click
var certModal = document.getElementById('certModal');
if (certModal) {
    certModal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeCertModal();
        }
    });
}

function previewProofFile(input) {
    var preview = document.getElementById('proofPreview');
    var previewImg = document.getElementById('proofPreviewImg');
    var icon = document.getElementById('proofIcon');
    var text = document.getElementById('proofText');
    
    if (input.files && input.files[0]) {
        var file = input.files[0];
        
        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                preview.style.display = 'block';
                icon.style.display = 'none';
                text.textContent = file.name;
            };
            reader.readAsDataURL(file);
        } else {
            preview.style.display = 'none';
            icon.style.display = 'block';
            text.textContent = 'Selected: ' + file.name;
        }
    }
}

// Drag and drop support
var dropZone = document.getElementById('proofDropZone');
if (dropZone) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(eventName) {
        dropZone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
        });
    });
    
    ['dragenter', 'dragover'].forEach(function(eventName) {
        dropZone.addEventListener(eventName, function() {
            dropZone.style.borderColor = 'var(--primary)';
            dropZone.style.background = 'rgba(79, 70, 229, 0.05)';
        });
    });
    
    ['dragleave', 'drop'].forEach(function(eventName) {
        dropZone.addEventListener(eventName, function() {
            dropZone.style.borderColor = 'var(--border-color)';
            dropZone.style.background = 'transparent';
        });
    });
    
    dropZone.addEventListener('drop', function(e) {
        var files = e.dataTransfer.files;
        if (files.length) {
            document.getElementById('payment_proof').files = files;
            previewProofFile(document.getElementById('payment_proof'));
        }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
