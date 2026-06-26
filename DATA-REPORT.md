# KESA Learn — Data Quality Report (from DB dump, 12 Jun 2026)

| Table | Rows |
|---|---|
| users | 2319 |
| certificates | 1503 |
| registrations | 3444 |
| events | 67 |

## Findings

- **1390 of 2319 users (59%) have no phone number** — they will be asked to add & OTP-verify one at next login (contact gate).
- **20 phone numbers are shared by more than one account** (41 accounts involved) — affected users must choose ONE email to keep at next login; the others are deleted after OTP confirmation (records transferred, history emailed, admin notified).
- Duplicate emails: 0 (email has a UNIQUE constraint — good).
- Placeholder accounts: 1421.
- Admin accounts on file: 7 — review that each is expected:
  - Adnan V A <adnanmongam@gmail.com>
  - Super Admin <admin@kesalearn.com>
  - Muhammed Ibnu Subair <mibnusubair@gmail.com>
  - Nafia Nasrin V P <nazrinvp7012@gmail.com>
  - Sayyid Shaheer <sayyidshaheer007@gmail.com>
  - Dr. Jihada K <jihadassv@gmail.com>
  - Ashfaq Jafar KP <ashfaqjafarkp@gmail.com>

- Referential integrity between certificates ⇄ users/events is enforced by FOREIGN KEY constraints in the schema (verified) — no orphan risk.

## Top shared phone numbers (merge candidates)

| Last-10 digits | Accounts |
|---|---|
| ******6458 | 3 |
| ******9880 | 2 |
| ******2353 | 2 |
| ******5630 | 2 |
| ******9401 | 2 |
| ******3915 | 2 |
| ******2421 | 2 |
| ******6339 | 2 |
| ******0444 | 2 |
| ******2909 | 2 |
| ******5637 | 2 |
| ******9341 | 2 |
| ******1078 | 2 |
| ******2315 | 2 |
| ******6872 | 2 |

## What the upgrade does about it

1. **Contact gate** — users missing a phone (or valid email) must add and
   OTP-verify it before continuing (auth/complete_contact.php).
2. **Duplicate gate (mandatory)** — when one mobile number has multiple
   emails, the user picks the email to keep; the rest are deleted after a
   fresh mobile OTP. Certificates/registrations move to the kept account,
   a full history is emailed to each removed address, and
   info@kesalearn.com is notified (auth/resolve_accounts.php).
3. **Hygiene** — the consolidated migration + cron/security-cleanup.php purge
   expired OTPs/tokens and clear stale lockouts.

