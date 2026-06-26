<?php
/**
 * Profile Photo Upload API
 * Handles image upload and database updates
 */
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/../../../includes/image-processor.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Verify CSRF token
if (!verifyCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token invalid']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No image provided']);
    exit;
}

try {
    $processor = new ImageProcessor();
    $result = $processor->processProfileImage($input['image'], $_SESSION['user_id']);
    
    if ($result['success']) {
        $db = getDB();
        $user = getCurrentUser();
        
        // Delete old image if exists
        if ($user['profile_image']) {
            $processor->deleteOldImage($user['profile_image']);
        }
        
        // Update database
        $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
        $stmt->execute([$result['file']['path'], $_SESSION['user_id']]);
        
        logActivity('profile_photo_updated', 'Profile photo updated');
        
        echo json_encode([
            'success' => true,
            'message' => 'Photo uploaded successfully',
            'path' => '/uploads/' . $result['file']['path']
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
    }
} catch (Exception $e) {
    error_log('[v0] Profile photo upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to process image'
    ]);
}
?>
