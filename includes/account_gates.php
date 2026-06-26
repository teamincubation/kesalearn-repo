<?php
/**
 * KESA Learn - Account Gates
 *
 * Runs on every logged-in page (via auth_check.php). Two gates:
 *
 *   1. CONTACT GATE - account is missing a valid email or mobile number
 *      -> /auth/complete_contact.php (add + OTP-verify).
 *
 *   2. DUPLICATE GATE - the same mobile number (phone / mobile_number /
 *      WhatsApp) is attached to MORE THAN ONE account with different
 *      emails. Email and phone must be unique, so the user MUST choose
 *      which email stays active; every other account on that number is
 *      DELETED after a fresh mobile-OTP confirmation. Before deletion:
 *        - non-conflicting certificates & registrations are moved to the
 *          kept account (so earned certificates are never lost),
 *        - a full history report is emailed to the deleted account's email,
 *        - the admin (info@kesalearn.com) is notified.
 *      There is NO skip option - the user cannot reach the dashboard until
 *      only one account owns the number.
 */

require_once __DIR__ . '/otp_service.php';

const KESA_ADMIN_EMAIL = 'info@kesalearn.com';

function runAccountGates(): void {
    if (!isset($_SESSION['user_id'])) return;
    if (!empty($_SESSION['gates_checked'])) return;

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $exempt = ['/auth/complete_contact.php', '/auth/resolve_accounts.php', '/auth/logout.php'];
    foreach ($exempt as $e) {
        if (str_ends_with($script, $e) || str_starts_with($script, '/api/')) return;
    }

    $db = getDB();
    $st = $db->prepare("SELECT id, email, phone, mobile_number FROM users WHERE id = ?");
    $st->execute([(int)$_SESSION['user_id']]);
    $me = $st->fetch();
    if (!$me) return;

    // Gate 1: missing contact info
    $hasEmail  = filter_var($me['email'], FILTER_VALIDATE_EMAIL)
                 && !str_contains($me['email'], '@placeholder');
    $hasMobile = phoneKey($me['mobile_number']) !== '' || phoneKey($me['phone']) !== '';
    if (!$hasEmail || !$hasMobile) {
        header('Location: /auth/complete_contact.php');
        exit;
    }

    // Gate 2: this mobile number is attached to more than one account
    $myKey = phoneKey($me['mobile_number']) ?: phoneKey($me['phone']);
    if ($myKey !== '' && count(accountsOnNumber($db, $myKey)) > 1) {
        header('Location: /auth/resolve_accounts.php');
        exit;
    }

    $_SESSION['gates_checked'] = 1;
}

/** All live accounts whose mobile_number OR phone ends with the same 10 digits. */
function accountsOnNumber(PDO $db, string $phoneKey): array {
    $st = $db->prepare(
        "SELECT id, name, email, phone, mobile_number, created_at, role
         FROM users
         WHERE RIGHT(REGEXP_REPLACE(COALESCE(mobile_number,''),'[^0-9]',''),10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]',''),10) = ?
         ORDER BY id ASC"
    );
    $st->execute([$phoneKey, $phoneKey]);
    return $st->fetchAll();
}

/** Registrations + certificates history for an account (for the report email). */
function accountHistory(PDO $db, int $uid): array {
    $regs = $certs = [];
    try {
        $st = $db->prepare(
            "SELECT r.id, r.payment_status, r.created_at, e.title, e.start_date
             FROM registrations r LEFT JOIN events e ON e.id = r.event_id
             WHERE r.user_id = ? ORDER BY r.created_at DESC"
        );
        $st->execute([$uid]);
        $regs = $st->fetchAll();
    } catch (PDOException $e) {}
    try {
        $st = $db->prepare(
            "SELECT c.certificate_code, c.certificate_number, c.issue_date, c.generated_at, e.title
             FROM certificates c LEFT JOIN events e ON e.id = c.event_id
             WHERE c.user_id = ? ORDER BY c.generated_at DESC"
        );
        $st->execute([$uid]);
        $certs = $st->fetchAll();
    } catch (PDOException $e) {}
    return ['registrations' => $regs, 'certificates' => $certs];
}

/**
 * Delete $dupId after moving its records to $keepId.
 * Order matters: transfers MUST happen before the DELETE because the
 * certificates/registrations foreign keys are ON DELETE CASCADE.
 */
