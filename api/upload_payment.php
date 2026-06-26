<?php
/**
 * KESA Learn - AJAX Payment Proof Upload
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

if (!verifyCSRFToken()) {
    echo json_encode(['success' => false, 'message' => 'Security token expired.']);
    exit;
}

$registrationId = intval($_POST['registration_id'] ?? 0);

if (!$registrationId) {
    echo json_encode(['success' => false, 'message' => 'Missing registration ID.']);
    exit;
}

// Verify ownership
$db = getDB();
$stmt = $db->prepare("SELECT id FROM registrations WHERE id = ? AND user_id = ?");
$stmt->execute([$registrationId, $_SESSION['user_id']]);

if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Registration not found.']);
    exit;
}

if (empty($_FILES['payment_proof'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$upload = uploadFile($_FILES['payment_proof'], 'payment_proofs', ALLOWED_IMAGE_TYPES, MAX_PROOF_SIZE);

if ($upload['success']) {
    $stmt = $db->prepare("UPDATE registrations SET payment_proof = ?, payment_method = 'upi', payment_status = 'pending' WHERE id = ?");
    $stmt->execute([$upload['path'], $registrationId]);
    
    logActivity('payment_proof_upload', "Payment proof uploaded for registration #$registrationId");
    
    echo json_encode(['success' => true, 'message' => 'Payment proof uploaded successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => $upload['error']]);
}
