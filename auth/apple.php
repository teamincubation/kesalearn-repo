<?php
/**
 * KESA Learn - Apple Sign-in Handler
 * Handles Apple Sign-In/Sign-Up
 */
require_once __DIR__ . '/../includes/functions.php';

// Load Apple OAuth Configuration
if (file_exists(__DIR__ . '/../includes/apple_config.php')) {
    require_once __DIR__ . '/../includes/apple_config.php';
}

// Apple OAuth Configuration
$appleTeamId = defined('APPLE_TEAM_ID') ? APPLE_TEAM_ID : (getenv('APPLE_TEAM_ID') ?: '');
$appleClientId = defined('APPLE_CLIENT_ID') ? APPLE_CLIENT_ID : (getenv('APPLE_CLIENT_ID') ?: '');
$appleKeyId = defined('APPLE_KEY_ID') ? APPLE_KEY_ID : (getenv('APPLE_KEY_ID') ?: '');

// Build proper redirect URI with full domain
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$redirectUri = $protocol . '://' . $host . '/auth/apple';

error_log("[Apple Sign-in] Using redirect URI: " . $redirectUri);

if (empty($appleTeamId) || empty($appleClientId) || empty($appleKeyId)) {
    error_log("[Apple Sign-in] Missing configuration - Team ID, Client ID, or Key ID not set");
    setFlash('error', 'Apple Sign-in is not configured. Please contact support.');
    redirect('/auth/login');
    exit;
}

// Step 1: Redirect to Apple for authorization
if (!isset($_POST['user']) && !isset($_GET['code']) && !isset($_GET['error'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['apple_oauth_state'] = $state;
    
    // Apple uses a hidden form POST for authorization
    $authUrl = 'https://appleid.apple.com/auth/authorize?' . http_build_query([
        'client_id' => $appleClientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code id_token',
        'response_mode' => 'form_post',
        'scope' => 'name email',
        'state' => $state,
    ]);
    
    header('Location: ' . $authUrl);
    exit;
}

// Handle errors from Apple
if (isset($_GET['error']) || (isset($_POST['error']))) {
    $error = $_GET['error'] ?? $_POST['error'] ?? 'unknown_error';
    if ($error === 'user_cancelled_login') {
        setFlash('info', 'Apple Sign-in was cancelled.');
    } else {
        setFlash('error', 'Apple Sign-in failed: ' . sanitize($error));
    }
    redirect('/auth/login');
    exit;
}

// Step 2: Handle Apple Sign-in response
if (isset($_POST['code']) || isset($_POST['id_token']) || isset($_POST['user'])) {
    
    // Verify state parameter
    $state = $_POST['state'] ?? $_GET['state'] ?? '';
    if (!isset($_SESSION['apple_oauth_state']) || $state !== $_SESSION['apple_oauth_state']) {
        setFlash('error', 'Invalid authentication state. Please try again.');
        redirect('/auth/login');
        exit;
    }
    unset($_SESSION['apple_oauth_state']);
    
    $code = $_POST['code'] ?? null;
    $idToken = $_POST['id_token'] ?? null;
    $userJson = $_POST['user'] ?? null;
    
    if (!$code) {
        setFlash('error', 'Missing authorization code from Apple.');
        redirect('/auth/login');
        exit;
    }
    
    // Generate client secret (JWT) for token exchange
    $clientSecret = generateAppleClientSecret($appleTeamId, $appleClientId, $appleKeyId);
    
    // Exchange code for tokens
    $tokenResponse = file_get_contents('https://appleid.apple.com/auth/token', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'client_id' => $appleClientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
        ],
    ]));
    
    if (!$tokenResponse) {
        error_log("[Apple Sign-in] Failed to get token response from Apple API");
        setFlash('error', 'Failed to authenticate with Apple. Please try again.');
        redirect('/auth/login');
        exit;
    }
    
    $tokenData = json_decode($tokenResponse, true);
    
    if (!isset($tokenData['id_token'])) {
        error_log("[Apple Sign-in] No ID token in response. Response: " . substr($tokenResponse, 0, 500));
        if (isset($tokenData['error'])) {
            error_log("[Apple Sign-in] Error from Apple: " . $tokenData['error']);
        }
        setFlash('error', 'Failed to authenticate with Apple. Please try again.');
        redirect('/auth/login');
        exit;
    }
    
    // Decode ID token (JWT) to get user info
    $parts = explode('.', $tokenData['id_token']);
    if (count($parts) !== 3) {
        error_log("[Apple Sign-in] Invalid JWT token format");
        setFlash('error', 'Invalid token from Apple. Please try again.');
        redirect('/auth/login');
        exit;
    }
    
    // Decode payload (second part)
    $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=')), true);
    
    if (!$payload || !isset($payload['email'])) {
        error_log("[Apple Sign-in] Could not extract email from ID token");
        setFlash('error', 'Could not get email from Apple. Please try again.');
        redirect('/auth/login');
        exit;
    }
    
    $appleEmail = $payload['email'];
    $appleId = $payload['sub']; // Unique Apple ID
    
    // Get user name from POST data (Apple sends it separately)
    $userName = '';
    if ($userJson) {
        $userData = json_decode($userJson, true);
        if (isset($userData['name'])) {
            if (isset($userData['name']['firstName']) && isset($userData['name']['lastName'])) {
                $userName = trim($userData['name']['firstName'] . ' ' . $userData['name']['lastName']);
            } elseif (isset($userData['name']['firstName'])) {
                $userName = $userData['name']['firstName'];
            }
        }
    }
    
    error_log("[Apple Sign-in] Successfully obtained ID token for user: " . $appleEmail);
    
    // Get database connection
    $db = getDB();
    
    // Check if user exists by email
    $stmt = $db->prepare("SELECT id, email, name FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$appleEmail]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        // User exists - log them in
        $_SESSION['user_id'] = $existingUser['id'];
        $_SESSION['user_email'] = $existingUser['email'];
        $_SESSION['user_name'] = $existingUser['name'];
        
        error_log("[Apple Sign-in] User logged in: " . $existingUser['id']);
        
        // Update last login
        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$existingUser['id']]);
        
        // Log activity
        logActivity('apple_signin', 'Signed in with Apple');
        
        setFlash('success', 'Welcome back! You are now signed in.');
        redirect('/user/dashboard');
        exit;
    } else {
        // New user - create account
        if (empty($userName)) {
            $userName = explode('@', $appleEmail)[0]; // Use email prefix as name
        }
        
        // Check if email is already registered through other means (shouldn't happen but be safe)
        $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkStmt->execute([$appleEmail]);
        
        if ($checkStmt->rowCount() > 0) {
            setFlash('error', 'This email is already registered. Please sign in instead.');
            redirect('/auth/login');
            exit;
        }
        
        // Create new user account
        $stmt = $db->prepare("
            INSERT INTO users (name, email, is_placeholder) 
            VALUES (?, ?, 0)
        ");
        
        try {
            $stmt->execute([$userName, $appleEmail]);
            $userId = $db->lastInsertId();
            
            // Set session
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_email'] = $appleEmail;
            $_SESSION['user_name'] = $userName;
            
            error_log("[Apple Sign-in] New user created: " . $userId);
            
            // Log activity
            logActivity('apple_signup', 'Signed up with Apple');
            
            setFlash('success', 'Account created successfully! Welcome to KESA Learn.');
            redirect('/user/dashboard');
            exit;
        } catch (PDOException $e) {
            error_log("[Apple Sign-in] Failed to create user: " . $e->getMessage());
            setFlash('error', 'Failed to create account. Please try again.');
            redirect('/auth/signup');
            exit;
        }
    }
    exit;
}

