<?php
/**
 * KESA Learn - Download an Instructor Certificate
 * GET /certificate/download-instructor?code=KESA-INST-XXXXXX
 * Also accepts the printed certificate number via ?code=.
 */
require_once __DIR__ . '/../includes/functions.php';

$code = trim($_GET['code'] ?? '');
if ($code === '' || mb_strlen($code) > 100) {
    http_response_code(400);
    exit('Missing certificate code.');
}

$db = getDB();
try {
    $st = $db->prepare(
        "SELECT certificate_code, certificate_number, certificate_file
         FROM instructor_certificates
         WHERE (certificate_code = ? OR certificate_number = ?) AND is_active = 1
         LIMIT 1"
    );
    $st->execute([strtoupper($code), $code]);
    $cert = $st->fetch();
} catch (PDOException $e) {
    $cert = null;
}

if (!$cert || empty($cert['certificate_file'])) {
    http_response_code(404);
    exit('Certificate not found.');
}

// Resolve and validate the path stays inside the uploads dir (no traversal)
$base = realpath(__DIR__ . '/../uploads/instructors/certificates');
$file = realpath(__DIR__ . '/../' . $cert['certificate_file']);
if (!$base || !$file || !str_starts_with($file, $base) || !is_file($file)) {
    http_response_code(404);
    exit('Certificate file is missing.');
}

try {
    $db->prepare("UPDATE instructor_certificates SET download_count = download_count + 1 WHERE certificate_code = ?")
       ->execute([$cert['certificate_code']]);
} catch (PDOException $e) {}

$ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
$downloadName = $cert['certificate_code'] . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
readfile($file);
exit;
