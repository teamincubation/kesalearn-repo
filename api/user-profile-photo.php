<?php
/**
 * API: Download User Profile Photo
 * Admin endpoint to download user profile pictures
 * Requires admin authentication
 */

require_once __DIR__ . '/../includes/admin_check.php';
require_once __DIR__ . '/../includes/image-processor.php';

header('Content-Type: application/json');

try {
    // Check admin authentication
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    // Get user ID from request
    $userId = intval($_GET['user_id'] ?? 0);
    $action = $_GET['action'] ?? 'download';
    
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user ID']);
        exit;
    }
    
    // Get user profile image
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, profile_image FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    if (empty($user['profile_image'])) {
        http_response_code(404);
        echo json_encode(['error' => 'No profile photo found for this user']);
        exit;
    }
    
    // Get image processor
    $processor = new ImageProcessor();
    $imagePath = $processor->getImageForDownload($user['profile_image']);
    
    if (!$imagePath) {
        http_response_code(404);
        echo json_encode(['error' => 'Profile photo file not found']);
        exit;
    }
    
    if ($action === 'download') {
        // Download the image
        header('Content-Type: image/jpeg');
        header('Content-Disposition: attachment; filename="profile_' . $userId . '_' . time() . '.jpg"');
        header('Content-Length: ' . filesize($imagePath));
        
        readfile($imagePath);
        exit;
        
    } elseif ($action === 'view') {
        // Display the image inline
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600');
        
        readfile($imagePath);
        exit;
        
    } elseif ($action === 'info') {
        // Return image information
        $filesize = filesize($imagePath);
        $dimensions = @getimagesize($imagePath);
        
        echo json_encode([
            'success' => true,
            'user_id' => $userId,
            'user_name' => $user['name'],
            'file_size' => $filesize,
            'file_size_kb' => round($filesize / 1024, 2),
            'dimensions' => $dimensions ? $dimensions[0] . 'x' . $dimensions[1] : 'unknown',
            'mime_type' => 'image/jpeg',
            'stored_path' => $user['profile_image'],
            'download_url' => '/api/user-profile-photo.php?user_id=' . $userId . '&action=download',
            'view_url' => '/api/user-profile-photo.php?user_id=' . $userId . '&action=view',
        ]);
        exit;
    }
    
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
