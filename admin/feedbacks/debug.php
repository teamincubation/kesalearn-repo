<?php
/**
 * Feedback Action Handler - Debug Version
 * This file processes approve, reject, and delete actions for feedback
 */
require_once __DIR__ . '/../../includes/admin_check.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => 'Unknown error',
    'debug' => $_SERVER['REQUEST_METHOD']
];

try {
    // Log all incoming data
    error_log("[FEEDBACK_ACTION] REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
    error_log("[FEEDBACK_ACTION] POST: " . json_encode($_POST));
    error_log("[FEEDBACK_ACTION] FILES: " . json_encode($_FILES));
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Get POST data
    $action = $_POST['action'] ?? null;
    $feedbackId = intval($_POST['feedback_id'] ?? 0);
    $csrfToken = $_POST['csrf_token'] ?? null;
    
    error_log("[FEEDBACK_ACTION] Action: $action, FeedbackID: $feedbackId, HasCSRF: " . (!empty($csrfToken) ? 'yes' : 'no'));
    
    // Validate inputs
    if (!$feedbackId) {
        throw new Exception('No feedback ID provided');
    }
    
    if (!$action || !in_array($action, ['approve', 'reject', 'delete'])) {
        throw new Exception('Invalid action: ' . $action);
    }
    
    if (!$csrfToken || !verifyCSRFToken($csrfToken)) {
        error_log("[FEEDBACK_ACTION] CSRF verification failed");
        throw new Exception('Invalid security token');
    }
    
    // Get database
    $db = getDB();
    
    // Check if feedback exists
    $check = $db->prepare("SELECT id, is_approved FROM feedbacks WHERE id = ?");
    $check->execute([$feedbackId]);
    $feedback = $check->fetch();
    
    if (!$feedback) {
        error_log("[FEEDBACK_ACTION] Feedback not found: $feedbackId");
        throw new Exception('Feedback not found');
    }
    
    error_log("[FEEDBACK_ACTION] Feedback found: " . json_encode($feedback));
    
    // Process action
    if ($action === 'approve') {
        $stmt = $db->prepare("UPDATE feedbacks SET is_approved = 1 WHERE id = ?");
        if (!$stmt->execute([$feedbackId])) {
            error_log("[FEEDBACK_ACTION] Approve failed: " . json_encode($stmt->errorInfo()));
            throw new Exception('Failed to approve feedback');
        }
        error_log("[FEEDBACK_ACTION] Approved feedback $feedbackId");
        $response['success'] = true;
        $response['message'] = 'Feedback approved successfully';
        
    } elseif ($action === 'reject') {
        $stmt = $db->prepare("UPDATE feedbacks SET is_approved = 0 WHERE id = ?");
        if (!$stmt->execute([$feedbackId])) {
            error_log("[FEEDBACK_ACTION] Reject failed: " . json_encode($stmt->errorInfo()));
            throw new Exception('Failed to reject feedback');
        }
        error_log("[FEEDBACK_ACTION] Rejected feedback $feedbackId");
        $response['success'] = true;
        $response['message'] = 'Feedback rejected';
        
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM feedbacks WHERE id = ?");
        if (!$stmt->execute([$feedbackId])) {
            error_log("[FEEDBACK_ACTION] Delete failed: " . json_encode($stmt->errorInfo()));
            throw new Exception('Failed to delete feedback');
        }
        error_log("[FEEDBACK_ACTION] Deleted feedback $feedbackId");
        $response['success'] = true;
        $response['message'] = 'Feedback deleted permanently';
    }
    
    // Log activity
    logActivity('feedback_' . $action, "Admin $action feedback #$feedbackId");
    
} catch (Exception $e) {
    error_log("[FEEDBACK_ACTION] Exception: " . $e->getMessage());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
