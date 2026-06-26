<?php
/**
 * Profile Photo Delete API
 * Handles image deletion from database and filesystem
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

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = getDB();
    $user = getCurrentUser();
    
    if ($user['profile_image']) {
        // Delete file
        $processor = new ImageProcessor();
        $processor->deleteOldImage($user['profile_image']);
        
        // Update database
        $stmt = $db->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        logActivity('profile_photo_deleted', 'Profile photo removed');
        
        echo json_encode([
            'success' => true,
            'message' => 'Photo removed successfully'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No profile photo to remove'
        ]);
    }
} catch (Exception $e) {
    error_log('[v0] Profile photo delete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete photo'
    ]);
}
?>
