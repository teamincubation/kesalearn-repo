<?php
/**
 * KESA Learn - Email Helper
 * 
 * Uses PHP's built-in mail() function as fallback.
 * For production, install PHPMailer via Composer:
 *   composer require phpmailer/phpmailer
 * 
 * Then uncomment the PHPMailer section below.
 */

require_once __DIR__ . '/functions.php';

function sendEmail(string $to, string $subject, string $body): bool {
    /* ------------------------------------------------
       OPTION 1: PHPMailer (Recommended for production)
       Uncomment after running: composer require phpmailer/phpmailer
       ------------------------------------------------
    
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;
    
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;
        
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
    */
    
    // ------------------------------------------------
    // OPTION 2: PHP mail() fallback
    // ------------------------------------------------
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
    
    return mail($to, $subject, $body, $headers);
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail(string $email, string $token): bool {
    $resetUrl = SITE_URL . '/auth/reset_password?token=' . $token;
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
            <p style="color: #6c757d;">Knowledge Enhancement & Skill Acquisition</p>
        </div>
        <div style="background: #f8f9fa; padding: 30px; border-radius: 12px;">
            <h2 style="color: #1a1a2e; margin-top: 0;">Reset Your Password</h2>
            <p style="color: #4a4a4a; line-height: 1.6;">We received a request to reset your password. Click the button below to create a new password:</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $resetUrl . '" style="background: #e7404a; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Reset Password</a>
            </div>
            <p style="color: #6c757d; font-size: 14px;">This link expires in 1 hour. If you did not request this, please ignore this email.</p>
        </div>
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, 'Reset Your Password - KESA Learn', $body);
}

/**
 * Send email verification
 */
function sendVerificationEmail(string $email, string $token, string $name): bool {
    $verifyUrl = SITE_URL . '/auth/verify_email?token=' . $token;
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
            <p style="color: #6c757d;">Knowledge Enhancement & Skill Acquisition</p>
        </div>
        <div style="background: #f8f9fa; padding: 30px; border-radius: 12px;">
            <h2 style="color: #1a1a2e; margin-top: 0;">Welcome, ' . htmlspecialchars($name) . '!</h2>
            <p style="color: #4a4a4a; line-height: 1.6;">Thank you for joining KESA Learn. Please verify your email address to get started:</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $verifyUrl . '" style="background: #4950ba; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Verify Email</a>
            </div>
            <p style="color: #6c757d; font-size: 14px;">This link expires in 24 hours.</p>
        </div>
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, 'Verify Your Email - KESA Learn', $body);
}

/**
 * Send registration confirmation email (only when payment is pending for paid events or for free events)
 */
function sendRegistrationEmail(string $email, string $name, string $eventTitle): bool {
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
            <p style="color: #4a4a4a; line-height: 1.6;">Please complete your payment to confirm your seat. You can track your registration status from your dashboard.</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . SITE_URL . '/user/dashboard" style="background: #4950ba; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Go to Dashboard</a>
            </div>
        </div>
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, 'Registration Received - ' . $eventTitle, $body);
}

/**
 * Send payment success email (only when payment is confirmed)
 */
function sendPaymentSuccessEmail(string $email, string $name, string $eventTitle, float $amount, string $currency = 'INR'): bool {
    $formattedAmount = $currency . ' ' . number_format($amount, 2);
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
        </div>
        <div style="background: #e6f9f0; padding: 30px; border-radius: 12px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#0d7a42" stroke-width="2" style="margin: 0 auto;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M9 12l2 2 4-4"></path>
                </svg>
            </div>
            <h2 style="color: #0d7a42; margin-top: 0; text-align: center;">Payment Successful!</h2>
            <p style="color: #4a4a4a; line-height: 1.6; text-align: center;">Hi ' . htmlspecialchars($name) . ', your payment has been confirmed.</p>
            
            <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #1a1a2e; margin: 0 0 8px 0;">' . htmlspecialchars($eventTitle) . '</h3>
                <p style="color: #0d7a42; font-weight: bold; font-size: 1.2rem; margin: 0;">Amount Paid: ' . $formattedAmount . '</p>
            </div>
            
            <p style="color: #4a4a4a; line-height: 1.6;">Your registration is now complete. You will receive event details and joining instructions before the event starts.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . SITE_URL . '/user/registrations" style="background: #0d7a42; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">View My Registrations</a>
            </div>
        </div>
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, 'Payment Confirmed - ' . $eventTitle, $body);
}

