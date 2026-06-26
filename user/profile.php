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
            $stmt = $db->prepare("UPDATE users SET gender = ? WHERE id = ?");
            $stmt->execute([$gender ?: null, $_SESSION['user_id']]);
        } elseif ($nameIsLocked) {
            $stmt = $db->prepare("UPDATE users SET dob = ?, gender = ? WHERE id = ?");
            $stmt->execute([$dob ?: null, $gender ?: null, $_SESSION['user_id']]);
        } elseif ($dobIsLocked) {
            $stmt = $db->prepare("UPDATE users SET name = ?, gender = ? WHERE id = ?");
            $stmt->execute([$name, $gender ?: null, $_SESSION['user_id']]);
        } else {
            $stmt = $db->prepare("UPDATE users SET name = ?, dob = ?, gender = ? WHERE id = ?");
            $stmt->execute([$name, $dob ?: null, $gender ?: null, $_SESSION['user_id']]);
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
    ['field' => 'phone', 'label' => 'WhatsApp Number', 'icon' => 'phone', 'filled' => (!empty($user['phone']) || !empty($user['mobile_number'])), 'weight' => 10],
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

/* 2-Column Desktop Grid Layout and responsive enhancements */
.profile-layout-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.desktop-only {
    display: none !important;
}

.mobile-only {
    display: block !important;
}

@media (min-width: 769px) {
    .profile-container {
        max-width: 1100px !important;
        padding: 24px;
    }
    .profile-layout-grid {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 24px;
        align-items: start;
    }
    .profile-sidebar-col {
        position: sticky;
        top: calc(var(--nav-height) + 20px);
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .profile-main-col {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .desktop-only {
        display: block !important;
    }
    .mobile-only {
        display: none !important;
    }
}

/* Override fixed save-section to be standard inline block */
.save-section {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    right: auto !important;
    padding: 20px !important;
    background: var(--bg-primary) !important;
    border: 1px solid var(--border-color) !important;
    border-radius: var(--radius-xl) !important;
    box-shadow: var(--shadow-sm) !important;
    z-index: 1 !important;
    display: flex !important;
    gap: 16px !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-top: 16px !important;
    box-sizing: border-box;
}

.save-section .btn {
    margin: 0;
    padding: 12px 28px;
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    font-weight: 700;
}

/* Remove top margin since save button is no longer sticky */
.profile-page {
    margin-top: 0 !important;
    padding-top: calc(var(--nav-height) + 20px) !important;
}
</style>

<div class="profile-page">
    <!-- Mobile Page Header -->
    <div class="mobile-page-header">
        <span class="mobile-page-title">My Profile</span>
    </div>
    
    <div class="profile-container user-page-content">
        <div class="profile-layout-grid">
            <!-- Column 1 (Left Sidebar) -->
            <div class="profile-sidebar-col">
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
                
                <!-- Desktop Logout Card -->
                <div class="card desktop-only" style="padding: 16px; border-radius: var(--radius-xl); background: var(--bg-primary); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); display: none;">
                    <a href="/auth/logout" class="btn btn-secondary btn-lg btn-full" style="text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; box-sizing: border-box; background: #fff5f5; color: #e53e3e; border: 1.5px solid #feb2b2;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Logout
                    </a>
                </div>
            </div>
            
            <!-- Column 2 (Main Content) -->
            <div class="profile-main-col">
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
                            <label for="email" style="display:flex; justify-content:space-between; align-items:center;">
                                Email Address
                                <?php 
                                $isGoogleUser = !empty($user['google_id']) || ($user['auth_method'] ?? '') === 'google';
                                $isEmailVerified = ($user['email_verified'] ?? 0) == 1 || $isGoogleUser;
                                if ($isEmailVerified): ?>
                                    <span class="verification-badge verified" id="emailBadge">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Verified
                                    </span>
                                <?php else: ?>
                                    <span class="verification-badge unverified" id="emailBadge">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        Unverified
                                    </span>
                                <?php endif; ?>
                            </label>
                            <div class="profile-input-wrapper">
                                <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" disabled style="flex:1;">
                                <div class="profile-input-actions">
                                    <?php if (!$isEmailVerified): ?>
                                        <button type="button" class="verify-btn" id="emailVerifyBtn" onclick="startEmailVerification()">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                            Verify
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group-m">
                            <label for="phone" style="display:flex; justify-content:space-between; align-items:center;">
                                WhatsApp Number
                                <?php 
                                $isOtpUser = ($user['auth_method'] ?? '') === 'otp' || ($user['auth_method'] ?? '') === 'both';
                                $isPhoneVerified = !empty($user['mobile_verified_at']) || $isOtpUser;
                                if ($isPhoneVerified): ?>
                                    <span class="verification-badge verified" id="phoneBadge">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Verified
                                    </span>
                                <?php else: ?>
                                    <span class="verification-badge unverified" id="phoneBadge">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        Unverified
                                    </span>
                                <?php endif; ?>
                            </label>
                            <div class="profile-input-wrapper">
                                <div class="phone-input-container" style="flex:1; display:flex; align-items:center; border:1px solid var(--border-color); border-radius:var(--radius-md); overflow:hidden; background:var(--bg-tertiary); max-height: 44px;">
                                    <span style="padding:10px 12px; font-size:0.9rem; font-weight:600; color:var(--text-muted); background:var(--bg-secondary); border-right:1px solid var(--border-color); height:44px; display:flex; align-items:center;">+91</span>
                                    <input type="tel" id="phoneDisplay" class="form-control" placeholder="10-digit number" value="<?php 
                                        $rawPhone = preg_replace('/\D/', '', !empty($user['phone']) ? $user['phone'] : ($user['mobile_number'] ?? ''));
                                        echo sanitize(substr($rawPhone, -10)); 
                                    ?>" readonly style="border:none !important; outline:none !important; box-shadow:none !important; background:transparent; flex:1;">
                                </div>
                                <div class="profile-input-actions" id="phoneActionsContainer">
                                    <button type="button" class="verify-btn btn-secondary" id="phoneActionBtn" onclick="togglePhoneEdit()">Change</button>
                                    <?php if (!$isPhoneVerified): ?>
                                        <button type="button" class="verify-btn" id="phoneVerifyBtn" onclick="startPhoneVerification()">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                            Verify
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
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
                <button type="submit" id="saveBtn" class="btn btn-primary btn-lg btn-full" style="width: 100%;" disabled>Save Changes</button>
            </div>
        </form>
        
        <!-- Mobile Logout Button -->
        <div class="mobile-only" style="margin-top: 16px; margin-bottom: 24px; padding: 0 4px; display: none;">
            <a href="/auth/logout" class="btn btn-secondary btn-lg btn-full" style="text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; box-sizing: border-box; background: #fff5f5; color: #e53e3e; border: 1.5px solid #feb2b2;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Logout
            </a>
        </div>
    </div> <!-- Close .profile-main-col -->
</div> <!-- Close .profile-layout-grid -->
        
        <!-- OTP Verification Modal -->
        <div id="otpModal" class="otp-modal">
            <div class="otp-modal-content">
                <div class="otp-modal-header">
                    <h3 id="otpModalTitle">Verify Code</h3>
                    <p id="otpModalSub"></p>
                </div>
                <div id="otpError" class="otp-alert otp-alert--error" style="display:none; text-align:left;"></div>
                
                <!-- 6 digit boxes -->
                <div class="otp-digits" id="modalOtpDigits" style="margin-bottom:20px;">
                    <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                    <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <span class="otp-digit-sep">-</span>
                    <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                </div>
                
                <button id="modalOtpVerifyBtn" type="button" class="otp-btn otp-btn--primary otp-btn--full" onclick="submitModalOtp()">
                    <span id="otpVerifyBtnText">Verify &amp; Save</span>
                    <span id="otpVerifySpinner" class="otp-spinner" style="display:none;"></span>
                </button>
                
                <div class="otp-actions" style="margin-top:16px;">
                    <button type="button" class="otp-link-btn" id="modalResendBtn" onclick="resendModalOtp()">
                        Resend OTP <span id="modalTimer" class="otp-timer"></span>
                    </button>
                    <button type="button" class="otp-link-btn" onclick="closeOtpModal()">Cancel</button>
                </div>
            </div>
        </div>
        
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

/* Verification badges, input wrappers, and buttons */
.profile-input-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
}
.profile-input-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-shrink: 0;
}
@media (max-width: 576px) {
    .profile-input-wrapper {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    .profile-input-actions {
        width: 100%;
        justify-content: stretch;
    }
    .profile-input-actions .verify-btn {
        flex: 1;
        justify-content: center;
    }
}

.verification-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.verification-badge.verified {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}
.verification-badge.unverified {
    background: #fff5f5;
    color: #e53e3e;
    border: 1px solid #feb2b2;
}
.verification-badge svg {
    stroke-width: 2.5;
}

.verify-btn {
    padding: 10px 16px;
    background: var(--blue);
    color: #fff;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    box-shadow: 0 2px 4px rgba(73, 80, 186, 0.15);
}
.verify-btn:hover {
    background: var(--purple);
    box-shadow: 0 4px 8px rgba(160, 88, 174, 0.25);
    transform: translateY(-1px);
}
.verify-btn:active {
    transform: translateY(0);
}
.verify-btn:disabled {
    background: var(--text-muted);
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
    opacity: 0.7;
}
.verify-btn.btn-secondary {
    background: var(--bg-primary);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    box-shadow: none;
}
.verify-btn.btn-secondary:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border-color: var(--text-muted);
    box-shadow: none;
    transform: none;
}

/* Glassmorphic Modal styles */
.otp-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.otp-modal.active {
    display: flex;
    opacity: 1;
}
.otp-modal-content {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    padding: 36px 32px;
    width: 90%;
    max-width: 440px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04), 0 0 0 1px rgba(0, 0, 0, 0.05);
    text-align: center;
    transform: translateY(20px);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.otp-modal.active .otp-modal-content {
    transform: translateY(0);
}
.otp-modal-header h3 {
    margin: 0 0 8px;
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.5px;
}
.otp-modal-header p {
    margin: 0 0 24px;
    font-size: 0.9rem;
    color: var(--text-muted);
    line-height: 1.5;
}

.otp-digits {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 24px;
}
.otp-digit {
    width: 46px;
    height: 56px;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    text-align: center;
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--text-primary);
    background: var(--bg-secondary);
    outline: none;
    transition: all 0.2s ease;
    caret-color: transparent;
    -moz-appearance: textfield;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
}
.otp-digit::-webkit-outer-spin-button,
.otp-digit::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.otp-digit:focus {
    border-color: var(--blue);
    background: var(--bg-primary);
    box-shadow: 0 0 0 4px rgba(73, 80, 186, 0.18), inset 0 2px 4px rgba(0,0,0,0.02);
}
.otp-digit.filled {
    border-color: var(--blue);
    background: var(--bg-primary);
}
.otp-digit.error {
    border-color: var(--red);
    background: #fff5f5;
    animation: otp-shake 0.35s ease;
}
.otp-digit-sep {
    font-size: 1.5rem;
    font-weight: 400;
    color: var(--text-muted);
    user-select: none;
    margin: 0 2px;
}
@keyframes otp-shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-4px); }
    40% { transform: translateX(4px); }
    60% { transform: translateX(-3px); }
    80% { transform: translateX(3px); }
}

