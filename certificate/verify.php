<?php
/**
 * KESA Learn - Professional Certificate Verification Page
 * With verification loader animation and comprehensive details
 */
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

// Function to mask email for privacy
function maskEmail($email) {
    if (empty($email) || !strpos($email, '@')) {
        return 'N/A';
    }
    
    list($username, $domain) = explode('@', $email);
    $usernameLength = strlen($username);
    
    if ($usernameLength <= 2) {
        $maskedUsername = str_repeat('*', $usernameLength);
    } else {
        // Show first letter and last letter, mask the rest
        $firstChar = $username[0];
        $lastChar = $username[$usernameLength - 1];
        $middleLength = max(1, $usernameLength - 2);
        $maskedMiddle = str_repeat('*', $middleLength);
        $maskedUsername = $firstChar . $maskedMiddle . $lastChar;
    }
    
    return $maskedUsername . '@' . $domain;
}

// Get certificate code from URL
$certCode = isset($_GET['code']) ? trim($_GET['code']) : '';

$certificate = null;
$registration = null;
$totalParticipants = 0;
$linkedInShareUrl = '';
$orgName = 'KESA Learn';
$instructorName = 'N/A';
$instructorDesignation = 'N/A';

// Try to get LinkedIn org name from settings
try {
    $orgName = getSetting('linkedin_org_name', 'KESA Learn');
} catch (Exception $e) {
    // Use default
}

