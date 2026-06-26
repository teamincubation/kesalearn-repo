<?php
/**
 * KESA Learn - Admin Sidebar Include
 */
$adminPage = $adminPage ?? '';
$user = getCurrentUser();
$db = getDB();
$pendingPayments = $db->query("SELECT COUNT(*) FROM registrations WHERE payment_status = 'pending' AND payment_method = 'upi'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo generateCSRFToken(); ?>">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' - Admin' : 'Admin Dashboard'; ?> | KESA Learn</title>
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/images/favicon.png">
    <link rel="apple-touch-icon" href="/assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans+Flex:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/enhancements.css">
    <?php if (isset($extraCSS)): foreach ((array)$extraCSS as $css): ?>
        <link rel="stylesheet" href="<?php echo $css; ?>">
    <?php endforeach; endif; ?>
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar Overlay (mobile) -->
        <div class="sidebar-overlay"></div>
        
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <a href="/admin/" class="sidebar-logo">
                    <span class="logo-font"><span style="color: #e7404a;">K</span><span style="color: #a058ae;">E</span><span style="color: #4950ba;">S</span><span style="color: #f5cb39;">A</span></span>
                    <span class="badge-admin">Admin</span>
                </a>
            </div>
            
            <nav class="sidebar-nav">
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Overview</div>
                    <a href="/admin/" class="sidebar-link <?php echo $adminPage === 'dashboard' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                        Dashboard
                    </a>
                    <?php if (hasAdminPermission('analytics')): ?>
                    <a href="/admin/analytics/" class="sidebar-link <?php echo $adminPage === 'analytics' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        Analytics
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Manage</div>
                    <?php if (hasAdminPermission('events')): ?>
                    <a href="/admin/events/" class="sidebar-link <?php echo $adminPage === 'events' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Events
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('registrations')): ?>
                    <a href="/admin/registrations/" class="sidebar-link <?php echo $adminPage === 'registrations' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Registrations
                        <?php 
                        $pendingRegistrations = $db->query("SELECT COUNT(*) FROM registrations WHERE payment_status = 'pending'")->fetchColumn();
                        if ($pendingRegistrations > 0): ?>
                            <span class="count-badge"><?php echo $pendingRegistrations; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('registrations')): ?>
                    <a href="/admin/payments/" class="sidebar-link <?php echo $adminPage === 'payments' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V5a3 3 0 00-3-3H6a3 3 0 00-3 3v11a3 3 0 003 3z"/></svg>
                        Razorpay Payments
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('users')): ?>
                    <a href="/admin/users/" class="sidebar-link <?php echo $adminPage === 'users' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/></svg>
                        Users
                    </a>
                    <a href="/admin/users/name-change-requests.php" class="sidebar-link <?php echo $adminPage === 'name_change_requests' ? 'active' : ''; ?>" style="margin-left: 16px; font-size: 0.9rem; opacity: 0.9;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Name Change Requests
                        <?php
                        try {
                            $pendingNCR = $db->query("SELECT COUNT(*) FROM name_change_requests WHERE status = 'pending'")->fetchColumn();
                            if ($pendingNCR > 0): ?>
                                <span class="count-badge"><?php echo $pendingNCR; ?></span>
                            <?php endif;
                        } catch (PDOException $e) {} ?>
                    </a>
                                        <?php endif; ?>
                    <?php if (hasAdminPermission('instructors')): ?>
                    <a href="/admin/instructors/" class="sidebar-link <?php echo $adminPage === 'instructors' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        Instructors
                    </a>
                    <a href="/admin/instructors/certificates" class="sidebar-link <?php echo $adminPage === 'instructor_certs' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Instructor Certificates
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('registrations')): ?>
                    <a href="/admin/coupons/" class="sidebar-link <?php echo $adminPage === 'coupons' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        Offers & Coupons
                        <?php
                        try {
                            $activeCoupons = $db->query("SELECT COUNT(*) FROM coupons WHERE is_active = 1")->fetchColumn();
                            if ($activeCoupons > 0): ?>
                                <span class="count-badge" style="background:#6366f1;"><?php echo $activeCoupons; ?></span>
                            <?php endif;
                        } catch (PDOException $e) {} ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('live_sessions')): ?>
                    <a href="/admin/live-sessions/" class="sidebar-link <?php echo $adminPage === 'live-sessions' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        Live Sessions
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('events')): ?>
                    <a href="/admin/assignments/" class="sidebar-link <?php echo $adminPage === 'assignments' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Assignments
                        <?php
                        try {
                            $pendingAssignments = $db->query("SELECT COUNT(*) FROM assignment_submissions WHERE status = 'pending'")->fetchColumn();
                            if ($pendingAssignments > 0): ?>
                                <span class="count-badge"><?php echo $pendingAssignments; ?></span>
                            <?php endif;
                        } catch (PDOException $e) {} ?>
                    </a>
                    <a href="/admin/assignments/review.php" class="sidebar-link <?php echo $adminPage === 'assignment-review' ? 'active' : ''; ?>" style="margin-left: 16px; font-size: 0.9rem; opacity: 0.9;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        Review Submissions
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Content</div>
                    <?php if (hasAdminPermission('announcements')): ?>
                    <a href="/admin/announcements/" class="sidebar-link <?php echo $adminPage === 'announcements' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                        Announcements
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('events')): ?>
                    <a href="/admin/reading-materials/" class="sidebar-link <?php echo $adminPage === 'reading_materials' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        Reading Materials
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('certificates')): ?>
                    <a href="/admin/certificates/" class="sidebar-link <?php echo $adminPage === 'certificates' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                        Certificates
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('banners')): ?>
                    <a href="/admin/banners/" class="sidebar-link <?php echo $adminPage === 'banners' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Banners
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('content')): ?>
                    <a href="/admin/content/" class="sidebar-link <?php echo $adminPage === 'content' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Site Content
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Engagement</div>
                    <?php if (hasAdminPermission('feedbacks')): ?>
                    <a href="/admin/feedbacks/" class="sidebar-link <?php echo $adminPage === 'feedbacks' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        Feedbacks
                        <?php
                        $pendingFeedbacks = $db->query("SELECT COUNT(*) FROM feedbacks WHERE is_approved = 0")->fetchColumn();
                        if ($pendingFeedbacks > 0): ?>
                            <span class="count-badge"><?php echo $pendingFeedbacks; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="sidebar-section">
                    <div class="sidebar-section-title">System</div>
                    <?php if (hasAdminPermission('maintenance')): ?>
                    <a href="/admin/maintenance/" class="sidebar-link <?php echo $adminPage === 'maintenance' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Maintenance
                        <?php
                        try {
                            $maintenanceActive = $db->query("SELECT is_active FROM maintenance_mode WHERE id = 1")->fetchColumn();
                            if ($maintenanceActive): ?>
                                <span class="count-badge" style="background: #ef4444;">ON</span>
                            <?php endif;
                        } catch (PDOException $e) {} ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('tools')): ?>
                    <a href="/admin/tools/sms-test" class="sidebar-link">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-4 4v-4z"/></svg>
                        SMS / OTP Test
                    </a>
                    <a href="/admin/tools/fix-placeholder-users" class="sidebar-link <?php echo $adminPage === 'tools' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Tools
                    </a>
                    <a href="/admin/tools/run-setup.php" class="sidebar-link" style="margin-left:16px;font-size:0.9rem;opacity:0.9;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                        DB Setup / Migrate
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('settings')): ?>
                    <a href="/admin/settings/" class="sidebar-link <?php echo $adminPage === 'settings' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                        Settings
                    </a>
                    <?php endif; ?>
                    <?php if (hasAdminPermission('logs')): ?>
                    <a href="/admin/logs/" class="sidebar-link <?php echo $adminPage === 'logs' ? 'active' : ''; ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Activity Logs
                    </a>
                    <?php endif; ?>
                    <?php if (isSuperAdmin()): ?>
                    <a href="/admin/admin-management/" class="sidebar-link <?php echo $adminPage === 'admin_management' ? 'active' : ''; ?>" style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.1) 0%, rgba(245, 158, 11, 0.1) 100%); border-left: 3px solid #f59e0b;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Admin Management
                    </a>
                    <?php endif; ?>
                </div>
            </nav>
            
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?php echo strtoupper(substr($user['name'] ?? 'A', 0, 1)); ?></div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name"><?php echo sanitize($user['name'] ?? 'Admin'); ?></div>
                        <div class="sidebar-user-role">Administrator</div>
                    </div>
                </div>
                <div style="margin-top: 12px; display: flex; gap: 8px;">
                    <a href="/" class="btn btn-sm btn-secondary" style="flex:1; font-size: 0.8rem;">View Site</a>
                    <a href="/auth/logout" class="btn btn-sm btn-primary" style="flex:1; font-size: 0.8rem;">Logout</a>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <div class="admin-main">
            <div class="admin-topbar">
                <div class="admin-topbar-left">
                    <button class="sidebar-toggle" aria-label="Toggle sidebar">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h1><?php echo sanitize($pageTitle ?? 'Dashboard'); ?></h1>
                </div>
                <div class="admin-topbar-right">
                    <?php echo displayFlash(); ?>
                </div>
            </div>
            
            <div class="admin-content">
