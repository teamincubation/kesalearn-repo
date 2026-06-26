<?php
/**
 * KESA Learn - Maintenance Page
 * Beautiful, empathetic maintenance page with countdown
 */
require_once __DIR__ . '/config/database.php';

$db = getDB();

// Get maintenance settings
$settings = null;
try {
    $settings = $db->query("SELECT * FROM maintenance_mode WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table doesn't exist
}

$message = $settings['message'] ?? 'We are currently performing scheduled maintenance to improve your experience. We will be back shortly!';
$scheduledEnd = $settings['scheduled_end'] ?? null;
$hasCountdown = !empty($scheduledEnd) && strtotime($scheduledEnd) > time();

// Check if maintenance has ended (for auto-redirect)
$maintenanceEnded = false;
if (!empty($scheduledEnd) && strtotime($scheduledEnd) <= time()) {
    $maintenanceEnded = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We'll Be Right Back | KESA Learn</title>
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans+Flex:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --red: #e7404a;
            --purple: #a058ae;
            --blue: #4950ba;
            --yellow: #f5cb39;
        }
        
        body {
            font-family: 'Google Sans Flex', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        /* Animated Background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }
        
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.1;
            animation: float 20s infinite ease-in-out;
        }
        
        .shape-1 { width: 400px; height: 400px; background: #fff; top: -100px; left: -100px; animation-delay: 0s; }
        .shape-2 { width: 300px; height: 300px; background: var(--yellow); bottom: -50px; right: -50px; animation-delay: -5s; }
        .shape-3 { width: 200px; height: 200px; background: var(--red); top: 50%; left: 10%; animation-delay: -10s; }
        .shape-4 { width: 150px; height: 150px; background: var(--blue); top: 20%; right: 15%; animation-delay: -15s; }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }
            25% { transform: translate(30px, -30px) rotate(90deg) scale(1.1); }
            50% { transform: translate(-20px, 20px) rotate(180deg) scale(0.9); }
            75% { transform: translate(20px, 10px) rotate(270deg) scale(1.05); }
        }
        
        /* Main Container */
        .maintenance-container {
            position: relative;
            z-index: 10;
            text-align: center;
            padding: 40px;
            max-width: 700px;
            width: 90%;
        }
        
        /* Logo */
        .logo {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 30px;
            animation: logoEntrance 1s ease-out;
        }
        
        .logo span:nth-child(1) { color: var(--red); }
        .logo span:nth-child(2) { color: var(--purple); }
        .logo span:nth-child(3) { color: var(--blue); }
        .logo span:nth-child(4) { color: var(--yellow); }
        
        @keyframes logoEntrance {
            from { transform: scale(0.5); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        
        /* Icon Animation */
        .maintenance-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: iconPulse 2s infinite ease-in-out;
            backdrop-filter: blur(10px);
        }
        
        .maintenance-icon svg {
            width: 60px;
            height: 60px;
            color: white;
            animation: iconSpin 8s linear infinite;
        }
        
        @keyframes iconPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255,255,255,0.4); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(255,255,255,0); }
        }
        
        @keyframes iconSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Content Card */
        .content-card {
            background: rgba(255,255,255,0.95);
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.15);
            backdrop-filter: blur(20px);
            animation: cardSlideUp 0.8s ease-out 0.3s both;
        }
        
        @keyframes cardSlideUp {
            from { transform: translateY(40px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .content-card h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 16px;
            line-height: 1.3;
        }
        
        .content-card p {
            font-size: 1.1rem;
            color: #555;
            line-height: 1.7;
            margin-bottom: 30px;
        }
        
        /* Countdown */
        .countdown-section {
            margin: 30px 0;
        }
        
        .countdown-label {
            font-size: 0.9rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 16px;
        }
        
        .countdown {
            display: flex;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .countdown-item {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 20px 24px;
            min-width: 90px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            transition: transform 0.3s ease;
        }
        
        .countdown-item:hover {
            transform: translateY(-5px);
        }
        
        .countdown-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            line-height: 1;
        }
        
        .countdown-unit {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.8);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 8px;
        }
        
        /* Thank You Message (shown after countdown) */
        .thank-you-message {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }
        
        .thank-you-message.show {
            display: block;
        }
        
        .thank-you-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: thankYouBounce 0.6s ease-out;
        }
        
        .thank-you-icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }
        
        @keyframes thankYouBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Heart Animation */
        .heart-container {
            margin: 30px 0 10px;
        }
        
        .heart {
            display: inline-block;
            animation: heartbeat 1.5s infinite;
        }
        
        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            25% { transform: scale(1.1); }
            50% { transform: scale(1); }
            75% { transform: scale(1.1); }
        }
        
        /* Progress Bar */
        .progress-container {
            margin: 30px 0;
        }
        
        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--red), var(--purple), var(--blue), var(--yellow));
            background-size: 300% 100%;
            border-radius: 8px;
            animation: progressShimmer 2s linear infinite;
            width: 60%;
        }
        
        @keyframes progressShimmer {
            0% { background-position: 0% 50%; }
            100% { background-position: 300% 50%; }
        }
        
        /* Footer */
        .footer-text {
            margin-top: 20px;
            font-size: 0.9rem;
            color: #888;
        }
        
        .footer-text a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer-text a:hover {
            text-decoration: underline;
        }
        
        /* Social Icons */
        .social-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 24px;
        }
        
        .social-link {
            width: 44px;
            height: 44px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: all 0.3s ease;
        }
        
        .social-link:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateY(-3px);
        }
        
        /* Responsive */
        @media (max-width: 600px) {
            .logo { font-size: 3rem; }
            .content-card { padding: 30px 24px; }
            .content-card h1 { font-size: 1.6rem; }
            .countdown-item { min-width: 70px; padding: 16px; }
            .countdown-number { font-size: 1.8rem; }
            .maintenance-icon { width: 90px; height: 90px; }
            .maintenance-icon svg { width: 45px; height: 45px; }
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="bg-animation">
        <div class="floating-shape shape-1"></div>
        <div class="floating-shape shape-2"></div>
        <div class="floating-shape shape-3"></div>
        <div class="floating-shape shape-4"></div>
    </div>
    
    <div class="maintenance-container">
        <!-- Logo -->
        <div class="logo">
            <span>K</span><span>E</span><span>S</span><span>A</span>
        </div>
        
        <!-- Maintenance Icon -->
        <div class="maintenance-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
        </div>
        
        <!-- Content Card -->
        <div class="content-card">
            <!-- Main Content (hidden when countdown ends) -->
            <div id="mainContent">
                <h1>We're Making Things Better!</h1>
                <p><?php echo nl2br(htmlspecialchars($message)); ?></p>
                
                <?php if ($hasCountdown): ?>
                <!-- Countdown Section -->
                <div class="countdown-section">
                    <div class="countdown-label">We'll be back in</div>
                    <div class="countdown" id="countdown">
                        <div class="countdown-item">
                            <div class="countdown-number" id="days">00</div>
                            <div class="countdown-unit">Days</div>
                        </div>
                        <div class="countdown-item">
                            <div class="countdown-number" id="hours">00</div>
                            <div class="countdown-unit">Hours</div>
                        </div>
                        <div class="countdown-item">
                            <div class="countdown-number" id="minutes">00</div>
                            <div class="countdown-unit">Minutes</div>
                        </div>
                        <div class="countdown-item">
                            <div class="countdown-number" id="seconds">00</div>
                            <div class="countdown-unit">Seconds</div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Progress Bar (when no countdown) -->
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Heart Animation -->
                <div class="heart-container">
                    <span class="heart" style="font-size: 2rem;">❤️</span>
                </div>
                <p style="margin-bottom: 0; font-size: 0.95rem; color: #888;">
                    Thank you for your patience and understanding.
                </p>
            </div>
            
            <!-- Thank You Message (shown after countdown ends) -->
            <div class="thank-you-message" id="thankYouMessage">
                <div class="thank-you-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h1>We're Back Online!</h1>
                <p>Thank you for waiting. We've made some improvements to serve you better.</p>
                <p style="font-size: 0.9rem; color: #888;">Redirecting you to the homepage...</p>
            </div>
            
            <!-- Footer -->
            <div class="footer-text">
                Need help? <a href="mailto:support@kesalearn.com">Contact Support</a>
            </div>
            
            <!-- Social Links -->
            <div class="social-links">
                <a href="#" class="social-link" title="Facebook">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                </a>
                <a href="#" class="social-link" title="Instagram">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                </a>
                <a href="#" class="social-link" title="YouTube">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                </a>
            </div>
        </div>
    </div>
    
    <?php if ($hasCountdown): ?>
    <script>
        const endTime = new Date('<?php echo date('Y-m-d H:i:s', strtotime($scheduledEnd)); ?>').getTime();
        
        function updateCountdown() {
            const now = new Date().getTime();
            const distance = endTime - now;
            
            if (distance < 0) {
                // Countdown finished
                document.getElementById('mainContent').style.display = 'none';
                document.getElementById('thankYouMessage').classList.add('show');
                
                // Redirect after 3 seconds
                setTimeout(function() {
                    window.location.href = '/';
                }, 3000);
                return;
            }
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            document.getElementById('days').textContent = String(days).padStart(2, '0');
            document.getElementById('hours').textContent = String(hours).padStart(2, '0');
            document.getElementById('minutes').textContent = String(minutes).padStart(2, '0');
            document.getElementById('seconds').textContent = String(seconds).padStart(2, '0');
        }
        
        // Update every second
        updateCountdown();
        setInterval(updateCountdown, 1000);
    </script>
    <?php endif; ?>
    
    <?php if ($maintenanceEnded): ?>
    <script>
        // Maintenance ended, show thank you and redirect
        document.getElementById('mainContent').style.display = 'none';
        document.getElementById('thankYouMessage').classList.add('show');
        
        setTimeout(function() {
            window.location.href = '/';
        }, 3000);
    </script>
    <?php endif; ?>
</body>
</html>
