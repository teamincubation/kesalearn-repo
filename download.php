<?php
/**
 * Secure file download handler for announcements
 */
require_once __DIR__ . '/includes/functions.php';

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    http_response_code(404);
    die('File not found.');
}

$db = getDB();
$stmt = $db->prepare("SELECT file_path, title FROM announcements WHERE id = ? AND link_type = 'download' AND file_path IS NOT NULL");
$stmt->execute([$id]);
$announcement = $stmt->fetch();

if (!$announcement || empty($announcement['file_path'])) {
    http_response_code(404);
    die('File not found.');
}

$filePath = __DIR__ . '/uploads/announcements/' . $announcement['file_path'];

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found on server.');
}

// Get file info
$fileName = $announcement['file_path'];
$fileSize = filesize($filePath);
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// MIME types
$mimeTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed',
    'txt' => 'text/plain'
];

$mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

// Create a user-friendly filename from the title
$safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $announcement['title']);
$downloadName = $safeTitle . '.' . $ext;

// Set headers for download
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Clear output buffer
if (ob_get_level()) {
    ob_end_clean();
}

// Read and output file
readfile($filePath);
exit;
