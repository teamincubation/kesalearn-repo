<?php
/**
 * KESA Learn - Utility Functions
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

// Start session with long lifetime (persistent until manual logout)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Check if current session was invalidated (logged out from another device)
// Skip this check on login page to prevent race conditions
if (!defined('SKIP_SESSION_CHECKS') && isset($_SESSION['user_id']) && isset($_SESSION['tracking_session_id'])) {
    try {
        $db = getDB();
        $db->setAttribute(PDO::ATTR_TIMEOUT, 2); // 2 second timeout for this query
        $checkStmt = $db->prepare("SELECT is_active, logout_reason FROM user_sessions WHERE id = ? AND user_id = ?");
        
        if ($checkStmt && $checkStmt->execute([$_SESSION['tracking_session_id'], $_SESSION['user_id']])) {
            $sessionCheck = $checkStmt->fetch();
            
            if ($sessionCheck && !$sessionCheck['is_active']) {
                // Session was invalidated - force logout
                $logoutReason = $sessionCheck['logout_reason'];
                
                // Clear session
                session_unset();
                session_destroy();
                
                // Clear remember token cookie
                if (isset($_COOKIE['remember_token'])) {
                    setcookie('remember_token', '', time() - 3600, '/');
                }
                
                // Start new session for flash message
                session_start();
                
                if ($logoutReason === 'logged_in_from_another_device') {
                    $_SESSION['flash_message'] = 'You have been logged out because your account was signed in from another device.';
                    $_SESSION['flash_type'] = 'warning';
                }
                
                // Redirect to login
                header('Location: /auth/login');
                exit;
            }
        }
    } catch (Exception $e) {
        // Silently continue if check fails - this is critical for login page to work
        error_log("[SESSION_CHECK] Error: " . $e->getMessage());
    }
}

// Auto-login via remember token cookie
// Skip this on login page to prevent race conditions
if (!defined('SKIP_SESSION_CHECKS') && !isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    try {
        $token = $_COOKIE['remember_token'];
        $db = getDB();
        $db->setAttribute(PDO::ATTR_TIMEOUT, 2); // 2 second timeout for this query
        $stmt = $db->prepare("SELECT rt.user_id, u.name, u.email, u.role FROM remember_tokens rt JOIN users u ON rt.user_id = u.id WHERE rt.token_hash = ? AND rt.expires_at > NOW()");
        
        if ($stmt && $stmt->execute([hash('sha256', $token)])) {
            $row = $stmt->fetch();
            if ($row) {
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_email'] = $row['email'];
                $_SESSION['user_role'] = $row['role'];
            } else {
                setcookie('remember_token', '', time() - 3600, '/');
            }
        }
    } catch (Exception $e) {
        // Silently continue if remember token check fails
        error_log("[REMEMBER_TOKEN] Error: " . $e->getMessage());
    }
}

/* =====================================================
   CSRF Protection
   ===================================================== */

