<?php
/**
 * KESA Learn - Daily Security Cleanup
 *
 * Purges expired OTPs, password-reset tokens and remember tokens so stale
 * credentials can never be replayed, and unlocks accounts whose temporary
 * lockout has passed.
 *
 * Set up in Hostinger hPanel -> Advanced -> Cron Jobs (once per day):
 *   php /home/uXXXXXXXXX/domains/kesalearn.com/public_html/cron/security-cleanup.php cron_key=KESA_CRON_2026
 * or via URL (the /cron/ folder is blocked from the web by .htaccess, so
 * use the PHP command form above).
 */

// Simple key check so the script cannot be triggered accidentally
$key = $_GET['cron_key'] ?? ($argv[1] ?? '');
$key = str_replace('cron_key=', '', (string)$key);
if ($key !== 'KESA_CRON_2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/database.php';

$db = getDB();
$out = [];

$jobs = [
    'phone_otps'          => "DELETE FROM phone_otps WHERE expires_at < NOW() OR used_at IS NOT NULL",
    'email_otps'          => "DELETE FROM email_otps WHERE expires_at < NOW() OR used_at IS NOT NULL",
    'password_resets'     => "DELETE FROM password_resets WHERE expires_at < NOW()",
    'remember_tokens'     => "DELETE FROM remember_tokens WHERE expires_at < NOW()",
    'email_verifications' => "DELETE FROM email_verifications WHERE expires_at IS NOT NULL AND expires_at < NOW()",
    'stale_lockouts'      => "UPDATE users SET locked_until = NULL, login_attempts = 0
                              WHERE locked_until IS NOT NULL AND locked_until < NOW()",
];

foreach ($jobs as $name => $sql) {
    try {
        $count = $db->exec($sql);
        $out[$name] = $count;
    } catch (PDOException $e) {
        $out[$name] = 'skipped (' . $e->getCode() . ')';
    }
}

$line = '[' . date('Y-m-d H:i:s') . '] security-cleanup ' . json_encode($out);
error_log($line);
echo $line . PHP_EOL;
