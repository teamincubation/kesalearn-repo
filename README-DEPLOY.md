# KESA Learn — COMPLETE Site Package v2 (June 2026)

The entire `public_html/` with all fixes and features applied. Upload over
your current site (overwrite all), run ONE SQL file, done. Your offline
`uploads/` media is not included — restore it after uploading.

## Deploy

1. **Backup the DB** (phpMyAdmin → Export).
2. **Run the single migration**: `sql/migrations/2026-06-13_consolidated.sql`
   in phpMyAdmin. Safe to re-run; includes the security hardening.
3. **Upload this package** into `public_html/` (overwrite; keep `.htaccess`).
4. **SMTP password**: set `SMTP_PASS` in `config/constants.php` — required
   for email OTP, account-deletion reports and admin notifications.
5. **Restore media** into `uploads/`.
6. **Test OTP immediately**: log in as admin → sidebar → Tools →
   **SMS / OTP Test** → send to your own number. The page shows MSG91's raw
   response, so any remaining problem (key, template, balance, DLT block)
   is identified on the spot.

## What changed in v2 (your latest requests)

### 1. Duplicate accounts: resolve by DELETION (no more merge, no skipping)
When one mobile/WhatsApp number is attached to several emails, the user is
stopped at login on **/auth/resolve_accounts** and must:
1. choose the ONE email that stays active,
2. confirm with a fresh OTP sent to the shared mobile number.
Then every other account on that number is **permanently deleted**:
- certificates & registrations are moved to the kept account first (so
  earned certificates are never lost and stay publicly verifiable),
- a **full history report is emailed to each deleted address**,
- **info@kesalearn.com receives an admin notification**,
- an audit row is stored in the new `account_deletions` table.
The user cannot reach the dashboard until the number maps to exactly one
account. Email + phone uniqueness is thereby guaranteed.

### 2. OTP send fixed
- Your MSG91 Auth Key is now baked into `config/sms.php`
  (`512162AUwWWf1Hr69f1288dP1`, template KESA_OTP, sender LABINC).
- The service first calls MSG91's **v5 OTP API**; if MSG91 rejects it
  (e.g. the template is registered as a *Flow* template rather than an
  *OTP* template), it automatically retries via the **v5 Flow API** with
  the same DLT template — covering both registration types.
- Every attempt is logged to the new `sms_logs` table and visible in
  **Admin → Tools → SMS / OTP Test**.
- The migration force-enables `otp_module_enabled` (it was '0' in your DB —
  one of the reasons nothing was sent). **If OTP still fails after this,
  the test page will show MSG91's exact error — send me that text and the
  template's variable name from your MSG91 dashboard.**

### 3. Instructor certificates with certificate numbers
- Upload form now requires the **certificate number printed on the
  certificate** (unique).
- `/certificate/search` finds instructor certificates by auto code
  (KESA-INST-…) **or** by that printed number, shows both, and offers
  **View** + **Download** (new endpoint `/certificate/download-instructor`
  with download counting and path-traversal protection).

### 4. "Verify Profile" removed completely
- Admin sidebar item **Verify Profiles** and its page
  (`admin/users/completed-profiles.php`) deleted; no remaining
  `profile_completion` references.
- The learner dashboard card was de-verified: now simply
  "Set Your Certificate Name" with a Save button.
- Stale `user/profile-backup.php` removed.

### 5. Premium UI/UX (white + KESA brand colours)
`assets/css/enhancements.css` is now a full premium theme layered over
your existing pages — no markup rewrites, so nothing breaks:
- **Learner portal**: welcome hero with the 4-colour KESA ribbon, K-red
  primary actions, stat cards individually accented red/purple/blue/yellow
  (E·S·A colours on demand), refined quick actions, upcoming-event rows
  with blue date chips, pulsing LIVE badge, achievement-yellow certificate
  name card, frosted bottom navigation with K-red active state.
- **Admin console**: deep-ink sidebar with K-red active pill and yellow
  count badges, frosted topbar, 4-colour stat cards, sticky premium table
  headers with red-tint row hover, rounded inputs everywhere.
- **Auth/OTP screens**: gradient K-red primary buttons, soft focus rings.
- 44px touch targets, iOS no-zoom inputs, reduced-motion support, branded
  scrollbar, print styles. Admin pages now load this layer too.

## Earlier fixes still included
Working `api/otp.php` (the old one had a fatal syntax error), email OTP
channel, contact-completion gate, certificate search page, hardened
`.htaccess` (blocks all removed debug/reset scripts, HSTS, no PHP in
uploads), 8 broken legacy pages repaired, local logo file, dangerous files
removed (see SECURITY-NOTES.txt), daily `cron/security-cleanup.php`.

## Still rotate these
MSG91 auth key (it's in this chat history), Razorpay secret + webhook
secret, DB password — then update the matching config files.
