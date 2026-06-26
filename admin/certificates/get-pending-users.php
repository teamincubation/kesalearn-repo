<?php
/**
 * KESA Learn - AJAX: Get Pending Users for Certificate
 * Returns users registered for an event who don't have certificates yet
 */
require_once __DIR__ . '/../../includes/admin_check.php';

header('Content-Type: application/json');

$eventId = intval($_GET['event_id'] ?? 0);

if (!$eventId) {
    echo json_encode(['success' => false, 'error' => 'Invalid event ID']);
    exit;
}

$db = getDB();

// Get event info
$eventStmt = $db->prepare("SELECT id, title, status FROM events WHERE id = ?");
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Event not found']);
    exit;
}

// Get total registered users for this event
$totalStmt = $db->prepare("
    SELECT COUNT(DISTINCT r.user_id) 
    FROM registrations r 
    WHERE r.event_id = ? AND r.payment_status IN ('paid', 'verified', 'free')
");
$totalStmt->execute([$eventId]);
$totalRegistered = (int)$totalStmt->fetchColumn();

// Get users who already have certificates for this event
$certifiedStmt = $db->prepare("
    SELECT COUNT(DISTINCT c.user_id) 
    FROM certificates c 
    WHERE c.event_id = ? AND c.user_id IS NOT NULL
");
$certifiedStmt->execute([$eventId]);
$alreadyCertified = (int)$certifiedStmt->fetchColumn();

// Get pending users (registered but no certificate)
$pendingStmt = $db->prepare("
    SELECT u.id, u.name, u.email 
    FROM users u
    INNER JOIN registrations r ON u.id = r.user_id
    WHERE r.event_id = ? 
    AND r.payment_status IN ('paid', 'verified', 'free')
    AND u.id NOT IN (
        SELECT user_id FROM certificates WHERE event_id = ? AND user_id IS NOT NULL
    )
    ORDER BY u.name ASC
");
$pendingStmt->execute([$eventId, $eventId]);
$pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'event' => [
        'id' => $event['id'],
        'title' => $event['title'],
        'status' => $event['status']
    ],
    'stats' => [
        'total_registered' => $totalRegistered,
        'already_certified' => $alreadyCertified,
        'pending' => count($pendingUsers)
    ],
    'pending_users' => $pendingUsers
]);
