<?php
/**
 * KESA Learn - User Profile
 * Modern mobile-first design with photo cropper and completion infographics
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/image-processor.php';

$user = getCurrentUser();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    }
    
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $dob = $_POST['dob'] ?? '';
    $gender = $_POST['gender'] ?? '';
    
    // Handle password change
    if (!empty($_POST['new_password'])) {
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }
        
        if (empty($errors)) {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$passwordHash, $_SESSION['user_id']]);
            
            logActivity('password_change', 'Password updated');
        }
    }
    
    if (empty($errors)) {
        $db = getDB();
        // Name is locked once certificate_name is verified — only admin can change it via name-change-requests
        $nameIsLocked = !empty($user['certificate_name']);
        // DOB is locked once set (one-time field)
        $dobIsLocked  = !empty($user['dob']);

        if ($nameIsLocked && $dobIsLocked) {
            $stmt = $db->prepare("UPDATE users SET phone = ?, gender = ? WHERE id = ?");
            $stmt->execute([$phone, $gender ?: null, $_SESSION['user_id']]);
        } elseif ($nameIsLocked) {
            $stmt = $db->prepare("UPDATE users SET phone = ?, dob = ?, gender = ? WHERE id = ?");
            $stmt->execute([$phone, $dob ?: null, $gender ?: null, $_SESSION['user_id']]);
        } elseif ($dobIsLocked) {
            $stmt = $db->prepare("UPDATE users SET name = ?, phone = ?, gender = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $gender ?: null, $_SESSION['user_id']]);
        } else {
            $stmt = $db->prepare("UPDATE users SET name = ?, phone = ?, dob = ?, gender = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $dob ?: null, $gender ?: null, $_SESSION['user_id']]);
        }

        if (!$nameIsLocked) {
            $_SESSION['user_name'] = $name;
        }
        logActivity('profile_update', 'Profile updated');
        
        setFlash('success', 'Profile updated successfully!');
        redirect('/user/profile');
    }
}

// Calculate profile completion with weights
$completionItems = [
    ['field' => 'profile_image', 'label' => 'Profile Photo', 'icon' => 'camera', 'filled' => !empty($user['profile_image']), 'weight' => 15],
    ['field' => 'name', 'label' => 'Full Name', 'icon' => 'user', 'filled' => !empty($user['name']), 'weight' => 15],
    ['field' => 'email', 'label' => 'Email', 'icon' => 'mail', 'filled' => !empty($user['email']), 'weight' => 15],
    ['field' => 'phone', 'label' => 'WhatsApp Number', 'icon' => 'phone', 'filled' => !empty($user['phone']), 'weight' => 10],
    ['field' => 'dob', 'label' => 'Date of Birth', 'icon' => 'calendar', 'filled' => !empty($user['dob']), 'weight' => 10],
    ['field' => 'gender', 'label' => 'Gender', 'icon' => 'users', 'filled' => !empty($user['gender']), 'weight' => 10],
];

$filledWeight = 0;
$totalWeight = 0;
$missingItems = [];
foreach ($completionItems as $item) {
    $totalWeight += $item['weight'];
    if ($item['filled']) {
        $filledWeight += $item['weight'];
    } else {
        $missingItems[] = $item;
    }
}
$completionPercent = round(($filledWeight / $totalWeight) * 100);

$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Profile Page Mobile-First Styles */
.profile-page {
    padding: 80px 0 100px;
    background: var(--bg-secondary);
    min-height: 100vh;
}

/* Mobile Page Header */
.mobile-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-light);
    position: sticky;
    top: var(--nav-height);
    z-index: 100;
}

@media (min-width: 769px) {
    .mobile-page-header { display: none; }
}

.mobile-page-title {
    font-size: 1.1rem;
    font-weight: 700;
}

.dashboard-link-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--blue);
    color: #fff;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
}

.dashboard-link-btn:hover {
    background: var(--purple);
}

.dashboard-link-btn svg {
    width: 16px;
    height: 16px;
}

.profile-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 16px;
}

/* Profile Hero Card */
.profile-hero {
    background: linear-gradient(135deg, var(--blue) 0%, var(--purple) 100%);
    border-radius: var(--radius-xl);
    padding: 24px 20px;
    margin-bottom: 16px;
    color: #fff;
    position: relative;
    overflow: hidden;
}

.profile-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}

