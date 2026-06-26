<?php
/**
 * KESA Learn - OTP Service
 *
 * Single source of truth for sending & verifying one-time passwords over
 * SMS (MSG91, DLT compliant template route) and Email.
 *
 * Tables used:
 *   phone_otps  (existing) - SMS OTPs, bcrypt-hashed
 *   email_otps  (new)      - Email OTPs, bcrypt-hashed
 *
 * All functions return ['success' => bool, 'message' => string, ...].
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sms.php';

/* =====================================================
   Normalisation helpers
   ===================================================== */

function otpNormalizeMobile(string $m): string {
    $m = preg_replace('/\D/', '', $m);
    if (strlen($m) === 12 && str_starts_with($m, '91')) $m = substr($m, 2);
    if (strlen($m) === 11 && str_starts_with($m, '0'))  $m = substr($m, 1);
    return strlen($m) === 10 ? $m : '';
}

/** Last 10 digits of any stored phone value - used for matching accounts. */
function phoneKey(?string $raw): string {
    $d = preg_replace('/\D/', '', (string)$raw);
    return strlen($d) >= 10 ? substr($d, -10) : '';
}

/* =====================================================
   Rate limiting
   ===================================================== */

function otpRateCheck(PDO $db, string $identifier, string $table): array {
    $col = $table === 'phone_otps' ? 'mobile' : 'email';

    // Resend cooldown
    $st = $db->prepare("SELECT created_at FROM {$table} WHERE {$col} = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$identifier]);
    $last = $st->fetchColumn();
    if ($last && (time() - strtotime($last)) < OTP_RESEND_COOLDOWN) {
        $wait = OTP_RESEND_COOLDOWN - (time() - strtotime($last));
        return ['success' => false, 'message' => "Please wait {$wait}s before requesting another code."];
    }

    // Per-identifier cap inside window
    $st = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = ? AND created_at > DATE_SUB(NOW(), INTERVAL " . (int)OTP_SEND_WINDOW . " SECOND)");
    $st->execute([$identifier]);
    if ((int)$st->fetchColumn() >= OTP_MAX_SENDS_WINDOW) {
        return ['success' => false, 'message' => 'Too many OTP requests. Please try again after a few minutes.'];
    }

    // Per-IP hourly cap (column added by migration; ignore if absent)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip) {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE request_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $st->execute([$ip]);
            if ((int)$st->fetchColumn() >= OTP_IP_HOURLY_CAP) {
                return ['success' => false, 'message' => 'Too many requests from this network. Please try again later.'];
            }
        } catch (PDOException $e) { /* column not migrated yet - skip IP cap */ }
    }

    return ['success' => true, 'message' => 'ok'];
}

/* =====================================================
   SMS OTP  (MSG91 v5 OTP API - uses your DLT template)
   ===================================================== */

/**
 * Sends the OTP through MSG91's dedicated OTP endpoint with your approved
 * DLT template (KESA_OTP). MSG91 fills ##OTP## in the template, signs the
 * message with sender LABINC and routes it under your DLT entity, so the
 * operators will not block it (the old sendhttp free-text route is the
 * reason SMS never arrived before).
 */