/**
 * Send payment failed/rejected email
 */
function sendPaymentFailedEmail(string $email, string $name, string $eventTitle, string $reason = ''): bool {
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
        </div>
        <div style="background: #fef2f2; padding: 30px; border-radius: 12px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" style="margin: 0 auto;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M15 9l-6 6M9 9l6 6"></path>
                </svg>
            </div>
            <h2 style="color: #dc2626; margin-top: 0; text-align: center;">Payment Issue</h2>
            <p style="color: #4a4a4a; line-height: 1.6; text-align: center;">Hi ' . htmlspecialchars($name) . ', there was an issue with your payment for:</p>
            
            <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #1a1a2e; margin: 0;">' . htmlspecialchars($eventTitle) . '</h3>
                ' . ($reason ? '<p style="color: #dc2626; margin: 8px 0 0 0;">' . htmlspecialchars($reason) . '</p>' : '') . '
            </div>
            
            <p style="color: #4a4a4a; line-height: 1.6;">Please try again or contact our support team for assistance.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . SITE_URL . '/user/dashboard" style="background: #e7404a; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Try Again</a>
            </div>
        </div>
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, 'Payment Issue - ' . $eventTitle, $body);
}

/**
 * Send payment pending verification email (for manual UPI payments)
 */
function sendPaymentPendingEmail(string $email, string $name, string $eventTitle): bool {
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
        </div>
        <div style="background: #fef9e7; padding: 30px; border-radius: 12px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#d4ad1e" stroke-width="2" style="margin: 0 auto;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M12 6v6l4 2"></path>
                </svg>
            </div>
            <h2 style="color: #d4ad1e; margin-top: 0; text-align: center;">Payment Verification Pending</h2>
            <p style="color: #4a4a4a; line-height: 1.6; text-align: center;">Hi ' . htmlspecialchars($name) . ', we have received your payment proof for:</p>
            
            <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #1a1a2e; margin: 0;">' . htmlspecialchars($eventTitle) . '</h3>
            </div>
            
            <p style="color: #4a4a4a; line-height: 1.6;">Our team will verify your payment 30 minutes to 6 hours. You will receive a confirmation email once verified.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . SITE_URL . '/user/dashboard" style="background: #4950ba; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Track Status</a>
            </div>
        </div>
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA under Labinc Education Pvt. Ltd. in association with Team Incubation.</p>
    </div>';
    
    return sendEmail($email, 'Payment Verification Pending - ' . $eventTitle, $body);
}

/**
 * Send event session starting notification email
 */
