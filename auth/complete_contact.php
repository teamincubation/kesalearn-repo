<?php
/**
 * KESA Learn - Complete Your Contact Details (mandatory gate)
 *
 * Shown when a logged-in account is missing a valid email or a mobile
 * number. The new value is verified by OTP (SMS via MSG91 for mobile,
 * email code for email) before being saved.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/otp_service.php';

if (!isLoggedIn()) redirect('/auth/login');

$db = getDB();
$st = $db->prepare("SELECT id, name, email, phone, mobile_number FROM users WHERE id = ?");
$st->execute([(int)$_SESSION['user_id']]);
$me = $st->fetch();
if (!$me) redirect('/auth/logout');

$hasEmail  = filter_var($me['email'], FILTER_VALIDATE_EMAIL) && !str_contains($me['email'], '@placeholder');
$hasMobile = phoneKey($me['mobile_number']) !== '' || phoneKey($me['phone']) !== '';

// Nothing missing? Continue to dashboard.
if ($hasEmail && $hasMobile) {
    redirect('/user/dashboard');
}

/* ── AJAX endpoints (send / verify) ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Refresh the page.']); exit;
    }

    $step = $_POST['ajax'];

    if ($step === 'send_mobile') {
        $mobile = otpNormalizeMobile($_POST['mobile'] ?? '');
        if ($mobile === '') { echo json_encode(['success' => false, 'message' => 'Enter a valid 10-digit mobile number.']); exit; }
        // Ensure number is not owned by another live account
        $chk = $db->prepare("SELECT id FROM users WHERE id != ?
                             AND (RIGHT(REGEXP_REPLACE(COALESCE(mobile_number,''),'[^0-9]',''),10) = ?
                               OR RIGHT(REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]',''),10) = ?) LIMIT 1");
        $chk->execute([(int)$me['id'], $mobile, $mobile]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This number belongs to another KESA account. Use a number that belongs to you alone, or contact info@kesalearn.com.']); exit;
        }
        $_SESSION['cc_pending_mobile'] = $mobile;
        echo json_encode(sendSmsOtp($mobile, 'login')); exit;
    }

    if ($step === 'verify_mobile') {
        $mobile = $_SESSION['cc_pending_mobile'] ?? '';
        $res = verifySmsOtp($mobile, $_POST['otp'] ?? '', 'login');
        if ($res['success']) {
            $upd = "UPDATE users SET mobile_number = ?, updated_at = NOW()";
            try { $db->prepare($upd . ", mobile_verified_at = NOW() WHERE id = ?")->execute([$mobile, (int)$me['id']]); }
            catch (PDOException $e) { $db->prepare($upd . " WHERE id = ?")->execute([$mobile, (int)$me['id']]); }
            unset($_SESSION['cc_pending_mobile'], $_SESSION['gates_checked']);
            logActivity('contact_updated', 'Mobile number added & verified via OTP', (int)$me['id']);
        }
        echo json_encode($res); exit;
    }

    if ($step === 'send_email') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']); exit; }
        $chk = $db->prepare("SELECT id FROM users WHERE id != ? AND LOWER(email) = ? LIMIT 1");
        $chk->execute([(int)$me['id'], $email]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This email belongs to another KESA account. Use a different email, or contact info@kesalearn.com.']); exit;
        }
        $_SESSION['cc_pending_email'] = $email;
        echo json_encode(sendEmailOtp($email, 'login')); exit;
    }

    if ($step === 'verify_email') {
        $email = $_SESSION['cc_pending_email'] ?? '';
        $res = verifyEmailOtp($email, $_POST['otp'] ?? '', 'login');
        if ($res['success']) {
            $db->prepare("UPDATE users SET email = ?, email_verified = 1, updated_at = NOW() WHERE id = ?")
               ->execute([$email, (int)$me['id']]);
            $_SESSION['user_email'] = $email;
            unset($_SESSION['cc_pending_email'], $_SESSION['gates_checked']);
            logActivity('contact_updated', 'Email address added & verified via OTP', (int)$me['id']);
        }
        echo json_encode($res); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown step.']); exit;
}

$pageTitle = 'Complete Your Profile';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="max-width:520px;padding:110px 16px 60px;">
  <div class="cc-card">
    <img src="/assets/images/kesa_logo.png" alt="KESA" style="height:42px;margin-bottom:18px;">
    <h1 class="cc-title">One quick step, <?php echo sanitize(explode(' ', $me['name'])[0]); ?></h1>
    <p class="cc-sub">To keep your certificates and registrations safely linked to you, please add and verify the missing contact detail below. This is required to continue.</p>

    <?php if (!$hasMobile): ?>
    <div class="cc-block" id="blk-mobile">
      <label class="cc-label">Mobile Number <span style="color:var(--red)">*</span></label>
      <div class="cc-row">
        <span class="cc-prefix">+91</span>
        <input type="tel" id="cc-mobile" maxlength="10" inputmode="numeric" placeholder="10-digit number" class="cc-input">
        <button class="btn btn-primary" id="cc-mobile-send" onclick="ccSend('mobile')">Send OTP</button>
      </div>
      <div class="cc-otp" id="cc-mobile-otp" style="display:none;">
        <input type="text" maxlength="6" inputmode="numeric" placeholder="Enter 6-digit OTP" class="cc-input" id="cc-mobile-code">
        <button class="btn btn-primary" onclick="ccVerify('mobile')">Verify</button>
      </div>
      <div class="cc-msg" id="cc-mobile-msg"></div>
    </div>
    <?php endif; ?>

    <?php if (!$hasEmail): ?>
    <div class="cc-block" id="blk-email">
      <label class="cc-label">Email Address <span style="color:var(--red)">*</span></label>
      <div class="cc-row">
        <input type="email" id="cc-email" placeholder="you@example.com" class="cc-input" style="flex:1">
        <button class="btn btn-primary" id="cc-email-send" onclick="ccSend('email')">Send OTP</button>
      </div>
      <div class="cc-otp" id="cc-email-otp" style="display:none;">
        <input type="text" maxlength="6" inputmode="numeric" placeholder="Enter 6-digit OTP" class="cc-input" id="cc-email-code">
        <button class="btn btn-primary" onclick="ccVerify('email')">Verify</button>
      </div>
      <div class="cc-msg" id="cc-email-msg"></div>
    </div>
    <?php endif; ?>

    <p class="cc-foot">Need help? Write to <a href="mailto:hello@kesalearn.com">hello@kesalearn.com</a> · <a href="/auth/logout">Log out</a></p>
  </div>
</div>

<style>
.cc-card{background:#fff;border:1px solid var(--border-color);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);padding:36px 32px;text-align:center}
.cc-title{font-size:1.5rem;font-weight:800;color:var(--text-primary);margin-bottom:8px}
.cc-sub{color:var(--text-secondary);font-size:.95rem;margin-bottom:26px;line-height:1.55}
.cc-block{text-align:left;margin-bottom:22px;padding:18px;border:1px solid var(--border-light);border-radius:var(--radius-md);background:var(--bg-secondary)}
.cc-label{display:block;font-weight:700;font-size:.85rem;margin-bottom:10px;color:var(--text-primary)}
.cc-row{display:flex;gap:8px;align-items:stretch}
.cc-prefix{display:flex;align-items:center;padding:0 12px;background:#fff;border:1px solid var(--border-color);border-radius:var(--radius-sm);font-weight:600;color:var(--text-secondary)}
.cc-input{flex:1;min-width:0;padding:12px 14px;border:1px solid var(--border-color);border-radius:var(--radius-sm);font-size:1rem;font-family:inherit}
.cc-input:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(231,64,74,.12)}
.cc-otp{display:flex;gap:8px;margin-top:10px}
.cc-msg{font-size:.85rem;margin-top:8px;min-height:18px}
.cc-msg.ok{color:#15803d}.cc-msg.err{color:var(--red)}
.cc-foot{font-size:.82rem;color:var(--text-muted);margin-top:6px}
@media (max-width:480px){.cc-card{padding:26px 18px}.cc-row{flex-wrap:wrap}.cc-row .btn{width:100%}}
</style>
<script>
const CC_CSRF = document.querySelector('meta[name="csrf-token"]').content;
async function ccPost(data){
  const body = new URLSearchParams({...data, csrf_token: CC_CSRF});
  const r = await fetch(location.pathname, {method:'POST', body});
  return r.json();
}
function ccMsg(kind, text, ok){
  const el = document.getElementById('cc-'+kind+'-msg');
  el.textContent = text; el.className = 'cc-msg ' + (ok ? 'ok' : 'err');
}
async function ccSend(kind){
  const btn = document.getElementById('cc-'+kind+'-send');
  btn.disabled = true; btn.textContent = 'Sending…';
  const data = {ajax:'send_'+kind};
  if (kind === 'mobile') data.mobile = document.getElementById('cc-mobile').value;
  else data.email = document.getElementById('cc-email').value;
  try{
    const res = await ccPost(data);
    ccMsg(kind, res.message, res.success);
    if (res.success) document.getElementById('cc-'+kind+'-otp').style.display = 'flex';
  }catch(e){ ccMsg(kind, 'Network error. Try again.', false); }
  setTimeout(()=>{btn.disabled=false; btn.textContent='Resend OTP';}, 30000);
  btn.textContent = 'Resend OTP';
}
async function ccVerify(kind){
  const data = {ajax:'verify_'+kind, otp: document.getElementById('cc-'+kind+'-code').value};
  try{
    const res = await ccPost(data);
    ccMsg(kind, res.message, res.success);
    if (res.success){
      document.getElementById('blk-'+kind).style.opacity = .5;
      setTimeout(()=>location.reload(), 900);
    }
  }catch(e){ ccMsg(kind, 'Network error. Try again.', false); }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
