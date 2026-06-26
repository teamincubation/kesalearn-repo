<?php
/**
 * KESA Learn - SMS / OTP Configuration (MSG91, DLT compliant)
 *
 * IMPORTANT: Paste your real MSG91 Auth Key below before going live.
 * This directory is blocked from web access by .htaccess.
 * After editing, never commit this file to any public repository.
 */

// ── MSG91 credentials ────────────────────────────────────────────
define('MSG91_AUTH_KEY',    '512162AUwWWf1Hr69f1288dP1');
define('MSG91_SENDER_ID',   'LABINC');
define('MSG91_TEMPLATE_ID', '69f10a1f581a609c56083d42');       // DLT approved template: KESA_OTP
define('MSG91_DLT_ENTITY',  '1701177741453647901');

// ── OTP behaviour ────────────────────────────────────────────────
define('OTP_LENGTH',           6);
define('OTP_EXPIRY_SECONDS',   600);  // 10 minutes
define('OTP_RESEND_COOLDOWN',  30);   // seconds between resends
define('OTP_MAX_SENDS_WINDOW', 3);    // max sends per number per window
define('OTP_SEND_WINDOW',      600);  // window for the above (seconds)
define('OTP_MAX_ATTEMPTS',     5);    // wrong-code attempts before invalidation
define('OTP_IP_HOURLY_CAP',    15);   // max sends per IP per hour
