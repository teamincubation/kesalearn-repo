<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$sessionId = (int)($_POST['session_id'] ?? 0);
$eventId   = (int)($_POST['event_id'] ?? 0);
$attended  = (int)(bool)($_POST['attended'] ?? 0); // 0 or 1

if (!$sessionId || !$eventId) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $db = getDB();

    // Verify user is registered for this event.
    // Accept any non-rejected status — free events are 'verified', paid ones 'paid' or 'verified'.
    $reg = $db->prepare(
        "SELECT id FROM registrations
         WHERE user_id = ? AND event_id = ? AND payment_status IN ('paid','verified')
         LIMIT 1"
    );
    $reg->execute([$userId, $eventId]);
    if (!$reg->fetch()) {
        // Broader fallback: accept any registration (covers pending free-event rows)
        $reg2 = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ? LIMIT 1");
        $reg2->execute([$userId, $eventId]);
        if (!$reg2->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Not enrolled in this event']);
            exit;
        }
    }

    // Verify session belongs to this event
    $sess = $db->prepare("SELECT id, status FROM live_sessions WHERE id = ? AND event_id = ?");
    $sess->execute([$sessionId, $eventId]);
    $session = $sess->fetch();
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }
    // Only block truly non-past statuses; 'live' sessions can also have attendance marked
    if (in_array($session['status'], ['scheduled', 'cancelled'])) {
        echo json_encode(['success' => false, 'error' => 'Attendance can only be marked for completed sessions']);
        exit;
    }

    // Check if already marked — first-time only
    $existing = $db->prepare("SELECT id FROM session_attendance WHERE session_id = ? AND user_id = ?");
    $existing->execute([$sessionId, $userId]);
    if ($existing->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Attendance already marked for this session']);
        exit;
    }

    $db->prepare(
        "INSERT INTO session_attendance (session_id, user_id, event_id, attended, marked_at)
         VALUES (?, ?, ?, ?, NOW())"
    )->execute([$sessionId, $userId, $eventId, $attended]);

    // Log to user_event_activities (optional — skip silently if table missing)
    try {
        $actType   = $attended ? 'session_attended' : 'session_missed';
        $titleStmt = $db->prepare("SELECT title FROM live_sessions WHERE id = ?");
        $titleStmt->execute([$sessionId]);
        $sessTitle = $titleStmt->fetchColumn() ?: 'Session #' . $sessionId;
        $db->prepare(
            "INSERT INTO user_event_activities (user_id, event_id, activity_type, reference_id, reference_name, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$userId, $eventId, $actType, $sessionId, $sessTitle]);
    } catch (PDOException $e) {
        error_log('[Attendance] Activity log skipped: ' . $e->getMessage());
    }

    logActivity('attendance_marked', "Session #{$sessionId} - " . ($attended ? 'attended' : 'absent'));

    echo json_encode(['success' => true, 'attended' => (bool)$attended]);

} catch (PDOException $e) {
    // Expose DB error message so missing-table issues are diagnosable in server logs
    error_log('[Attendance] DB Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('[Attendance] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred']);
}
