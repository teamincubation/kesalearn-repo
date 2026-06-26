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

$userId     = (int)$_SESSION['user_id'];
$materialId = (int)($_POST['material_id'] ?? 0);
$eventId    = (int)($_POST['event_id'] ?? 0);

if (!$materialId || !$eventId) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $db = getDB();

    // Verify material exists and belongs to this event
    $matStmt = $db->prepare("SELECT id, title FROM event_materials WHERE id = ? AND event_id = ? AND is_active = 1");
    $matStmt->execute([$materialId, $eventId]);
    $mat = $matStmt->fetch();
    if (!$mat) {
        echo json_encode(['success' => false, 'error' => 'Material not found']);
        exit;
    }

    // INSERT IGNORE — idempotent, first-read-only tracking
    $db->prepare("INSERT IGNORE INTO material_reads (material_id, user_id, event_id, first_read_at)
                  VALUES (?, ?, ?, NOW())")
       ->execute([$materialId, $userId, $eventId]);

    $newlyRead = (bool)$db->lastInsertId();

    // Log to user_event_activities only on first read
    if ($newlyRead) {
        try {
            $db->prepare("INSERT INTO user_event_activities (user_id, event_id, activity_type, reference_id, reference_name, created_at)
                          VALUES (?, ?, 'material_read', ?, ?, NOW())")
               ->execute([$userId, $eventId, $materialId, $mat['title']]);
        } catch (PDOException $e) { /* activity log optional */ }
    }

    echo json_encode(['success' => true, 'newly_read' => $newlyRead]);
} catch (Exception $e) {
    error_log('[Material Read] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred']);
}
