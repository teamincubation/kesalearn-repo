<?php
/**
 * KESA Learn - Download Assignment Material (User-facing)
 * Serves assignment materials uploaded by admins to registered learners.
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db = getDB();
$materialId = (int)($_GET['id'] ?? 0);

if (!$materialId) {
    http_response_code(400);
    die('Invalid material request.');
}

// Fetch material
$stmt = $db->prepare("SELECT am.*, a.event_id FROM assignment_materials am JOIN assignments a ON am.assignment_id = a.id WHERE am.id = ?");
$stmt->execute([$materialId]);
$material = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$material) {
    http_response_code(404);
    die('Material not found.');
}

// Verify user is registered for the event
$regStmt = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ?");
$regStmt->execute([$_SESSION['user_id'], $material['event_id']]);
if (!$regStmt->fetch()) {
    http_response_code(403);
    die('You are not registered for this course.');
}

// Serve file
$filePath = __DIR__ . '/../uploads/materials/' . $material['file_path'];

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found on server.');
}

$ext = strtolower(pathinfo($material['file_name'], PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt' => 'text/plain',
    'zip' => 'application/zip',
    'mp3' => 'audio/mpeg',
    'mp4' => 'video/mp4',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $material['file_name'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');

readfile($filePath);
exit;