if (!empty($certCode)) {
    try {
        // Query to get certificate and event details
        $stmt = $db->prepare("
            SELECT c.*, 
                   e.id as event_id, e.title as event_title, e.description as event_description,
                   e.type as event_type, e.start_date, e.end_date, e.venue, e.is_online,
                   e.price as event_price, e.currency, e.is_free, e.banner_image,
                   e.seats_taken as event_participants,
                   u.name as user_name, u.email as user_email
            FROM certificates c 
            LEFT JOIN events e ON c.event_id = e.id 
            LEFT JOIN users u ON c.user_id = u.id 
            WHERE c.certificate_code = ?
            LIMIT 1
        ");
        $stmt->execute([$certCode]);
        $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($certificate) {
            // Fetch instructor details from event_instructors junction table
            try {
                if (!empty($certificate['event_id'])) {
                    $instrStmt = $db->prepare("
                        SELECT i.name as instructor_name, i.designation as instructor_designation
                        FROM instructors i 
                        INNER JOIN event_instructors ei ON i.id = ei.instructor_id 
                        WHERE ei.event_id = ?
                        ORDER BY ei.sort_order ASC
                        LIMIT 1
                    ");
                    $instrStmt->execute([$certificate['event_id']]);
                    $instrResult = $instrStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($instrResult) {
                        $instructorName = $instrResult['instructor_name'] ?? 'N/A';
                        $instructorDesignation = $instrResult['instructor_designation'] ?? 'N/A';
                    }
                }
            } catch (Exception $e) {
                // Instructor details not available - use defaults
                error_log('[v0] Instructor fetch error: ' . $e->getMessage());
                $instructorName = 'N/A';
                $instructorDesignation = 'N/A';
            }
            
            // Get registration details
            if (!empty($certificate['user_id']) && !empty($certificate['event_id'])) {
                $regStmt = $db->prepare("
                    SELECT r.*
                    FROM registrations r
                    WHERE r.user_id = ? AND r.event_id = ?
                    LIMIT 1
                ");
                $regStmt->execute([$certificate['user_id'], $certificate['event_id']]);
                $registration = $regStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Get total participants count
            if (!empty($certificate['event_id'])) {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND payment_status IN ('paid', 'verified')");
                $countStmt->execute([$certificate['event_id']]);
                $totalParticipants = $countStmt->fetchColumn();
            }
            
            // Increment view count
            $db->prepare("UPDATE certificates SET download_count = download_count + 1 WHERE id = ?")->execute([$certificate['id']]);
            
            // Generate LinkedIn share URL
            $certName = $certificate['event_title'] ?? 'Certificate of Achievement';
            $issueDate = $certificate['generated_at'] ?? date('Y-m-d');
            $issueYear = date('Y', strtotime($issueDate));
            $issueMonth = intval(date('n', strtotime($issueDate))); // 1-12 without leading zeros
            $certUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'kesalearn.com') . '/certificate/verify?code=' . urlencode($certCode);
            
            // LinkedIn certification URL with correct parameters
            $linkedInShareUrl = 'https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME'
                . '&name=' . urlencode($certName)
                . '&organizationName=' . urlencode($orgName)
                . '&issueYear=' . $issueYear
                . '&issueMonth=' . $issueMonth
                . '&certId=' . urlencode($certCode)
                . '&certUrl=' . urlencode($certUrl);
        }
    } catch (Exception $e) {
        // Certificate lookup failed - will show not found
        $certificate = null;
        error_log('[v0] Certificate verify error: ' . $e->getMessage());
    }
}

$pageTitle = $certificate ? 'Certificate Verified' : 'Verify Certificate';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - KESA Learn</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            color: #1e293b;
            line-height: 1.6;
        }
        
        /* Verification Loader */
        .loader-overlay {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.5s, visibility 0.5s;
        }
        
        .loader-overlay.hide {
            opacity: 0;
            visibility: hidden;
        }
        
        .loader-icon {
            width: 80px;
            height: 80px;
            margin-bottom: 32px;
            position: relative;
        }
        
        .loader-circle {
            width: 80px;
            height: 80px;
            border: 3px solid rgba(255,255,255,0.1);
            border-top-color: #22c55e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        .loader-check {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #22c55e;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .loader-check.show {
            opacity: 1;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loader-text {
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 24px;
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
            background: linear-gradient(90deg, #22c55e 0%, #16a34a 100%);
            border-radius: 3px;
            width: 0%;
            transition: width 0.05s linear;
        }
        
        .loader-percent {
            color: rgba(255,255,255,0.7);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Main Content */
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }
        
        /* Header */
        .header {
            text-align: center;
            padding: 24px 0;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e293b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .logo span {
            color: #dc2626;
        }
        
        /* Verified Banner */
        .verified-banner {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            color: #fff;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .verified-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.1) 50%, transparent 100%);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .verified-icon {
            width: 72px;
            height: 72px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        
        .verified-icon svg {
            width: 40px;
            height: 40px;
        }
        
        .verified-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .verified-subtitle {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .cert-id-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 8px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 16px;
            letter-spacing: 0.5px;
        }
        
        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        
        @media (min-width: 640px) {
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .info-card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .info-card.full-width {
            grid-column: 1 / -1;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .card-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .card-icon.green { background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); color: #16a34a; }
        .card-icon.blue { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #2563eb; }
        .card-icon.purple { background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%); color: #7c3aed; }
        .card-icon.orange { background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%); color: #ea580c; }
        
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .detail-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            text-align: right;
        }
        
        .detail-value.highlight {
            color: #059669;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 24px;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            flex: 1;
            min-width: 200px;
        }
        
.btn-preview {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
        }
        
        .btn-preview:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-download {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: #fff;
        }
        
        .btn-download:hover {
            background: linear-gradient(135deg, #047857 0%, #059669 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        
        .btn-home {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-home:hover {
            background: #e2e8f0;
        }
        
        /* Not Found State */
        .not-found {
            background: #fff;
            border-radius: 16px;
            padding: 48px 24px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .not-found-icon {
            width: 80px;
            height: 80px;
            background: #fef2f2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            color: #dc2626;
        }
        
        .not-found h2 {
            font-size: 1.5rem;
            margin-bottom: 12px;
            color: #1e293b;
        }
        
        .not-found p {
            color: #64748b;
            margin-bottom: 24px;
        }
        
        /* Search Form */
        .search-card {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            margin-top: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .search-card h3 {
            font-size: 1.1rem;
            margin-bottom: 16px;
        }
        
        .search-form {
            display: flex;
            gap: 12px;
        }
        
        .search-input {
            flex: 1;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
        }
        
        .search-btn {
            padding: 14px 24px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        @media (max-width: 640px) {
            .search-form {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php if (!empty($certCode)): ?>
    <!-- Verification Loader -->
    <div class="loader-overlay" id="loader">
        <div class="loader-icon">
            <div class="loader-circle"></div>
            <div class="loader-check" id="loaderCheck">
                <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
        </div>
        <div class="loader-text" id="loaderText">Verifying Certificate...</div>
        <div class="loader-progress">
            <div class="loader-progress-bar" id="progressBar"></div>
        </div>
        <div class="loader-percent" id="progressPercent">0%</div>
    </div>
    <?php endif; ?>
    
    <div class="container">
        <header class="header">
            <a href="/" class="logo">
                <span>KESA</span> Learn
            </a>
        </header>
        
        <?php if ($certificate): ?>
        <!-- Certificate Found - Verified -->
        <div class="verified-banner">
            <div class="verified-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 class="verified-title">Certificate Verified</h1>
            <p class="verified-subtitle">This certificate is authentic and was issued by <?php echo htmlspecialchars($orgName); ?></p>
            <div class="cert-id-badge"><?php echo htmlspecialchars($certificate['certificate_code']); ?></div>
        </div>
        
        <div class="info-grid">
            <!-- Recipient Info -->
            <div class="info-card">
                <div class="card-header">
                    <div class="card-icon green">
                        <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    <h2 class="card-title">Recipient Details</h2>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Name</span>
                    <span class="detail-value"><?php echo htmlspecialchars($certificate['user_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Email</span>
                    <span class="detail-value"><?php echo htmlspecialchars(maskEmail($certificate['user_email'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Certificate ID</span>
                    <span class="detail-value"><?php echo htmlspecialchars($certificate['certificate_code']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Issue Date</span>
                    <span class="detail-value"><?php echo date('F d, Y', strtotime($certificate['generated_at'])); ?></span>
                </div>
            </div>
            
            <!-- Event Info -->
            <div class="info-card">
                <div class="card-header">
                    <div class="card-icon blue">
                        <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h2 class="card-title">Event Details</h2>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Event Name</span>
                    <span class="detail-value"><?php echo htmlspecialchars($certificate['event_title'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Event Type</span>
                    <span class="detail-value"><?php echo ucfirst(htmlspecialchars($certificate['event_type'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Start Date</span>
                    <span class="detail-value"><?php echo $certificate['start_date'] ? date('M d, Y h:i A', strtotime($certificate['start_date'])) : 'N/A'; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">End Date</span>
                    <span class="detail-value"><?php echo $certificate['end_date'] ? date('M d, Y h:i A', strtotime($certificate['end_date'])) : 'N/A'; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Venue</span>
                    <span class="detail-value"><?php echo htmlspecialchars($certificate['venue'] ?? ($certificate['is_online'] ? 'Online' : 'N/A')); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Participants</span>
                    <span class="detail-value highlight"><?php echo $totalParticipants ?: ($certificate['event_participants'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Instructor Name</span>
                    <span class="detail-value"><?php echo htmlspecialchars($instructorName); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Designation</span>
                    <span class="detail-value"><?php echo htmlspecialchars($instructorDesignation); ?></span>
                </div>
            </div>
            
            <?php if ($registration): ?>
            <!-- Registration Info -->
            <div class="info-card">
                <div class="card-header">
                    <div class="card-icon purple">
                        <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <h2 class="card-title">Registration Details</h2>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Registered On</span>
                    <span class="detail-value"><?php echo date('M d, Y h:i A', strtotime($registration['registered_at'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Status</span>
                    <span class="detail-value highlight"><?php echo ucfirst($registration['payment_status']); ?></span>
                </div>
                <?php if (!empty($registration['verified_at'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Verified On</span>
                    <span class="detail-value"><?php echo date('M d, Y h:i A', strtotime($registration['verified_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Payment Info -->
            <div class="info-card">
                <div class="card-header">
                    <div class="card-icon orange">
                        <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <h2 class="card-title">Payment Details</h2>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Event Fee</span>
                    <span class="detail-value"><?php echo $certificate['is_free'] ? 'Free' : ($certificate['currency'] ?? 'INR') . ' ' . number_format($certificate['event_price'] ?? 0, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Paid</span>
                    <span class="detail-value"><?php echo 'INR ' . number_format($registration['amount'] ?? 0, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Method</span>
                    <span class="detail-value"><?php echo ucfirst($registration['payment_method'] ?? 'N/A'); ?></span>
                </div>
                <?php if (!empty($registration['payment_id'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Transaction ID</span>
                    <span class="detail-value"><?php echo htmlspecialchars($registration['payment_id']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="/certificate/view?code=<?php echo urlencode($certificate['certificate_code']); ?>" class="btn btn-preview" target="_blank">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                View Certificate
            </a>
            <a href="/certificate/download?code=<?php echo urlencode($certificate['certificate_code']); ?>" class="btn btn-download">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download Certificate
            </a>
            <a href="/" class="btn btn-home">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Back to Home
            </a>
        </div>
        
        <?php else: ?>
        <!-- Certificate Not Found -->
        <div class="not-found">
            <div class="not-found-icon">
                <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2>Certificate Not Found</h2>
            <p>We could not find a certificate with the code "<?php echo htmlspecialchars($certCode); ?>". Please check the code and try again.</p>
            <a href="/" class="btn btn-home" style="display: inline-flex;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Back to Home
            </a>
        </div>
        
        <div class="search-card">
            <h3>Verify Another Certificate</h3>
            <form class="search-form" action="/certificate/verify" method="GET">
                <input type="text" name="code" class="search-input" placeholder="Enter certificate code..." required>
                <button type="submit" class="search-btn">Verify</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($certCode)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const loader = document.getElementById('loader');
        const progressBar = document.getElementById('progressBar');
        const progressPercent = document.getElementById('progressPercent');
        const loaderText = document.getElementById('loaderText');
        const loaderCheck = document.getElementById('loaderCheck');
        const loaderCircle = document.querySelector('.loader-circle');
        
        let progress = 0;
        const duration = 1500; // 1.5 seconds
        const interval = 20; // Update every 20ms
        const step = 100 / (duration / interval);
        
        const timer = setInterval(() => {
            progress += step;
            if (progress >= 100) {
                progress = 100;
                clearInterval(timer);
                
                // Show checkmark
                loaderCircle.style.display = 'none';
                loaderCheck.classList.add('show');
                loaderText.textContent = '<?php echo $certificate ? "Verified!" : "Not Found"; ?>';
                
                // Hide loader after short delay
                setTimeout(() => {
                    loader.classList.add('hide');
                }, 500);
            }
            
            progressBar.style.width = progress + '%';
            progressPercent.textContent = Math.round(progress) + '%';
        }, interval);
    });
    </script>
    <?php endif; ?>
</body>
</html>
