<?php
/**
 * KESA Learn - Admin: SMS / OTP Diagnostics
 *
 * Admin-only replacement for the old public diag-otp.php (removed for
 * security). Sends a real OTP to a number you enter and shows MSG91's raw
 * responses so delivery problems (wrong key, template mismatch, DLT
 * rejection, low balance) are visible immediately.
 */
require_once __DIR__ . '/../../includes/admin_check.php';
require_once __DIR__ . '/../../includes/otp_service.php';

$adminPage = 'tools';
$pageTitle = 'SMS / OTP Diagnostics';

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $mobile = otpNormalizeMobile($_POST['mobile'] ?? '');
    if ($mobile === '') {
        $result = ['success' => false, 'message' => 'Enter a valid 10-digit number.'];
    } else {
        $result = sendSmsOtp($mobile, 'login');
    }
}

$logs = [];
try {
    $logs = getDB()->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 20")->fetchAll();
} catch (PDOException $e) {}

$keyState = !defined('MSG91_AUTH_KEY') ? 'NOT DEFINED'
          : (str_starts_with(MSG91_AUTH_KEY, 'PASTE_') ? 'PLACEHOLDER - paste your key in config/sms.php'
          : 'Configured (' . substr(MSG91_AUTH_KEY, 0, 6) . '…' . substr(MSG91_AUTH_KEY, -4) . ')');
$otpEnabled = getSetting('otp_module_enabled', '1') === '1';

require_once __DIR__ . '/../includes/sidebar.php';
?>
  <div class="admin-header">
    <h1>SMS / OTP Diagnostics</h1>
    <p>Send a live test OTP and inspect MSG91's raw responses. Configuration is read from <code>config/sms.php</code>.</p>
  </div>

  <div class="admin-card" style="margin-bottom:20px;">
    <h2 style="font-size:1rem;margin-bottom:12px;">Configuration</h2>
    <table class="admin-table" style="max-width:640px;">
      <tr><td style="width:220px;"><strong>MSG91 Auth Key</strong></td><td><?php echo sanitize($keyState); ?></td></tr>
      <tr><td><strong>Template ID</strong></td><td><code><?php echo sanitize(defined('MSG91_TEMPLATE_ID') ? MSG91_TEMPLATE_ID : '—'); ?></code> (KESA_OTP)</td></tr>
      <tr><td><strong>Sender ID</strong></td><td><?php echo sanitize(defined('MSG91_SENDER_ID') ? MSG91_SENDER_ID : '—'); ?></td></tr>
      <tr><td><strong>OTP module</strong></td><td><?php echo $otpEnabled ? '<span style="color:#15803d;font-weight:700;">Enabled</span>' : '<span style="color:#e7404a;font-weight:700;">DISABLED</span> — run the migration or enable it in Settings'; ?></td></tr>
    </table>
  </div>

  <div class="admin-card" style="margin-bottom:20px;">
    <h2 style="font-size:1rem;margin-bottom:12px;">Send Test OTP</h2>
    <?php if ($result): ?>
      <div class="alert <?php echo $result['success'] ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($result['message']); ?></div>
    <?php endif; ?>
    <form method="post" style="display:flex;gap:10px;max-width:480px;flex-wrap:wrap;">
      <?php echo csrfField(); ?>
      <input type="tel" name="mobile" maxlength="10" placeholder="10-digit mobile number" required
             style="flex:1;min-width:200px;padding:10px 12px;border:1px solid #ddd;border-radius:8px;">
      <button class="btn btn-primary">Send Test OTP</button>
    </form>
    <p style="font-size:.82rem;color:#888;margin-top:8px;">Rate limits apply (30s cooldown, 3 sends per 10 minutes per number).</p>
  </div>

  <div class="admin-card">
    <h2 style="font-size:1rem;margin-bottom:12px;">Recent MSG91 Responses</h2>
    <div style="overflow-x:auto;">
      <table class="admin-table">
        <thead><tr><th>Time</th><th>Mobile</th><th>Endpoint</th><th>HTTP</th><th>Raw Response</th></tr></thead>
        <tbody>
          <?php if (!$logs): ?>
            <tr><td colspan="5" style="text-align:center;color:#888;">No log entries yet (run the migration to create the sms_logs table, then send a test).</td></tr>
          <?php endif; ?>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td style="white-space:nowrap;"><?php echo sanitize($l['created_at']); ?></td>
            <td><?php echo sanitize($l['mobile']); ?></td>
            <td><code><?php echo sanitize($l['endpoint']); ?></code></td>
            <td><?php echo (int)$l['http_code']; ?></td>
            <td style="max-width:420px;word-break:break-all;font-size:.78rem;"><?php echo sanitize($l['response'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="font-size:.82rem;color:#888;margin-top:10px;">
      Common failures: <em>"authkey is invalid"</em> → wrong key in config/sms.php ·
      <em>"template not approved/mapped"</em> → in MSG91, the KESA_OTP template must be approved and the
      variable named <code>##OTP##</code> · HTTP 200 + success but no SMS → check MSG91 balance and
      Delivery Reports in the MSG91 dashboard (DLT operator blocks show there).
    </p>
  </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
