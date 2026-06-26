<?php
/**
 * KESA Learn - Seat Availability Check API
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/functions.php';

$eventId = intval($_GET['event_id'] ?? 0);

if (!$eventId) {
    echo json_encode(['error' => 'Missing event ID']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT max_seats, seats_taken FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    echo json_encode(['error' => 'Event not found']);
    exit;
}

echo json_encode([
    'max_seats' => $event['max_seats'],
    'seats_taken' => $event['seats_taken'],
    'available' => $event['max_seats'] ? ($event['max_seats'] - $event['seats_taken']) : null
]);
