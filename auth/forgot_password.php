<?php
/**
 * KESA Learn - Forgot Password
 */
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) redirect('/user/dashboard');

$sent = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    }
    
    $email = sanitizeEmail($_POST['email'] ?? '');
    
    if (!validateEmail($email)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (empty($errors)) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Delete old tokens
            $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            
            // Generate new token (using IST for expiry)
            $token = bin2hex(random_bytes(32));
            $istTz = new DateTimeZone('Asia/Kolkata');
            $expiresDt = new DateTime('now', $istTz);
            $expiresDt->modify('+' . RESET_TOKEN_EXPIRY . ' seconds');
            $expires = $expiresDt->format('Y-m-d H:i:s');
            
            $stmt = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $token, $expires]);
            
            // Send email
            require_once __DIR__ . '/../includes/mailer.php';
            sendPasswordResetEmail($email, $token);
            
            logActivity('password_reset_request', "Password reset requested for: $email", $user['id']);
        }
        
        // Always show success (don't reveal if email exists)
        $sent = true;
    }
}

$pageTitle = 'Forgot Password';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <div class="logo">
                <span style="color: var(--red);">K</span><span style="color: var(--purple);">E</span><span style="color: var(--blue);">S</span><span style="color: var(--yellow);">A</span>
            </div>
            <h2>Reset Password</h2>
            <p>Enter your email to receive a password reset link</p>
        </div>
        
        <?php if ($sent): ?>
            <div class="flash-message flash-success">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>If an account with that email exists, we've sent a password reset link. Please check your inbox.</span>
            </div>
            <a href="/auth/login" class="btn btn-secondary btn-full">Back to Login</a>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="flash-message flash-error">
                    <?php echo sanitize($errors[0]); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" novalidate>
                <?php echo csrfField(); ?>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com" required autofocus>
                </div>
                
                <button type="submit" class="btn btn-primary btn-full btn-lg">Send Reset Link</button>
            </form>
            
            <div class="auth-footer">
                Remember your password? <a href="/auth/login">Log in</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