.otp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 6px rgba(73, 80, 186, 0.15);
}
.otp-btn--primary {
    background: var(--blue);
    color: #fff;
}
.otp-btn--primary:hover {
    background: var(--purple);
    box-shadow: 0 6px 12px rgba(160, 88, 174, 0.25);
    transform: translateY(-1px);
}
.otp-btn--primary:active {
    transform: translateY(0);
}
.otp-btn--primary:disabled {
    background: var(--text-muted);
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
    opacity: 0.7;
}
.otp-btn--full {
    width: 100%;
}

.otp-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 20px;
    gap: 12px;
}
.otp-link-btn {
    background: none;
    border: none;
    color: var(--text-secondary);
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    padding: 8px 12px;
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.otp-link-btn:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
}
.otp-link-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    background: none;
}
.otp-timer {
    font-weight: 700;
    color: var(--blue);
}

.otp-alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 0.88rem;
    font-weight: 600;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.otp-alert--error {
    background: #fff5f5;
    border: 1.5px solid #feb2b2;
    color: #e53e3e;
}
.otp-spinner {
    width: 18px;
    height: 18px;
    border: 2.5px solid rgba(255, 255, 255, 0.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: otp-spin 0.7s linear infinite;
    display: inline-block;
    flex-shrink: 0;
}
@keyframes otp-spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 480px) {
    .otp-modal-content {
        padding: 28px 20px;
    }
    .otp-digit {
        width: 38px;
        height: 48px;
        font-size: 1.35rem;
        border-radius: 8px;
    }
    .otp-digits {
        gap: 4px;
    }
    .otp-digit-sep {
        margin: 0;
    }
    .otp-actions {
        flex-direction: column;
        gap: 4px;
    }
    .otp-link-btn {
        width: 100%;
        justify-content: center;
    }
}
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

