<?php
session_start();
require_once '../config/database.php';

$token = $_GET['token'] ?? '';
$success_message = '';
$error_message = '';
$valid_token = false;
$user_data = null;

// Validate token
if (!empty($token)) {
    try {
        $stmt = $pdo->prepare("
            SELECT prt.*, au.username, au.email 
            FROM password_reset_tokens prt 
            JOIN admin_users au ON prt.user_id = au.id 
            WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used = 0
        ");
        $stmt->execute([$token]);
        $user_data = $stmt->fetch();
        
        if ($user_data) {
            $valid_token = true;
        } else {
            $error_message = 'Invalid or expired reset token. Please request a new password reset.';
        }
    } catch(PDOException $e) {
        $error_message = 'Database error occurred.';
        error_log("Token validation error: " . $e->getMessage());
    }
} else {
    $error_message = 'No reset token provided.';
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error_message = 'Please fill in all fields.';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'Password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } else {
        try {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE admin_users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_data['user_id']]);
            
            // Mark token as used
            $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE token = ?");
            $stmt->execute([$token]);
            
            $success_message = 'Password has been reset successfully! You can now login with your new password.';
            $valid_token = false; // Hide form after successful reset
            
        } catch(PDOException $e) {
            $error_message = 'Failed to reset password. Please try again.';
            error_log("Password reset error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | KESA Learning Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reset-container {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }

        .reset-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .reset-header h1 {
            color: #333;
            margin-bottom: 0.5rem;
        }

        .reset-header p {
            color: #666;
            font-size: 0.9rem;
        }

        .user-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .password-requirements {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
        }

        .back-links {
            text-align: center;
            margin-top: 1rem;
        }

        .back-links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
        }

        .back-links a:hover {
            text-decoration: underline;
        }

        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak { background: #dc3545; width: 25%; }
        .strength-fair { background: #ffc107; width: 50%; }
        .strength-good { background: #28a745; width: 75%; }
        .strength-strong { background: #007bff; width: 100%; }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-header">
            <h1>Reset Password</h1>
            <p>Enter your new password below</p>
        </div>

        <?php if ($success_message): ?>
            <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if ($valid_token && $user_data): ?>
            <div class="user-info">
                <strong>Resetting password for:</strong><br>
                <?php echo htmlspecialchars($user_data['username']); ?> (<?php echo htmlspecialchars($user_data['email']); ?>)
            </div>

            <form method="POST" id="resetForm">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required>
                    <div class="password-requirements">Minimum 6 characters</div>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="strengthBar"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" class="btn">Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="back-links">
            <a href="login.php">← Back to Login</a>
            <?php if (!$valid_token): ?>
                <a href="forgot-password.php">Request New Reset</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strengthBar');
            
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
            if (password.match(/\d/)) strength++;
            if (password.match(/[^a-zA-Z\d]/)) strength++;
            
            strengthBar.className = 'password-strength-bar';
            if (strength === 1) strengthBar.classList.add('strength-weak');
            else if (strength === 2) strengthBar.classList.add('strength-fair');
            else if (strength === 3) strengthBar.classList.add('strength-good');
            else if (strength === 4) strengthBar.classList.add('strength-strong');
        });

        // Password confirmation validation
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>
