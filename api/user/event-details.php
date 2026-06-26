<?php
/**
 * KESA Learn - Event Details API for User Dashboard
 * Returns event info and announcements for a registered user
 */
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = $_SESSION['user_id'];
$eventId = intval($_GET['event_id'] ?? 0);

if (!$eventId) {
    echo json_encode(['success' => false, 'error' => 'Event ID required']);
    exit;
}

$db = getDB();

// Check if user is registered for this event
$checkReg = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ?");
$checkReg->execute([$userId, $eventId]);
if (!$checkReg->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Not registered for this event']);
    exit;
}

// Get event details
$eventStmt = $db->prepare("
    SELECT id, title, slug, description, start_date, end_date, type, is_online, venue, 
           banner_image, status, support_phone, support_whatsapp
    FROM events 
    WHERE id = ?
");
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Event not found']);
    exit;
}

// Format dates
$event['start_date_formatted'] = date('M d, Y', strtotime($event['start_date']));
$event['end_date_formatted'] = date('M d, Y', strtotime($event['end_date']));

// Get announcements for this event (if event_id column exists) or global announcements
$announcements = [];
try {
    // Try to get event-specific announcements first
    $annStmt = $db->prepare("
        SELECT id, title, content, label, link_url, created_at 
        FROM announcements 
        WHERE is_active = 1 
        AND start_date <= NOW() 
        AND end_date >= NOW()
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $annStmt->execute();
    $announcements = $annStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates
    foreach ($announcements as &$ann) {
        $ann['created_at_formatted'] = date('M d, Y \a\t h:i A', strtotime($ann['created_at']));
    }
} catch (PDOException $e) {
    // Table might not exist or have different structure
    $announcements = [];
}

echo json_encode([
    'success' => true,
    'event' => $event,
    'announcements' => $announcements
]);