function executeAccountDeletion(PDO $db, int $keepId, int $dupId): array {
    if ($keepId === $dupId) return ['success' => false, 'message' => 'Cannot delete the account you chose to keep.'];

    // Snapshot details for the report BEFORE anything moves
    $st = $db->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $st->execute([$dupId]);
    $dup = $st->fetch();
    if (!$dup) return ['success' => false, 'message' => 'Account not found.'];
    $history = accountHistory($db, $dupId);

    $tables = [ // table => unique column scoped with user_id (NULL = none)
        'registrations'          => 'event_id',
        'certificates'           => 'event_id',
        'quiz_attempts'          => null,
        'assignment_submissions' => null,
        'feedbacks'              => null,
        'feedback_requests'      => null,
        'event_ratings'          => 'event_id',
        'session_attendance'     => null,
        'material_reads'         => null,
        'certificate_downloads'  => null,
        'coupon_usages'          => null,
    ];

    try {
        $db->beginTransaction();

        $moved = [];
        foreach ($tables as $table => $uniqueCol) {
            try {
                if ($uniqueCol === null) {
                    $st = $db->prepare("UPDATE {$table} SET user_id = ? WHERE user_id = ?");
                    $st->execute([$keepId, $dupId]);
                } else {
                    $st = $db->prepare(
                        "UPDATE {$table} t SET t.user_id = ?
                         WHERE t.user_id = ?
                           AND NOT EXISTS (
                               SELECT 1 FROM (SELECT {$uniqueCol} FROM {$table} WHERE user_id = ?) p
                               WHERE p.{$uniqueCol} = t.{$uniqueCol})"
                    );
                    $st->execute([$keepId, $dupId, $keepId]);
                }
                $moved[$table] = $st->rowCount();
            } catch (PDOException $e) {
                if (!in_array($e->getCode(), ['42S02', '42S22'])) throw $e;
            }
        }

        // Backfill profile fields the kept account is missing
        $db->prepare(
            "UPDATE users p JOIN users d ON d.id = ?
             SET p.dob = COALESCE(p.dob, d.dob),
                 p.gender = COALESCE(p.gender, d.gender),
                 p.country = COALESCE(p.country, d.country),
                 p.state = COALESCE(p.state, d.state),
                 p.district = COALESCE(p.district, d.district),
                 p.college = COALESCE(p.college, d.college),
                 p.city = COALESCE(p.city, d.city),
                 p.certificate_name = COALESCE(p.certificate_name, d.certificate_name),
                 p.profile_image = COALESCE(p.profile_image, d.profile_image),
                 p.google_id = COALESCE(p.google_id, d.google_id)
             WHERE p.id = ?"
        )->execute([$dupId, $keepId]);
        // google_id is UNIQUE-indexed per account semantics - clear it on the
        // duplicate before the COALESCE result could collide on re-runs
        $db->prepare("UPDATE users SET google_id = NULL WHERE id = ?")->execute([$dupId]);

        // Audit row (survives the deletion - no FK on purpose)
        $db->prepare(
            "INSERT INTO account_deletions
             (kept_user_id, deleted_user_id, deleted_email, deleted_name, moved_summary, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$keepId, $dupId, $dup['email'], $dup['name'], json_encode($moved)]);

        // Hard delete - cascades clean every remaining child row
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$dupId]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[ACCOUNT-DELETE] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Could not remove the account. No data was changed. Please contact support.'];
    }

    // Emails are sent after the commit (failure to send never blocks the flow)
    sendDeletionReportEmail($db, $dup, $keepId, $history, $moved);
    notifyAdminOfDeletion($db, $dup, $keepId, $history);

    return ['success' => true, 'message' => 'Account removed.', 'moved' => $moved];
}

