<?php
/**
 * KESA Learn - API: Rate Event (AJAX)
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$eventId = intval($_POST['event_id'] ?? 0);
$rating = min(5, max(1, intval($_POST['rating'] ?? 0)));
$review = sanitize($_POST['review'] ?? '');
$userId = $_SESSION['user_id'];

if (!$eventId || !$rating) {
    echo json_encode(['success' => false, 'error' => 'Missing event or rating']);
    exit;
}

$db = getDB();

// Check if user has a completed registration for this event
$stmt = $db->prepare("SELECT r.id FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = ? AND r.event_id = ? AND (r.payment_status IN ('paid', 'verified') OR r.payment_method = 'free') AND e.end_date < NOW()");
$stmt->execute([$userId, $eventId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'You can only rate events you have attended']);
    exit;
}

// Upsert rating
$stmt = $db->prepare("INSERT INTO event_ratings (user_id, event_id, rating, review) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), review = VALUES(review)");
$stmt->execute([$userId, $eventId, $rating, $review]);

logActivity('event_rated', "User rated event ID $eventId: $rating stars", $userId);

echo json_encode(['success' => true, 'message' => 'Thank you for your rating!']);
