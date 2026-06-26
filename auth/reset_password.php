<?php
/**
 * KESA Learn - Reset Password
 */
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['token'] ?? '';
$valid = false;
$errors = [];

if (empty($token)) {
    setFlash('error', 'Invalid reset link.');
    redirect('/auth/forgot_password');
}

// Verify token
$db = getDB();
$stmt = $db->prepare("SELECT pr.*, u.email FROM password_resets pr JOIN users u ON pr.user_id = u.id WHERE pr.token = ? AND pr.expires_at > NOW()");
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) {
    setFlash('error', 'This reset link has expired or is invalid. Please request a new one.');
    redirect('/auth/forgot_password');
}

$valid = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    }
    
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    
    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Update password
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, login_attempts = 0, locked_until = NULL WHERE id = ?");
        $stmt->execute([$passwordHash, $reset['user_id']]);
        
        // Delete used token
        $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->execute([$reset['user_id']]);
        
        logActivity('password_reset', "Password reset completed for user ID: {$reset['user_id']}", $reset['user_id']);
        
        setFlash('success', 'Password updated successfully! You can now log in.');
        redirect('/auth/login');
    }
}

$pageTitle = 'Reset Password';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <div class="logo">
                <span style="color: var(--red);">K</span><span style="color: var(--purple);">E</span><span style="color: var(--blue);">S</span><span style="color: var(--yellow);">A</span>
            </div>
            <h2>Set New Password</h2>
            <p>Create a new password for your account</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="flash-message flash-error">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" novalidate>
            <?php echo csrfField(); ?>
            
            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Minimum 8 characters" required>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter your password" required>
            </div>
            
            <button type="submit" class="btn btn-primary btn-full btn-lg">Update Password</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