<script>
// State for OTP Modal
var otpChannel = ''; // 'sms' or 'email'
var otpPurpose = ''; // 'verify_email' or 'verify_phone'
var otpTargetValue = ''; // the phone number or email being verified
var modalResendTimer = null;

function togglePhoneEdit() {
    const input = document.getElementById('phoneDisplay');
    const actionBtn = document.getElementById('phoneActionBtn');
    const verifyBtn = document.getElementById('phoneVerifyBtn');
    const container = input.closest('.phone-input-container');
    
    if (input.readOnly) {
        // Switch to edit mode
        input.readOnly = false;
        input.focus();
        actionBtn.textContent = 'Cancel';
        container.style.background = 'var(--bg-primary)';
        container.style.borderColor = 'var(--blue)';
        
        // Hide standard verify button if any, and show Verify & Save button
        if (verifyBtn) verifyBtn.style.display = 'none';
        
        // Add or show a custom "Save" button if not already there
        let saveBtn = document.getElementById('phoneSaveBtn');
        if (!saveBtn) {
            saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.id = 'phoneSaveBtn';
            saveBtn.className = 'verify-btn';
            saveBtn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Verify & Save';
            saveBtn.onclick = startPhoneVerification;
            document.getElementById('phoneActionsContainer').appendChild(saveBtn);
        } else {
            saveBtn.style.display = 'inline-flex';
        }
    } else {
        // Cancel edit mode, restore original value
        input.readOnly = true;
        input.value = "<?php 
            $rawPhone = preg_replace('/\D/', '', !empty($user['phone']) ? $user['phone'] : ($user['mobile_number'] ?? ''));
            echo sanitize(substr($rawPhone, -10)); 
        ?>";
        actionBtn.textContent = 'Change';
        container.style.background = 'var(--bg-tertiary)';
        container.style.borderColor = 'var(--border-color)';
        
        if (verifyBtn) verifyBtn.style.display = 'inline-flex';
        const saveBtn = document.getElementById('phoneSaveBtn');
        if (saveBtn) saveBtn.style.display = 'none';
    }
}

