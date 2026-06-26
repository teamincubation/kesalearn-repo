<?php
/**
 * KESA Learn - OTP API (rewritten)
 *
 * POST /api/otp.php  (JSON or form body)
 *   action  = send | verify | save_whatsapp
 *   channel = sms | email            (default sms)
 *   purpose = signup | login
 *   mobile  = 10-digit Indian number (sms channel)
 *   email   = email address          (email channel; also required on signup)
 *   name    = string                 (signup only)
 *   otp     = 6 digits               (verify only)
 *   whatsapp_same / whatsapp_number  (save_whatsapp)
 *
 * Notes
 *  - SMS goes through MSG91 v5 OTP endpoint with the DLT-approved KESA_OTP
 *    template (sender LABINC). Credentials live in /config/sms.php.
 *  - Duplicate accounts on one mobile number are resolved at next login via
 *    /auth/resolve_accounts.php (user keeps one email; others are deleted).
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/otp_service.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function jsonOut(bool $ok, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra));
    exit;
}

$raw   = file_get_contents('php://input');
$input = ($raw && ($j = json_decode($raw, true))) ? $j : $_POST;

$action  = trim($input['action'] ?? '');
$channel = ($input['channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
$purpose = in_array($input['purpose'] ?? '', ['signup', 'login', 'verify_email', 'verify_phone'], true) ? $input['purpose'] : 'login';
$mobile  = otpNormalizeMobile($input['mobile'] ?? '');
$email   = strtolower(trim($input['email'] ?? ''));

if (!in_array($action, ['send', 'verify', 'save_whatsapp'], true)) jsonOut(false, 'Unknown action.');

$db = getDB();

/** Find an account by mobile number (any stored format, last-10 match). */
function findUserByMobile(PDO $db, string $mobile): ?array {
    $st = $db->prepare(
        "SELECT * FROM users
         WHERE mobile_number = ? OR mobile_number = ? OR phone = ? OR phone = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]',''),10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(mobile_number,''),'[^0-9]',''),10) = ?
         ORDER BY id ASC LIMIT 1"
    );
    $st->execute([$mobile, '+91' . $mobile, $mobile, '+91' . $mobile, $mobile, $mobile]);
    $u = $st->fetch();
    return $u ?: null;
}

