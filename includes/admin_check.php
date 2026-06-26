<?php
/**
 * KESA Learn - Admin Auth Middleware
 * Include this file at the top of any admin page.
 */

require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    setFlash('error', 'Please log in to access this page.');
    redirect('/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
}

if (!isAdmin()) {
    setFlash('error', 'You do not have permission to access this page.');
    redirect('/');
}

// Auto-create admin_permissions table if not exists
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS admin_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        section VARCHAR(50) NOT NULL,
        can_access TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_section (user_id, section)
    )");
} catch (PDOException $e) {
    // Table might already exist or other error - continue
}

/**
 * Check if current admin has permission to access a section
 * Call this function after setting $adminPage variable
 */
function checkSectionPermission(string $section): void {
    if (!hasAdminPermission($section)) {
        // Show restricted page instead of redirect
        global $adminPage, $pageTitle;
        $pageTitle = 'Access Restricted';
        include __DIR__ . '/../admin/includes/sidebar.php';
        echo renderRestrictedOverlay();
        include __DIR__ . '/../admin/includes/footer.php';
        exit;
    }
}