function startEmailVerification() {
    otpChannel = 'email';
    otpPurpose = 'verify_email';
    otpTargetValue = "<?php echo sanitize($user['email']); ?>";
    
    sendModalOtp();
}

function startPhoneVerification() {
    const input = document.getElementById('phoneDisplay');
    const mobile = input.value.replace(/\D/g, '');
    if (mobile.length !== 10) {
        alert('Please enter a valid 10-digit mobile number.');
        return;
    }
    
    otpChannel = 'sms';
    otpPurpose = 'verify_phone';
    otpTargetValue = mobile;
    
    sendModalOtp();
}

function sendModalOtp() {
    const errDiv = document.getElementById('otpError');
    if (errDiv) errDiv.style.display = 'none';
    
    const verifyBtn = document.getElementById('phoneVerifyBtn') || document.getElementById('phoneSaveBtn');
    if (verifyBtn) verifyBtn.disabled = true;
    
    let postData = {
        action: 'send',
        purpose: otpPurpose,
        channel: otpChannel
    };
    if (otpChannel === 'sms') {
        postData.mobile = otpTargetValue;
    } else {
        postData.email = otpTargetValue;
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/otp.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (tokenMeta) {
        xhr.setRequestHeader('X-CSRF-Token', tokenMeta.getAttribute('content'));
    }
    
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (verifyBtn) verifyBtn.disabled = false;
        
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    openOtpModal();
                } else {
                    alert(res.message || 'Error sending OTP. Please try again.');
                }
            } catch (e) {
                alert('Invalid response from server.');
            }
        } else {
            alert('Network error. Please try again.');
        }
    };
    xhr.send(JSON.stringify(postData));
}