function generateCSRFToken(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCSRFToken(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST[CSRF_TOKEN_NAME] ?? '';
        if (empty($token) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
    }
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generateCSRFToken() . '">';
}

/* =====================================================
   Input Sanitization
   ===================================================== */

function sanitize(string $input): string {
    // Use ENT_NOQUOTES to prevent apostrophes from being encoded to &#039;
    // This is safe for HTML display as we're already escaping HTML special chars
    return htmlspecialchars(trim($input), ENT_NOQUOTES, 'UTF-8');
}

function sanitizeEmail(string $email): string {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

/**
 * Sanitize HTML content from rich text editors like TinyMCE
 * Allows formatting tags while preventing XSS attacks
 */
function sanitizeHTML(string $html): string {
    // If empty, return as is
    if (empty(trim($html))) {
        return '';
    }
    
    // Allowed HTML tags from TinyMCE output
    $allowed_tags = '<b><i><strong><em><u><s><strike><br><p><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote><pre><code><a><img><table><thead><tbody><tfoot><tr><td><th><span><div><hr><sup><sub><mark>';
    
    // Strip tags that aren't in the allowed list
    $html = strip_tags($html, $allowed_tags);
    
    // Remove dangerous attributes from remaining tags
    $html = preg_replace('/<(\w+)\s+(?:on\w+|javascript:|data:|vbscript:)[^>]*>/i', '<$1>', $html);
    
    // Remove any remaining event handlers
    $html = preg_replace('/\s*on\w+\s*=\s*["\']?[^"\'>\s]*["\']?/i', '', $html);
    
    return trim($html);
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/* =====================================================
   Authentication Helpers
   ===================================================== */

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin(): bool {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Check if site is in maintenance mode and redirect if necessary
 */
function checkMaintenanceMode(): void {
    // Skip maintenance check for admin pages, maintenance page, certificate pages, and auth
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, '/admin') === 0 || 
        strpos($requestUri, '/maintenance') === 0 ||
        strpos($requestUri, '/auth/') === 0 ||
        strpos($requestUri, '/certificate/') === 0) {
        return;
    }
    
    try {
        $db = getDB();
        $settings = $db->query("SELECT * FROM maintenance_mode WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings || !$settings['is_active']) {
            // Check if scheduled maintenance has started
            if ($settings && !empty($settings['scheduled_start']) && !empty($settings['scheduled_end'])) {
                $now = time();
                $start = strtotime($settings['scheduled_start']);
                $end = strtotime($settings['scheduled_end']);
                
                if ($now >= $start && $now < $end) {
                    // Scheduled maintenance is active
                    if ($settings['allow_admin_access'] && isAdmin()) {
                        return; // Allow admin access
                    }
                    header('Location: /maintenance.php');
                    exit;
                }
            }
            return;
        }
        
        // Maintenance is active
        if ($settings['allow_admin_access'] && isAdmin()) {
            return; // Allow admin access
        }
        
        // Check if scheduled end time has passed
        if (!empty($settings['scheduled_end']) && strtotime($settings['scheduled_end']) <= time()) {
            // Auto-disable maintenance mode
            $db->exec("UPDATE maintenance_mode SET is_active = 0 WHERE id = 1");
            return;
        }
        
        // Redirect to maintenance page
        header('Location: /maintenance.php');
        exit;
        
    } catch (PDOException $e) {
        // Table doesn't exist or error, continue normally
        return;
    }
}

/**
 * Require admin access - redirects if not admin
 */
function requireAdmin(): void {
    if (!isLoggedIn()) {
        setFlash('error', 'Please log in to access this page.');
        redirect('/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    }
    
    if (!isAdmin()) {
        setFlash('error', 'You do not have permission to access this page.');
        redirect('/');
    }
}

/**
 * Validate CSRF Token (alias for verifyCSRFToken for consistency)
 */
function validateCSRFToken(string $token): bool {
    return verifyCSRFToken($token);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    
    $db = getDB();
    try {
        // phone = WhatsApp number (single source of truth — whatsapp_number column removed)
        $stmt = $db->prepare("SELECT id, name, email, phone, dob, gender, country, state, district, city, college, role, profile_image, email_verified, certificate_name, certificate_name_verified_at, whatsapp_collected FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // Fallback: new columns not yet in DB (pre-migration) — fetch base columns only
        $stmt = $db->prepare("SELECT id, name, email, phone, dob, gender, country, state, district, city, college, role, profile_image, email_verified FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
    }
    return $row ?: null;
}

function getUserById(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, email, phone, dob, gender, country, state, district, city, college, role, profile_image, email_verified, created_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/* =====================================================
   Flash Messages
   ===================================================== */

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function displayFlash(): string {
    $flash = getFlash();
    if (!$flash) return '';
    
    $type = $flash['type'];
    $message = sanitize($flash['message']);
    $iconMap = [
        'success' => '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
        'error' => '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
        'warning' => '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.27 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
        'info' => '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
    ];
    $icon = $iconMap[$type] ?? $iconMap['info'];
    
    return '<div class="flash-message flash-' . $type . '">' . $icon . '<span>' . $message . '</span><button class="flash-close" onclick="this.parentElement.remove()">&times;</button></div>';
}

/* =====================================================
   File Upload Helpers
   ===================================================== */

function uploadFile(array $file, string $directory, array $allowedTypes = null, int $maxSize = null): array {
    $allowedTypes = $allowedTypes ?? ALLOWED_IMAGE_TYPES;
    $maxSize = $maxSize ?? MAX_IMAGE_SIZE;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed. Error code: ' . $file['error']];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File too large. Maximum size: ' . formatBytes($maxSize)];
    }
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedTypes)];
    }
    
    $uploadDir = UPLOAD_PATH . $directory . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('kesa_', true) . '.' . strtolower($extension);
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'path' => $directory . '/' . $filename];
    }
    
    return ['success' => false, 'error' => 'Failed to move uploaded file.'];
}