function sendDeletionReportEmail(PDO $db, array $dup, int $keepId, array $history, array $moved): void {
    $st = $db->prepare("SELECT email FROM users WHERE id = ?");
    $st->execute([$keepId]);
    $keptEmail = (string)$st->fetchColumn();

    $rows = '';
    foreach ($history['registrations'] as $r) {
        $rows .= '<tr><td style="padding:6px 10px;border:1px solid #eee;">Registration</td>'
               . '<td style="padding:6px 10px;border:1px solid #eee;">' . htmlspecialchars($r['title'] ?? 'Event') . '</td>'
               . '<td style="padding:6px 10px;border:1px solid #eee;">' . htmlspecialchars($r['payment_status'] ?? '') . '</td>'
               . '<td style="padding:6px 10px;border:1px solid #eee;">' . htmlspecialchars(substr((string)$r['created_at'], 0, 10)) . '</td></tr>';
    }
    foreach ($history['certificates'] as $c) {
        $rows .= '<tr><td style="padding:6px 10px;border:1px solid #eee;">Certificate</td>'
               . '<td style="padding:6px 10px;border:1px solid #eee;">' . htmlspecialchars($c['title'] ?? 'Event') . '</td>'
               . '<td style="padding:6px 10px;border:1px solid #eee;">' . htmlspecialchars($c['certificate_code'] . ($c['certificate_number'] ? ' / ' . $c['certificate_number'] : '')) . '</td>'
               . '<td style="padding:6px 10px;border:1px solid #eee;">' . htmlspecialchars(substr((string)($c['issue_date'] ?: $c['generated_at']), 0, 10)) . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="4" style="padding:10px;border:1px solid #eee;color:#777;">No registrations or certificates were on this account.</td></tr>';

    $body = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;padding:24px;border:1px solid #eee;border-radius:12px">'
          . '<h2 style="color:#e7404a;margin:0 0 10px">KESA Learn</h2>'
          . '<p>Hi ' . htmlspecialchars($dup['name']) . ',</p>'
          . '<p>As you requested during sign-in, the KESA account registered to <strong>' . htmlspecialchars($dup['email']) . '</strong> has been removed because your mobile number must belong to a single account. Your active account is now <strong>' . htmlspecialchars($keptEmail) . '</strong>.</p>'
          . '<p>Your certificates and event registrations from this account were transferred to your active account wherever possible. For your records, here is the complete history of the removed account:</p>'
          . '<table style="border-collapse:collapse;width:100%;font-size:13px">'
          . '<tr style="background:#fef0f0"><th style="padding:6px 10px;border:1px solid #eee;text-align:left">Type</th><th style="padding:6px 10px;border:1px solid #eee;text-align:left">Event</th><th style="padding:6px 10px;border:1px solid #eee;text-align:left">Details</th><th style="padding:6px 10px;border:1px solid #eee;text-align:left">Date</th></tr>'
          . $rows . '</table>'
          . '<p style="margin-top:14px">Certificates remain verifiable at <a href="https://kesalearn.com/certificate/search">kesalearn.com/certificate/search</a>.</p>'
          . '<p style="color:#777;font-size:13px">If you did not request this, contact us immediately at ' . KESA_ADMIN_EMAIL . '.</p>'
          . '</div>';

    sendKesaMail($dup['email'], 'Your KESA Learn account was removed - full history attached', $body);
}

function notifyAdminOfDeletion(PDO $db, array $dup, int $keepId, array $history): void {
    $st = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $st->execute([$keepId]);
    $keep = $st->fetch() ?: ['name' => '?', 'email' => '?'];

    $body = '<div style="font-family:Arial,sans-serif;font-size:14px">'
          . '<h3 style="color:#e7404a">Duplicate account resolved by user</h3>'
          . '<p><strong>Deleted:</strong> ' . htmlspecialchars($dup['name']) . ' &lt;' . htmlspecialchars($dup['email']) . '&gt; (user #' . (int)$dup['id'] . ')<br>'
          . '<strong>Kept active:</strong> ' . htmlspecialchars($keep['name']) . ' &lt;' . htmlspecialchars($keep['email']) . '&gt; (user #' . $keepId . ')<br>'
          . '<strong>Records on the deleted account:</strong> '
          . count($history['registrations']) . ' registration(s), '
          . count($history['certificates']) . ' certificate(s) - transferred to the kept account where no conflict existed.</p>'
          . '<p>Confirmed by the user with a mobile OTP on the shared number. A full history report was emailed to the deleted address. Audit row stored in account_deletions.</p>'
          . '</div>';

    sendKesaMail(KESA_ADMIN_EMAIL, 'KESA: duplicate account deleted - ' . $dup['email'], $body);
}

/** sendEmail() from mailer.php with a raw mail() fallback. */
function sendKesaMail(string $to, string $subject, string $html): bool {
    $sent = false;
    $mailer = __DIR__ . '/mailer.php';
    if (is_file($mailer)) {
        require_once $mailer;
        if (function_exists('sendEmail')) $sent = sendEmail($to, $subject, $html);
    }
    if (!$sent) {
        $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'KESA Learn')
                  . ' <' . (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'hello@kesalearn.com') . ">\r\n";
        $sent = @mail($to, $subject, $html, $headers);
    }
    if (!$sent) error_log('[MAIL] failed to ' . $to . ' / ' . $subject);
    return $sent;
}