function openOtpModal() {
    document.getElementById('otpModalTitle').textContent = otpChannel === 'email' ? 'Verify Email Address' : 'Verify Mobile Number';
    document.getElementById('otpModalSub').textContent = 'Enter the 6-digit verification code sent to ' + (otpChannel === 'email' ? otpTargetValue : '+91 ' + otpTargetValue);
    document.getElementById('otpModal').classList.add('active');
    
    clearModalOtpBoxes();
    startModalResendTimer();
    
    // Focus the first box
    var first = document.querySelector('#modalOtpDigits .otp-digit');
    if (first) setTimeout(function() { first.focus(); }, 100);
}

function closeOtpModal() {
    document.getElementById('otpModal').classList.remove('active');
    clearInterval(modalResendTimer);
}

function clearModalOtpBoxes() {
    document.querySelectorAll('#modalOtpDigits .otp-digit').forEach(function (b) {
        b.value = ''; b.classList.remove('filled', 'error');
    });
}

function getModalOtpValue() {
    var val = '';
    document.querySelectorAll('#modalOtpDigits .otp-digit').forEach(function (b) { val += b.value || ''; });
    return val.replace(/\D/g, '');
}

function shakeModalOtpBoxes() {
    var boxes = document.querySelectorAll('#modalOtpDigits .otp-digit');
    boxes.forEach(function (b) { b.classList.remove('error'); void b.offsetWidth; b.classList.add('error'); });
    setTimeout(function () { boxes.forEach(function (b) { b.classList.remove('error'); }); }, 500);
}

function initModalOtpDigitBoxes() {
    var boxes = Array.from(document.querySelectorAll('#modalOtpDigits .otp-digit'));
    boxes.forEach(function (box, idx) {
        box.addEventListener('input', function () {
            var v = box.value.replace(/\D/g, '');
            box.value = v.slice(-1);
            box.classList.toggle('filled', box.value !== '');
            if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
            if (getModalOtpValue().length === 6) { setTimeout(submitModalOtp, 80); }
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace') {
                if (!box.value && idx > 0) { boxes[idx-1].value = ''; boxes[idx-1].classList.remove('filled'); boxes[idx-1].focus(); }
            } else if (e.key === 'ArrowLeft'  && idx > 0)              boxes[idx-1].focus();
              else if (e.key === 'ArrowRight' && idx < boxes.length-1) boxes[idx+1].focus();
              else if (e.key === 'Enter')                                submitModalOtp();
        });
        box.addEventListener('paste', function (e) {
            var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            if (!pasted) return;
            e.preventDefault();
            for (var i = 0; i < boxes.length && i < pasted.length; i++) { boxes[i].value = pasted[i]; boxes[i].classList.add('filled'); }
            boxes[Math.min(pasted.length, boxes.length-1)].focus();
            if (pasted.length >= 6) { setTimeout(submitModalOtp, 80); }
        });
        box.addEventListener('click', function () { box.select(); });
    });
}

