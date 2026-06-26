<?php
/**
 * Background Worker - Send Verification Badge Emails
 * 
 * This is a standalone worker that processes verification emails independently.
 * Can be called via cron: curl https://kesalearn.com/worker/send-verification-emails.php
 * Or run from command line: php /path/to/send-verification-emails.php
 * 
 * This does NOT interfere with page handlers - it's completely isolated.
 */

// Minimal setup - no output to avoid affecting API calls
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Check if this is a cron call or direct call
$isCron = (php_sapi_name() === 'cli' || !empty($_GET['cron_key']));

// Simple auth for web calls
$cronKey = $_GET['cron_key'] ?? $_POST['cron_key'] ?? '';
if (!$isCron && empty($cronKey)) {
    http_response_code(403);
    die('Forbidden');
}

// Initialize
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

try {
    $db = getDB();
    
    // Create verification email table if it doesn't exist
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
            KEY (created_at),
            KEY (user_id)
        )
    ");
    
    // Get pending emails
    $stmt = $db->prepare("
        SELECT id, user_id, email_type, recipient_email, recipient_name
        FROM verification_emails
        WHERE status = 'pending' AND attempts < 3
        LIMIT 50
    ");
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sent = 0;
    $failed = 0;
    
    foreach ($emails as $email) {
        try {
            $body = '';
            $subject = '';
            
            if ($email['email_type'] === 'badge_approved') {
                $subject = 'Your KESA Account is Verified - Get Your Badge Now!';
                $body = '<html><body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                    <div style="background: linear-gradient(135deg, #43d000 0%, #2fa600 100%); padding: 40px; text-align: center; color: white; border-radius: 12px 12px 0 0;">
                        <h2 style="margin: 0; font-size: 1.8rem;">Congratulations!</h2>
                        <p style="margin: 10px 0 0 0; font-size: 1.1rem;">Your KESA Account is Verified</p>
                    </div>
                    <div style="background: #f8f9fa; padding: 40px; border-radius: 0 0 12px 12px;">
                        <p>Hi ' . htmlspecialchars($email['recipient_name']) . ',</p>
                        <p>Your KESA Learn account has been verified and you now have access to exclusive benefits:</p>
                        <ul style="color: #1a1a2e; font-weight: 500;">
                            <li>Free KESA Webinars with Certificates</li>
                            <li>Verified Badge on Your Profile</li>
                            <li>KESA Premium Updates</li>
                        </ul>
                        <p>We will keep you updated with information about FREE KESA webinars with certificates directly in your email. Stay tuned for amazing learning opportunities!</p>
                        <p style="color: #92400e; background: #fef3c7; padding: 12px; border-radius: 4px; font-size: 0.9rem;">
                            <strong>Important:</strong> Updating your profile will reset your badge verification request and automatically resubmit it for re-verification.
                        </p>
                        <p style="color: #666; font-size: 12px; text-align: center; margin-top: 30px;">KESA Learn - A flagship project of Team Incubation</p>
                    </div>
                </body></html>';
            } elseif ($email['email_type'] === 'badge_removed') {
                $subject = 'Verification Badge Status Update - KESA Learn';
                $body = '<html><body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                    <div style="background: #fef9e7; padding: 30px; border-radius: 12px;">
                        <h2 style="color: #92400e; margin-top: 0; text-align: center;">Verification Badge Update</h2>
                        <p>Hi ' . htmlspecialchars($email['recipient_name']) . ',</p>
                        <p>We noticed that you recently updated your profile information. Your verification badge status has been reset.</p>
                        <div style="background: white; padding: 16px; border-left: 4px solid #f59e0b; border-radius: 4px; margin: 16px 0;">
                            <strong>What happened:</strong> Your verification badge was automatically reset because your profile was updated. Your profile has been resubmitted for verification review.
                        </div>
                        <div style="background: #f0fdf4; padding: 16px; border-left: 4px solid #43d000; border-radius: 4px; margin: 16px 0;">
                            <strong>What next:</strong> Our team will review your updated profile and restore your verification badge once the review is complete.
                        </div>
                        <p>Thank you for maintaining the integrity of your profile!</p>
                        <p style="color: #666; font-size: 12px; text-align: center; margin-top: 30px;">KESA Learn - A flagship project of Team Incubation</p>
                    </div>
                </body></html>';
            }
            
            if (!empty($body) && !empty($subject)) {
                // Send email using the working mailer
                $result = mail($email['recipient_email'], $subject, $body, "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n");
                
                if ($result) {
                    // Mark as sent
                    $updateStmt = $db->prepare("UPDATE verification_emails SET status = 'sent', sent_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$email['id']]);
                    $sent++;
                } else {
                    // Increment attempts
                    $updateStmt = $db->prepare("UPDATE verification_emails SET attempts = attempts + 1, error_message = ? WHERE id = ?");
                    $updateStmt->execute(["mail() function returned false", $email['id']]);
                    $failed++;
                }
            }
        } catch (Exception $e) {
            // Update with error
            $updateStmt = $db->prepare("UPDATE verification_emails SET attempts = attempts + 1, error_message = ? WHERE id = ?");
            $updateStmt->execute([$e->getMessage(), $email['id']]);
            $failed++;
        }
    }
    
    // Return JSON response
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'sent' => $sent,
        'failed' => $failed,
        'processed' => count($emails)
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