function sendSessionStartingEmail(string $email, string $name, array $event): bool {
    $eventDate = date('l, F j, Y', strtotime($event['start_date']));
    $eventTime = date('g:i A', strtotime($event['start_date']));
    $isOnline = $event['is_online'];
    $meetingLink = $event['meeting_link'] ?? '';
    $venue = $event['venue'] ?? '';
    
    // Speaker info
    $speakerHtml = '';
    if (!empty($event['speakers'])) {
        $speakerHtml = '<div style="background: white; padding: 16px; border-radius: 8px; margin: 16px 0;">
            <h4 style="color: #1a1a2e; margin: 0 0 8px 0; font-size: 0.9rem;">Featured Speaker(s):</h4>';
        foreach ($event['speakers'] as $speaker) {
            $speakerHtml .= '<p style="margin: 4px 0; color: #4a4a4a;">' . htmlspecialchars($speaker['name']) . 
                           (!empty($speaker['title']) ? ' - ' . htmlspecialchars($speaker['title']) : '') . '</p>';
        }
        $speakerHtml .= '</div>';
    }
    
    // Location/meeting details
    $locationHtml = '';
    if ($isOnline && $meetingLink) {
        $locationHtml = '
        <div style="background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
            <p style="color: white; margin: 0 0 12px 0; font-size: 1rem;">Join the Online Session</p>
            <a href="' . htmlspecialchars($meetingLink) . '" style="background: white; color: #128C7E; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Join Now</a>
        </div>';
    } elseif (!$isOnline && $venue) {
        $locationHtml = '
        <div style="background: #f0f4ff; padding: 16px; border-radius: 8px; margin: 16px 0; border-left: 4px solid #4950ba;">
            <p style="margin: 0; color: #4a4a4a;"><strong>Venue:</strong> ' . htmlspecialchars($venue) . '</p>
        </div>';
    }
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
            <p style="color: #6c757d; margin: 8px 0 0 0;">Knowledge Enhancement & Skill Acquisition</p>
        </div>
        
        <div style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); padding: 20px; border-radius: 12px 12px 0 0; text-align: center;">
            <div style="display: inline-block; background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: 20px; margin-bottom: 10px;">
                <span style="color: white; font-weight: bold; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px;">Session Starting Soon</span>
            </div>
            <h2 style="color: white; margin: 10px 0 0 0; font-size: 1.3rem;">' . htmlspecialchars($event['title']) . '</h2>
        </div>
        
        <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 12px 12px;">
            <p style="color: #4a4a4a; line-height: 1.6; margin-top: 0;">Hi ' . htmlspecialchars($name) . ',</p>
            <p style="color: #4a4a4a; line-height: 1.6;">Your registered event is about to begin. Get ready!</p>
            
            <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #e7404a;">
                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <p style="margin: 0 0 4px 0; color: #6c757d; font-size: 0.85rem;">Date</p>
                        <p style="margin: 0; color: #1a1a2e; font-weight: 600;">' . $eventDate . '</p>
                    </div>
                    <div>
                        <p style="margin: 0 0 4px 0; color: #6c757d; font-size: 0.85rem;">Time</p>
                        <p style="margin: 0; color: #1a1a2e; font-weight: 600;">' . $eventTime . '</p>
                    </div>
                    <div>
                        <p style="margin: 0 0 4px 0; color: #6c757d; font-size: 0.85rem;">Mode</p>
                        <p style="margin: 0; color: #1a1a2e; font-weight: 600;">' . ($isOnline ? 'Online' : 'In-Person') . '</p>
                    </div>
                </div>
            </div>
            
            ' . $speakerHtml . '
            ' . $locationHtml . '
            
            <div style="background: #fff8e1; padding: 16px; border-radius: 8px; margin: 20px 0;">
                <p style="margin: 0; color: #856404; font-size: 0.9rem;">
                    <strong>Quick Reminder:</strong> Please join a few minutes early to ensure a smooth start. Have your questions ready!
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0 0 0;">
                <a href="' . SITE_URL . '/user/dashboard" style="background: #4950ba; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Go to Dashboard</a>
            </div>
        </div>
        
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">
            KESA Learn - A flagship project of Team Incubation<br>
            <a href="' . SITE_URL . '" style="color: #adb5bd;">kesalearn.com</a>
        </p>
    </div>';
    
    return sendEmail($email, 'Starting Soon: ' . $event['title'] . ' - KESA Learn', $body);
}

/**
 * Send event reminder email (scheduled for before event)
 */