function startModalResendTimer() {
    var btn = document.getElementById('modalResendBtn');
    var timer = document.getElementById('modalTimer');
    var secs = 60;
    btn.disabled = true;
    timer.textContent = '(' + secs + 's)';
    clearInterval(modalResendTimer);
    modalResendTimer = setInterval(function () {
        secs--;
        timer.textContent = secs > 0 ? '(' + secs + 's)' : '';
        if (secs <= 0) { clearInterval(modalResendTimer); btn.disabled = false; }
    }, 1000);
}

function resendModalOtp() {
    sendModalOtp();
}

function submitModalOtp() {
    const errDiv = document.getElementById('otpError');
    if (errDiv) errDiv.style.display = 'none';
    
    const otp = getModalOtpValue();
    if (otp.length !== 6) {
        shakeModalOtpBoxes();
        showModalErr('Please enter all 6 digits of the OTP.');
        return;
    }
    
    setModalBtnState(true);
    
    let postData = {
        action: 'verify',
        purpose: otpPurpose,
        channel: otpChannel,
        otp: otp
    };
    if (otpChannel === 'sms') {
        postData.mobile = otpTargetValue;
    } else {
        postData.email = otpTargetValue;
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/otp.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (tokenMeta) {
        xhr.setRequestHeader('X-CSRF-Token', tokenMeta.getAttribute('content'));
    }
    
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        setModalBtnState(false);
        
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    closeOtpModal();
                    
                    // Immediately update DOM before alert/reload
                    if (otpPurpose === 'verify_email') {
                        const emailBadge = document.getElementById('emailBadge');
                        if (emailBadge) {
                            emailBadge.className = 'verification-badge verified';
                            emailBadge.innerHTML = '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg> Verified';
                        }
                        const emailVerifyBtn = document.getElementById('emailVerifyBtn');
                        if (emailVerifyBtn) {
                            emailVerifyBtn.disabled = true;
                            emailVerifyBtn.style.display = 'none';
                        }
                    } else if (otpPurpose === 'verify_phone') {
                        const phoneBadge = document.getElementById('phoneBadge');
                        if (phoneBadge) {
                            phoneBadge.className = 'verification-badge verified';
                            phoneBadge.innerHTML = '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg> Verified';
                        }
                        const phoneVerifyBtn = document.getElementById('phoneVerifyBtn');
                        if (phoneVerifyBtn) {
                            phoneVerifyBtn.disabled = true;
                            phoneVerifyBtn.style.display = 'none';
                        }
                        const phoneActionBtn = document.getElementById('phoneActionBtn');
                        if (phoneActionBtn) {
                            phoneActionBtn.style.display = 'none';
                        }
                    }
                    
                    alert(res.message || 'Verified successfully!');
                    window.location.reload(); // reload to show verified state
                } else {
                    shakeModalOtpBoxes();
                    showModalErr(res.message || 'Verification failed. Please try again.');
                }
            } catch (e) {
                showModalErr('Invalid response from server.');
            }
        } else {
            showModalErr('Network error. Please try again.');
        }
    };
    xhr.send(JSON.stringify(postData));
}

function showModalErr(msg) {
    const errDiv = document.getElementById('otpError');
    if (errDiv) {
        errDiv.textContent = msg;
        errDiv.style.display = 'block';
    }
}

function setModalBtnState(loading) {
    const btn = document.getElementById('modalOtpVerifyBtn');
    const text = document.getElementById('otpVerifyBtnText');
    const spinner = document.getElementById('otpVerifySpinner');
    
    btn.disabled = loading;
    if (text) text.textContent = loading ? 'Verifying...' : 'Verify & Save';
    if (spinner) spinner.style.display = loading ? 'inline-block' : 'none';
}

// Initialize digit boxes and modal backdrop close on DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    initModalOtpDigitBoxes();
    
    const modal = document.getElementById('otpModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeOtpModal();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
