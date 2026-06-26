<?php
/**
 * KESA Learn - Session Notification Cron Job
 * 
 * This script should be run via cron job every minute to check for events
 * that are about to start and send notifications to registered users.
 * 
 * Cron example (every minute):
 * * * * * * /usr/bin/php /path/to/kesa-learn/cron/send-session-notifications.php
 * 
 * Security: Protect this file or use a secret token
 */

// Verify cron token for security (optional but recommended)
$cronToken = $_GET['token'] ?? '';
$expectedToken = getenv('CRON_SECRET_TOKEN') ?: 'your-secret-cron-token';

if ($cronToken !== $expectedToken && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Unauthorized');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = getDB();

// Track sent notifications in database to prevent duplicates
// Check for events starting in the next 5 minutes that haven't been notified
// Use IST timezone for all timestamp calculations
$istTz = new DateTimeZone('Asia/Kolkata');
$nowDt = new DateTime('now', $istTz);
$checkTimeDt = (clone $nowDt)->modify('+5 minutes');
$checkTime = $checkTimeDt->format('Y-m-d H:i:s');
$now = $nowDt->format('Y-m-d H:i:s');

// Create notification log table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id` INT UNSIGNED NOT NULL,
    `notification_type` VARCHAR(50) NOT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_notification` (`event_id`, `notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Get events starting within 5 minutes that haven't been notified
$stmt = $db->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM notification_logs nl WHERE nl.event_id = e.id AND nl.notification_type = 'session_start') as notified
    FROM events e 
    WHERE e.status = 'published' 
    AND e.start_date BETWEEN ? AND ?
    HAVING notified = 0
");
$stmt->execute([$now, $checkTime]);
$upcomingEvents = $stmt->fetchAll();

$notificationsSent = 0;
$errors = [];

foreach ($upcomingEvents as $event) {
    // Get speakers for this event
    $speakersStmt = $db->prepare("SELECT name, title FROM event_speakers WHERE event_id = ? ORDER BY sort_order");
    $speakersStmt->execute([$event['id']]);
    $event['speakers'] = $speakersStmt->fetchAll();
    
    // Get all registered users with confirmed payment
    $usersStmt = $db->prepare("
        SELECT u.id, u.name, u.email 
        FROM registrations r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.event_id = ? 
        AND r.payment_status IN ('paid', 'verified')
    ");
    $usersStmt->execute([$event['id']]);
    $registeredUsers = $usersStmt->fetchAll();
    
    // Send notification to each user
    foreach ($registeredUsers as $user) {
        try {
            $sent = sendSessionStartingEmail($user['email'], $user['name'], $event);
            if ($sent) {
                $notificationsSent++;
            } else {
                $errors[] = "Failed to send to {$user['email']} for event {$event['id']}";
            }
        } catch (Exception $e) {
            $errors[] = "Error sending to {$user['email']}: " . $e->getMessage();
        }
    }
    
    // Mark this event as notified
    try {
        $logStmt = $db->prepare("INSERT INTO notification_logs (event_id, notification_type) VALUES (?, 'session_start')");
        $logStmt->execute([$event['id']]);
    } catch (PDOException $e) {
        // Ignore duplicate key errors (already notified)
    }
    
    // Log activity
    logActivity('session_notifications_sent', "Sent session start notifications for: {$event['title']} ({$notificationsSent} emails)");
}

// Also check for 24-hour reminders
$reminder24h = date('Y-m-d H:i:s', strtotime('+24 hours'));
$reminder24hEnd = date('Y-m-d H:i:s', strtotime('+24 hours 5 minutes'));

$stmt = $db->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM notification_logs nl WHERE nl.event_id = e.id AND nl.notification_type = 'reminder_24h') as notified
    FROM events e 
    WHERE e.status = 'published' 
    AND e.start_date BETWEEN ? AND ?
    HAVING notified = 0
");
$stmt->execute([$reminder24h, $reminder24hEnd]);
$events24h = $stmt->fetchAll();

foreach ($events24h as $event) {
    $usersStmt = $db->prepare("
        SELECT u.id, u.name, u.email 
        FROM registrations r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.event_id = ? 
        AND r.payment_status IN ('paid', 'verified')
    ");
    $usersStmt->execute([$event['id']]);
    $registeredUsers = $usersStmt->fetchAll();
    
    foreach ($registeredUsers as $user) {
        try {
            sendEventReminderEmail($user['email'], $user['name'], $event, '24h');
            $notificationsSent++;
        } catch (Exception $e) {
            $errors[] = "Error sending 24h reminder to {$user['email']}: " . $e->getMessage();
        }
    }
    
    try {
        $logStmt = $db->prepare("INSERT INTO notification_logs (event_id, notification_type) VALUES (?, 'reminder_24h')");
        $logStmt->execute([$event['id']]);
    } catch (PDOException $e) {
        // Ignore
    }
}

// Also check for 1-hour reminders
$reminder1h = date('Y-m-d H:i:s', strtotime('+1 hour'));
$reminder1hEnd = date('Y-m-d H:i:s', strtotime('+1 hour 5 minutes'));

$stmt = $db->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM notification_logs nl WHERE nl.event_id = e.id AND nl.notification_type = 'reminder_1h') as notified
    FROM events e 
    WHERE e.status = 'published' 
    AND e.start_date BETWEEN ? AND ?
    HAVING notified = 0
");
$stmt->execute([$reminder1h, $reminder1hEnd]);
$events1h = $stmt->fetchAll();

foreach ($events1h as $event) {
    $usersStmt = $db->prepare("
        SELECT u.id, u.name, u.email 
        FROM registrations r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.event_id = ? 
        AND r.payment_status IN ('paid', 'verified')
    ");
    $usersStmt->execute([$event['id']]);
    $registeredUsers = $usersStmt->fetchAll();
    
    foreach ($registeredUsers as $user) {
        try {
            sendEventReminderEmail($user['email'], $user['name'], $event, '1h');
            $notificationsSent++;
        } catch (Exception $e) {
            $errors[] = "Error sending 1h reminder to {$user['email']}: " . $e->getMessage();
        }
    }
    
    try {
        $logStmt = $db->prepare("INSERT INTO notification_logs (event_id, notification_type) VALUES (?, 'reminder_1h')");
        $logStmt->execute([$event['id']]);
    } catch (PDOException $e) {
        // Ignore
    }
}

// Output results (for cron logs)
$result = [
    'success' => true,
    'notifications_sent' => $notificationsSent,
    'errors' => $errors,
    'timestamp' => date('Y-m-d H:i:s')
];

if (php_sapi_name() === 'cli') {
    echo "Notifications sent: $notificationsSent\n";
    if (!empty($errors)) {
        echo "Errors:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
    }
} else {
    header('Content-Type: application/json');
    echo json_encode($result);
}