function sendEventReminderEmail(string $email, string $name, array $event, string $reminderType = '24h'): bool {
    $eventDate = date('l, F j, Y', strtotime($event['start_date']));
    $eventTime = date('g:i A', strtotime($event['start_date']));
    
    $reminderText = $reminderType === '24h' ? 'tomorrow' : 'in 1 hour';
    $subject = $reminderType === '24h' ? 'Reminder: Tomorrow - ' : 'Starting in 1 Hour: ';
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
        </div>
        
        <div style="background: #f0f4ff; padding: 30px; border-radius: 12px;">
            <h2 style="color: #4950ba; margin-top: 0; text-align: center;">Event Reminder</h2>
            <p style="color: #4a4a4a; line-height: 1.6; text-align: center;">
                Hi ' . htmlspecialchars($name) . ', your registered event starts ' . $reminderText . '!
            </p>
            
            <div style="background: white; padding: 24px; border-radius: 8px; margin: 20px 0; text-align: center;">
                <h3 style="color: #1a1a2e; margin: 0 0 16px 0;">' . htmlspecialchars($event['title']) . '</h3>
                <p style="margin: 8px 0; color: #4a4a4a;"><strong>Date:</strong> ' . $eventDate . '</p>
                <p style="margin: 8px 0; color: #4a4a4a;"><strong>Time:</strong> ' . $eventTime . '</p>
                <p style="margin: 8px 0; color: #4a4a4a;"><strong>Mode:</strong> ' . ($event['is_online'] ? 'Online' : 'In-Person') . '</p>
            </div>
            
            <div style="text-align: center; margin: 30px 0 0 0;">
                <a href="' . SITE_URL . '/user/dashboard" style="background: #4950ba; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">View Event Details</a>
            </div>
        </div>
        
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, $subject . $event['title'], $body);
}

/**
 * Send live session notification email
 */