/* =====================================================
   Slug Generator
   ===================================================== */

function generateSlug(string $text): string {
    $slug = strtolower($text);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function uniqueSlug(string $text, string $table = 'events'): string {
    $db = getDB();
    $slug = generateSlug($text);
    $original = $slug;
    $counter = 1;
    
    while (true) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() == 0) break;
        $slug = $original . '-' . $counter++;
    }
    
    return $slug;
}

/* =====================================================
   Timezone & Date/Time Helpers (IST)
   ===================================================== */

// IST Timezone constant
define('IST_TIMEZONE', 'Asia/Kolkata');

/**
 * Get current DateTime object in IST
 */
function getISTDateTime(): DateTime {
    return new DateTime('now', new DateTimeZone(IST_TIMEZONE));
}

/**
 * Get current timestamp formatted for MySQL in IST
 */
function getISTTimestamp(): string {
    return getISTDateTime()->format('Y-m-d H:i:s');
}

/**
 * Get current date in IST (Y-m-d format)
 */
function getISTDate(): string {
    return getISTDateTime()->format('Y-m-d');
}

/**
 * Convert any datetime string to IST DateTime object
 */
function toIST(string $datetime): DateTime {
    $dt = new DateTime($datetime);
    $dt->setTimezone(new DateTimeZone(IST_TIMEZONE));
    return $dt;
}

/**
 * Format a datetime string to IST formatted output
 */
function formatToIST(string $datetime, string $format = 'M d, Y h:i A'): string {
    return toIST($datetime)->format($format);
}

/* =====================================================
   Formatting Helpers
   ===================================================== */

function formatDate(string $date, string $format = 'M d, Y'): string {
    // Ensure IST timezone is used
    $dt = new DateTime($date, new DateTimeZone(IST_TIMEZONE));
    return $dt->format($format);
}

function formatDateTime(string $datetime, string $format = 'M d, Y h:i A'): string {
    // Ensure IST timezone is used
    $dt = new DateTime($datetime, new DateTimeZone(IST_TIMEZONE));
    return $dt->format($format);
}

function formatPrice(float $amount, string $currency = 'INR'): string {
    if ($amount == 0) return 'Free';
    $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function timeAgo(string $datetime): string {
    $tz = new DateTimeZone(IST_TIMEZONE);
    $now = new DateTime('now', $tz);
    $ago = new DateTime($datetime, $tz);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

/* =====================================================
   Event Helpers
   ===================================================== */

function getEventStatusBadge(string $status): string {
    $badges = [
        'draft'     => '<span class="badge badge-draft">Draft</span>',
        'published' => '<span class="badge badge-published">Published</span>',
        'completed' => '<span class="badge badge-completed">Completed</span>',
        'cancelled' => '<span class="badge badge-cancelled">Cancelled</span>'
    ];
    return $badges[$status] ?? '<span class="badge">' . ucfirst($status) . '</span>';
}

function getPaymentStatusBadge(string $status): string {
    $badges = [
        'pending'  => '<span class="badge badge-warning">Pending</span>',
        'paid'     => '<span class="badge badge-info">Paid</span>',
        'verified' => '<span class="badge badge-success">Verified</span>',
        'rejected' => '<span class="badge badge-danger">Rejected</span>'
    ];
    return $badges[$status] ?? '<span class="badge">' . ucfirst($status) . '</span>';
}

function getEventTypeBadge(string $type): string {
    $badges = [
        'webinar'  => '<span class="badge badge-blue">Webinar</span>',
        'workshop' => '<span class="badge badge-purple">Workshop</span>',
        'offline'  => '<span class="badge badge-red">Offline</span>',
        'course'   => '<span class="badge badge-yellow">Course</span>'
    ];
    return $badges[$type] ?? '<span class="badge">' . ucfirst($type) . '</span>';
}

function isEventRegistrationOpen(array $event): bool {
    if ($event['status'] !== 'published') return false;
    
    // Use IST for all time comparisons
    $istTz = new DateTimeZone(IST_TIMEZONE);
    $now = new DateTime('now', $istTz);
    
    if ($event['registration_deadline']) {
        $deadline = new DateTime($event['registration_deadline'], $istTz);
        if ($deadline < $now) return false;
    }
    
    if ($event['max_seats'] && $event['seats_taken'] >= $event['max_seats']) return false;
    
    $startDate = new DateTime($event['start_date'], $istTz);
    if ($startDate < $now) return false;
    
    return true;
}

/* =====================================================
   Activity Logging
   ===================================================== */

function logActivity(string $action, string $details = '', ?int $userId = null): void {
    $db = getDB();
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $details, $ip]);
}

