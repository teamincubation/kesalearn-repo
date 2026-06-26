<?php
/**
 * KESA Learn - Submit Event Feedback
 * Handles rating and feedback submission for completed events
 */
require_once __DIR__ . '/../includes/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/dashboard');
}

if (!verifyCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    setFlash('error', 'Invalid request. Please try again.');
    redirect('/user/dashboard');
}

$db = getDB();
$userId = $_SESSION['user_id'];

$registrationId = intval($_POST['registration_id'] ?? 0);
$eventId = intval($_POST['event_id'] ?? 0);
$rating = intval($_POST['rating'] ?? 0);
$feedback = sanitize($_POST['feedback'] ?? '');
$didNotParticipate = isset($_POST['did_not_participate']) ? 1 : 0;

// Validate registration belongs to user
$regCheck = $db->prepare("SELECT r.id, e.title FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.id = ? AND r.user_id = ?");
$regCheck->execute([$registrationId, $userId]);
$registration = $regCheck->fetch();

if (!$registration) {
    setFlash('error', 'Registration not found.');
    redirect('/user/dashboard');
}

// Check if event_ratings table exists
try {
    $db->query("SELECT 1 FROM event_ratings LIMIT 1");
} catch (PDOException $e) {
    setFlash('error', 'Feedback system is being set up. Please try again later.');
    redirect('/user/dashboard');
}

// Check if feedback already submitted
$existingCheck = $db->prepare("SELECT id FROM event_ratings WHERE registration_id = ?");
$existingCheck->execute([$registrationId]);
if ($existingCheck->fetch()) {
    setFlash('info', 'You have already submitted feedback for this event.');
    redirect('/user/dashboard');
}

// Validate rating if not marking as did not participate
if (!$didNotParticipate && ($rating < 1 || $rating > 5)) {
    setFlash('error', 'Please select a rating between 1 and 5 stars.');
    redirect('/user/dashboard');
}

try {
    // Insert feedback
    $stmt = $db->prepare("
        INSERT INTO event_ratings (user_id, event_id, registration_id, rating, feedback, did_not_participate)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $eventId,
        $registrationId,
        $didNotParticipate ? 0 : $rating,
        $feedback,
        $didNotParticipate
    ]);
    
    // Update registration participation status
    $participationStatus = $didNotParticipate ? 'not_attended' : 'attended';
    $db->prepare("UPDATE registrations SET participation_status = ? WHERE id = ?")->execute([$participationStatus, $registrationId]);
    
    // Log activity
    $action = $didNotParticipate ? 'marked_not_participated' : 'submitted_feedback';
    logActivity($action, "Event: {$registration['title']}", $userId);
    
    if ($didNotParticipate) {
        setFlash('info', 'You have marked that you did not participate in this event.');
    } else {
        setFlash('success', 'Thank you for your feedback!');
    }
    
} catch (PDOException $e) {
    setFlash('error', 'Failed to submit feedback. Please try again.');
}

redirect('/user/dashboard');
