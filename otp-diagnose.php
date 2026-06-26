<?php
/**
 * KESA Learn - OTP SYSTEM DIAGNOSTIC (standalone, one file)
 *
 * HOW TO USE
 *   1. Upload this file to public_html/ via Hostinger File Manager.
 *   2. Open:  https://kesalearn.com/otp-diagnose.php?key=KESA-DIAG-2026
 *   3. Read the verdicts; optionally send a live test OTP to your number.
 *   4. DELETE THIS FILE when finished (button at the bottom).
 *
 * It checks, in order:
 *   [1] config/sms.php present + MSG91 constants loaded
 *   [2] Database: otp_module_enabled, required tables/columns, recent OTP rows
 *   [3] Outbound HTTPS from this server to MSG91
 *   [4] MSG91 auth key validity (balance endpoint)
 *   [5] LIVE send through v5/otp and, if rejected, v5/flow - full raw responses
 *   [6] Prints the exact fix for every known MSG91 error string
 */

const DIAG_KEY = 'KESA-DIAG-2026';

if (($_GET['key'] ?? '') !== DIAG_KEY) { http_response_code(403); exit('Forbidden. Append ?key=KESA-DIAG-2026'); }

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

function row($label, $ok, $detail = '') {
    $icon = $ok === true ? '✅' : ($ok === false ? '❌' : '⚠️');
    echo "<tr><td style='padding:8px 12px;border:1px solid #eee;white-space:nowrap'>{$icon} " . htmlspecialchars($label) . "</td>"
       . "<td style='padding:8px 12px;border:1px solid #eee;word-break:break-all;font-family:monospace;font-size:12.5px'>" . $detail . "</td></tr>";
}
function curlJson(string $url, ?string $payload, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($payload !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); }
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$http, $err, (string)$resp];
}

echo "<!doctype html><html><head><title>KESA OTP Diagnostic</title></head>
<body style='font-family:system-ui,Arial,sans-serif;max-width:880px;margin:30px auto;padding:0 16px;color:#1a1a2e'>
<h1 style='color:#e7404a'>KESA OTP Diagnostic</h1>
<p style='color:#555'>Run: " . date('Y-m-d H:i:s') . " IST · Server IP (whitelist this in MSG91 if IP-security is ON): <strong>"
. htmlspecialchars(@file_get_contents('https://api.ipify.org') ?: gethostbyname(gethostname())) . "</strong></p>
<table style='border-collapse:collapse;width:100%'>";

/* [1] Config --------------------------------------------------------------- */
$cfg = __DIR__ . '/config/sms.php';
if (is_file($cfg)) {
    require_once $cfg;
    $key = defined('MSG91_AUTH_KEY') ? MSG91_AUTH_KEY : '';
    if ($key === '' || str_starts_with($key, 'PASTE_')) {
        row('config/sms.php', false, 'File exists but MSG91_AUTH_KEY is empty/placeholder. FIX: paste your MSG91 Auth Key into config/sms.php.');
    } else {
        row('config/sms.php', true, 'Auth key: ' . substr($key, 0, 6) . '…' . substr($key, -4)
            . ' · Template: ' . (defined('MSG91_TEMPLATE_ID') ? MSG91_TEMPLATE_ID : 'MISSING')
            . ' · Sender: ' . (defined('MSG91_SENDER_ID') ? MSG91_SENDER_ID : 'MISSING'));
    }
} else {
    row('config/sms.php', false, 'FILE MISSING. FIX: upload config/sms.php from the v2 package (the OTP service cannot run without it).');
}

