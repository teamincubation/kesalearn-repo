<?php
/**
 * Verification Badge Email Service
 * Modern, professional email system for verification status changes
 * Uses email queue database for reliable async delivery
 */

$dbPath = __DIR__ . '/../config/database.php';
$functionsPath = __DIR__ . '/functions.php';
$mailerPath = __DIR__ . '/mailer.php';

if (!file_exists($dbPath)) {
    $dbPath = __DIR__ . '/../../config/database.php';
}
if (!file_exists($functionsPath)) {
    $functionsPath = __DIR__ . '/../functions.php';
}
if (!file_exists($mailerPath)) {
    $mailerPath = __DIR__ . '/../mailer.php';
}

require_once $dbPath;
require_once $functionsPath;
require_once $mailerPath;

class VerificationEmailService {
    private $db;
    
    public function __construct() {
        try {
            $this->db = getDB();
            $this->initializeEmailQueue();
        } catch (Exception $e) {
            error_log("[VerificationEmailService] Initialization error: " . $e->getMessage());
            throw new Exception("Email service initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize email queue table if it doesn't exist
     */
    private function initializeEmailQueue() {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS verification_email_queue (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    email_type ENUM('badge_approved', 'badge_removed', 'registration_confirmation') NOT NULL,
                    email_subject VARCHAR(255),
                    email_body LONGTEXT,
                    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
                    attempts INT DEFAULT 0,
                    max_attempts INT DEFAULT 3,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    sent_at TIMESTAMP NULL,
                    error_message TEXT,
                    KEY (status),
                    KEY (user_id),
                    KEY (created_at)
                )
            ");
        } catch (Exception $e) {
            error_log("[Verification Email] Queue initialization error: " . $e->getMessage());
        }
    }
    
    /**
     * Queue verification badge approved email
     */
    public function queueBadgeApprovedEmail($userId, $email, $name) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO verification_email_queue 
                (user_id, recipient_email, email_type) 
                VALUES (?, ?, 'badge_approved')
            ");
            
            if ($stmt->execute([$userId, $email])) {
                error_log("[Verification Email] Badge approved email queued for user $userId");
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("[Verification Email] Queue error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Queue verification badge removed email
     */
    public function queueBadgeRemovedEmail($userId, $email, $name) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO verification_email_queue 
                (user_id, recipient_email, email_type) 
                VALUES (?, ?, 'badge_removed')
            ");
            
            if ($stmt->execute([$userId, $email])) {
                error_log("[Verification Email] Badge removed email queued for user $userId");
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("[Verification Email] Queue error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process pending emails from queue
     */
    public function processPendingEmails() {
        try {
            $stmt = $this->db->prepare("
                SELECT eq.id, eq.user_id, eq.recipient_email, eq.email_type, eq.email_subject, eq.email_body, u.name 
                FROM verification_email_queue eq
                LEFT JOIN users u ON eq.user_id = u.id
                WHERE eq.status = 'pending' AND eq.attempts < eq.max_attempts
                LIMIT 10
            ");
            
            $stmt->execute();
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sent = 0;
            foreach ($emails as $email) {
                if ($email['email_type'] === 'badge_approved') {
                    $sent += $this->sendBadgeApprovedEmail($email);
                } else if ($email['email_type'] === 'badge_removed') {
                    $sent += $this->sendBadgeRemovedEmail($email);
                } else if ($email['email_type'] === 'registration_confirmation') {
                    $sent += $this->sendRegistrationConfirmationEmail($email);
                }
            }
            
            return $sent;
        } catch (Exception $e) {
            error_log("[Verification Email] Process error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Send badge approved email
     */
    private function sendBadgeApprovedEmail($emailData) {
        try {
            $to = $emailData['recipient_email'];
            $name = $emailData['name'];
            
            $subject = 'Your KESA Account is Verified - Get Your Badge Now!';
            
            $body = '<html><body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; line-height: 1.6;">
                <div style="background: linear-gradient(135deg, #43d000 0%, #2fa600 100%); padding: 40px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h2 style="color: white; margin: 0; font-size: 1.8rem;">Congratulations!</h2>
                    <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0 0; font-size: 1rem;">Your KESA Account is Verified</p>
                </div>
                <div style="background: #f8f9fa; padding: 40px; border-radius: 0 0 8px 8px;">
                    <p>Hi ' . htmlspecialchars($name) . ',</p>
                    <p>Your KESA Learn account has been verified! You now have access to exclusive benefits:</p>
                    <ul style="color: #333; font-weight: 500;">
                        <li>Free KESA Webinars with Certificates</li>
                        <li>Verified Badge on Your Profile</li>
                        <li>KESA Premium Updates</li>
                    </ul>
                    <p>We will keep you updated with information about FREE KESA webinars with certificates directly in your email. Stay tuned for amazing learning opportunities!</p>
                    <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; margin: 24px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #92400e; font-size: 0.9rem;">
                            <strong>Important:</strong> Updating your profile will reset your badge verification request and automatically resubmit it for re-verification. Verified users will receive KESA Premium updates.
                        </p>
                    </div>
                    <p style="color: #666; font-size: 12px; text-align: center; margin-top: 30px;">KESA Learn - A flagship project of Team Incubation</p>
                </div>
            </body></html>';
            
            if (sendEmail($to, $subject, $body)) {
                $this->markAsSent($emailData['id']);
                return 1;
            } else {
                $this->recordAttempt($emailData['id'], 'Failed to send via mail()');
                return 0;
            }
        } catch (Exception $e) {
            $this->recordAttempt($emailData['id'], $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Send badge removed email
     */
    private function sendBadgeRemovedEmail($emailData) {
        try {
            $to = $emailData['recipient_email'];
            $name = $emailData['name'];
            
            $subject = 'Verification Badge Status Update - KESA Learn';
            
            $body = '<html><body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; line-height: 1.6;">
                <div style="background: #fef9e7; padding: 30px; border-radius: 8px;">
                    <h2 style="color: #92400e; margin-top: 0; text-align: center;">Verification Badge Update</h2>
                    <p>Hi ' . htmlspecialchars($name) . ',</p>
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
            
            if (sendEmail($to, $subject, $body)) {
                $this->markAsSent($emailData['id']);
                return 1;
            } else {
                $this->recordAttempt($emailData['id'], 'Failed to send via mail()');
                return 0;
            }
        } catch (Exception $e) {
            $this->recordAttempt($emailData['id'], $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Send registration confirmation email
     */
    private function sendRegistrationConfirmationEmail($emailData) {
        try {
            $to = $emailData['recipient_email'];
            $subject = $emailData['email_subject'] ?? 'Registration Received';
            $body = $emailData['email_body'] ?? '';
            
            if (sendEmail($to, $subject, $body)) {
                $this->markAsSent($emailData['id']);
                error_log("[Registration Email] Sent successfully to $to");
                return 1;
            } else {
                $this->recordAttempt($emailData['id'], 'Failed to send via mail()');
                error_log("[Registration Email] Failed to send to $to");
                return 0;
            }
        } catch (Exception $e) {
            $this->recordAttempt($emailData['id'], $e->getMessage());
            error_log("[Registration Email] Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Mark email as sent
     */
    private function markAsSent($emailId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE verification_email_queue 
                SET status = 'sent', sent_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$emailId]);
        } catch (Exception $e) {
            error_log("[Verification Email] Mark sent error: " . $e->getMessage());
        }
    }
    
    /**
     * Record failed attempt
     */
    private function recordAttempt($emailId, $errorMessage) {
        try {
            $stmt = $this->db->prepare("
                UPDATE verification_email_queue 
                SET attempts = attempts + 1, error_message = ? 
                WHERE id = ?
            ");
            $stmt->execute([$errorMessage, $emailId]);
        } catch (Exception $e) {
            error_log("[Verification Email] Record attempt error: " . $e->getMessage());
        }
    }
    
    /**
     * Queue registration confirmation email
     */
    public function queueRegistrationEmail($userId, $email, $name, $eventTitle) {
        try {
            $subject = 'Registration Received - ' . $eventTitle;
            
            $body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
                </div>
                <div style="background: #f8f9fa; padding: 30px; border-radius: 12px;">
                    <h2 style="color: #1a1a2e; margin-top: 0;">Registration Received!</h2>
                    <p style="color: #4a4a4a; line-height: 1.6;">Hi ' . htmlspecialchars($name) . ', we have received your registration for:</p>
                    <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #f5cb39; margin: 20px 0;">
                        <h3 style="color: #1a1a2e; margin: 0;">' . htmlspecialchars($eventTitle) . '</h3>
                    </div>
                    <p style="color: #4a4a4a; line-height: 1.6;">Your registration has been recorded. You can track your registration status from your dashboard.</p>
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="' . SITE_URL . '/user/dashboard" style="background: #4950ba; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Go to Dashboard</a>
                    </div>
                </div>
                <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
            </div>';
            
            $stmt = $this->db->prepare("
                INSERT INTO verification_email_queue 
                (user_id, recipient_email, email_type, email_subject, email_body) 
                VALUES (?, ?, 'registration_confirmation', ?, ?)
            ");
            
            if ($stmt->execute([$userId, $email, $subject, $body])) {
                error_log("[Registration Email] Confirmation email queued for user $userId - Event: $eventTitle");
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("[Registration Email] Queue error: " . $e->getMessage());
            return false;
        }
    }
}

// Global instance
$verificationEmailService = new VerificationEmailService();
