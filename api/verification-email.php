<?php
/**
 * Verification Badge Email API
 * Asynchronous endpoint for queueing verification badge emails
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/verification-email-service.php';

$response = ['success' => false, 'message' => ''];

try {
    $action = $_POST['action'] ?? null;
    
    if (!$action) {
        http_response_code(400);
        $response['message'] = 'No action specified';
        echo json_encode($response);
        exit;
    }
    
    $service = new VerificationEmailService();
    
    // Queue badge approved email
    if ($action === 'queue_badge_approved') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $email = $_POST['email'] ?? '';
        $name = $_POST['name'] ?? '';
        
        if (!$userId || !$email || !$name) {
            http_response_code(400);
            $response['message'] = 'Missing required parameters';
            echo json_encode($response);
            exit;
        }
        
        if ($service->queueBadgeApprovedEmail($userId, $email, $name)) {
            $response['success'] = true;
            $response['message'] = 'Email queued successfully';
        } else {
            http_response_code(500);
            $response['message'] = 'Failed to queue email';
        }
    }
    // Queue badge removed email
    else if ($action === 'queue_badge_removed') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $email = $_POST['email'] ?? '';
        $name = $_POST['name'] ?? '';
        
        if (!$userId || !$email || !$name) {
            http_response_code(400);
            $response['message'] = 'Missing required parameters';
            echo json_encode($response);
            exit;
        }
        
        if ($service->queueBadgeRemovedEmail($userId, $email, $name)) {
            $response['success'] = true;
            $response['message'] = 'Email queued successfully';
        } else {
            http_response_code(500);
            $response['message'] = 'Failed to queue email';
        }
    }
    // Process pending emails
    else if ($action === 'process_queue') {
        $sent = $service->processPendingEmails();
        $response['success'] = true;
        $response['message'] = "$sent emails processed";
        $response['sent_count'] = $sent;
    }
    else {
        http_response_code(400);
        $response['message'] = 'Invalid action';
    }
} catch (Exception $e) {
    http_response_code(500);
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log("[Verification Email API] Error: " . $e->getMessage());
}

echo json_encode($response);
