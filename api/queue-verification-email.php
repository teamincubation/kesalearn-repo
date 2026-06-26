<?php
/**
 * Simple API to queue verification emails
 * Does NOT send emails - just stores them for the background worker
 */

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if (empty($action)) {
        http_response_code(400);
        die(json_encode(['error' => 'Missing action']));
    }
    
    require_once __DIR__ . '/../config/database.php';
    $db = getDB();
    
    // Create table if needed
    $db->exec("
        CREATE TABLE IF NOT EXISTS verification_emails (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            email_type ENUM('badge_approved', 'badge_removed') NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_name VARCHAR(255),
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            attempts INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL,
            error_message TEXT,
            KEY (status),
            KEY (created_at)
        )
    ");
    
    if ($action === 'queue_badge_approved') {
        $userId = $_POST['user_id'] ?? $_GET['user_id'] ?? 0;
        $email = $_POST['email'] ?? $_GET['email'] ?? '';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        
        if (!$userId || !$email) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing user_id or email']));
        }
        
        $stmt = $db->prepare("INSERT INTO verification_emails (user_id, email_type, recipient_email, recipient_name) VALUES (?, 'badge_approved', ?, ?)");
        $stmt->execute([$userId, $email, $name]);
        
        echo json_encode(['success' => true, 'queued' => 'badge_approved']);
        
    } elseif ($action === 'queue_badge_removed') {
        $userId = $_POST['user_id'] ?? $_GET['user_id'] ?? 0;
        $email = $_POST['email'] ?? $_GET['email'] ?? '';
        $name = $_POST['name'] ?? $_GET['name'] ?? '';
        
        if (!$userId || !$email) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing user_id or email']));
        }
        
        $stmt = $db->prepare("INSERT INTO verification_emails (user_id, email_type, recipient_email, recipient_name) VALUES (?, 'badge_removed', ?, ?)");
        $stmt->execute([$userId, $email, $name]);
        
        echo json_encode(['success' => true, 'queued' => 'badge_removed']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
