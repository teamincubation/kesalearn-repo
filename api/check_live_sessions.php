<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

try {
    // Check sessions that need status updates
    // 1. Move scheduled sessions to live if start time has passed and reminder hasn't been auto-sent
    // 2. Move live sessions to completed if end time has passed
    
    $now = new DateTime();
    $currentTime = $now->format('Y-m-d H:i:s');
    
    // Find sessions that should be marked as live
    $liveStmt = $db->prepare("
        SELECT id, title, start_datetime, end_datetime, created_by, auto_notification_enabled
        FROM live_sessions 
        WHERE status = 'scheduled' 
        AND start_datetime <= NOW()
        AND is_manually_started = FALSE
    ");
    $liveStmt->execute();
    $sessionsToStart = $liveStmt->fetchAll();
    
    foreach ($sessionsToStart as $session) {
        // Update status to live
        $db->prepare("UPDATE live_sessions SET status = 'live' WHERE id = ?")->execute([$session['id']]);
        
        // Send auto-notification if enabled and not already sent
        if ($session['auto_notification_enabled']) {
            $reminderCheckStmt = $db->prepare("
                SELECT id FROM session_notifications 
                WHERE session_id = ? AND notification_type = 'start'
            ");
            $reminderCheckStmt->execute([$session['id']]);
            
            if (!$reminderCheckStmt->fetch()) {
                // Get all registered users for this session's event
                $eventStmt = $db->prepare("
                    SELECT DISTINCT u.id, u.email, u.name 
                    FROM registrations r
                    JOIN users u ON r.user_id = u.id
                    WHERE r.session_id = ? AND u.email IS NOT NULL
                ");
                $eventStmt->execute([$session['id']]);
                $users = $eventStmt->fetchAll();
                
                // Send live start emails
                foreach ($users as $user) {
                    $subject = "Live Session Started: {$session['title']}";
                    $message = "
                    <h2>Live Session Has Started!</h2>
                    <p>Hi {$user['name']},</p>
                    <p>The live session <strong>{$session['title']}</strong> has started now.</p>
                    <p><a href='" . BASE_URL . "/live-sessions/{$session['id']}' style='background: #25D366; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;'>Join Now</a></p>
                    <p>See you in the session!</p>
                    ";
                    
                    sendEmail($user['email'], $subject, $message);
                    
                    // Log notification
                    $db->prepare("
                        INSERT INTO session_notifications (session_id, user_id, notification_type, sent_at, status)
                        VALUES (?, ?, 'start', NOW(), 'sent')
                    ")->execute([$session['id'], $user['id']]);
                }
            }
        }
    }
    
    // Find sessions that should be marked as completed
    $completedStmt = $db->prepare("
        SELECT id, title 
        FROM live_sessions 
        WHERE status = 'live' 
        AND end_datetime <= NOW()
    ");
    $completedStmt->execute();
    $sessionsToComplete = $completedStmt->fetchAll();
    
    foreach ($sessionsToComplete as $session) {
        $db->prepare("UPDATE live_sessions SET status = 'completed' WHERE id = ?")->execute([$session['id']]);
    }
    
    // Get all sessions for countdown display
    $sessionsStmt = $db->prepare("
        SELECT id, title, status, start_datetime, end_datetime
        FROM live_sessions
        WHERE status IN ('scheduled', 'live')
        ORDER BY start_datetime ASC
    ");
    $sessionsStmt->execute();
    $sessions = $sessionsStmt->fetchAll();
    
    $sessionData = [];
    foreach ($sessions as $session) {
        $startTime = new DateTime($session['start_datetime']);
        $endTime = $session['end_datetime'] ? new DateTime($session['end_datetime']) : null;
        $now = new DateTime();
        
        $sessionData[] = [
            'id' => $session['id'],
            'title' => $session['title'],
            'status' => $session['status'],
            'startsIn' => $this->calculateCountdown($startTime, $now),
            'endsIn' => $endTime ? $this->calculateCountdown($endTime, $now) : null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'sessions' => $sessionData,
        'updatedAt' => $currentTime
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function calculateCountdown($targetTime, $currentTime) {
    $diff = $targetTime->diff($currentTime);
    
    if ($diff->invert) {
        return '0s'; // Time has passed
    }
    
    $hours = $diff->h + ($diff->days * 24);
    $minutes = $diff->i;
    $seconds = $diff->s;
    
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    } elseif ($minutes > 0) {
        return "{$minutes}m {$seconds}s";
    } else {
        return "{$seconds}s";
    }
}
?>
