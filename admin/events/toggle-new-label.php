<?php
/**
 * KESA Learn - Toggle Event "New" Label
 */
require_once __DIR__ . '/../../includes/admin_check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$eventId = intval($_POST['event_id'] ?? 0);
$isNew = intval($_POST['is_new'] ?? 0);

if (!$eventId) {
    echo json_encode(['success' => false, 'error' => 'Invalid event']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("UPDATE events SET is_new = ? WHERE id = ?");
$stmt->execute([$isNew ? 1 : 0, $eventId]);

logActivity('event_label_updated', "Updated New label for event #$eventId to " . ($isNew ? 'ON' : 'OFF'));

echo json_encode(['success' => true]);
