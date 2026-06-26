<?php
/**
 * KESA Learn - Download Assignment Submission
 * Secure file download handler for admin
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    die('Invalid submission ID');
}

// Fetch submission
$stmt = $db->prepare("SELECT file_path, file_name FROM assignment_submissions WHERE id = ?");
$stmt->execute([$id]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sub || empty($sub['file_path'])) {
    http_response_code(404);
    die('File not found');
}

// Build full path
$filePath = __DIR__ . '/../../uploads/' . $sub['file_path'];

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found on server: ' . $sub['file_path']);
}

// Set headers for download
$fileName = $sub['file_name'] ?: basename($sub['file_path']);
$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($filePath);
exit;