function sendLiveSessionEmail(string $email, string $name, array $session, string $type = 'scheduled'): bool {
    $sessionTitle = htmlspecialchars($session['title']);
    $eventTitle = htmlspecialchars($session['event_title']);
    $instructorName = htmlspecialchars($session['instructor_name'] ?? 'TBA');
    $platform = ucfirst(str_replace('_', ' ', $session['platform']));
    $startTime = date('M d, Y \a\t h:i A', strtotime($session['start_datetime']));
    $meetingLink = $session['meeting_link'] ?? '';
    $recordingUrl = $session['recording_url'] ?? '';
    
    // Set subject and accent color based on type
    $subject = '';
    $accentColor = '#4950ba';
    $bgColor = '#f0f4ff';
    $icon = '';
    $heading = '';
    $message = '';
    $ctaText = '';
    $ctaUrl = SITE_URL . '/user/dashboard';
    
    switch ($type) {
        case 'scheduled':
            $subject = 'New Live Session Scheduled: ' . $sessionTitle;
            $accentColor = '#4950ba';
            $bgColor = '#f0f4ff';
            $icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#4950ba" stroke-width="2" style="margin: 0 auto;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
            $heading = 'New Live Session Scheduled!';
            $message = 'A new live session has been scheduled for your event. Mark your calendar and join us!';
            $ctaText = 'View Dashboard';
            break;
            
        case 'updated':
            $subject = 'Session Updated: ' . $sessionTitle;
            $accentColor = '#d4ad1e';
            $bgColor = '#fef9e7';
            $icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#d4ad1e" stroke-width="2" style="margin: 0 auto;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
            $heading = 'Session Details Updated';
            $message = 'The session details have been updated. Please review the new information below.';
            $ctaText = 'View Dashboard';
            break;
            
        case 'live':
            $subject = 'LIVE NOW: ' . $sessionTitle;
            $accentColor = '#dc2626';
            $bgColor = '#fef2f2';
            $icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" style="margin: 0 auto;"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
            $heading = 'The Session is LIVE!';
            $message = 'The live session is happening right now! Click the button below to join immediately.';
            $ctaText = 'Join Now';
            $ctaUrl = $meetingLink ?: $ctaUrl;
            break;
            
        case 'recording_available':
            $subject = 'Recording Available: ' . $sessionTitle;
            $accentColor = '#0d7a42';
            $bgColor = '#e6f9f0';
            $icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#0d7a42" stroke-width="2" style="margin: 0 auto;"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>';
            $heading = 'Recording Now Available!';
            $expiryNote = !empty($session['recording_expiry_date']) ? '<br><span style="color: #6c757d; font-size: 14px;">Access available until ' . date('M d, Y', strtotime($session['recording_expiry_date'])) . '</span>' : '';
            $message = 'The recording for this session is now available. Watch it at your convenience!' . $expiryNote;
            $ctaText = 'Watch Recording';
            $ctaUrl = $recordingUrl ?: $ctaUrl;
            break;
            
        default:
            return false;
    }
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
            <p style="color: #6c757d;">Knowledge Enhancement & Skill Acquisition</p>
        </div>
        <div style="background: ' . $bgColor . '; padding: 30px; border-radius: 12px;">
            <div style="text-align: center; margin-bottom: 20px;">
                ' . $icon . '
            </div>
            <h2 style="color: ' . $accentColor . '; margin-top: 0; text-align: center;">' . $heading . '</h2>
            <p style="color: #4a4a4a; line-height: 1.6; text-align: center;">Hi ' . htmlspecialchars($name) . ', ' . $message . '</p>
            
            <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid ' . $accentColor . ';">
                <h3 style="color: #1a1a2e; margin: 0 0 8px 0;">' . $sessionTitle . '</h3>
                <p style="color: #6c757d; margin: 0 0 12px 0; font-size: 14px;">Event: ' . $eventTitle . '</p>
                <table style="width: 100%; font-size: 14px; color: #4a4a4a;">
                    <tr>
                        <td style="padding: 4px 0;"><strong>Session Date & Time:</strong></td>
                        <td style="padding: 4px 0;">' . $startTime . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0;"><strong>Instructor:</strong></td>
                        <td style="padding: 4px 0;">' . $instructorName . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0;"><strong>Platform:</strong></td>
                        <td style="padding: 4px 0;">' . $platform . '</td>
                    </tr>
                </table>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $ctaUrl . '" style="background: ' . $accentColor . '; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">' . $ctaText . '</a>
            </div>
            
            ' . (!empty($meetingLink) && $type !== 'live' && $type !== 'recording_available' ? '
            <p style="color: #6c757d; font-size: 14px; text-align: center;">
                <strong>Meeting Link:</strong> <a href="' . htmlspecialchars($meetingLink) . '" style="color: ' . $accentColor . ';">' . htmlspecialchars($meetingLink) . '</a>
            </p>' : '') . '
        </div>
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, $subject, $body);
}

/**
 * Send certificate available notification email
 */
