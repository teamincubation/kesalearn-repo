<?php
/**
 * KESA Learn - Feedback Action Handler
 * Processes approve, mark_read, delete actions for feedback
 * Returns JSON responses for AJAX operations
 */
require_once __DIR__ . '/../../includes/admin_check.php';

header('Content-Type: application/json');

$db = getDB();
$response = ['success' => false, 'message' => 'Unknown error'];

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get CSRF token from POST or header
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

// Verify CSRF token
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$action = sanitize($_POST['action'] ?? '');
$feedbackId = intval($_POST['feedback_id'] ?? 0);

if (!$feedbackId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid feedback ID']);
    exit;
}

// Verify feedback exists
try {
    $checkStmt = $db->prepare("SELECT id, is_approved FROM feedbacks WHERE id = ?");
    $checkStmt->execute([$feedbackId]);
    $feedback = $checkStmt->fetch();
    
    if (!$feedback) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Feedback not found']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

try {
    if ($action === 'approve') {
        $stmt = $db->prepare("UPDATE feedbacks SET is_approved = 1, is_read = 0 WHERE id = ?");
        $stmt->execute([$feedbackId]);
        
        logActivity('feedback_approved', "Admin approved feedback #$feedbackId and will display as testimonial");
        
        http_response_code(200);
        $response = [
            'success' => true, 
            'message' => 'Feedback approved! It will now appear as a testimonial on your website.',
            'action' => 'approve'
        ];
    }
    elseif ($action === 'mark_read') {
        // Mark as read - it becomes a draft without publishing
        $stmt = $db->prepare("UPDATE feedbacks SET is_read = 1, is_approved = 0 WHERE id = ?");
        $stmt->execute([$feedbackId]);
        
        logActivity('feedback_marked_read', "Admin marked feedback #$feedbackId as read (draft mode)");
        
        http_response_code(200);
        $response = [
            'success' => true, 
            'message' => 'Feedback marked as read. It will remain as a draft without publishing.',
            'action' => 'mark_read'
        ];
    }
    elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM feedbacks WHERE id = ?");
        $stmt->execute([$feedbackId]);
        
        logActivity('feedback_deleted', "Admin deleted feedback #$feedbackId");
        
        http_response_code(200);
        $response = [
            'success' => true, 
            'message' => 'Feedback deleted permanently.',
            'action' => 'delete'
        ];
    }
    else {
        http_response_code(400);
        $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
    }
} catch (PDOException $e) {
    http_response_code(500);
    logActivity('feedback_action_error', "Error processing feedback action: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'Database error occurred'];
}

echo json_encode($response);
exit;
?>