/* =====================================================
   Pagination Helper
   ===================================================== */

function paginate(int $total, int $perPage, int $currentPage, string $baseUrl): string {
    $totalPages = ceil($total / $perPage);
    if ($totalPages <= 1) return '';
    
    $html = '<div class="pagination">';
    
    if ($currentPage > 1) {
        $html .= '<a href="' . $baseUrl . '?page=' . ($currentPage - 1) . '" class="page-link">&laquo; Prev</a>';
    }
    
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $html .= '<a href="' . $baseUrl . '?page=1" class="page-link">1</a>';
        if ($start > 2) $html .= '<span class="page-dots">...</span>';
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<a href="' . $baseUrl . '?page=' . $i . '" class="page-link' . $active . '">' . $i . '</a>';
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<span class="page-dots">...</span>';
        $html .= '<a href="' . $baseUrl . '?page=' . $totalPages . '" class="page-link">' . $totalPages . '</a>';
    }
    
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . $baseUrl . '?page=' . ($currentPage + 1) . '" class="page-link">Next &raquo;</a>';
    }
    
    $html .= '</div>';
    return $html;
}

/* =====================================================
   Content Helpers
   ===================================================== */

function getSiteContent(string $key, string $default = ''): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT content_value FROM site_content WHERE content_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    return $result !== false ? $result : $default;
}

function truncateText(string $text, int $length = 150): string {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . '...';
}

/* =====================================================
   Certificate Code Generator
   ===================================================== */

function generateCertificateCode(int $eventId, int $userId): string {
    $year = date('Y');
    $code = sprintf('KESA-%s-%04d-%04d', $year, $eventId, $userId);
    return $code;
}

/* =====================================================
   Redirect Helper
   ===================================================== */

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function redirectBack(): void {
    $referer = $_SERVER['HTTP_REFERER'] ?? '/';
    redirect($referer);
}

/* =====================================================
   Super Admin & Permission Helpers
   ===================================================== */

// Super admin email - has full access to everything
define('SUPER_ADMIN_EMAIL', 'admin@kesalearn.com');

// All available admin sections
define('ADMIN_SECTIONS', [
    'dashboard' => 'Dashboard',
    'analytics' => 'Analytics',
    'events' => 'Events',
    'registrations' => 'Registrations',
    'users' => 'Users',
    'instructors' => 'Instructors',
    'live_sessions' => 'Live Sessions',
    'announcements' => 'Announcements',
    'certificates' => 'Certificates',
    'banners' => 'Banners',
    'content' => 'Site Content',
    'feedbacks' => 'Feedbacks',
    'ratings' => 'Ratings',
    'maintenance' => 'Maintenance',
    'tools' => 'Tools',
    'settings' => 'Settings',
    'logs' => 'Activity Logs',
    'admin_management' => 'Admin Management'
]);

/**
 * Check if current user is the super admin
 */
function isSuperAdmin(): bool {
    if (!isLoggedIn()) return false;
    return isset($_SESSION['user_email']) && strtolower($_SESSION['user_email']) === strtolower(SUPER_ADMIN_EMAIL);
}

/**
 * Check if user has permission to access a section
 */
function hasAdminPermission(string $section): bool {
    if (!isAdmin()) return false;
    if (isSuperAdmin()) return true; // Super admin has all permissions
    
    // Check permissions in database
    $db = getDB();
    $userId = $_SESSION['user_id'];
    
    // Ensure permissions table exists and create default permissions
    try {
        $stmt = $db->prepare("SELECT can_access FROM admin_permissions WHERE user_id = ? AND section = ?");
        $stmt->execute([$userId, $section]);
        $result = $stmt->fetch();
        
        if ($result === false) {
            // No permission record found - default to NO ACCESS for non-super admins
            // EXCEPT: Always allow dashboard access
            // This ensures new modules require explicit permission assignment
            return $section === 'dashboard';
        }
        
        return (bool) $result['can_access'];
    } catch (PDOException $e) {
        // Table doesn't exist yet - allow dashboard only
        return $section === 'dashboard';
    }
}

/**
 * Set permission for a user on a section
 */
