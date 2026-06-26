<?php
/**
 * KESA Learn - Resolve Duplicate Accounts (mandatory, no skip)
 *
 * Reached when the logged-in user's mobile number is attached to more than
 * one account (different emails). Email and phone must be unique, so the
 * user must pick the ONE email that stays active. Every other account on
 * this number is deleted after the user confirms with a fresh OTP sent to
 * the shared mobile number.
 *
 * Before each deletion (see includes/account_gates.php):
 *   - certificates & registrations move to the kept account where possible,
 *   - a full history report is emailed to the deleted account's address,
 *   - admin (info@kesalearn.com) is notified,
 *   - an audit row is written to account_deletions.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/account_gates.php';

if (!isLoggedIn()) redirect('/auth/login');

$db   = getDB();
$meId = (int)$_SESSION['user_id'];
$st = $db->prepare("SELECT id, name, email, phone, mobile_number FROM users WHERE id = ?");
$st->execute([$meId]);
$me = $st->fetch();
if (!$me) redirect('/auth/logout');

$myKey    = phoneKey($me['mobile_number']) ?: phoneKey($me['phone']);
$accounts = $myKey !== '' ? accountsOnNumber($db, $myKey) : [];

// Resolved (or nothing to resolve)? Continue to the dashboard.
if (count($accounts) <= 1) {
    $_SESSION['gates_checked'] = 1;
    redirect('/user/dashboard');
}

function maskEmailAddr(string $e): string {
    if (!str_contains($e, '@')) return $e;
    [$u, $d] = explode('@', $e, 2);
    $n = strlen($u);
    return ($n <= 2 ? str_repeat('*', $n) : $u[0] . str_repeat('*', max(1, $n - 2)) . $u[$n - 1]) . '@' . $d;
}
function acctStats(PDO $db, int $uid): array {
    $s = ['regs' => 0, 'certs' => 0];
    try { $q = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?"); $q->execute([$uid]); $s['regs'] = (int)$q->fetchColumn(); } catch (PDOException $e) {}
    try { $q = $db->prepare("SELECT COUNT(*) FROM certificates WHERE user_id = ?");  $q->execute([$uid]); $s['certs'] = (int)$q->fetchColumn(); } catch (PDOException $e) {}
    return $s;
}

/* ── AJAX: send OTP / confirm deletion ──────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Refresh the page.']); exit;
    }

    if ($_POST['ajax'] === 'send_otp') {
        $keepId = (int)($_POST['keep_id'] ?? 0);
        $valid  = array_filter($accounts, fn($a) => (int)$a['id'] === $keepId);
        if (!$valid) { echo json_encode(['success' => false, 'message' => 'Choose which email to keep first.']); exit; }
        $_SESSION['resolve_keep_id'] = $keepId;
        echo json_encode(sendSmsOtp($myKey, 'delete')); exit;
    }

    if ($_POST['ajax'] === 'confirm') {
        $keepId = (int)($_SESSION['resolve_keep_id'] ?? 0);
        $valid  = array_filter($accounts, fn($a) => (int)$a['id'] === $keepId);
        if (!$keepId || !$valid) { echo json_encode(['success' => false, 'message' => 'Selection expired. Start again.']); exit; }

        $res = verifySmsOtp($myKey, $_POST['otp'] ?? '', 'delete');
        if (!$res['success']) { echo json_encode($res); exit; }

        $deleted = [];
        $failed  = [];
        foreach ($accounts as $a) {
            if ((int)$a['id'] === $keepId) continue;
            if ($a['role'] === 'admin') { $failed[] = $a['email'] . ' (admin accounts cannot be auto-deleted - contact support)'; continue; }
            $r = executeAccountDeletion($db, $keepId, (int)$a['id']);
            if ($r['success']) $deleted[] = $a['email']; else $failed[] = $a['email'];
        }

        if ($failed) {
            echo json_encode(['success' => false,
                'message' => 'Some accounts could not be removed: ' . implode(', ', $failed) . '. Please contact ' . KESA_ADMIN_EMAIL . '.']); exit;
        }

        // Switch the session to the kept account (ownership proven by OTP on the shared number)
        $st = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $st->execute([$keepId]);
        if ($keep = $st->fetch()) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int)$keep['id'];
            $_SESSION['user_name']  = $keep['name'];
            $_SESSION['user_email'] = $keep['email'];
            $_SESSION['user_role']  = $keep['role'];
            createRememberToken((int)$keep['id']);
        }
        unset($_SESSION['resolve_keep_id'], $_SESSION['gates_checked']);
        logActivity('duplicate_accounts_resolved',
            'Kept ' . ($keep['email'] ?? '#' . $keepId) . '; deleted: ' . implode(', ', $deleted), $keepId);

        echo json_encode(['success' => true,
            'message'  => 'Done! Removed: ' . implode(', ', array_map('maskEmailAddr', $deleted)),
            'redirect' => '/user/dashboard']); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown step.']); exit;
}

$pageTitle = 'Choose Your Active Email';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="max-width:680px;padding:110px 16px 60px;">
  <div class="rs-card">
    <img src="/assets/images/kesa_logo.png" alt="KESA" style="height:42px;margin-bottom:14px;">
    <h1 class="rs-title">One mobile number, one account</h1>
    <p class="rs-sub">Your mobile number <strong>+91 ******<?php echo substr($myKey, -4); ?></strong> is attached to
      <strong><?php echo count($accounts); ?> accounts</strong> with different emails. To keep your email and phone
      number unique, choose the email you want to <span style="color:#15803d;font-weight:700">keep active</span>.
      The other account<?php echo count($accounts) > 2 ? 's' : ''; ?> will be <span style="color:var(--red);font-weight:700">permanently removed</span> —
      your certificates and registrations move to the account you keep, and a full history report is emailed to each removed address.</p>

    <div class="rs-list" id="rs-list">
      <?php foreach ($accounts as $a): $s = acctStats($db, (int)$a['id']); ?>
      <label class="rs-acct" for="keep-<?php echo (int)$a['id']; ?>">
        <input type="radio" name="keep_id" id="keep-<?php echo (int)$a['id']; ?>" value="<?php echo (int)$a['id']; ?>"
               <?php echo (int)$a['id'] === $meId ? 'checked' : ''; ?>>
        <span class="rs-acct-body">
          <span class="rs-acct-email"><?php echo sanitize($a['email']); ?>
            <?php if ((int)$a['id'] === $meId): ?><span class="rs-tag">signed in now</span><?php endif; ?>
          </span>
          <span class="rs-acct-meta"><?php echo sanitize($a['name']); ?> · <?php echo $s['regs']; ?> registration<?php echo $s['regs'] == 1 ? '' : 's'; ?> ·
            <?php echo $s['certs']; ?> certificate<?php echo $s['certs'] == 1 ? '' : 's'; ?> · member since <?php echo date('M Y', strtotime($a['created_at'])); ?></span>
        </span>
        <span class="rs-keep-pill">KEEP THIS</span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="rs-warn" id="rs-warn"></div>

    <div id="rs-step1">
      <button class="btn btn-primary rs-btn" id="rs-send" onclick="rsSendOtp()">Continue — send OTP to my mobile</button>
    </div>

    <div id="rs-step2" style="display:none;">
      <p class="rs-otp-note">Enter the 6-digit code sent to <strong>+91 ******<?php echo substr($myKey, -4); ?></strong> to confirm.
        This permanently deletes the unselected account<?php echo count($accounts) > 2 ? 's' : ''; ?>.</p>
      <div class="rs-otp-row">
        <input type="text" id="rs-otp" maxlength="6" inputmode="numeric" placeholder="6-digit OTP" class="rs-input">
        <button class="btn btn-primary" id="rs-confirm" onclick="rsConfirm()">Verify &amp; Delete</button>
      </div>
      <button class="rs-resend" id="rs-resend" onclick="rsSendOtp()" disabled>Resend OTP</button>
    </div>

    <div class="rs-msg" id="rs-msg"></div>

    <p class="rs-foot">This step is required to continue. Questions? <a href="mailto:info@kesalearn.com">info@kesalearn.com</a> · <a href="/auth/logout">Log out</a></p>
  </div>
</div>

<style>
.rs-card{background:#fff;border:1px solid var(--border-color);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);padding:36px 32px;text-align:center}
.rs-title{font-size:1.45rem;font-weight:800;color:var(--text-primary);margin-bottom:8px}
.rs-sub{color:var(--text-secondary);font-size:.93rem;line-height:1.65;margin-bottom:22px;text-align:left}
.rs-list{display:flex;flex-direction:column;gap:10px;margin-bottom:18px}
.rs-acct{display:flex;align-items:center;gap:14px;text-align:left;border:2px solid var(--border-light);background:var(--bg-secondary);border-radius:var(--radius-md);padding:14px 16px;cursor:pointer;transition:border-color .15s, background .15s}
.rs-acct input{accent-color:var(--red);width:18px;height:18px;flex:none}
.rs-acct-body{flex:1;min-width:0}
.rs-acct-email{display:block;font-weight:700;color:var(--text-primary);word-break:break-all}
.rs-acct-meta{display:block;font-size:.8rem;color:var(--text-muted);margin-top:3px}
.rs-tag{display:inline-block;background:var(--blue,#4950ba);color:#fff;font-size:.65rem;font-weight:700;border-radius:99px;padding:2px 8px;margin-left:6px;vertical-align:middle}
.rs-keep-pill{display:none;background:#dcfce7;color:#15803d;font-size:.68rem;font-weight:800;border-radius:99px;padding:4px 10px;flex:none}
.rs-acct:has(input:checked){border-color:#15803d;background:#f0fdf4}
.rs-acct:has(input:checked) .rs-keep-pill{display:inline-block}
.rs-btn{width:100%;padding:14px}
.rs-otp-note{font-size:.88rem;color:var(--text-secondary);margin-bottom:12px}
.rs-otp-row{display:flex;gap:8px;justify-content:center}
.rs-input{padding:12px 14px;border:1px solid var(--border-color);border-radius:var(--radius-sm);font-size:1.05rem;letter-spacing:.2em;text-align:center;width:170px}
.rs-input:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(231,64,74,.12)}
.rs-resend{background:none;border:none;color:var(--text-muted);font-size:.8rem;margin-top:10px;cursor:pointer;text-decoration:underline}
.rs-resend:disabled{opacity:.5;cursor:default}
.rs-msg{font-size:.88rem;margin-top:12px;min-height:20px}
.rs-msg.ok{color:#15803d}.rs-msg.err{color:var(--red)}
.rs-warn{font-size:.82rem;color:var(--red);min-height:0}
.rs-foot{font-size:.8rem;color:var(--text-muted);margin-top:14px}
@media(max-width:520px){.rs-card{padding:24px 16px}.rs-otp-row{flex-wrap:wrap}.rs-otp-row .btn{width:100%}}
</style>
<script>
const RS_CSRF = document.querySelector('meta[name="csrf-token"]').content;
async function rsPost(data){
  const body = new URLSearchParams({...data, csrf_token: RS_CSRF});
  const r = await fetch(location.pathname, {method:'POST', body});
  return r.json();
}
function rsMsg(t, ok){ const el=document.getElementById('rs-msg'); el.textContent=t; el.className='rs-msg '+(ok?'ok':'err'); }
function rsKeepId(){ const c=document.querySelector('input[name="keep_id"]:checked'); return c ? c.value : null; }
async function rsSendOtp(){
  const keep = rsKeepId();
  if(!keep){ rsMsg('Select the email you want to keep first.', false); return; }
  const btn=document.getElementById('rs-send'); btn.disabled=true; btn.textContent='Sending OTP…';
  try{
    const res = await rsPost({ajax:'send_otp', keep_id: keep});
    rsMsg(res.message, res.success);
    if(res.success){
      document.getElementById('rs-step1').style.display='none';
      document.getElementById('rs-step2').style.display='block';
      const rb=document.getElementById('rs-resend'); rb.disabled=true;
      setTimeout(()=>rb.disabled=false, 30000);
      document.querySelectorAll('#rs-list input').forEach(i=>i.disabled=true);
    } else { btn.disabled=false; btn.textContent='Continue — send OTP to my mobile'; }
  }catch(e){ rsMsg('Network error. Try again.', false); btn.disabled=false; btn.textContent='Continue — send OTP to my mobile'; }
}
async function rsConfirm(){
  const btn=document.getElementById('rs-confirm'); btn.disabled=true; btn.textContent='Working…';
  try{
    const res = await rsPost({ajax:'confirm', otp: document.getElementById('rs-otp').value});
    rsMsg(res.message, res.success);
    if(res.success){ setTimeout(()=>location.href=res.redirect, 1400); }
    else { btn.disabled=false; btn.textContent='Verify & Delete'; }
  }catch(e){ rsMsg('Network error. Try again.', false); btn.disabled=false; btn.textContent='Verify & Delete'; }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
