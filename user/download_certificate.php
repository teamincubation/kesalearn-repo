<?php
/**
 * KESA Learn - Download Certificate
 * Downloads the admin-uploaded certificate file (no auto-generation)
 */
require_once __DIR__ . '/../includes/auth_check.php';

$certId = intval($_GET['id'] ?? 0);
if (!$certId) {
    setFlash('error', 'Invalid certificate.');
    redirect('/user/certificates');
}

$db = getDB();
$stmt = $db->prepare("
    SELECT c.*, e.title as event_title, u.name as user_name
    FROM certificates c
    JOIN events e ON c.event_id = e.id
    JOIN users u ON c.user_id = u.id
    WHERE c.id = ? AND c.user_id = ?
");
$stmt->execute([$certId, $_SESSION['user_id']]);
$cert = $stmt->fetch();

if (!$cert) {
    setFlash('error', 'Certificate not found.');
    redirect('/user/certificates');
}

// Check if certificate file exists
$certFile = $cert['certificate_file'] ?? '';
if (empty($certFile)) {
    setFlash('error', 'Certificate file not available. Please contact support.');
    redirect('/user/certificates');
}

// Build full file path
$filePath = __DIR__ . '/../uploads/certificates/issued/' . $certFile;

// Also check in old location
if (!file_exists($filePath)) {
    $filePath = __DIR__ . '/../uploads/certificates/' . $certFile;
}

if (!file_exists($filePath)) {
    setFlash('error', 'Certificate file not found on server. Please contact support.');
    redirect('/user/certificates');
}

// Increment download count
$stmt = $db->prepare("UPDATE certificates SET download_count = download_count + 1 WHERE id = ?");
$stmt->execute([$certId]);

// Get file extension and MIME type
$ext = strtolower(pathinfo($certFile, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp'
];
$mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

// Create safe download filename
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cert['user_name']);
$safeEvent = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cert['event_title']);
$certCode = $cert['certificate_code'] ?? $cert['certificate_number'] ?? ('CERT-' . $cert['id']);
$downloadName = "KESA_Certificate_{$safeName}_{$safeEvent}_{$certCode}.{$ext}";

// Set headers for download
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Clear output buffer and serve file
if (ob_get_level()) {
    ob_end_clean();
}

readfile($filePath);
exit;
