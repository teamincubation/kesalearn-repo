<?php
/**
 * KESA Learn - Site Constants
 */

// Site Info
define('SITE_NAME', 'KESA Learn');
$isLocalHost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '[::1]']) || (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0);
define('SITE_URL', $isLocalHost ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] : 'https://kesalearn.com');
define('SITE_EMAIL', 'hello@kesalearn.com');

// File Upload Paths
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// Upload Limits
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);      // 5MB
define('MAX_PROOF_SIZE', 10 * 1024 * 1024);     // 10MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

// Session Settings
define('SESSION_LIFETIME', 31536000);           // 1 year (persistent until manual logout)
define('REMEMBER_TOKEN_LIFETIME', 31536000);    // 1 year
define('CSRF_TOKEN_NAME', 'csrf_token');

// Rate Limiting
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);                // 15 minutes

// Password Reset
define('RESET_TOKEN_EXPIRY', 3600);             // 1 hour
define('EMAIL_VERIFY_EXPIRY', 86400);           // 24 hours

// Pagination
define('ITEMS_PER_PAGE', 12);
define('ADMIN_ITEMS_PER_PAGE', 20);

// KESA Brand Colors
define('COLOR_RED', '#e7404a');
define('COLOR_PURPLE', '#a058ae');
define('COLOR_BLUE', '#4950ba');
define('COLOR_YELLOW', '#f5cb39');

// Timezone Configuration
// IST (Indian Standard Time) = UTC+05:30
require_once __DIR__ . '/../includes/timezone.php';

// Email Settings (update with your SMTP details)
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'hello@kesalearn.com');
define('SMTP_PASS', '');                        // Set your email password
define('SMTP_FROM_NAME', 'KESA Learn');
define('SMTP_FROM_EMAIL', 'hello@kesalearn.com');
