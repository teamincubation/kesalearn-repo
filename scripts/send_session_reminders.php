<?php
/**
 * KESA Learn - Live Session Reminder Notifications
 * Sends email/SMS reminders 5 minutes before scheduled live sessions
 * 
 * Run via cron job every minute:
 * * * * * php /path/to/kesa-learn/scripts/send_session_reminders.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

try {
    $db = getDB();
    
    // Get current time
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $reminderTime = $now->format('Y-m-d H:i:s');
    
    // Calculate 5-minute window: current time to 6 minutes ahead
    $futureTime = (new DateTime('now', new DateTimeZone('UTC')))->add(new DateInterval('PT6M'))->format('Y-m-d H:i:s');
    $pastTime = (new DateTime('now', new DateTimeZone('UTC')))->sub(new DateInterval('PT1M'))->format('Y-m-d H:i:s');
    
    echo "[LOG] Running session reminders check at $reminderTime\n";
    
    // Find sessions starting between now and 6 minutes in the future
    $stmt = $db->prepare("
        SELECT ls.*, e.title as event_title, e.id as event_id
        FROM live_sessions ls
        JOIN events e ON ls.event_id = e.id
        WHERE ls.status = 'scheduled' 
        AND ls.start_datetime >= ?
        AND ls.start_datetime <= ?
    ");
    $stmt->execute([$pastTime, $futureTime]);
    $upcomingSessions = $stmt->fetchAll();
    
    echo "[LOG] Found " . count($upcomingSessions) . " upcoming sessions\n";
    
    foreach ($upcomingSessions as $session) {
        $sessionId = $session['id'];
        $eventId = $session['event_id'];
        
        // Check if reminder already sent for this session
        $notificationStmt = $db->prepare("
            SELECT id FROM session_notifications 
            WHERE session_id = ? 
            AND notification_type = 'reminder'
            LIMIT 1
        ");
        $notificationStmt->execute([$sessionId]);
        $alreadySent = $notificationStmt->fetch();
        
        if ($alreadySent) {
            echo "[LOG] Reminder already sent for session $sessionId\n";
            continue;
        }
        
        // Get all registered users for this event
        $usersStmt = $db->prepare("
            SELECT DISTINCT u.id, u.name, u.email, u.phone
            FROM registrations r
            JOIN users u ON r.user_id = u.id
            WHERE r.event_id = ? AND r.status = 'confirmed'
        ");
        $usersStmt->execute([$eventId]);
        $registeredUsers = $usersStmt->fetchAll();
        
        echo "[LOG] Sending reminder notifications to " . count($registeredUsers) . " users for session $sessionId\n";
        
        // Send reminders to each user
        $sentCount = 0;
        $failedCount = 0;
        
        foreach ($registeredUsers as $user) {
            $userId = $user['id'];
            $userName = $user['name'];
            $userEmail = $user['email'];
            $userPhone = $user['phone'];
            
            // Prepare email content
            $sessionTitle = $session['title'] ?? $session['event_title'];
            $startTime = date('M d, Y h:i A', strtotime($session['start_datetime']));
            $meetingLink = $session['meeting_link'] ?? '#';
            $platform = ucfirst(str_replace('_', ' ', $session['platform']));
            
            $emailSubject = "🔔 Live Session Starting Soon - $sessionTitle";
            $emailBody = "
                <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 8px; }
                            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
                            .header h1 { margin: 0; font-size: 24px; }
                            .content { background: white; padding: 20px; }
                            .session-details { background: #f0f4ff; padding: 15px; border-left: 4px solid #667eea; margin: 15px 0; border-radius: 4px; }
                            .session-details p { margin: 8px 0; }
                            .cta-button { display: inline-block; padding: 12px 30px; background: #25D366; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; font-weight: bold; }
                            .footer { background: #f9f9f9; padding: 15px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; margin-top: 20px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>Live Session Starting Soon!</h1>
                            </div>
                            <div class='content'>
                                <p>Hi <strong>$userName</strong>,</p>
                                
                                <p>A live session you&apos;re registered for is starting in about <strong>5 minutes</strong>!</p>
                                
                                <div class='session-details'>
                                    <p><strong>Session:</strong> $sessionTitle</p>
                                    <p><strong>Starting at:</strong> $startTime</p>
                                    <p><strong>Platform:</strong> $platform</p>
                                </div>
                                
                                <p>Click the button below to join the live session:</p>
                                <a href='$meetingLink' class='cta-button'>Join Now</a>
                                
                                <p style='margin-top: 20px; color: #666; font-size: 14px;'>
                                    If you can&apos;t access the link above, go to your event dashboard and click the live session link.
                                </p>
                            </div>
                            <div class='footer'>
                                <p>This is an automated notification from KESA Learn. Please do not reply to this email.</p>
                            </div>
                        </div>
                    </body>
                </html>
            ";
            
            // Send email
            if (sendEmail($userEmail, $emailSubject, $emailBody)) {
                $sentCount++;
                echo "[OK] Email sent to $userEmail for session $sessionId\n";
            } else {
                $failedCount++;
                echo "[ERROR] Failed to send email to $userEmail for session $sessionId\n";
            }
            
            // Log notification in database
            $logStmt = $db->prepare("
                INSERT INTO session_notifications (session_id, user_id, notification_type)
                VALUES (?, ?, 'reminder')
            ");
            $logStmt->execute([$sessionId, $userId]);
        }
        
        // Update session status to 'live' if start time has passed
        $sessionStartTime = new DateTime($session['start_datetime']);
        if ($now >= $sessionStartTime) {
            $updateStmt = $db->prepare("
                UPDATE live_sessions 
                SET status = 'live'
                WHERE id = ? AND status = 'scheduled'
            ");
            $updateStmt->execute([$sessionId]);
            echo "[LOG] Session $sessionId status updated to 'live'\n";
        }
        
        echo "[LOG] Completed session $sessionId: $sentCount sent, $failedCount failed\n";
    }
    
    echo "[LOG] Session reminder script completed successfully\n";
    
} catch (Exception $e) {
    error_log("[ERROR] Session reminder script failed: " . $e->getMessage());
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
