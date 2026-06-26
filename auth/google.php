<?php
/**
 * KESA Learn - Google OAuth Handler
 * Handles Google Sign-In/Sign-Up
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_tracker.php';

// Load Google OAuth Configuration
if (file_exists(__DIR__ . '/../includes/google_config.php')) {
    require_once __DIR__ . '/../includes/google_config.php';
}

// Google OAuth Configuration
$googleClientId = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : (getenv('GOOGLE_CLIENT_ID') ?: '');
$googleClientSecret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : (getenv('GOOGLE_CLIENT_SECRET') ?: '');

// Build proper redirect URI with full domain
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$redirectUri = $protocol . '://' . $host . '/auth/google';

// Log redirect URI for debugging
error_log("[Google OAuth] Using redirect URI: " . $redirectUri);

if (empty($googleClientId) || empty($googleClientSecret)) {
    error_log("[Google OAuth] Missing configuration - Client ID or Secret not set");
    setFlash('error', 'Google Sign-In is not configured. Please contact support.');
    redirect('/auth/login');
    exit;
}

// Step 1: Redirect to Google for authorization
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $googleClientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account'
    ]);
    
    header('Location: ' . $authUrl);
    exit;
}

// Handle errors from Google
if (isset($_GET['error'])) {
    $error = $_GET['error'];
    if ($error === 'access_denied') {
        setFlash('info', 'Google Sign-In was cancelled.');
    } else {
        setFlash('error', 'Google Sign-In failed: ' . sanitize($error));
    }
    redirect('/auth/login');
    exit;
}

// Step 2: Exchange code for access token
if (isset($_GET['code'])) {
    // Verify state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['google_oauth_state']) || $_GET['state'] !== $_SESSION['google_oauth_state']) {
        setFlash('error', 'Invalid authentication state. Please try again.');
        redirect('/auth/login');
        exit;
    }
    unset($_SESSION['google_oauth_state']);
    
    $code = $_GET['code'];
    
    // Exchange code for access token
    $tokenResponse = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'code' => $code,
                'client_id' => $googleClientId,
                'client_secret' => $googleClientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code'
            ])
        ]
    ]));
    
    if (!$tokenResponse) {
        error_log("[Google OAuth] Failed to get token response from Google API");
        setFlash('error', 'Failed to authenticate with Google. Please try again.');
        redirect('/auth/login');
        exit;
    }
    
    $tokenData = json_decode($tokenResponse, true);
    
    if (!isset($tokenData['access_token'])) {
        error_log("[Google OAuth] No access token in response. Response: " . substr($tokenResponse, 0, 500));
        // Check for error details
        if (isset($tokenData['error'])) {
            error_log("[Google OAuth] Error from Google: " . $tokenData['error'] . " - " . ($tokenData['error_description'] ?? ''));
        }
        setFlash('error', 'Failed to get access token from Google. Please try again.');
        redirect('/auth/login');
        exit;
    }
    
    error_log("[Google OAuth] Successfully obtained access token for user");
    
    // Get user info from Google
    $userInfoResponse = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, stream_context_create([
        'http' => [
            'header' => 'Authorization: Bearer ' . $tokenData['access_token']
        ]
    ]));
    
    if (!$userInfoResponse) {
        setFlash('error', 'Failed to get user information from Google.');
        redirect('/auth/login');
        exit;
    }
    
    $googleUser = json_decode($userInfoResponse, true);
    
    if (!isset($googleUser['email'])) {
        setFlash('error', 'Could not retrieve email from Google account.');
        redirect('/auth/login');
        exit;
    }
    
    $email = strtolower(trim($googleUser['email']));
    $name = $googleUser['name'] ?? '';
    $googleId = $googleUser['id'] ?? '';
    $picture = $googleUser['picture'] ?? '';
    
    // Check if user exists in database
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Existing user - update Google ID if not set
        if (empty($user['google_id'])) {
            $updateStmt = $db->prepare("UPDATE users SET google_id = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$googleId, $user['id']]);
        }
        
        // Check if this is a placeholder account - upgrade it
        if (!empty($user['is_placeholder']) && $user['is_placeholder'] == 1) {
            $updateStmt = $db->prepare("UPDATE users SET name = ?, google_id = ?, is_placeholder = 0, email_verified = 1, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$name, $googleId, $user['id']]);
        }
        
        $userId = $user['id'];
        $userName = $user['name'] ?: $name;
        $userRole = $user['role'];
        
        logActivity('google_login', "User logged in via Google: $email", $userId);
    } else {
        // New user - create account
        $stmt = $db->prepare("
            INSERT INTO users (name, email, google_id, email_verified, role, created_at, updated_at) 
            VALUES (?, ?, ?, 1, 'user', NOW(), NOW())
        ");
        $stmt->execute([$name, $email, $googleId]);
        $userId = $db->lastInsertId();
        $userName = $name;
        $userRole = 'user';
        
        logActivity('google_signup', "New user registered via Google: $email", $userId);
    }
    
    // Set session
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $userName;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $userRole;
    
    // Track login session - Single Device Login
    try {
        $tracker = new UserTracker($db);
        $loggedOutCount = $tracker->invalidateOtherSessions($userId);
        $tracker->createSession($userId);
        $tracker->recordLogin($userId, 'google', true);
    } catch (Exception $e) {}
    
    // Create remember token
    createRememberToken($userId);
    
    // Check if WhatsApp number has been collected
    $waStmt = $db->prepare("SELECT whatsapp_collected FROM users WHERE id = ?");
    $waStmt->execute([$userId]);
    $waRow = $waStmt->fetch();
    $needWhatsapp = empty($waRow['whatsapp_collected']);

    // Redirect based on role — add ?collect_whatsapp=1 if needed
    if ($userRole === 'admin') {
        setFlash('success', 'Welcome back, ' . $userName . '!');
        redirect('/admin/');
    } else {
        setFlash('success', 'Welcome, ' . $userName . '!');
        redirect($needWhatsapp ? '/user/dashboard?collect_whatsapp=1' : '/user/dashboard');
    }
    exit;
}
