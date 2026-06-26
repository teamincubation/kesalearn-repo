<?php
/**
 * Admin: Process Email Queue
 * This page processes pending emails from the queue
 * Can be called manually or via cron job
 */

require_once __DIR__ . '/../../includes/auth_check.php';

// Allow access from command line or admin panel
$isCommandLine = php_sapi_name() === 'cli';
$isAdmin = $isCommandLine || (isset($_SESSION['admin_id']) && hasAdminPermission('system'));

if (!$isAdmin && !$isCommandLine) {
    redirect('/admin/');
}

// Process the email queue
$sent = processEmailQueue();

if ($isCommandLine) {
    echo "Email queue processed. Emails sent: $sent\n";
} else {
    setFlash('success', "Email queue processed. $sent email(s) sent successfully.");
    redirect('/admin/tools');
}
?>