function sendSmsOtp(string $mobileRaw, string $purpose = 'login'): array {
    $mobile = otpNormalizeMobile($mobileRaw);
    if ($mobile === '') return ['success' => false, 'message' => 'Enter a valid 10-digit Indian mobile number.'];

    if (!defined('MSG91_AUTH_KEY') || str_starts_with(MSG91_AUTH_KEY, 'PASTE_')) {
        return ['success' => false, 'message' => 'OTP service is not configured. Please contact support.'];
    }

    $db = getDB();
    $rate = otpRateCheck($db, $mobile, 'phone_otps');
    if (!$rate['success']) return $rate;

    $otp = str_pad((string)random_int(0, 10 ** OTP_LENGTH - 1), OTP_LENGTH, '0', STR_PAD_LEFT);

    $payload = json_encode([
        'template_id' => MSG91_TEMPLATE_ID,
        'mobile'      => '91' . $mobile,
        'otp'         => $otp,
        'otp_expiry'  => (int)(OTP_EXPIRY_SECONDS / 60),
        'sender'      => MSG91_SENDER_ID,
    ]);

    $ch = curl_init('https://control.msg91.com/api/v5/otp');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'authkey: ' . MSG91_AUTH_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    smsLog($db, $mobile, 'v5/otp', $httpCode, $curlErr ?: (string)$response);

    if ($curlErr) {
        error_log('[OTP][SMS] cURL: ' . $curlErr);
        return ['success' => false, 'message' => 'Could not reach the SMS service. Please try again.'];
    }

    $data = json_decode((string)$response, true);
    $ok   = $httpCode === 200 && isset($data['type']) && strtolower($data['type']) === 'success';

    // Fallback: if the template is registered as a Flow (not an OTP-type)
    // template in MSG91, the dedicated OTP endpoint rejects it. The Flow
    // endpoint delivers the same DLT template with the ##OTP## variable.
    if (!$ok) {
        error_log("[OTP][SMS] v5/otp failed HTTP {$httpCode}: {$response} - trying v5/flow");
        $flowPayload = json_encode([
            'template_id' => MSG91_TEMPLATE_ID,
            'short_url'   => '0',
            'recipients'  => [[
                'mobiles' => '91' . $mobile,
                'otp'     => $otp,
                'OTP'     => $otp,
                'var'     => $otp,
                'VAR1'    => $otp,
            ]],
        ]);
        $ch = curl_init('https://control.msg91.com/api/v5/flow/');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $flowPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'accept: application/json',
                'authkey: ' . MSG91_AUTH_KEY,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        smsLog($db, $mobile, 'v5/flow', $httpCode, $curlErr ?: (string)$response);

        $data = json_decode((string)$response, true);
        $ok   = !$curlErr && $httpCode === 200 && isset($data['type']) && strtolower($data['type']) === 'success';
    }

    if (!$ok) {
        error_log("[OTP][SMS] MSG91 send failed HTTP {$httpCode}: {$response}");
        return ['success' => false, 'message' => 'Could not send the SMS right now. Please retry or use email OTP.'];
    }

    storeOtpRow($db, 'phone_otps', 'mobile', $mobile, $otp, $purpose);
    return ['success' => true, 'message' => 'OTP sent to +91 ' . substr($mobile, 0, 2) . 'XXXXXX' . substr($mobile, -2) . '.'];
}

function verifySmsOtp(string $mobileRaw, string $entered, string $purpose = 'login'): array {
    $mobile = otpNormalizeMobile($mobileRaw);
    if ($mobile === '') return ['success' => false, 'message' => 'Invalid mobile number.'];
    return verifyOtpRow(getDB(), 'phone_otps', 'mobile', $mobile, $entered, $purpose);
}

/* =====================================================
   Email OTP
   ===================================================== */

function sendEmailOtp(string $email, string $purpose = 'login'): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Enter a valid email address.'];
    }

    $db = getDB();
    $rate = otpRateCheck($db, $email, 'email_otps');
    if (!$rate['success']) return $rate;

    $otp = str_pad((string)random_int(0, 10 ** OTP_LENGTH - 1), OTP_LENGTH, '0', STR_PAD_LEFT);

    $subject = 'Your KESA Learn verification code';
    $minutes = (int)(OTP_EXPIRY_SECONDS / 60);
    $body = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;border:1px solid #eee;border-radius:12px">'
          . '<h2 style="color:#e7404a;margin:0 0 12px">KESA Learn</h2>'
          . '<p style="color:#333">Use this code to continue. It is valid for ' . $minutes . ' minutes.</p>'
          . '<div style="font-size:32px;font-weight:700;letter-spacing:8px;color:#1a1a2e;background:#fef0f0;padding:16px;text-align:center;border-radius:8px">' . $otp . '</div>'
          . '<p style="color:#777;font-size:13px;margin-top:16px">If you did not request this, you can safely ignore this email. Never share this code with anyone.</p>'
          . '</div>';

    $sent = false;
    $mailer = __DIR__ . '/mailer.php';
    if (is_file($mailer)) {
        require_once $mailer;
        if (function_exists('sendEmail')) $sent = sendEmail($email, $subject, $body);
    }
    if (!$sent) {
        $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'KESA Learn')
                  . ' <' . (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'hello@kesalearn.com') . ">\r\n";
        $sent = @mail($email, $subject, $body, $headers);
    }
    if (!$sent) return ['success' => false, 'message' => 'Could not send the email right now. Please retry or use SMS OTP.'];

    storeOtpRow($db, 'email_otps', 'email', $email, $otp, $purpose);
    return ['success' => true, 'message' => 'OTP sent to ' . $email . '.'];
}