function setAdminPermission(int $userId, string $section, bool $canAccess): bool {
    if (!isSuperAdmin()) return false;
    
    $db = getDB();
    try {
        $stmt = $db->prepare("INSERT INTO admin_permissions (user_id, section, can_access) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)");
        $stmt->execute([$userId, $section, $canAccess ? 1 : 0]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get all permissions for a user
 */
function getUserPermissions(int $userId): array {
    $db = getDB();
    $permissions = [];
    
    try {
        $stmt = $db->prepare("SELECT section, can_access FROM admin_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $permissions[$row['section']] = (bool) $row['can_access'];
        }
    } catch (PDOException $e) {
        // Table doesn't exist
    }
    
    return $permissions;
}

/**
 * Render restricted access overlay
 */
function renderRestrictedOverlay(): string {
    return '
    <div style="position: relative; min-height: 400px;">
        <div style="position: absolute; inset: 0; background: rgba(255,255,255,0.9); backdrop-filter: blur(8px); z-index: 100; display: flex; align-items: center; justify-content: center;">
            <div style="text-align: center; padding: 40px; max-width: 400px;">
                <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">
                    <svg width="40" height="40" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h2 style="color: #1e293b; margin: 0 0 12px 0; font-size: 1.5rem;">Access Restricted</h2>
                <p style="color: #64748b; margin: 0 0 20px 0; line-height: 1.6;">This area is restricted. Only the Super Administrator can access this section.</p>
                <a href="/admin/" class="btn btn-primary">Return to Dashboard</a>
            </div>
        </div>
        <div style="filter: blur(4px); opacity: 0.3; pointer-events: none; padding: 40px;">
            <div style="background: #f1f5f9; height: 60px; border-radius: 8px; margin-bottom: 16px;"></div>
            <div style="background: #f1f5f9; height: 200px; border-radius: 8px; margin-bottom: 16px;"></div>
            <div style="background: #f1f5f9; height: 100px; border-radius: 8px;"></div>
        </div>
    </div>';
}

/* =====================================================
   Site Settings Helpers
   ===================================================== */

function getSetting(string $key, string $default = ''): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    return $result !== false ? $result : $default;
}

function setSetting(string $key, string $value): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
        $result = $stmt->execute([$key, $value]);
        if (!$result) {
            throw new Exception("Failed to execute query for setting: $key");
        }
        error_log("[setSetting] Successfully saved $key = $value");
    } catch (Exception $e) {
        error_log("[setSetting] Error saving $key: " . $e->getMessage());
        throw $e;
    }
}

/* =====================================================
   Remember Me Token Helpers
   ===================================================== */

function createRememberToken(int $userId): void {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    // Use IST for expiry timestamp
    $expiresAt = getISTDateTime();
    $expiresAt->modify('+' . REMEMBER_TOKEN_LIFETIME . ' seconds');
    $expires = $expiresAt->format('Y-m-d H:i:s');
    
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $hash, $expires]);
    
    setcookie('remember_token', $token, time() + REMEMBER_TOKEN_LIFETIME, '/', '', true, true);
}

function clearRememberToken(): void {
    if (isset($_COOKIE['remember_token'])) {
        $db = getDB();
        $hash = hash('sha256', $_COOKIE['remember_token']);
        $db->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$hash]);
        setcookie('remember_token', '', time() - 3600, '/');
    }
}



/**
 * Queue verification badge approved email (async)
 */
function queueVerificationBadgeEmail($userId, $email, $name) {
    try {
        require_once __DIR__ . '/verification-email-service.php';
        global $verificationEmailService;
        if (!isset($verificationEmailService)) {
            $verificationEmailService = new VerificationEmailService();
        }
        return $verificationEmailService->queueBadgeApprovedEmail($userId, $email, $name);
    } catch (Exception $e) {
        error_log("[Verification Email] Queue error: " . $e->getMessage());
        return false;
    }
}

/**
 * Queue verification badge removed email (async)
 */
function queueVerificationRemovedEmail($userId, $email, $name) {
    try {
        require_once __DIR__ . '/verification-email-service.php';
        global $verificationEmailService;
        if (!isset($verificationEmailService)) {
            $verificationEmailService = new VerificationEmailService();
        }
        return $verificationEmailService->queueBadgeRemovedEmail($userId, $email, $name);
    } catch (Exception $e) {
        error_log("[Verification Email] Queue error: " . $e->getMessage());
        return false;
    }
}