function sendCertificateNotificationEmail(string $email, string $name, string $eventTitle, string $certificateNumber): bool {
    $certificatesUrl = SITE_URL . '/user/certificates';
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
            <p style="color: #6c757d; margin: 8px 0 0 0;">Knowledge Enhancement & Skill Acquisition</p>
        </div>
        
        <div style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); padding: 24px; border-radius: 12px 12px 0 0; text-align: center;">
            <div style="width: 70px; height: 70px; background: rgba(255,255,255,0.2); border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <circle cx="12" cy="8" r="6"></circle>
                    <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"></path>
                </svg>
            </div>
            <h2 style="color: white; margin: 0; font-size: 1.4rem;">Congratulations!</h2>
            <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0 0; font-size: 1rem;">Your Certificate is Ready</p>
        </div>
        
        <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 12px 12px;">
            <p style="color: #4a4a4a; line-height: 1.6; margin-top: 0; font-size: 1rem;">
                Hi <strong>' . htmlspecialchars($name) . '</strong>,
            </p>
            <p style="color: #4a4a4a; line-height: 1.6;">
                Great news! Your certificate for the following program is now available for download:
            </p>
            
            <div style="background: white; padding: 20px; border-radius: 10px; margin: 24px 0; border-left: 4px solid #059669; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                <h3 style="color: #1a1a2e; margin: 0 0 12px 0; font-size: 1.1rem;">' . htmlspecialchars($eventTitle) . '</h3>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="color: #6b7280; font-size: 0.9rem;">Certificate Number:</span>
                    <code style="background: #f3f4f6; padding: 4px 10px; border-radius: 4px; font-family: monospace; font-weight: 600; color: #059669;">' . htmlspecialchars($certificateNumber) . '</code>
                </div>
            </div>
            
            <div style="background: #ecfdf5; padding: 16px; border-radius: 8px; margin: 20px 0;">
                <p style="margin: 0; color: #065f46; font-size: 0.9rem;">
                    <strong>What you can do:</strong><br>
                    - Download your certificate as PDF/Image<br>
                    - Share your achievement on LinkedIn<br>
                    - Verify authenticity anytime using certificate number
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0 0 0;">
                <a href="' . $certificatesUrl . '" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: white; padding: 16px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block; font-size: 1rem; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);">Download Certificate</a>
            </div>
            
            <p style="color: #9ca3af; font-size: 0.85rem; text-align: center; margin: 24px 0 0 0;">
                Thank you for learning with us. Keep growing!
            </p>
        </div>
        
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">
            KESA Learn - A flagship project of Team Incubation<br>
            <a href="' . SITE_URL . '" style="color: #adb5bd;">kesalearn.com</a>
        </p>
    </div>';
    
    return sendEmail($email, 'Your Certificate is Ready - ' . $eventTitle, $body);
}

/**
 * Send feedback request email
 */
function sendFeedbackRequestEmail(string $email, string $name, string $eventTitle): bool {
    $feedbackUrl = SITE_URL . '/user/feedback';
    
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
            <p style="color: #6c757d; margin: 8px 0 0 0;">Knowledge Enhancement & Skill Acquisition</p>
        </div>
        
        <div style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); padding: 24px; border-radius: 16px 16px 0 0; text-align: center;">
            <div style="width: 64px; height: 64px; background: rgba(255,255,255,0.2); border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                <span style="font-size: 32px;">&#9733;</span>
            </div>
            <h2 style="color: white; margin: 0; font-size: 1.4rem;">We Value Your Feedback!</h2>
        </div>
        
        <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 16px 16px;">
            <p style="color: #4a4a4a; line-height: 1.6; margin-top: 0;">Hi ' . htmlspecialchars($name) . ',</p>
            <p style="color: #4a4a4a; line-height: 1.6;">Thank you for attending our event! We hope you had a great learning experience.</p>
            
            <div style="background: white; padding: 20px; border-radius: 12px; margin: 20px 0; border-left: 4px solid #8b5cf6;">
                <h3 style="color: #1a1a2e; margin: 0 0 8px 0;">' . htmlspecialchars($eventTitle) . '</h3>
                <p style="color: #6c757d; margin: 0; font-size: 0.9rem;">Event Completed</p>
            </div>
            
            <p style="color: #4a4a4a; line-height: 1.6;">Your feedback helps us improve and create better learning experiences for everyone. Please take a moment to share your thoughts.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $feedbackUrl . '" style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: bold; display: inline-block; font-size: 1rem;">Share Your Feedback</a>
            </div>
            
            <div style="background: #fef3c7; padding: 16px; border-radius: 10px; margin: 20px 0;">
                <p style="margin: 0; color: #92400e; font-size: 0.9rem;">
                    <strong>It only takes 1 minute!</strong> Your honest feedback helps us serve you better.
                </p>
            </div>
        </div>
        
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">
            KESA Learn - A flagship project of Team Incubation<br>
            <a href="' . SITE_URL . '" style="color: #adb5bd;">kesalearn.com</a>
        </p>
    </div>';
    
    return sendEmail($email, 'Share Your Feedback - ' . $eventTitle, $body);
}

