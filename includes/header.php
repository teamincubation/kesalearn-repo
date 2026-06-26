<?php
/**
 * KESA Learn - Common Header
 */
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/functions.php';
}

// Track user visits
require_once __DIR__ . '/visit_tracker.php';

// Trigger automatic email processing (runs once per minute max)
// This sends any pending verification emails without blocking the page
if (rand(1, 100) <= 5) { // 5% chance to trigger, keeps server load low
    $lockFile = sys_get_temp_dir() . '/kesa-email-worker.lock';
    $lockTime = file_exists($lockFile) ? filemtime($lockFile) : 0;
    
    // Only run if last run was more than 60 seconds ago
    if (time() - $lockTime > 60) {
        // Update lock file
        touch($lockFile);
        
        // Trigger worker asynchronously in background
        $workerUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/worker/send-verification-emails.php?cron_key=auto';
        
        // Non-blocking async request (doesn't wait for response)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $workerUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            @curl_exec($ch);
            curl_close($ch);
        }
    }
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo getSiteContent('site_description', 'KESA Learn - Knowledge Enhancement & Skill Acquisition Program'); ?>">
    <meta name="theme-color" content="#e7404a">
    <meta name="csrf-token" content="<?php echo generateCSRFToken(); ?>">
    
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' - ' . SITE_NAME : SITE_NAME; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/images/favicon.png">
    <link rel="apple-touch-icon" href="/assets/images/favicon.png">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans+Flex:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/enhancements.css">
    <?php if (isset($extraCSS)): ?>
        <?php foreach ((array)$extraCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar" role="navigation" aria-label="Main navigation">
        <div class="container">
            <a href="/" class="nav-logo" aria-label="KESA Learn Home">
                <img src="/assets/images/kesa_logo.png" alt="KESA Learn" height="40">
            </a>
            
            <ul class="nav-links" id="navLinks">
                <li><a href="/" class="<?php echo $currentPage === 'index' && $currentDir !== 'events' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="/events/" class="<?php echo $currentDir === 'events' ? 'active' : ''; ?>">Events</a></li>
                <li><a href="/#about">About</a></li>
                <li><a href="/certificate/search" class="<?php echo $currentDir === 'certificate' ? 'active' : ''; ?>">Certificate</a></li>
                <li><a href="/#contact">Contact</a></li>
                <?php if (isLoggedIn()): ?>
                    <li><a href="/user/profile" class="<?php echo $currentPage === 'profile' ? 'active' : ''; ?>">Profile</a></li>
                <?php endif; ?>
            </ul>
            
            <div class="nav-actions">
                <?php if (isLoggedIn()): ?>
                    <?php if (isAdmin()): ?>
                        <a href="/admin/" class="btn btn-sm btn-secondary">Admin</a>
                    <?php endif; ?>
                    <a href="/user/dashboard" class="btn btn-sm btn-secondary">My Dashboard</a>
                <?php else: ?>
                    <a href="/auth/login" class="btn btn-sm btn-secondary">Log In</a>
                    <a href="/auth/signup" class="btn btn-sm btn-primary">Sign Up</a>
                <?php endif; ?>
            </div>
            
            <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </nav>
    
    <!-- Flash Messages -->
    <?php $flashMessage = displayFlash(); ?>
    <?php if (!empty($flashMessage)): ?>
    <div class="container" style="padding-top: 80px; padding-bottom: 0;">
        <?php echo $flashMessage; ?>
    </div>
    <?php endif; ?>
    
    <main>
