<?php
/**
 * KESA Learn - Sign Up (Google + Mobile OTP)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_tracker.php';

if (isLoggedIn()) { redirect('/user/dashboard'); }

$otpEnabled = getSetting('otp_module_enabled', '1') === '1';
$pageTitle  = 'Sign Up';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card" style="max-width:480px;">

        <div class="auth-header">
            <div class="logo logo-font">
                <span style="color:var(--red);">K</span><span style="color:var(--purple);">E</span><span style="color:var(--blue);">S</span><span style="color:var(--yellow);">A</span>
            </div>
            <h2>Create Your Account</h2>
            <p>Join KESA Learn and start your learning journey</p>
        </div>

        <!-- Google Sign Up -->
        <a href="/auth/google" class="btn-google-primary">
            <svg width="20" height="20" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Continue with Google
        </a>

        <?php if ($otpEnabled): ?>
        <div class="auth-divider"><span>or sign up with mobile</span></div>

        <!-- ── Step 1: Details form ─────────────────────────────── -->
        <div id="step-details">
            <div id="su-error" class="otp-alert otp-alert--error" style="display:none;"></div>

            <div class="otp-field">
                <label class="otp-label" for="su-name">Full Name <span class="otp-req">*</span></label>
                <input type="text" id="su-name" class="otp-input" placeholder="Your full name"
                       autocomplete="name" onkeydown="if(event.key==='Enter')document.getElementById('su-email').focus()">
            </div>
            <div class="otp-field">
                <label class="otp-label" for="su-email">Email Address <span class="otp-req">*</span></label>
                <input type="email" id="su-email" class="otp-input" placeholder="you@example.com"
                       autocomplete="email" onkeydown="if(event.key==='Enter')document.getElementById('su-mobile').focus()">
            </div>
            <div class="otp-field">
                <label class="otp-label" for="su-mobile">Mobile Number <span class="otp-req">*</span></label>
                <div class="otp-phone-wrap">
                    <span class="otp-phone-prefix">+91</span>
                    <input type="tel" id="su-mobile" class="otp-input otp-phone-input"
                           placeholder="10-digit mobile" maxlength="10"
                           inputmode="numeric" autocomplete="tel"
                           onkeydown="if(event.key==='Enter')sendSignupOtp()">
                </div>
            </div>

            <button id="btn-send" type="button" class="otp-btn otp-btn--primary otp-btn--full" onclick="sendSignupOtp()">
                <span id="btn-send-text">Send OTP</span>
                <span id="btn-send-spin" class="otp-spinner" style="display:none;"></span>
            </button>
        </div>

        <!-- ── Step 2: OTP entry ────────────────────────────────── -->
        <div id="step-otp" style="display:none;">
            <div id="su-otp-error" class="otp-alert otp-alert--error" style="display:none;"></div>

            <!-- Sent banner -->
            <div class="otp-sent-banner">
                <div class="otp-sent-icon">
                    <svg width="22" height="22" fill="none" stroke="#16a34a" viewBox="0 0 24 24" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="otp-sent-title">OTP sent successfully</p>
                    <p class="otp-sent-sub">6-digit code sent to <strong id="su-sent-mobile"></strong></p>
                </div>
            </div>

            <!-- 6 digit boxes -->
            <p class="otp-digit-label">Enter the 6-digit OTP</p>
            <div class="otp-digits" id="su-otp-digits">
                <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                <span class="otp-digit-sep">-</span>
                <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
            </div>

            <button id="btn-verify" type="button" class="otp-btn otp-btn--primary otp-btn--full" onclick="verifySignupOtp()" style="margin-top:18px;">
                <span id="btn-verify-text">Create Account</span>
                <span id="btn-verify-spin" class="otp-spinner" style="display:none;"></span>
            </button>

            <div class="otp-actions">
                <button type="button" class="otp-link-btn" id="btn-resend" onclick="resendSignupOtp()">
                    Resend OTP <span id="resend-timer" class="otp-timer"></span>
                </button>
                <button type="button" class="otp-link-btn" onclick="backToDetails()">&larr; Change details</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="otp-footer">
            Already have an account? <a href="/auth/login">Log in</a>
        </div>
    </div>
</div>

<!-- WhatsApp number popup -->
<div id="wa-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:32px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);text-align:center;">
        <div style="width:60px;height:60px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        </div>
        <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:8px;">Add WhatsApp Number</h3>
        <p style="font-size:0.88rem;color:#6b7280;margin-bottom:20px;">We use WhatsApp to send updates about your events and courses.</p>
        <div id="wa-error" class="otp-alert otp-alert--error" style="display:none;text-align:left;"></div>
        <div id="wa-same-section">
            <p style="font-size:0.9rem;font-weight:600;margin-bottom:14px;">Is <strong id="wa-mobile-shown"></strong> your WhatsApp number?</p>
            <div style="display:flex;gap:10px;">
                <button type="button" onclick="saveWhatsapp(true)"  class="otp-btn otp-btn--primary" style="flex:1;padding:12px;">Yes, it is</button>
                <button type="button" onclick="showWaDifferent()"   class="otp-btn otp-btn--ghost"   style="flex:1;padding:12px;">No, different</button>
            </div>
        </div>
        <div id="wa-diff-section" style="display:none;">
            <div style="text-align:left;margin-bottom:12px;">
                <label class="otp-label">WhatsApp Number <span class="otp-req">*</span></label>
                <div class="otp-phone-wrap">
                    <span class="otp-phone-prefix">+91</span>
                    <input type="tel" id="wa-number" class="otp-input otp-phone-input" placeholder="10-digit WhatsApp number" maxlength="10" inputmode="numeric">
                </div>
            </div>
            <button type="button" onclick="saveWhatsapp(false)" class="otp-btn otp-btn--primary otp-btn--full" style="padding:12px;">Save WhatsApp Number</button>
            <button type="button" onclick="document.getElementById('wa-diff-section').style.display='none';document.getElementById('wa-same-section').style.display='block';" class="otp-link-btn" style="margin-top:8px;">Back</button>
        </div>
    </div>
</div>

<style>
/* ── Shared auth shell ──────────────────────────────────────── */
.btn-google-primary {
    display:flex;align-items:center;justify-content:center;gap:12px;
    width:100%;padding:14px 20px;background:#fff;
    border:1.5px solid #e0e0e0;border-radius:10px;
    font-size:0.98rem;font-weight:600;color:#333;text-decoration:none;
    transition:all 0.2s;box-shadow:0 1px 4px rgba(0,0,0,0.07);
}
.btn-google-primary:hover { background:#f8f9fa;border-color:#d0d0d0;box-shadow:0 2px 8px rgba(0,0,0,0.11); }
.auth-divider { display:flex;align-items:center;text-align:center;margin:20px 0;color:var(--text-muted);font-size:0.83rem; }
.auth-divider::before,.auth-divider::after { content:'';flex:1;border-bottom:1px solid #e5e7eb; }
.auth-divider span { padding:0 14px; }

/* ── OTP form components ────────────────────────────────────── */
.otp-field  { margin-bottom:16px; }
.otp-label  { display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:6px; }
.otp-req    { color:#e53935; }
.otp-input  {
    width:100%;padding:11px 14px;font-size:0.92rem;color:#111827;
    border:1.5px solid #d1d5db;border-radius:8px;background:#fff;
    outline:none;transition:border-color 0.18s,box-shadow 0.18s;box-sizing:border-box;
}
.otp-input:focus { border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,0.12); }
.otp-phone-wrap { display:flex;align-items:stretch;border:1.5px solid #d1d5db;border-radius:8px;overflow:hidden;background:#fff;transition:border-color 0.18s; }
.otp-phone-wrap:focus-within { border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,0.12); }
.otp-phone-prefix { padding:0 12px;font-size:0.9rem;font-weight:600;color:#374151;background:#f9fafb;border-right:1px solid #d1d5db;display:flex;align-items:center;flex-shrink:0; }
.otp-phone-input  { border:none !important;border-radius:0 !important;box-shadow:none !important;outline:none;flex:1;padding:11px 14px;font-size:0.92rem;background:transparent; }

/* Buttons */
.otp-btn { display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:8px;font-size:0.92rem;font-weight:600;border:none;cursor:pointer;transition:all 0.18s; }
.otp-btn--primary { background:#4f46e5;color:#fff; }
.otp-btn--primary:hover { background:#4338ca; }
.otp-btn--primary:disabled { background:#a5b4fc;cursor:not-allowed; }
.otp-btn--ghost { background:#f3f4f6;color:#374151;border:1px solid #e5e7eb; }
.otp-btn--ghost:hover { background:#e5e7eb; }
.otp-btn--full { width:100%; }
.otp-link-btn { background:none;border:none;color:#6b7280;font-size:0.84rem;cursor:pointer;padding:6px 0;text-decoration:none; }
.otp-link-btn:hover { color:#374151;text-decoration:underline; }
.otp-link-btn:disabled { opacity:0.45;cursor:not-allowed; }

/* Spinner */
.otp-spinner { width:16px;height:16px;border:2.5px solid rgba(255,255,255,0.35);border-top-color:#fff;border-radius:50%;animation:otp-spin 0.7s linear infinite;display:inline-block;flex-shrink:0; }
@keyframes otp-spin { to { transform:rotate(360deg); } }

/* Alert */
.otp-alert { padding:10px 14px;border-radius:8px;font-size:0.85rem;font-weight:500;margin-bottom:14px; }
.otp-alert--error   { background:#fef2f2;border:1.5px solid #fecaca;color:#dc2626; }
.otp-alert--success { background:#f0fdf4;border:1.5px solid #bbf7d0;color:#15803d; }

/* OTP sent banner */
.otp-sent-banner { display:flex;align-items:center;gap:12px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:20px; }
.otp-sent-icon { width:38px;height:38px;background:#dcfce7;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.otp-sent-title { font-size:0.88rem;font-weight:700;color:#15803d;margin:0 0 2px; }
.otp-sent-sub { font-size:0.8rem;color:#166534;margin:0; }

/* 6-digit OTP boxes */
.otp-digit-label { font-size:0.85rem;font-weight:600;color:#374151;text-align:center;margin:0 0 12px; }
.otp-digits { display:flex;align-items:center;justify-content:center;gap:8px; }
.otp-digit {
    width:48px;height:56px;border:2px solid #d1d5db;border-radius:10px;
    text-align:center;font-size:1.5rem;font-weight:700;color:#111827;background:#fff;
    outline:none;transition:border-color 0.18s,box-shadow 0.18s;
    caret-color:transparent;-moz-appearance:textfield;
}
.otp-digit::-webkit-outer-spin-button,
.otp-digit::-webkit-inner-spin-button { -webkit-appearance:none;margin:0; }
.otp-digit:focus { border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,0.15); }
.otp-digit.filled { border-color:#4f46e5;background:#eef2ff; }
.otp-digit.error  { border-color:#dc2626;background:#fef2f2;animation:otp-shake 0.35s ease; }
.otp-digit-sep { font-size:1.4rem;font-weight:300;color:#d1d5db;user-select:none;margin:0 2px; }
@keyframes otp-shake {
    0%,100% { transform:translateX(0); }
    20%     { transform:translateX(-4px); }
    40%     { transform:translateX(4px); }
    60%     { transform:translateX(-3px); }
    80%     { transform:translateX(3px); }
}
@media (max-width:400px) {
    .otp-digit { width:40px;height:48px;font-size:1.25rem;border-radius:8px; }
    .otp-digits { gap:5px; }
}

/* Resend / actions row */
.otp-actions { display:flex;flex-direction:column;align-items:center;gap:4px;margin-top:14px; }
.otp-timer { font-weight:700;color:#4f46e5; }

/* Footer */
.otp-footer { text-align:center;margin-top:22px;font-size:0.88rem;color:#6b7280; }
.otp-footer a { color:#4f46e5;font-weight:600;text-decoration:none; }
.otp-footer a:hover { text-decoration:underline; }
</style>

<script>
// ── State ────────────────────────────────────────────────────
var _suMobile   = '';
var _suResendTimer;

// ── Utilities ────────────────────────────────────────────────
function showErr(id, msg) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
}
function hideErr(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
function setBtn(id, loading, label) {
    var btn  = document.getElementById(id);
    var text = document.getElementById(id + '-text');
    var spin = document.getElementById(id + '-spin');
    if (!btn) return;
    btn.disabled = loading;
    if (text) text.textContent = label;
    if (spin) spin.style.display = loading ? 'inline-block' : 'none';
}
function xhrPost(url, data, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (tokenMeta) {
        xhr.setRequestHeader('X-CSRF-Token', tokenMeta.getAttribute('content'));
    }
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (xhr.status >= 200 && xhr.status < 300) {
            try { onSuccess(JSON.parse(xhr.responseText)); }
            catch (e) { onError('Unexpected server response. Please try again.'); }
        } else {
            onError('Network error (' + xhr.status + '). Please try again.');
        }
    };
    xhr.onerror = function () { onError('Network error. Please check your connection.'); };
    xhr.send(JSON.stringify(data));
}

// ── OTP digit boxes ──────────────────────────────────────────
function getOtpValue() {
    var boxes = document.querySelectorAll('#su-otp-digits .otp-digit');
    var val = '';
    boxes.forEach(function (b) { val += b.value || ''; });
    return val.replace(/\D/g, '');
}
function clearOtpBoxes() {
    document.querySelectorAll('#su-otp-digits .otp-digit').forEach(function (b) {
        b.value = '';
        b.classList.remove('filled', 'error');
    });
}
function shakeOtpBoxes() {
    var boxes = document.querySelectorAll('#su-otp-digits .otp-digit');
    boxes.forEach(function (b) { b.classList.remove('error'); void b.offsetWidth; b.classList.add('error'); });
    setTimeout(function () {
        boxes.forEach(function (b) { b.classList.remove('error'); });
    }, 500);
}
function initOtpDigitBoxes() {
    var boxes = Array.from(document.querySelectorAll('#su-otp-digits .otp-digit'));
    boxes.forEach(function (box, idx) {
        box.addEventListener('input', function () {
            var v = box.value.replace(/\D/g, '');
            box.value = v.slice(-1);
            box.classList.toggle('filled', box.value !== '');
            if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
            if (getOtpValue().length === 6) { setTimeout(verifySignupOtp, 80); }
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace') {
                if (!box.value && idx > 0) {
                    boxes[idx - 1].value = '';
                    boxes[idx - 1].classList.remove('filled');
                    boxes[idx - 1].focus();
                }
            } else if (e.key === 'ArrowLeft'  && idx > 0)              boxes[idx - 1].focus();
              else if (e.key === 'ArrowRight' && idx < boxes.length - 1) boxes[idx + 1].focus();
              else if (e.key === 'Enter')                                  verifySignupOtp();
        });
        box.addEventListener('paste', function (e) {
            var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            if (!pasted) return;
            e.preventDefault();
            for (var i = 0; i < boxes.length && i < pasted.length; i++) {
                boxes[i].value = pasted[i];
                boxes[i].classList.add('filled');
            }
            boxes[Math.min(pasted.length, boxes.length - 1)].focus();
            if (pasted.length >= 6) { setTimeout(verifySignupOtp, 80); }
        });
        box.addEventListener('click', function () { box.select(); });
    });
}

// ── Resend timer ─────────────────────────────────────────────
function startResendTimer() {
    var btn   = document.getElementById('btn-resend');
    var timer = document.getElementById('resend-timer');
    var secs  = 60;
    btn.disabled = true;
    timer.textContent = '(' + secs + 's)';
    clearInterval(_suResendTimer);
    _suResendTimer = setInterval(function () {
        secs--;
        timer.textContent = secs > 0 ? '(' + secs + 's)' : '';
        if (secs <= 0) { clearInterval(_suResendTimer); btn.disabled = false; }
    }, 1000);
}

// ── Step 1: Send OTP ─────────────────────────────────────────
function sendSignupOtp() {
    hideErr('su-error');
    var name   = document.getElementById('su-name').value.trim();
    var email  = document.getElementById('su-email').value.trim();
    var mobile = document.getElementById('su-mobile').value.replace(/\D/g, '');

    if (!name)
        return showErr('su-error', 'Please enter your full name.');
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))
        return showErr('su-error', 'Enter a valid email address.');
    if (mobile.length !== 10)
        return showErr('su-error', 'Enter a valid 10-digit mobile number.');

    setBtn('btn-send', true, 'Sending OTP...');

    xhrPost(
        '/api/otp.php',
        { action: 'send', purpose: 'signup', name: name, email: email, mobile: mobile },
        function (d) {
            if (!d.success) {
                setBtn('btn-send', false, 'Send OTP');
                showErr('su-error', d.message || 'Something went wrong. Please try again.');
                return;
            }
            _suMobile = mobile;
            // Show OTP step
            document.getElementById('step-details').style.display = 'none';
            document.getElementById('su-sent-mobile').textContent = '+91 ' + mobile;
            document.getElementById('step-otp').style.display = 'block';
            setBtn('btn-send', false, 'Send OTP');
            clearOtpBoxes();
            initOtpDigitBoxes();
            startResendTimer();
            // Focus first box
            var first = document.querySelector('#su-otp-digits .otp-digit');
            if (first) setTimeout(function () { first.focus(); }, 80);
        },
        function (errMsg) {
            setBtn('btn-send', false, 'Send OTP');
            showErr('su-error', errMsg);
        }
    );
}

// ── Step 2: Verify OTP ───────────────────────────────────────
function verifySignupOtp() {
    hideErr('su-otp-error');
    var otp = getOtpValue();
    if (otp.length !== 6) {
        shakeOtpBoxes();
        return showErr('su-otp-error', 'Please enter all 6 digits of the OTP.');
    }

    setBtn('btn-verify', true, 'Creating Account...');

    xhrPost(
        '/api/otp.php',
        { action: 'verify', purpose: 'signup', mobile: _suMobile, otp: otp },
        function (d) {
            if (d.success) {
                if (d.need_whatsapp) {
                    document.getElementById('wa-mobile-shown').textContent = '+91 ' + _suMobile;
                    document.getElementById('wa-overlay').style.display = 'flex';
                    setBtn('btn-verify', false, 'Create Account');
                } else {
                    window.location.href = d.redirect || '/user/dashboard';
                }
            } else {
                shakeOtpBoxes();
                setBtn('btn-verify', false, 'Create Account');
                showErr('su-otp-error', d.message || 'Verification failed. Please try again.');
            }
        },
        function (errMsg) {
            shakeOtpBoxes();
            setBtn('btn-verify', false, 'Create Account');
            showErr('su-otp-error', errMsg);
        }
    );
}

// ── Resend OTP ───────────────────────────────────────────────
function resendSignupOtp() {
    hideErr('su-otp-error');
    setBtn('btn-resend', true, 'Resending...');

    xhrPost(
        '/api/otp.php',
        { action: 'send', purpose: 'signup',
          name: document.getElementById('su-name').value.trim(),
          email: document.getElementById('su-email').value.trim(),
          mobile: _suMobile },
        function (d) {
            if (d.success) {
                clearOtpBoxes();
                startResendTimer();
                var first = document.querySelector('#su-otp-digits .otp-digit');
                if (first) first.focus();
            } else {
                showErr('su-otp-error', d.message || 'Could not resend OTP. Please try again.');
                document.getElementById('btn-resend').disabled = false;
            }
        },
        function (errMsg) {
            showErr('su-otp-error', errMsg);
            document.getElementById('btn-resend').disabled = false;
        }
    );
}

function backToDetails() {
    clearInterval(_suResendTimer);
    document.getElementById('step-otp').style.display = 'none';
    document.getElementById('step-details').style.display = 'block';
    hideErr('su-otp-error');
}

// ── WhatsApp ─────────────────────────────────────────────────
function showWaDifferent() {
    document.getElementById('wa-same-section').style.display = 'none';
    document.getElementById('wa-diff-section').style.display = 'block';
}
function saveWhatsapp(same) {
    hideErr('wa-error');
    var waNum = same
        ? _suMobile
        : document.getElementById('wa-number').value.replace(/\D/g, '');
    if (!same && waNum.length !== 10)
        return showErr('wa-error', 'Enter a valid 10-digit WhatsApp number.');
    xhrPost(
        '/api/otp.php',
        { action: 'save_whatsapp', whatsapp_same: same ? '1' : '0', whatsapp_number: waNum, mobile: _suMobile },
        function (d) {
            if (d.success) { window.location.href = d.redirect || '/user/dashboard'; }
            else showErr('wa-error', d.message || 'Could not save number. Please try again.');
        },
        function (errMsg) { showErr('wa-error', errMsg); }
    );
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