/**
 * Send templated email (generic wrapper for custom templates)
 */
function sendTemplatedEmail(string $email, string $subject, string $templateType, array $data): bool {
    switch ($templateType) {
        case 'session_notification':
            return sendLiveSessionEmail($email, $data['user_name'], [
                'title' => $data['session_title'],
                'event_title' => $data['event_title'],
                'instructor_name' => $data['instructor_name'],
                'start_datetime' => $data['start_time'],
                'platform' => $data['platform'],
                'meeting_link' => $data['meeting_link'],
                'recording_url' => $data['recording_url'] ?? '',
                'recording_expiry_date' => $data['recording_expiry_date'] ?? ''
            ], $data['notification_type']);
        default:
            return false;
    }
}

/**
 * Send WhatsApp group invitation email
 */
function sendWhatsAppGroupInvitation(string $email, string $name, string $eventTitle, string $whatsappGroupLink): bool {
    $body = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #e7404a; margin: 0;">KESA Learn</h1>
            <p style="color: #6c757d;">Knowledge Enhancement & Skill Acquisition</p>
        </div>
        
        <div style="background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); padding: 30px; border-radius: 12px 12px 0 0; text-align: center;">
            <div style="display: inline-block; background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: 20px; margin-bottom: 12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="white" style="display: inline-block; vertical-align: middle; margin-right: 6px;">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.67-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421-7.403h-.004a9.87 9.87 0 00-4.946 1.347l-.355.203-3.68-.96.976 3.554-.228.364a9.86 9.86 0 001.485 5.83 9.89 9.89 0 008.544 4.368c.1 0 .2 0 .297-.005a9.889 9.889 0 008.676-10.047 9.89 9.89 0 00-2.717-8.154 9.87 9.87 0 00-5.655-1.914 9.87 9.87 0 00-1.347.06z"/>
                </svg>
                <span style="color: white; font-weight: bold; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px;">Join WhatsApp Group</span>
            </div>
            <h2 style="color: white; margin: 12px 0 0 0;">Connect With Fellow Participants</h2>
        </div>
        
        <div style="background: #f0f9f7; padding: 30px; border-radius: 0 0 12px 12px;">
            <p style="color: #4a4a4a; line-height: 1.6; margin-top: 0;">Hi ' . htmlspecialchars($name) . ',</p>
            
            <p style="color: #4a4a4a; line-height: 1.6;">Your payment for <strong>' . htmlspecialchars($eventTitle) . '</strong> has been confirmed! Join our exclusive WhatsApp group to:</p>
            
            <ul style="color: #4a4a4a; line-height: 1.8; margin: 16px 0; padding-left: 20px;">
                <li>Connect with fellow participants</li>
                <li>Get real-time updates and announcements</li>
                <li>Share resources and insights</li>
                <li>Receive event reminders and joining links</li>
            </ul>
            
            <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #25D366; margin: 24px 0;">
                <p style="color: #6c757d; font-size: 0.9rem; margin: 0 0 12px 0;">Click the button below to join the group:</p>
                <a href="' . htmlspecialchars($whatsappGroupLink) . '" style="display: inline-block; background: #25D366; color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; transition: all 0.2s;" onmouseover="this.style.background=\'#20ba5a\';" onmouseout="this.style.background=\'#25D366\';">Join WhatsApp Group</a>
            </div>
            
            <p style="color: #6c757d; font-size: 0.9rem; line-height: 1.6;">If you don\'t have WhatsApp, you can download it from the App Store or Play Store and join using the invite link.</p>
        </div>
        
        <p style="color: #adb5bd; font-size: 12px; text-align: center; margin-top: 20px;">KESA Learn - A flagship project of Team Incubation</p>
    </div>';
    
    return sendEmail($email, 'Join WhatsApp Group - ' . $eventTitle, $body);
}

