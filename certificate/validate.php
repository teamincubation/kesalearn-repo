<?php
/**
 * Certificate Validation Page
 * Shows detailed certificate information with event and payment details
 */

require_once __DIR__ . '/../includes/functions.php';

// Get certificate ID from URL
$certId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$certificate = null;
$event = null;
$registration = null;
$payment = null;
$user = null;
$error = null;

if ($certId > 0) {
    try {
        $db = getDB();
        
        // Simple query - just get the certificate by ID first
        $stmt = $db->prepare("SELECT * FROM certificates WHERE id = ?");
        $stmt->execute([$certId]);
        $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($certificate) {
            // Get event details if event_id exists
            if (!empty($certificate['event_id'])) {
                $eventStmt = $db->prepare("SELECT * FROM events WHERE id = ?");
                $eventStmt->execute([$certificate['event_id']]);
                $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Get user details if user_id exists
            if (!empty($certificate['user_id'])) {
                $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
                $userStmt->execute([$certificate['user_id']]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Get registration details if user and event exist
            if (!empty($certificate['user_id']) && !empty($certificate['event_id'])) {
                $regStmt = $db->prepare("
                    SELECT r.*, rp.razorpay_payment_id as payment_id, rp.razorpay_order_id as order_id, 
                           rp.amount as paid_amount, rp.status as razorpay_status, rp.created_at as payment_date
                    FROM registrations r
                    LEFT JOIN razorpay_payments rp ON r.id = rp.registration_id
                    WHERE r.user_id = ? AND r.event_id = ?
                    ORDER BY r.registered_at DESC
                    LIMIT 1
                ");
                $regStmt->execute([$certificate['user_id'], $certificate['event_id']]);
                $registration = $regStmt->fetch(PDO::FETCH_ASSOC);
            }
        } else {
            $error = "Certificate not found";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $error = "Unable to load certificate details: " . $e->getMessage();
    }
} else {
    $error = "Invalid certificate ID";
}

// Get organization name from settings
$orgName = 'KESA Learn';
try {
    $orgName = getSetting('linkedin_org_name', 'KESA Learn');
} catch (Exception $e) {}

$pageTitle = $certificate ? 'Certificate Validated' : 'Certificate Validation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo $orgName; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #059669;
            --primary-dark: #047857;
            --primary-light: #10b981;
            --bg-main: #f8fafc;
            --bg-card: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-main);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Validation Loader */
        .validation-loader {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }
        
        .validation-loader.hidden {
            opacity: 0;
            visibility: hidden;
        }
        
        .loader-icon {
            width: 80px;
            height: 80px;
            margin-bottom: 32px;
            position: relative;
        }
        
        .loader-icon svg {
            width: 100%;
            height: 100%;
            color: var(--primary-light);
        }
        
        .loader-ring {
            position: absolute;
            inset: -10px;
            border: 3px solid transparent;
            border-top-color: var(--primary-light);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loader-text {
            color: #fff;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 16px;
        }
        
        .loader-progress {
            width: 280px;
            height: 6px;
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 12px;
        }
        
        .loader-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 3px;
            width: 0%;
            transition: width 0.1s ease;
        }
        
        .loader-percent {
            color: var(--primary-light);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Main Content */
        .main-content {
            opacity: 0;
            transition: opacity 0.5s ease 0.3s;
        }
        
        .main-content.visible {
            opacity: 1;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 50px;
            color: #fff;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .header-badge svg {
            width: 18px;
            height: 18px;
        }
        
        .header-title {
            color: #fff;
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .header-subtitle {
            color: rgba(255,255,255,0.85);
            font-size: 0.95rem;
        }
        
        /* Container */
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px;
        }
        
        /* Validated Banner */
        .validated-banner {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 2px solid #a7f3d0;
            border-radius: var(--radius-lg);
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .validated-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4);
        }
        
        .validated-icon svg {
            width: 32px;
            height: 32px;
            color: #fff;
        }
        
        .validated-text h2 {
            color: var(--primary-dark);
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .validated-text p {
            color: #047857;
            font-size: 0.9rem;
        }
        
        /* Section Card */
        .section-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
            overflow: hidden;
            animation: slideUp 0.5s ease;
            animation-fill-mode: backwards;
        }
        
        .section-card:nth-child(2) { animation-delay: 0.1s; }
        .section-card:nth-child(3) { animation-delay: 0.2s; }
        .section-card:nth-child(4) { animation-delay: 0.3s; }
        .section-card:nth-child(5) { animation-delay: 0.4s; }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(to right, #f8fafc, #ffffff);
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .section-icon svg {
            width: 20px;
            height: 20px;
            color: #fff;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .section-body {
            padding: 20px;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .info-item {
            background: #f8fafc;
            border-radius: var(--radius-md);
            padding: 14px 16px;
            border: 1px solid var(--border);
        }
        
        .info-item.full-width {
            grid-column: 1 / -1;
        }
        
        .info-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        
        .info-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-primary);
            word-break: break-word;
        }
        
        .info-value.highlight {
            color: var(--primary);
        }
        
        .info-value.muted {
            color: var(--text-muted);
            font-style: italic;
            font-weight: 400;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-badge.verified {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-badge.paid {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge.free {
            background: #fef3c7;
            color: #92400e;
        }
        
        /* Error State */
        .error-card {
            background: #fef2f2;
            border: 2px solid #fecaca;
            border-radius: var(--radius-lg);
            padding: 40px;
            text-align: center;
        }
        
        .error-icon {
            width: 64px;
            height: 64px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .error-icon svg {
            width: 32px;
            height: 32px;
            color: #dc2626;
        }
        
        .error-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #991b1b;
            margin-bottom: 8px;
        }
        
        .error-message {
            color: #b91c1c;
            margin-bottom: 24px;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(5, 150, 105, 0.4);
        }
        
        .btn-linkedin {
            background: #0a66c2;
            color: #fff;
        }
        
        .btn-linkedin:hover {
            background: #004182;
        }
        
        .btn-outline {
            background: #fff;
            color: var(--text-primary);
            border: 2px solid var(--border);
        }
        
        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        /* Actions */
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }
        
        .actions .btn {
            flex: 1;
            min-width: 150px;
        }
        
        @media (max-width: 500px) {
            .actions {
                flex-direction: column;
            }
            .actions .btn {
                width: 100%;
            }
        }
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 24px;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .footer a {
            color: var(--primary);
            text-decoration: none;
        }
        
        /* Responsive */
        @media (max-width: 600px) {
            .header-title {
                font-size: 1.4rem;
            }
            
            .validated-banner {
                flex-direction: column;
                text-align: center;
            }
            
            .container {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <?php if ($certificate): ?>
    <!-- Validation Loader -->
    <div class="validation-loader" id="loader">
        <div class="loader-icon">
            <div class="loader-ring"></div>
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
        </div>
        <div class="loader-text">Validating Certificate...</div>
        <div class="loader-progress">
            <div class="loader-progress-bar" id="progressBar"></div>
        </div>
        <div class="loader-percent" id="progressPercent">0%</div>
    </div>
    <?php endif; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-badge">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    Certificate Validation
                </div>
                <h1 class="header-title"><?php echo htmlspecialchars($orgName); ?></h1>
                <p class="header-subtitle">Official Certificate Validation Portal</p>
            </div>
        </div>
        
        <div class="container">
            <?php if ($error): ?>
            <!-- Error State -->
            <div class="error-card">
                <div class="error-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h2 class="error-title">Validation Failed</h2>
                <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
                <a href="/user/certificates" class="btn btn-primary">Back to Certificates</a>
            </div>
            
            <?php else: ?>
            <!-- Validated Banner -->
            <div class="validated-banner">
                <div class="validated-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="validated-text">
                    <h2>Certificate Successfully Validated</h2>
                    <p>This certificate has been verified as authentic and issued by <?php echo htmlspecialchars($orgName); ?></p>
                </div>
            </div>
            
            <!-- Certificate Details -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                        </svg>
                    </div>
                    <h3 class="section-title">Certificate Information</h3>
                </div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Certificate ID</div>
                            <div class="info-value highlight"><?php echo htmlspecialchars($certificate['certificate_code'] ?? $certificate['certificate_number'] ?? 'CERT-' . $certificate['id']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="status-badge verified">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Verified
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Recipient Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificate['recipient_name'] ?? ($user['name'] ?? 'N/A')); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Certificate Issued Date</div>
                            <div class="info-value"><?php echo $certificate['generated_at'] ? date('F d, Y \a\t h:i A', strtotime($certificate['generated_at'])) : 'N/A'; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Event Details -->
            <?php if ($event): ?>
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h3 class="section-title">Event Details</h3>
                </div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-item full-width">
                            <div class="info-label">Event Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($event['title'] ?? ($certificate['event_name'] ?? 'N/A')); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Event Type</div>
                            <div class="info-value"><?php echo ucfirst(htmlspecialchars($event['type'] ?? 'N/A')); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Mode</div>
                            <div class="info-value"><?php echo ($event['is_online'] ?? false) ? 'Online' : 'In-Person'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Event Start Date & Time</div>
                            <div class="info-value"><?php echo !empty($event['start_date']) ? date('F d, Y \a\t h:i A', strtotime($event['start_date'])) : 'N/A'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Event End Date & Time</div>
                            <div class="info-value"><?php echo !empty($event['end_date']) ? date('F d, Y \a\t h:i A', strtotime($event['end_date'])) : 'N/A'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Venue / Location</div>
                            <div class="info-value"><?php echo htmlspecialchars($event['venue'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Total Participants</div>
                            <div class="info-value"><?php echo $event['seats_taken'] ?? 'N/A'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Event Fee</div>
                            <div class="info-value">
                                <?php if (!empty($event['is_free'])): ?>
                                    <span class="status-badge free">Free Event</span>
                                <?php else: ?>
                                    <?php echo ($event['currency'] ?? 'INR') . ' ' . number_format($event['price'] ?? 0, 2); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Registration Details -->
            <?php if ($registration): ?>
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <h3 class="section-title">Registration Details</h3>
                </div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Registration ID</div>
                            <div class="info-value highlight">#<?php echo $registration['id']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Registration Status</div>
                            <div class="info-value">
                                <span class="status-badge <?php echo $registration['payment_status'] === 'paid' || $registration['payment_status'] === 'verified' ? 'paid' : ''; ?>">
                                    <?php echo ucfirst($registration['payment_status'] ?? 'N/A'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">User Joined Event On</div>
                            <div class="info-value"><?php echo $registration['registered_at'] ? date('F d, Y \a\t h:i A', strtotime($registration['registered_at'])) : 'N/A'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Registration Amount</div>
                            <div class="info-value"><?php echo 'INR ' . number_format($registration['amount'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Payment Details -->
            <?php if ($registration && $registration['payment_id']): ?>
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                    </div>
                    <h3 class="section-title">Payment Details</h3>
                </div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Payment ID</div>
                            <div class="info-value" style="font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($registration['payment_id']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Order ID</div>
                            <div class="info-value" style="font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($registration['order_id'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Paid Amount</div>
                            <div class="info-value highlight">INR <?php echo number_format(($registration['paid_amount'] ?? 0) / 100, 2); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Payment Date</div>
                            <div class="info-value"><?php echo $registration['payment_date'] ? date('F d, Y \a\t h:i A', strtotime($registration['payment_date'])) : 'N/A'; ?></div>
                        </div>
                        <div class="info-item full-width">
                            <div class="info-label">Payment Status</div>
                            <div class="info-value">
                                <span class="status-badge paid">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <?php echo ucfirst($registration['razorpay_status'] ?? 'Completed'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div class="actions">
                <a href="/user/certificates" class="btn btn-outline">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back to Certificates
                </a>
                <a href="/user/download_certificate?id=<?php echo $certId; ?>" class="btn btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download Certificate
                </a>
                <?php
                $certName = $event['title'] ?? ($certificate['event_name'] ?? ($certificate['description'] ?? 'Certificate of Achievement'));
                $issueDate = $certificate['generated_at'] ?? $certificate['created_at'] ?? date('Y-m-d');
                $issueYear = date('Y', strtotime($issueDate));
                $issueMonth = intval(date('n', strtotime($issueDate)));
                $certCode = $certificate['certificate_code'] ?? $certificate['certificate_number'] ?? ('CERT-' . $certificate['id']);
                $certUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'kesalearn.com') . '/certificate/validate?id=' . $certId;
                $linkedInUrl = 'https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME'
                    . '&name=' . urlencode($certName)
                    . '&organizationName=' . urlencode($orgName)
                    . '&issueYear=' . $issueYear
                    . '&issueMonth=' . $issueMonth
                    . '&certId=' . urlencode($certCode)
                    . '&certUrl=' . urlencode($certUrl);
                ?>
                <a href="<?php echo $linkedInUrl; ?>" target="_blank" class="btn btn-linkedin">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                    Add to LinkedIn
                </a>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>Validated by <a href="/"><?php echo htmlspecialchars($orgName); ?></a> Certificate System</p>
        </div>
    </div>
    
    <?php if ($certificate): ?>
    <script>
        // Validation loader animation
        document.addEventListener('DOMContentLoaded', function() {
            const loader = document.getElementById('loader');
            const mainContent = document.getElementById('mainContent');
            const progressBar = document.getElementById('progressBar');
            const progressPercent = document.getElementById('progressPercent');
            
            let progress = 0;
            const duration = 1500;
            const interval = 20;
            const increment = 100 / (duration / interval);
            
            function easeOutQuart(t) {
                return 1 - Math.pow(1 - t, 4);
            }
            
            const animationInterval = setInterval(function() {
                progress += increment;
                if (progress >= 100) {
                    progress = 100;
                    clearInterval(animationInterval);
                    
                    setTimeout(function() {
                        loader.classList.add('hidden');
                        mainContent.classList.add('visible');
                    }, 300);
                }
                
                const easedProgress = easeOutQuart(progress / 100) * 100;
                progressBar.style.width = easedProgress + '%';
                progressPercent.textContent = Math.round(easedProgress) + '%';
            }, interval);
        });
    </script>
    <?php endif; ?>
</body>
</html>