function verifyEmailOtp(string $email, string $entered, string $purpose = 'login'): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Invalid email address.'];
    return verifyOtpRow(getDB(), 'email_otps', 'email', $email, $entered, $purpose);
}

/* =====================================================
   Shared store / verify
   ===================================================== */

function storeOtpRow(PDO $db, string $table, string $col, string $identifier, string $otp, string $purpose): void {
    $db->prepare("DELETE FROM {$table} WHERE {$col} = ? AND purpose = ?")->execute([$identifier, $purpose]);
    $hash    = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
    $expires = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);
    $ip      = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    try {
        $db->prepare("INSERT INTO {$table} ({$col}, otp_hash, purpose, attempts, expires_at, created_at, request_ip)
                      VALUES (?, ?, ?, 0, ?, NOW(), ?)")
           ->execute([$identifier, $hash, $purpose, $expires, $ip]);
    } catch (PDOException $e) {
        // request_ip column not migrated yet - insert without it
        $db->prepare("INSERT INTO {$table} ({$col}, otp_hash, purpose, attempts, expires_at, created_at)
                      VALUES (?, ?, ?, 0, ?, NOW())")
           ->execute([$identifier, $hash, $purpose, $expires]);
    }
}

function verifyOtpRow(PDO $db, string $table, string $col, string $identifier, string $entered, string $purpose): array {
    $entered = preg_replace('/\D/', '', trim($entered));
    if (strlen($entered) !== OTP_LENGTH) {
        return ['success' => false, 'message' => 'Please enter the ' . OTP_LENGTH . '-digit code.'];
    }

    $st = $db->prepare("SELECT * FROM {$table}
                        WHERE {$col} = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
                        ORDER BY id DESC LIMIT 1");
    $st->execute([$identifier, $purpose]);
    $row = $st->fetch();

    if (!$row) return ['success' => false, 'message' => 'Code expired or not found. Please request a new one.'];

    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$row['id']]);
        return ['success' => false, 'message' => 'Too many wrong attempts. Please request a new code.'];
    }

    if (!password_verify($entered, $row['otp_hash'])) {
        $db->prepare("UPDATE {$table} SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        $remaining = OTP_MAX_ATTEMPTS - 1 - (int)$row['attempts'];
        return ['success' => false, 'message' => 'Incorrect code.' . ($remaining > 0 ? " {$remaining} attempt(s) remaining." : '')];
    }

    $db->prepare("UPDATE {$table} SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return ['success' => true, 'message' => 'Verified.'];
}

/* =====================================================
   SMS delivery log (for the admin SMS test tool)
   ===================================================== */

function smsLog(PDO $db, string $mobile, string $endpoint, int $http, string $response): void {
    try {
        $db->prepare("INSERT INTO sms_logs (mobile, endpoint, http_code, response, created_at)
                      VALUES (?, ?, ?, ?, NOW())")
           ->execute([$mobile, $endpoint, $http, mb_substr($response, 0, 1000)]);
    } catch (PDOException $e) { /* table not migrated yet */ }
}