.profile-hero-content {
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 1;
}

.profile-avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.profile-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    color: #fff;
    border: 3px solid rgba(255,255,255,0.5);
    overflow: hidden;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-photo-edit-btn {
    position: absolute;
    bottom: -8px;
    right: -8px;
    width: 36px;
    height: 36px;
    background: var(--primary-color, #1565c0);
    border: 3px solid #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    transition: all 0.2s ease;
    padding: 0;
    color: white;
}

.profile-photo-edit-btn:hover {
    background: var(--primary-color-dark, #1053a0);
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
    transform: scale(1.05);
}

.profile-photo-edit-btn svg {
    width: 18px;
    height: 18px;
    fill: currentColor;
}

/* Photo Options Menu */
.photo-options-menu {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease;
}

.photo-options-menu.active {
    display: flex;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.photo-options-dialog {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 0;
    width: 90%;
    max-width: 300px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.photo-options-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
    color: var(--text-primary);
    font-size: 14px;
    font-weight: 500;
    background: var(--bg-primary);
}

.photo-options-item:last-child {
    border-bottom: none;
}

.photo-options-item:hover {
    background: var(--bg-secondary);
}

.photo-options-item svg {
    width: 20px;
    height: 20px;
    color: var(--primary-color, #1565c0);
}

.photo-options-close {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px 16px;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
    border-top: 1px solid var(--border-color);
    transition: background 0.2s ease;
}

.photo-options-close:hover {
    background: var(--bg-secondary);
}

.photo-upload-input {
    display: none;
}

.profile-hero-info h1 {
    font-size: 1.25rem;
    margin-bottom: 2px;
    color: #fff;
}

.profile-hero-info p {
    opacity: 0.9;
    font-size: 0.85rem;
}

/* Completion Ring Card */
.completion-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
}

.completion-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.completion-title {
    font-size: 0.95rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}

.completion-title svg {
    width: 18px;
    height: 18px;
    color: var(--blue);
}

.completion-ring {
    width: 64px;
    height: 64px;
    position: relative;
}

.completion-ring svg {
    transform: rotate(-90deg);
}

.ring-bg {
    fill: none;
    stroke: var(--bg-tertiary);
    stroke-width: 6;
}

.ring-fill {
    fill: none;
    stroke-width: 6;
    stroke-linecap: round;
    transition: stroke-dasharray 0.5s ease;
}

.ring-fill.low { stroke: var(--red); }
.ring-fill.medium { stroke: var(--yellow); }
.ring-fill.high { stroke: #0d7a42; }

.ring-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.9rem;
    font-weight: 800;
}

.completion-bar {
    height: 6px;
    background: var(--bg-tertiary);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 12px;
}

.completion-bar-fill {
    height: 100%;
    border-radius: 3px;
}

.completion-bar-fill.low { background: var(--red); }
.completion-bar-fill.medium { background: var(--yellow); }
.completion-bar-fill.high { background: #0d7a42; }

.completion-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.completion-chip {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    border-radius: 16px;
    font-size: 0.75rem;
    font-weight: 500;
    background: var(--bg-tertiary);
    color: var(--text-muted);
}

.completion-chip.done {
    background: #e6f9f0;
    color: #0d7a42;
}

.completion-chip svg {
    width: 12px;
    height: 12px;
}

.missing-alert {
    background: linear-gradient(135deg, #fef2f2 0%, #fff5f5 100%);
    border: 1px solid var(--red);
    border-radius: var(--radius-md);
    padding: 14px;
    margin-top: 14px;
}

.missing-alert h4 {
    color: var(--red);
    font-size: 0.85rem;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.missing-alert h4 svg {
    width: 14px;
    height: 14px;
}

.missing-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.missing-tags span {
    background: #fff;
    padding: 4px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    color: var(--red);
    border: 1px solid var(--red);
}

/* Form Section Cards */
.form-section {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.form-section-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    align-items: center;
    gap: 12px;
}

.form-section-icon {
    width: 36px;
    height: 36px;
    background: var(--blue-light);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
}

.form-section-icon svg {
    width: 18px;
    height: 18px;
    color: var(--blue);
}

.form-section-title h2 {
    font-size: 1rem;
    margin: 0;
}

.form-section-title p {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin: 0;
}

.form-section-body {
    padding: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

@media (min-width: 600px) {
    .form-grid {
        grid-template-columns: 1fr 1fr;
    }
    .form-grid .full-width {
        grid-column: 1 / -1;
    }
}

.form-group-m label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
}

.form-group-m .form-control {
    padding: 12px 14px;
    font-size: 1rem;
    border-radius: var(--radius-md);
}

.form-group-m .form-control:disabled {
    background: var(--bg-tertiary);
    color: var(--text-muted);
}

/* Save Button - Sticky */
.save-section {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    padding: 12px 20px;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-color);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    z-index: 999;
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    max-width: 100%;
}

.save-section .btn {
    margin: 0;
    min-width: 120px;
    background-color: #d1d5db !important;
    border-color: #d1d5db !important;
    color: #fff !important;
    transition: all 0.3s ease;
    cursor: not-allowed;
}

#saveBtn:not(:disabled),
#saveBtnMobile:not(:disabled) {
    background-color: #22c55e !important;
    border-color: #22c55e !important;
    cursor: pointer !important;
}

#saveBtn:not(:disabled):hover,
#saveBtnMobile:not(:disabled):hover {
    background-color: #16a34a !important;
    border-color: #16a34a !important;
}

#saveBtn:disabled,
#saveBtnMobile:disabled {
    background-color: #d1d5db !important;
    border-color: #d1d5db !important;
    cursor: not-allowed !important;
    opacity: 0.7;
}



/* Add top margin to profile page to account for sticky button */
.profile-page {
    margin-top: 60px;
}

/* Mobile optimizations */
@media (max-width: 480px) {
    .profile-hero-content {
        flex-direction: column;
        text-align: center;
    }
    
    .profile-hero-info h1 {
        font-size: 1.1rem;
    }
    
    .profile-avatar {
        width: 64px;
        height: 64px;
    }
    
    .form-section-body {
        padding: 16px;
    }
    
    .completion-top {
        flex-direction: column;
        gap: 12px;
        text-align: center;
    }
}
</style>

<div class="profile-page">
    <!-- Mobile Page Header -->
    <div class="mobile-page-header">
        <span class="mobile-page-title">My Profile</span>
        <button type="submit" form="profileForm" id="saveBtnMobile" class="btn btn-sm btn-primary" disabled>Save Changes</button>
    </div>
    
    <div class="profile-container user-page-content">
        <!-- Profile Hero Card -->
        <div class="profile-hero">
            <div class="profile-hero-content">
                <div class="profile-avatar-wrap">
                    <div class="profile-avatar" id="headerAvatar">
                        <?php if ($user['profile_image']): ?>
                            <img src="/uploads/<?php echo sanitize($user['profile_image']); ?>" alt="Profile" id="headerAvatarImg">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                        <?php endif; ?>
                    </div>
                    <!-- WhatsApp Style Edit Button -->
                    <button type="button" class="profile-photo-edit-btn" id="editPhotoBtn" title="Edit profile photo">
                        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25z"/><path d="M20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                    </button>
                </div>
                <div class="profile-hero-info">
                    <h1><?php echo sanitize($user['name']); ?></h1>
                    <p><?php echo sanitize($user['email']); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Profile Completion Card -->
        <?php if ($completionPercent < 100): ?>
        <div class="completion-card">
            <div class="completion-top">
                <div class="completion-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Profile Completion
                </div>
                <div class="completion-ring">
                    <svg width="64" height="64" viewBox="0 0 64 64">
                        <circle class="ring-bg" cx="32" cy="32" r="28"></circle>
                        <circle class="ring-fill <?php echo $completionPercent < 40 ? 'low' : ($completionPercent < 70 ? 'medium' : 'high'); ?>" 
                                cx="32" cy="32" r="28" 
                                stroke-dasharray="<?php echo ($completionPercent / 100) * 175.9; ?>, 175.9"></circle>
                    </svg>
                    <span class="ring-text"><?php echo $completionPercent; ?>%</span>
                </div>
            </div>
            
            <div class="completion-bar">
                <div class="completion-bar-fill <?php echo $completionPercent < 40 ? 'low' : ($completionPercent < 70 ? 'medium' : 'high'); ?>" style="width: <?php echo $completionPercent; ?>%;"></div>
            </div>
            
            <div class="completion-chips">
                <?php foreach ($completionItems as $item): ?>
                    <span class="completion-chip <?php echo $item['filled'] ? 'done' : ''; ?>">
                        <?php if ($item['filled']): ?>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <?php else: ?>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                        <?php endif; ?>
                        <?php echo $item['label']; ?>
                    </span>
                <?php endforeach; ?>
            </div>
            
            <?php if (count($missingItems) > 0): ?>
            <div class="missing-alert">
                <div class="missing-tags">
                    <?php foreach ($missingItems as $item): ?>
                        <span><?php echo $item['label']; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="flash-message flash-error" style="margin-bottom: 16px; border-radius: var(--radius-md);">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="profileForm">
            <?php echo csrfField(); ?>
            
            <!-- Personal Information Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <div class="form-section-title">
                        <h2>Personal Information</h2>
                        <p>Update your personal details</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid">
                        <div class="form-group-m full-width">
                            <label for="name">Full Name <span class="required">*</span></label>
                            <?php
                            // Check if there's an existing pending name change request
                            $pendingNameRequest = null;
                            try {
                                $ncStmt = $db->prepare("SELECT * FROM name_change_requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
                                $ncStmt->execute([$userId]);
                                $pendingNameRequest = $ncStmt->fetch();
                            } catch (Exception $e) { /* table may not exist yet */ }
                            ?>
                            <?php $nameLocked = !empty($user['certificate_name']); ?>
                            <?php if ($nameLocked): ?>
                            <!-- Name locked: certificate_name already verified, only admin can change -->
                            <div style="display:flex;gap:8px;align-items:center;">
                                <div style="flex:1;position:relative;">
                                    <input type="text" id="name" name="name" class="form-control"
                                           value="<?php echo sanitize($user['name']); ?>"
                                           readonly
                                           style="flex:1;background:var(--bg-tertiary);color:var(--text-muted);cursor:not-allowed;padding-right:36px;">
                                    <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);" title="Name locked after certificate verification">
                                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    </span>
                                </div>
                                <?php if ($pendingNameRequest): ?>
                                    <span class="name-change-pending-badge">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Pending
                                    </span>
                                <?php else: ?>
                                    <button type="button" id="nameChangeBtn" class="name-change-request-btn">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        Request Change
                                    </button>
                                <?php endif; ?>
                            </div>
                            <span style="font-size:0.75rem;color:var(--text-muted);margin-top:5px;display:flex;align-items:center;gap:5px;">
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Name is locked after certificate verification. To change it, submit a name change request for admin approval.
                            </span>
                            <?php else: ?>
                            <!-- Name editable: certificate not yet set -->
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="text" id="name" name="name" class="form-control"
                                       value="<?php echo sanitize($user['name']); ?>"
                                       required style="flex:1;">
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group-m">
                            <label for="email">Email Address</label>
                            <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" disabled>
                        </div>
                        
                        <div class="form-group-m">
                            <label for="phone">WhatsApp Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control" placeholder="+91 98765 43210" value="<?php echo sanitize($user['phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group-m">
                            <label for="dob">Date of Birth</label>
                            <?php if (!empty($user['dob'])): ?>
                                <input type="date" id="dob" name="dob" class="form-control" value="<?php echo sanitize($user['dob']); ?>" readonly style="background:var(--bg-secondary);cursor:not-allowed;color:var(--text-muted);" title="Date of birth cannot be changed once submitted">
                                <span style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;display:block;">Date of birth is locked and cannot be changed.</span>
                            <?php else: ?>
                                <input type="date" id="dob" name="dob" class="form-control" value="" max="<?php echo date('Y-m-d', strtotime('-13 years')); ?>" title="Once saved, date of birth cannot be changed">
                                <span style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;display:block;">Once saved, this cannot be changed.</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group-m">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" class="form-control">
                                <option value="">Select Gender</option>
                                <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo ($user['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            
            <!-- Security Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <div class="form-section-title">
                        <h2>Change Password</h2>
                        <p>Update your password (optional)</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid">
                        <div class="form-group-m">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Min 8 characters">
                        </div>
                        
                        <div class="form-group-m">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Repeat new password">
                        </div>
                    </div>
                </div>
            
            <!-- Save Button -->
            <div class="save-section">
                <div style="display: flex; gap: 12px; flex-direction: column;">
                    <button type="submit" id="saveBtn" class="btn btn-primary btn-lg btn-full" disabled>Save Changes</button>
                    <a href="/auth/logout" class="btn btn-secondary btn-lg btn-full" style="text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 8px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Logout
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Hidden Photo Upload Input -->
        <input type="file" id="photoUploadInput" class="photo-upload-input" accept="image/jpeg,image/png,image/webp">
        
        <!-- Photo Options Menu Modal -->
        <div id="photoOptionsMenu" class="photo-options-menu">
            <div class="photo-options-dialog">
                <div class="photo-options-item" id="galleryOption">
                    <svg fill="currentColor" viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                    Choose from Gallery
                </div>
                <div class="photo-options-close" id="closeOptions">
                    Cancel
                </div>
            </div>
        </div>
    </div>
</div>

<!-- User Bottom Navigation -->
<?php include __DIR__ . '/../includes/user_nav.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Photo upload functionality
    const editPhotoBtn = document.getElementById('editPhotoBtn');
    const photoOptionsMenu = document.getElementById('photoOptionsMenu');
    const photoUploadInput = document.getElementById('photoUploadInput');
    const galleryOption = document.getElementById('galleryOption');
    const closeOptions = document.getElementById('closeOptions');
    const headerAvatar = document.getElementById('headerAvatar');
    
    // Open photo menu
    editPhotoBtn.addEventListener('click', function() {
        photoOptionsMenu.classList.add('active');
    });
    
    // Close menu on background click
    photoOptionsMenu.addEventListener('click', function(e) {
        if (e.target === photoOptionsMenu) {
            closeMenu();
        }
    });
    
    // Close button
    closeOptions.addEventListener('click', closeMenu);
    
    function closeMenu() {
        photoOptionsMenu.classList.remove('active');
    }
    
    // Gallery option
    galleryOption.addEventListener('click', function() {
        photoUploadInput.accept = 'image/jpeg,image/png,image/webp';
        photoUploadInput.click();
        closeMenu();
    });
    
    // Handle file upload
    photoUploadInput.addEventListener('change', async function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        if (!file.type.startsWith('image/')) {
            alert('Please select a valid image file');
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be less than 5MB');
            return;
        }
        
        // Show loading state
        editPhotoBtn.disabled = true;
        
        try {
            // Convert file to base64
            const reader = new FileReader();
            reader.onload = async function(e) {
                const base64Image = e.target.result;
                
                // Upload via AJAX
                const response = await fetch('/api/user/profile-photo/upload', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('[name="csrf_token"]').value,
                    },
                    body: JSON.stringify({
                        image: base64Image
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    console.log('[v0] Photo uploaded successfully');
                    
                    // Update avatar preview
                    const img = document.createElement('img');
                    img.src = base64Image;
                    img.alt = 'Profile';
                    headerAvatar.innerHTML = '';
                    headerAvatar.appendChild(img);
                    
                    // Show success message
                    alert('Profile photo updated successfully!');
                    
                    // Reload after short delay
                    setTimeout(() => location.reload(), 1000);
                } else {
                    alert('Failed to upload photo: ' + (data.message || 'Unknown error'));
                }
            };
            reader.readAsDataURL(file);
        } catch (error) {
            console.error('[v0] Error uploading photo:', error);
            alert('Failed to upload photo');
        } finally {
            editPhotoBtn.disabled = false;
            photoUploadInput.value = '';
        }
    });
    
    // Form dirty state tracking - enable save button only when form is modified
    const profileForm = document.getElementById('profileForm');
    const saveBtn = document.getElementById('saveBtn');
    const saveBtnMobile = document.getElementById('saveBtnMobile');
    const formInputs = profileForm.querySelectorAll('input, select, textarea');
    
    // Get initial form state
    const initialFormState = {};
    formInputs.forEach(input => {
        initialFormState[input.name] = input.value;
    });
    
    // Track changes and enable/disable save button
    function checkFormChanges() {
        let hasChanges = false;
        
        formInputs.forEach(input => {
            if (input.name && input.name !== 'csrf_token' && initialFormState[input.name] !== input.value) {
                hasChanges = true;
            }
        });
        
        saveBtn.disabled = !hasChanges;
        if (saveBtnMobile) saveBtnMobile.disabled = !hasChanges;
    }
    
    // Add listeners to all form inputs
    formInputs.forEach(input => {
        input.addEventListener('input', checkFormChanges);
        input.addEventListener('change', checkFormChanges);
    });
    
    // Set initial state
    checkFormChanges();
});

</script>

<!-- Name Change Request Modal -->
<div id="nameChangeModal" class="nc-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ncModalTitle">
    <div class="nc-modal-box">
        <div class="nc-modal-header">
            <div class="nc-modal-title-row">
                <div class="nc-modal-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <div>
                    <h2 id="ncModalTitle">Request Name Change</h2>
                    <p>Admin approval required</p>
                </div>
            </div>
            <button class="nc-modal-close" id="ncModalClose" aria-label="Close">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="nc-modal-body">
            <p class="nc-info">Enter your full name exactly as it appears on your official ID. Your current name will be updated once admin verifies your request.</p>
            <form method="POST" action="/api/user/name-change-request.php" enctype="multipart/form-data" id="nameChangeForm">
                <?php echo csrfField(); ?>
                <div class="nc-field">
                    <label for="ncFullName">Full Name (as per official record) <span class="required">*</span></label>
                    <input type="text" id="ncFullName" name="requested_name" class="nc-input" placeholder="Enter your full legal name" required minlength="3" maxlength="120">
                </div>
                <div class="nc-field">
                    <label for="ncDocument">Upload ID Document <span class="required">*</span></label>
                    <div class="nc-doc-types">Accepted: Masked Aadhaar / Voter ID / School Certificate / Student ID Card / any official government ID showing your name</div>
                    <div class="nc-upload-area" id="ncUploadArea">
                        <input type="file" id="ncDocument" name="id_document" accept=".jpg,.jpeg,.png,.pdf" required style="display:none;">
                        <div class="nc-upload-placeholder" id="ncUploadPlaceholder">
                            <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            <span>Click to upload or drag &amp; drop</span>
                            <small>JPG, JPEG, PNG, PDF &bull; Max 2MB</small>
                        </div>
                        <div class="nc-upload-preview" id="ncUploadPreview" style="display:none;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span id="ncFileName"></span>
                            <button type="button" id="ncRemoveFile" class="nc-remove-file">Remove</button>
                        </div>
                    </div>
                    <span id="ncFileError" class="nc-field-error" style="display:none;"></span>
                </div>
                <div class="nc-modal-footer">
                    <button type="button" class="nc-btn-cancel" id="ncCancelBtn">Cancel</button>
                    <button type="submit" class="nc-btn-submit" id="ncSubmitBtn">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Name Change Request Button & Badge */
.name-change-request-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #f0f9ff;
    border: 1.5px solid #0ea5e9;
    color: #0284c7;
    border-radius: var(--radius-md);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
    flex-shrink: 0;
}
.name-change-request-btn:hover { background: #0ea5e9; color: #fff; }
.name-change-pending-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: #fef9c3;
    border: 1px solid #fde047;
    color: #854d0e;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
    flex-shrink: 0;
}
/* Modal */
.nc-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.nc-modal-box {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    overflow: hidden;
    animation: ncSlideUp 0.25s ease;
}
@keyframes ncSlideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
.nc-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 20px 20px 16px;
    border-bottom: 1px solid var(--border-light);
}
.nc-modal-title-row { display: flex; align-items: center; gap: 12px; }
.nc-modal-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
}
.nc-modal-icon svg { width: 20px; height: 20px; }
.nc-modal-header h2 { font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0 0 2px; }
.nc-modal-header p { font-size: 0.78rem; color: var(--text-muted); margin: 0; }
.nc-modal-close { background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 4px; border-radius: 6px; transition: background 0.15s; }
.nc-modal-close:hover { background: var(--bg-secondary); }
.nc-modal-body { padding: 20px; }
.nc-info { font-size: 0.83rem; color: var(--text-muted); margin: 0 0 18px; line-height: 1.5; background: #f0f9ff; border-left: 3px solid #0ea5e9; padding: 10px 12px; border-radius: 0 6px 6px 0; }
.nc-field { margin-bottom: 18px; }
.nc-field label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
.nc-input {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.92rem;
    background: var(--bg-secondary);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.2s;
    box-sizing: border-box;
}
.nc-input:focus { border-color: #0ea5e9; background: var(--bg-primary); }
.nc-doc-types { font-size: 0.76rem; color: var(--text-muted); margin-bottom: 8px; line-height: 1.5; }
.nc-upload-area {
    border: 2px dashed var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    transition: border-color 0.2s, background 0.2s;
    cursor: pointer;
    overflow: hidden;
}
.nc-upload-area:hover, .nc-upload-area.dragover { border-color: #0ea5e9; background: #f0f9ff; }
.nc-upload-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 24px;
    color: var(--text-muted);
    text-align: center;
}
.nc-upload-placeholder svg { color: var(--text-muted); opacity: 0.5; }
.nc-upload-placeholder span { font-size: 0.88rem; font-weight: 500; color: var(--text-secondary); }
.nc-upload-placeholder small { font-size: 0.76rem; }
.nc-upload-preview {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    color: #15803d;
    font-size: 0.88rem;
    font-weight: 500;
}
.nc-upload-preview svg { flex-shrink: 0; }
.nc-upload-preview span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nc-remove-file { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 0.8rem; font-weight: 600; padding: 0; flex-shrink: 0; }
.nc-field-error { font-size: 0.78rem; color: #ef4444; margin-top: 4px; }
.nc-modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 4px;
}
.nc-btn-cancel {
    padding: 10px 20px;
    border: 1.5px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    border-radius: var(--radius-md);
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.nc-btn-cancel:hover { background: var(--bg-secondary); }
.nc-btn-submit {
    padding: 10px 24px;
    background: #0ea5e9;
    color: #fff;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.nc-btn-submit:hover { background: #0284c7; }
.nc-btn-submit:disabled { background: var(--text-muted); cursor: not-allowed; }
</style>

<script>
// Name change modal
(function() {
    const modal = document.getElementById('nameChangeModal');
    const openBtn = document.getElementById('nameChangeBtn');
    const closeBtn = document.getElementById('ncModalClose');
    const cancelBtn = document.getElementById('ncCancelBtn');
    const form = document.getElementById('nameChangeForm');
    const uploadArea = document.getElementById('ncUploadArea');
    const fileInput = document.getElementById('ncDocument');
    const placeholder = document.getElementById('ncUploadPlaceholder');
    const preview = document.getElementById('ncUploadPreview');
    const fileName = document.getElementById('ncFileName');
    const removeBtn = document.getElementById('ncRemoveFile');
    const fileError = document.getElementById('ncFileError');
    const submitBtn = document.getElementById('ncSubmitBtn');

    if (!openBtn) return;

    function openModal() {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        form.reset();
        showPlaceholder();
    }

    function showPlaceholder() {
        placeholder.style.display = 'flex';
        preview.style.display = 'none';
        fileError.style.display = 'none';
    }

    function showPreview(name) {
        placeholder.style.display = 'none';
        preview.style.display = 'flex';
        fileName.textContent = name;
    }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

    uploadArea.addEventListener('click', function() { fileInput.click(); });
    uploadArea.addEventListener('dragover', function(e) { e.preventDefault(); uploadArea.classList.add('dragover'); });
    uploadArea.addEventListener('dragleave', function() { uploadArea.classList.remove('dragover'); });
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) validateAndSetFile(file);
    });

    fileInput.addEventListener('change', function() {
        if (this.files[0]) validateAndSetFile(this.files[0]);
    });

    removeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        fileInput.value = '';
        showPlaceholder();
    });

    function validateAndSetFile(file) {
        const allowed = ['image/jpeg', 'image/png', 'application/pdf'];
        const maxSize = 2 * 1024 * 1024;
        fileError.style.display = 'none';
        if (!allowed.includes(file.type)) {
            fileError.textContent = 'Only JPG, JPEG, PNG, PDF files are allowed.';
            fileError.style.display = 'block';
            return;
        }
        if (file.size > maxSize) {
            fileError.textContent = 'File must be 2MB or smaller.';
            fileError.style.display = 'block';
            return;
        }
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;
        showPreview(file.name);
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
        const fd = new FormData(form);
        fetch('/api/user/name-change-request.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeModal();
                    // Show pending badge
                    openBtn.style.display = 'none';
                    const badge = document.createElement('span');
                    badge.className = 'name-change-pending-badge';
                    badge.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Pending';
                    openBtn.parentNode.appendChild(badge);
                    alert('Name change request submitted! Admin will review and update your name.');
                } else {
                    alert('Error: ' + (data.error || 'Request failed. Please try again.'));
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Request';
                }
            })
            .catch(() => {
                alert('Network error. Please try again.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Request';
            });
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