/* [2] Database -------------------------------------------------------------- */
$dbOk = false;
if (is_file(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    try {
        $db = getDB(); $dbOk = true;
        row('Database connection', true, DB_NAME . ' @ ' . DB_HOST);

        $v = $db->query("SELECT setting_value FROM site_settings WHERE setting_key='otp_module_enabled'")->fetchColumn();
        row('otp_module_enabled', $v === '1', 'Value: ' . var_export($v, true) . ($v === '1' ? '' : ' — FIX: run the consolidated migration, or set it to 1.'));

        foreach (['phone_otps' => true, 'email_otps' => false, 'sms_logs' => false] as $t => $critical) {
            $exists = (bool)$db->query("SHOW TABLES LIKE '{$t}'")->fetchColumn();
            row("Table {$t}", $exists ? true : ($critical ? false : null),
                $exists ? 'present' : ($critical ? 'MISSING — core OTP storage!' : 'missing — run sql/migrations/2026-06-13_consolidated.sql (non-critical for SMS sending)'));
        }
        $col = $db->query("SHOW COLUMNS FROM phone_otps LIKE 'purpose'")->fetch();
        row('phone_otps.purpose type', stripos($col['Type'] ?? '', 'varchar') !== false ? true : null,
            ($col['Type'] ?? '?') . (stripos($col['Type'] ?? '', 'enum') !== false ? " — enum is OK for login/signup; run the migration to support 'delete' (account resolution)." : ''));

        $last = $db->query("SELECT mobile, purpose, created_at, expires_at, used_at FROM phone_otps ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        row('Recent phone_otps rows', $last ? true : null, $last ? htmlspecialchars(json_encode($last)) : 'none — confirms no OTP has ever been stored (send below to test).');
        try {
            $logs = $db->query("SELECT endpoint, http_code, LEFT(response,160) r, created_at FROM sms_logs ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            row('Recent sms_logs', $logs ? true : null, $logs ? htmlspecialchars(json_encode($logs)) : 'empty');
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        row('Database connection', false, htmlspecialchars($e->getMessage()));
    }
} else {
    row('config/database.php', false, 'missing');
}

/* [3] Outbound HTTPS --------------------------------------------------------- */
[$h,,$r] = curlJson('https://control.msg91.com/api/v5/otp', null, []);
row('Outbound HTTPS to MSG91', $h > 0, $h > 0 ? "reachable (HTTP {$h})" : 'BLOCKED — server cannot reach control.msg91.com. Contact Hostinger support.');

/* [4] Auth key validity (balance check, read-only) ---------------------------- */
if (defined('MSG91_AUTH_KEY') && !str_starts_with(MSG91_AUTH_KEY, 'PASTE_')) {
    [$h,,$r] = curlJson('https://control.msg91.com/api/balance.php?authkey=' . urlencode(MSG91_AUTH_KEY) . '&type=4', null, []);
    $bad = stripos($r, 'invalid') !== false || stripos($r, 'unauthorized') !== false;
    row('MSG91 auth key check (balance, route 4)', !$bad, 'HTTP ' . $h . ' → <code>' . htmlspecialchars(trim($r)) . '</code>'
        . ($bad ? ' — FIX: the key is invalid/disabled. Generate a fresh Auth Key in MSG91 → Settings → API Keys and update config/sms.php. If the response mentions IP, disable IP-security or whitelist the server IP shown above.' 
                : ' (a number = your transactional SMS balance)'));
}

/* [5] LIVE SEND --------------------------------------------------------------- */
echo "</table><h2 style='color:#e7404a;margin-top:28px'>Live send test</h2>";
$mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
if (strlen($mobile) === 12 && str_starts_with($mobile, '91')) $mobile = substr($mobile, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strlen($mobile) === 10 && defined('MSG91_AUTH_KEY')) {
    $otp = (string)random_int(100000, 999999);
    echo "<p>Sending OTP <strong>{$otp}</strong> to +91{$mobile}…</p><table style='border-collapse:collapse;width:100%'>";

    // v5/otp (OTP-type template route)
    [$h1, $e1, $r1] = curlJson('https://control.msg91.com/api/v5/otp',
        json_encode(['template_id' => MSG91_TEMPLATE_ID, 'mobile' => '91' . $mobile, 'otp' => $otp, 'otp_expiry' => 10, 'sender' => MSG91_SENDER_ID]),
        ['Content-Type: application/json', 'authkey: ' . MSG91_AUTH_KEY]);
    $ok1 = $h1 === 200 && stripos($r1, '"type":"success"') !== false;
    row('v5/otp endpoint', $ok1, "HTTP {$h1} " . ($e1 ? "cURL: {$e1} " : '') . '→ <code>' . htmlspecialchars($r1) . '</code>');

    $ok2 = null;
    if (!$ok1) {
        // v5/flow (Flow-type template route)
        [$h2, $e2, $r2] = curlJson('https://control.msg91.com/api/v5/flow/',
            json_encode(['template_id' => MSG91_TEMPLATE_ID, 'short_url' => '0',
                'recipients' => [['mobiles' => '91' . $mobile, 'otp' => $otp, 'OTP' => $otp, 'var' => $otp, 'VAR1' => $otp, 'var1' => $otp]]]),
            ['Content-Type: application/json', 'accept: application/json', 'authkey: ' . MSG91_AUTH_KEY]);
        $ok2 = $h2 === 200 && stripos($r2, '"type":"success"') !== false;
        row('v5/flow endpoint', $ok2, "HTTP {$h2} " . ($e2 ? "cURL: {$e2} " : '') . '→ <code>' . htmlspecialchars($r2) . '</code>');
    }
    echo "</table>";

    $raw = strtolower(($r1 ?? '') . ' ' . ($r2 ?? ''));
    echo "<h3>Verdict</h3><ul style='line-height:1.7'>";
    if ($ok1 || $ok2) {
        echo "<li><strong style='color:#15803d'>MSG91 ACCEPTED the message.</strong> If the SMS still doesn't arrive within 2 minutes:
              open MSG91 → Reports → Delivery/Logs for this request ID — a 'DND/operator/DLT' failure there means the DLT
              template/sender mapping is the issue; 'insufficient balance' means recharge. The website code is working.</li>";
        if (!$ok1 && $ok2) echo "<li>Your template is registered as a <strong>Flow</strong> template — the site already auto-falls back to Flow, so no code change is needed. (Optionally re-create it as an 'OTP' type template named KESA_OTP for the dedicated OTP route.)</li>";
    } else {
        if (str_contains($raw, 'authkey') || str_contains($raw, 'unauthorized'))
            echo "<li><strong>Invalid/disabled auth key.</strong> MSG91 → Settings → API Keys → create a new key → paste into config/sms.php.</li>";
        if (str_contains($raw, 'ip'))
            echo "<li><strong>IP security is blocking the server.</strong> MSG91 → Settings → Security → either disable IP whitelisting or add the server IP shown at the top.</li>";
        if (str_contains($raw, 'template'))
            echo "<li><strong>Template problem.</strong> MSG91 → (OTP or Flow section) → confirm template ID <code>" . htmlspecialchars(MSG91_TEMPLATE_ID) . "</code> exists, is <em>approved</em>, the variable is <code>##OTP##</code>, and sender <strong>LABINC</strong> is mapped to it.</li>";
        if (str_contains($raw, 'balance') || str_contains($raw, 'credit'))
            echo "<li><strong>Insufficient balance</strong> on the transactional route — recharge in MSG91.</li>";
        if (str_contains($raw, 'sender'))
            echo "<li><strong>Sender ID issue.</strong> LABINC must be DLT-approved and added under MSG91 → Sender IDs.</li>";
        echo "<li>If none of the above matched, copy the raw responses above and send them to your developer/Claude.</li>";
    }
    echo "</ul>";
} else {
    echo "<form method='post' style='display:flex;gap:10px;max-width:440px'>
            <input name='mobile' maxlength='10' placeholder='Your 10-digit mobile' required
                   style='flex:1;padding:11px 13px;border:1px solid #ddd;border-radius:9px'>
            <button style='background:#e7404a;color:#fff;border:0;border-radius:9px;padding:11px 20px;font-weight:700;cursor:pointer'>Send test OTP</button>
          </form>
          <p style='color:#888;font-size:13px'>Sends a real SMS through both MSG91 routes and prints the raw responses + a plain-English verdict.</p>";
}

/* [6] Self-delete -------------------------------------------------------------- */
if (isset($_GET['selfdelete']) && $_GET['selfdelete'] === '1') { @unlink(__FILE__); exit('<p>Diagnostic file deleted. ✅</p>'); }
echo "<hr style='margin:28px 0;border:0;border-top:1px solid #eee'>
<p><a href='?key=" . DIAG_KEY . "&selfdelete=1' onclick=\"return confirm('Delete this diagnostic file from the server?')\"
      style='color:#e7404a;font-weight:700'>🗑 Delete this file from the server (do this when finished)</a></p>
</body></html>";