// No valid request
redirect('/auth/login');
exit;

/**
 * Generate Apple Client Secret (JWT)
 * Required for token exchange
 */
function generateAppleClientSecret($teamId, $clientId, $keyId) {
    $appleKeyPath = defined('APPLE_PRIVATE_KEY_PATH') ? APPLE_PRIVATE_KEY_PATH : '';
    
    // If private key doesn't exist, use a placeholder
    if (!file_exists($appleKeyPath)) {
        error_log("[Apple Sign-in] WARNING: Private key file not found. Apple Sign-in may not work.");
        // Return a dummy JWT for development
        return 'development_jwt_placeholder';
    }
    
    $privateKey = file_get_contents($appleKeyPath);
    
    $issuedAt = time();
    $expiresAt = $issuedAt + 15777000; // 6 months
    
    $header = base64_encode(json_encode([
        'alg' => 'ES256',
        'kid' => $keyId,
        'typ' => 'JWT',
    ]));
    
    $payload = base64_encode(json_encode([
        'iss' => $teamId,
        'iat' => $issuedAt,
        'exp' => $expiresAt,
        'aud' => 'https://appleid.apple.com',
        'sub' => $clientId,
    ]));
    
    $signInput = $header . '.' . $payload;
    
    // Note: Proper ES256 signing requires OpenSSL
    // This is a simplified version. For production, use a proper JWT library
    try {
        // Use openssl to sign (requires proper PHP OpenSSL extension)
        $signature = '';
        openssl_sign($signInput, $signature, $privateKey, 'sha256');
        $signature = base64_encode($signature);
        
        return $signInput . '.' . $signature;
    } catch (Exception $e) {
        error_log("[Apple Sign-in] Failed to generate JWT: " . $e->getMessage());
        return 'failed_jwt';
    }
}
