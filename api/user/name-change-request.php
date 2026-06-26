<?php
session_start();
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$userId        = (int)$_SESSION['user_id'];
$requestedName = trim($_POST['requested_name'] ?? '');

if ($requestedName === '') {
    echo json_encode(['success' => false, 'error' => 'New name is required.']);
    exit;
}

// File upload
if (empty($_FILES['id_document']['tmp_name']) || $_FILES['id_document']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'ID document is required. Upload failed (error code: ' . ($_FILES['id_document']['error'] ?? 'no file') . ')']);
    exit;
}

$file    = $_FILES['id_document'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'pdf'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG or PDF allowed.']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File must be under 5 MB.']);
    exit;
}

$uploadDir = __DIR__ . '/../../uploads/name_change_docs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$fileName = $userId . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file. Check server write permissions on /uploads/name_change_docs/']);
    exit;
}

// Save to DB
try {
    $db = getDB();

    // Block duplicate pending request
    $dup = $db->prepare("SELECT id FROM name_change_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
    $dup->execute([$userId]);
    if ($dup->fetch()) {
        @unlink($destPath);
        echo json_encode(['success' => false, 'error' => 'You already have a pending request. Wait for admin review.']);
        exit;
    }

    // Current name
    $u = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    $u->execute([$userId]);
    $currentName = $u->fetchColumn() ?: 'Unknown';

    // Debug: log what we're inserting
    error_log('[NCR] Inserting: user_id=' . $userId . ', current_name=' . $currentName . ', requested_name=' . $requestedName . ', path=/uploads/name_change_docs/' . $fileName);

    $stmt = $db->prepare("
        INSERT INTO name_change_requests (user_id, current_name, requested_name, id_document_path, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    
    $result = $stmt->execute([
        $userId,
        $currentName,
        $requestedName,
        '/uploads/name_change_docs/' . $fileName,
    ]);

    if (!$result) {
        throw new Exception('INSERT failed: ' . json_encode($stmt->errorInfo()));
    }

    echo json_encode(['success' => true, 'message' => 'Request submitted. Admin will review it soon.']);

} catch (Exception $e) {
    @unlink($destPath);
    $errorMsg = $e->getMessage();
    error_log('[NCR] Exception: ' . $errorMsg);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $errorMsg]);
}