function findUserByEmail(PDO $db, string $email): ?array {
    $st = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

/* ── ACTION: send ───────────────────────────────────────────────────────── */
if ($action === 'send') {
    if (getSetting('otp_module_enabled', '1') !== '1') {
        jsonOut(false, 'OTP login is currently disabled. Please use Google Sign-In.');
    }

    if (in_array($purpose, ['verify_email', 'verify_phone'], true)) {
        if (!isLoggedIn()) {
            jsonOut(false, 'Not authenticated.');
        }
        if ($purpose === 'verify_email') {
            $email = $_SESSION['user_email'] ?? '';
            if (empty($email)) {
                jsonOut(false, 'Email address not found in session.');
            }
            $channel = 'email';
        }
        if ($purpose === 'verify_phone') {
            if ($mobile === '') {
                jsonOut(false, 'Enter a valid 10-digit mobile number.');
            }
            // Check if another user has verified this number
            $exists = findUserByMobile($db, $mobile);
            if ($exists && (int)$exists['id'] !== (int)$_SESSION['user_id']) {
                jsonOut(false, 'This mobile number is already linked to another account.');
            }
            $channel = 'sms';
        }
    }

    if ($channel === 'sms' && $mobile === '') jsonOut(false, 'Enter a valid 10-digit Indian mobile number.');
    if ($channel === 'email' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(false, 'Enter a valid email address.');

    if ($purpose === 'signup') {
        $name = trim($input['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) jsonOut(false, 'Full name is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(false, 'A valid email address is required.');
        if ($mobile === '') jsonOut(false, 'A valid mobile number is required.');

        if (findUserByEmail($db, $email)) {
            jsonOut(false, 'An account with this email already exists. Please log in instead.');
        }
        if (findUserByMobile($db, $mobile)) {
            jsonOut(false, 'An account with this mobile number already exists. Please log in instead.');
        }
        $_SESSION['otp_pending_signup'] = ['name' => $name, 'email' => $email, 'mobile' => $mobile];
    }

    if ($purpose === 'login') {
        $exists = $channel === 'sms' ? findUserByMobile($db, $mobile) : findUserByEmail($db, $email);
        if (!$exists) jsonOut(false, 'No account found. Please sign up first.');
    }

    $result = $channel === 'sms' ? sendSmsOtp($mobile, $purpose) : sendEmailOtp($email, $purpose);
    jsonOut($result['success'], $result['message']);
}

/* ── ACTION: verify ─────────────────────────────────────────────────────── */
if ($action === 'verify') {
    $entered = $input['otp'] ?? '';

    if (in_array($purpose, ['verify_email', 'verify_phone'], true)) {
        if (!isLoggedIn()) {
            jsonOut(false, 'Not authenticated.');
        }
        if ($purpose === 'verify_email') {
            $email = $_SESSION['user_email'] ?? '';
            $channel = 'email';
        }
        if ($purpose === 'verify_phone') {
            if ($mobile === '') {
                jsonOut(false, 'Invalid mobile number.');
            }
            $channel = 'sms';
        }
    }

    $result = $channel === 'sms'
        ? verifySmsOtp($mobile, $entered, $purpose)
        : verifyEmailOtp($email, $entered, $purpose);
    if (!$result['success']) jsonOut(false, $result['message']);

    if ($purpose === 'signup') {
        $pending = $_SESSION['otp_pending_signup'] ?? null;
        $matches = $pending && (($channel === 'sms' && $pending['mobile'] === $mobile)
                             || ($channel === 'email' && $pending['email'] === $email));
        if (!$matches) jsonOut(false, 'Session expired. Please start the sign-up process again.');

        // Race-safe: re-check uniqueness right before insert
        if (findUserByEmail($db, $pending['email']) || findUserByMobile($db, $pending['mobile'])) {
            jsonOut(false, 'An account with these details already exists. Please log in.');
        }

        $st = $db->prepare(
            "INSERT INTO users (name, email, mobile_number, password_hash, auth_method, email_verified, role, created_at, updated_at)
             VALUES (?, ?, ?, '', 'otp', ?, 'user', NOW(), NOW())"
        );
        $st->execute([$pending['name'], $pending['email'], $pending['mobile'], $channel === 'email' ? 1 : 0]);
        $userId   = (int)$db->lastInsertId();
        $userName = $pending['name'];
        $userRole = 'user';
        // Mark mobile verified if SMS channel proved it
        if ($channel === 'sms') {
            try { $db->prepare("UPDATE users SET mobile_verified_at = NOW() WHERE id = ?")->execute([$userId]); } catch (PDOException $e) {}
        }
        unset($_SESSION['otp_pending_signup']);
        logActivity('otp_signup', "New user via OTP ({$channel}): {$pending['email']} / +91{$pending['mobile']}", $userId);
    } elseif ($purpose === 'verify_email') {
        $userId = (int)$_SESSION['user_id'];
        $db->prepare("UPDATE users SET email_verified = 1, updated_at = NOW() WHERE id = ?")
           ->execute([$userId]);
        logActivity('email_verified', "User verified their email address via profile OTP verification", $userId);
        jsonOut(true, 'Email verified successfully.');
    } elseif ($purpose === 'verify_phone') {
        $userId = (int)$_SESSION['user_id'];
        $db->prepare("UPDATE users SET phone = ?, mobile_verified_at = NOW(), updated_at = NOW() WHERE id = ?")
           ->execute(['+91' . $mobile, $userId]);
        logActivity('phone_verified', "User updated and verified their phone number to +91$mobile via profile OTP verification", $userId);
        jsonOut(true, 'Mobile number verified and updated successfully.');
    } else {
        $user = $channel === 'sms' ? findUserByMobile($db, $mobile) : findUserByEmail($db, $email);
        if (!$user) jsonOut(false, 'Account not found. Please sign up first.');

        $userId   = (int)$user['id'];
        $userName = $user['name'];
        $userRole = $user['role'];

        if ($user['auth_method'] === 'google') {
            $db->prepare("UPDATE users SET auth_method = 'both' WHERE id = ?")->execute([$userId]);
        }
        if ($channel === 'sms') {
            try { $db->prepare("UPDATE users SET mobile_verified_at = COALESCE(mobile_verified_at, NOW()) WHERE id = ?")->execute([$userId]); } catch (PDOException $e) {}
        } else {
            $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$userId]);
        }
        logActivity('otp_login', "User logged in via OTP ({$channel})", $userId);
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $userId;
    $_SESSION['user_name'] = $userName;
    $_SESSION['user_role'] = $userRole;
    $emailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $emailStmt->execute([$userId]);
    $_SESSION['user_email'] = $emailStmt->fetchColumn() ?: '';

    createRememberToken($userId);

    try {
        require_once __DIR__ . '/../includes/user_tracker.php';
        $tracker = new UserTracker($db);
        $tracker->invalidateOtherSessions($userId);
        $tracker->createSession($userId);
        $tracker->recordLogin($userId, 'otp', true);
    } catch (Exception $e) {}

    $waSt = $db->prepare("SELECT whatsapp_collected FROM users WHERE id = ?");
    $waSt->execute([$userId]);
    $needWhatsapp = !(($waSt->fetch()['whatsapp_collected'] ?? 0));

    // Account gates (contact completion / merge approval) run on next page
    jsonOut(true, 'Verified successfully.', [
        'need_whatsapp' => $needWhatsapp,
        'redirect'      => $needWhatsapp ? '' : ($userRole === 'admin' ? '/admin/' : '/user/dashboard'),
    ]);
}

/* ── ACTION: save_whatsapp ──────────────────────────────────────────────── */
if ($action === 'save_whatsapp') {
    if (!isset($_SESSION['user_id'])) jsonOut(false, 'Not authenticated.');

    $userId = (int)$_SESSION['user_id'];
    $same   = ($input['whatsapp_same'] ?? '0') === '1';

    if ($same) {
        $uStmt = $db->prepare("SELECT mobile_number FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $waNumber = otpNormalizeMobile((string)($uStmt->fetchColumn() ?: ''));
    } else {
        $waNumber = otpNormalizeMobile($input['whatsapp_number'] ?? '');
    }
    if ($waNumber === '') jsonOut(false, 'Enter a valid 10-digit WhatsApp number.');

    $db->prepare("UPDATE users SET phone = ?, whatsapp_collected = 1, updated_at = NOW() WHERE id = ?")
       ->execute([$waNumber, $userId]);

    $role = $_SESSION['user_role'] ?? 'user';
    jsonOut(true, 'WhatsApp number saved.', ['redirect' => $role === 'admin' ? '/admin/' : '/user/dashboard']);
}
