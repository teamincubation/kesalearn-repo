<?php
/**
 * KESA Learn - User Dashboard
 * Modern mobile-first professional app experience
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db = getDB();
$userId = $_SESSION['user_id'];
$user = getCurrentUser();

// Determine whether to show WhatsApp collection modal.
// phone column IS the WhatsApp number — if phone is set, the user has already
// provided their number. whatsapp_collected = 1 is the authoritative flag, but
// phone being present is an equally valid indicator (covers pre-migration rows).
$showWhatsAppModal = false;
$waCollected = false;
if (!empty($user['phone'])) {
    // User already has a phone/WA number stored — never show the modal
    $waCollected = true;
} elseif (array_key_exists('whatsapp_collected', $user)) {
    $waCollected = (bool)$user['whatsapp_collected'];
}
if (!$waCollected || isset($_GET['collect_whatsapp'])) {
    $showWhatsAppModal = true;
}

// Handle WhatsApp update (AJAX POST from the modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_whatsapp'])) {
    $isAjax = !empty($_POST['ajax']);
    if ($isAjax) { header('Content-Type: application/json'); }

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
        if ($isAjax) { echo json_encode(['success' => false, 'error' => $error]); exit; }
        $whatsappError = $error;
    } else {
        $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
        if (empty($whatsapp) || strlen($whatsapp) < 10) {
            $error = 'Please enter a valid WhatsApp number (at least 10 digits).';
            if ($isAjax) { echo json_encode(['success' => false, 'error' => $error]); exit; }
            $whatsappError = $error;
        } else {
            try {
                // phone IS the WhatsApp number — single source of truth
                $normalized = substr($whatsapp, -10);
                $db->prepare("UPDATE users SET phone = ?, mobile_number = ?, mobile_verified_at = NOW(), whatsapp_collected = 1, updated_at = NOW() WHERE id = ?")
                   ->execute([$normalized, $normalized, $userId]);
                $user['phone']             = $normalized;
                $user['mobile_number']     = $normalized;
                $user['mobile_verified_at'] = date('Y-m-d H:i:s');
                $user['whatsapp_collected'] = 1;
                logActivity('whatsapp_updated', 'User saved WhatsApp number via dashboard');
                if ($isAjax) { echo json_encode(['success' => true, 'message' => 'WhatsApp number saved successfully']); exit; }
                // Non-AJAX: redirect to clear POST and prevent re-show
                redirect('/user/dashboard');
            } catch (Exception $e) {
                $error = 'Error saving WhatsApp number. Please try again.';
                if ($isAjax) { echo json_encode(['success' => false, 'error' => $error]); exit; }
                $whatsappError = $error;
            }
        }
    }
}

// Handle certificate name submission
$certNameSubmitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_certificate_name'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid security token.');
        redirect('/user/dashboard');
    }
    $certName = trim($_POST['certificate_name'] ?? '');
    if (empty($certName) || mb_strlen($certName) < 3) {
        setFlash('error', 'Please enter a valid full name (at least 3 characters).');
        redirect('/user/dashboard');
    }
    try {
        $db->prepare("UPDATE users SET certificate_name = ?, name = ?, certificate_name_verified_at = NOW() WHERE id = ?")
           ->execute([sanitize($certName), sanitize($certName), $userId]);
        $user['certificate_name'] = $certName;
        $user['name'] = $certName;
        $_SESSION['user_name'] = $certName;
        logActivity('certificate_name_set', 'Certificate name verified and set to: ' . $certName);
        setFlash('success', 'Your certificate name has been saved successfully!');
    } catch (Exception $e) {
        setFlash('error', 'Error saving name. Please try again.');
    }
    redirect('/user/dashboard');
}

// Stats
$totalRegs = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
$totalRegs->execute([$userId]);
$totalRegistrations = $totalRegs->fetchColumn();

$upcomingRegs = $db->prepare("SELECT COUNT(*) FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = ? AND e.start_date > NOW()");
$upcomingRegs->execute([$userId]);
$upcomingCount = $upcomingRegs->fetchColumn();

$totalCerts = $db->prepare("SELECT COUNT(*) FROM certificates WHERE user_id = ?");
$totalCerts->execute([$userId]);
$certificateCount = $totalCerts->fetchColumn();

$pastCount = $db->prepare("SELECT COUNT(*) FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = ? AND e.start_date <= NOW() AND r.payment_status = 'paid'");
$pastCount->execute([$userId]);
$pastEventCount = $pastCount->fetchColumn();

// Recent registrations with more details
$recentRegs = $db->prepare("SELECT r.*, e.title, e.slug, e.start_date, e.end_date, e.type, e.banner_image, e.is_online, e.venue FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = ? ORDER BY r.registered_at DESC");
$recentRegs->execute([$userId]);
$recentRegistrations = $recentRegs->fetchAll();

// Upcoming events (next 3)
$upcomingEvents = $db->prepare("SELECT r.*, e.title, e.slug, e.start_date, e.end_date, e.type, e.banner_image, e.is_online, e.meeting_link, e.venue, e.status as event_status FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.user_id = ? AND e.start_date > NOW() AND (r.payment_status = 'paid' OR r.payment_status = 'verified') ORDER BY e.start_date ASC LIMIT 3");
$upcomingEvents->execute([$userId]);
$upcomingEventsList = $upcomingEvents->fetchAll();

// Live Sessions for user's registered events (ONLY currently live sessions)
$liveSessions = [];
try {
    $liveSessionsStmt = $db->prepare("
        SELECT ls.*, e.title as event_title, e.slug as event_slug, i.name as instructor_name, i.photo as instructor_photo
        FROM live_sessions ls
        JOIN events e ON ls.event_id = e.id
        JOIN registrations r ON r.event_id = ls.event_id AND r.user_id = ?
        LEFT JOIN instructors i ON ls.instructor_id = i.id
        WHERE (r.payment_status = 'paid' OR r.payment_status = 'verified')
        AND ls.status = 'live'
        AND ls.start_datetime <= NOW()
        AND (ls.end_datetime IS NULL OR ls.end_datetime >= NOW())
        ORDER BY ls.start_datetime DESC
        LIMIT 10
    ");
    $liveSessionsStmt->execute([$userId]);
    $liveSessions = $liveSessionsStmt->fetchAll();
} catch (PDOException $e) {
    $liveSessions = [];
}

// Check if event_ratings table exists
$ratingsTableExists = false;
try {
    $db->query("SELECT 1 FROM event_ratings LIMIT 1");
    $ratingsTableExists = true;
} catch (PDOException $e) {
    $ratingsTableExists = false;
}

// Get all registered events with their status for comprehensive display
$allEvents = [];
try {
    if ($ratingsTableExists) {
        $allEventsStmt = $db->prepare("
            SELECT r.*, e.title, e.slug, e.start_date, e.end_date, e.type, e.banner_image, e.is_online, e.meeting_link, e.venue, e.status as event_status,
                   er.rating, er.feedback, er.did_not_participate, er.id as rating_id
            FROM registrations r 
            JOIN events e ON r.event_id = e.id 
            LEFT JOIN event_ratings er ON er.registration_id = r.id
            WHERE r.user_id = ?
            ORDER BY e.start_date DESC
        ");
    } else {
        $allEventsStmt = $db->prepare("
            SELECT r.*, e.title, e.slug, e.start_date, e.end_date, e.type, e.banner_image, e.is_online, e.meeting_link, e.venue, e.status as event_status,
                   NULL as rating, NULL as feedback, NULL as did_not_participate, NULL as rating_id
            FROM registrations r 
            JOIN events e ON r.event_id = e.id 
            WHERE r.user_id = ?
            ORDER BY e.start_date DESC
        ");
    }
    $allEventsStmt->execute([$userId]);
    $allEvents = $allEventsStmt->fetchAll();
} catch (PDOException $e) {
    $allEvents = [];
}

// Helper function to determine event live status (IST-aware)
function getEventLiveStatus($event) {
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $startTime = new DateTime($event['start_date'], $tz);
    $endTime = new DateTime($event['end_date'], $tz);
    
    if (isset($event['event_status']) && $event['event_status'] === 'completed') {
        return 'completed';
    }
    if ($now >= $startTime && $now <= $endTime) {
        return 'live';
    }
    if ($now > $endTime) {
        return 'completed';
    }
    return 'upcoming';
}

// Get completed events needing feedback
$completedEventsForFeedback = [];
foreach ($allEvents as $event) {
    $status = getEventLiveStatus($event);
    if ($status === 'completed' && empty($event['rating_id'])) {
        $completedEventsForFeedback[] = $event;
    }
}

// Calculate profile completion - 6 core fields only (removed country, state, city)
$completionFields = ['name', 'email', 'phone', 'dob', 'gender', 'profile_image'];
$filledCount = 0;
foreach ($completionFields as $field) {
    if (!empty($user[$field])) $filledCount++;
}
$completionPercent = round(($filledCount / count($completionFields)) * 100);

// Get greeting based on IST time
$istNow = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$hour = (int) $istNow->format('H');
if ($hour < 12) $greeting = 'Good morning';
elseif ($hour < 17) $greeting = 'Good afternoon';
else $greeting = 'Good evening';

$pageTitle = 'My Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Dashboard Modern Styles */
.dashboard-modern {
    padding: 90px 0 60px;
    background: var(--bg-secondary);
    min-height: 100vh;
}

@media (max-width: 768px) {
    .dashboard-modern {
        padding-bottom: 100px;
    }
}

.dashboard-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 0 16px;
}

/* Certificate Name Card */
.cert-name-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    padding: 20px;
    margin-bottom: 20px;
    border-left: 4px solid #0ea5e9;
    box-shadow: var(--shadow-sm);
    display: flex;
    gap: 16px;
    align-items: flex-start;
}
.cert-name-icon {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
}
.cert-name-icon svg { width: 22px; height: 22px; }
.cert-name-content { flex: 1; min-width: 0; }
.cert-name-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.cert-name-header h3 {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}
.cert-hint-wrapper { position: relative; display: inline-flex; align-items: center; }
.cert-hint-icon {
    width: 18px;
    height: 18px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 50%;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: help;
    flex-shrink: 0;
}
.cert-hint-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: rgba(26,26,46,0.95);
    color: #fff;
    font-size: 0.75rem;
    padding: 8px 12px;
    border-radius: 8px;
    white-space: nowrap;
    z-index: 100;
    pointer-events: none;
    max-width: 260px;
    white-space: normal;
    text-align: center;
    line-height: 1.4;
}
.cert-hint-wrapper:hover .cert-hint-tooltip { display: block; }
.cert-name-content p {
    font-size: 0.82rem;
    color: var(--text-muted);
    margin: 0 0 12px;
    line-height: 1.5;
}
.cert-name-form { width: 100%; }
.cert-name-input-row {
    display: flex;
    gap: 8px;
    align-items: center;
}
.cert-name-input {
    flex: 1;
    padding: 10px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.9rem;
    background: var(--bg-secondary);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.2s;
}
.cert-name-input:focus { border-color: #0ea5e9; background: var(--bg-primary); }
.cert-name-btn {
    padding: 10px 18px;
    background: #0ea5e9;
    color: #fff;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.2s;
}
.cert-name-btn:hover { background: #0284c7; }
@media (max-width: 480px) {
    .cert-name-card { flex-direction: column; gap: 12px; }
    .cert-name-input-row { flex-direction: column; }
    .cert-name-btn { width: 100%; }
}

/* Welcome Header */
.welcome-card {
    background: linear-gradient(135deg, var(--blue) 0%, var(--purple) 100%);
    border-radius: var(--radius-xl);
    padding: 28px 24px;
    margin-bottom: 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
}

.welcome-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}

.welcome-card::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: 50%;
    width: 150px;
    height: 150px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}

.welcome-content {
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 1;
}

.welcome-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    border: 3px solid rgba(255,255,255,0.5);
    overflow: hidden;
    flex-shrink: 0;
}

.welcome-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.welcome-text h1 {
    color: #fff;
    font-size: 1.35rem;
    margin-bottom: 4px;
}

.welcome-text p {
    opacity: 0.9;
    font-size: 0.9rem;
    margin: 0;
}



/* Profile Completion Card */
.completion-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
}

.completion-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.completion-title {
    font-size: 0.95rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}

.completion-title svg {
    width: 18px;
    height: 18px;
    color: var(--blue);
}

.completion-lottie {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    min-width: 24px;
}

.completion-benefit-message {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 12px;
    padding: 8px 0;
    font-size: 0.8rem;
    color: var(--text-muted);
    line-height: 1.4;
    width: 100%;
}

.completion-benefit-message span {
    color: #6b7280;
    font-weight: 500;
}

.completion-ring {
    width: 64px;
    height: 64px;
    position: relative;
}

.completion-ring svg {
    transform: rotate(-90deg);
}

.ring-bg {
    fill: none;
    stroke: var(--bg-tertiary);
    stroke-width: 6;
}

.ring-fill {
    fill: none;
    stroke-width: 6;
    stroke-linecap: round;
    transition: stroke-dasharray 0.5s ease;
}

.ring-fill.low { stroke: var(--red); }
.ring-fill.medium { stroke: var(--yellow); }
.ring-fill.high { stroke: #0d7a42; }

.ring-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.9rem;
    font-weight: 800;
}

.completion-bar {
    height: 6px;
    background: var(--bg-tertiary);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 12px;
}

.completion-bar-fill {
    height: 100%;
    border-radius: 3px;
}

.completion-bar-fill.low { background: var(--red); }
.completion-bar-fill.medium { background: var(--yellow); }
.completion-bar-fill.high { background: #0d7a42; }

.completion-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.completion-chip {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    border-radius: 16px;
    font-size: 0.75rem;
    font-weight: 500;
    background: var(--bg-tertiary);
    color: var(--text-muted);
}

.completion-chip.done {
    background: #e6f9f0;
    color: #0d7a42;
}

.completion-chip svg {
    width: 12px;
    height: 12px;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

@media (min-width: 640px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }
    
    .completion-benefit-message {
        font-size: 0.85rem;
    }
}

@media (max-width: 639px) {
    .completion-top {
        flex-direction: row;
        align-items: flex-start;
        margin-bottom: 12px;
    }
    
    .completion-title {
        flex: 1;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .completion-ring {
        margin-left: auto;
        margin-top: -6px;
    }
    
    .completion-benefit-message {
        display: block;
        width: 100%;
        margin-top: 12px;
        font-size: 0.75rem;
        line-height: 1.5;
    }
}

.stat-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 20px;
    box-shadow: var(--shadow-sm);
    text-align: center;
    transition: var(--transition);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
}

.stat-icon svg {
    width: 24px;
    height: 24px;
    color: #fff;
}

.stat-icon.red { background: var(--red); }
.stat-icon.purple { background: var(--purple); }
.stat-icon.blue { background: var(--blue); }
.stat-icon.yellow { background: var(--yellow); }

.stat-value {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-muted);
    font-weight: 500;
}

/* Quick Actions */
@media (max-width: 768px) {
    .mobile-hidden {
        display: none !important;
    }
}

@media (min-width: 769px) {
    .desktop-hidden {
        display: none !important;
    }
}

.quick-actions {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 4px;
    margin-bottom: 24px;
    -webkit-overflow-scrolling: touch;
}

.quick-actions::-webkit-scrollbar {
    display: none;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 18px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-primary);
    white-space: nowrap;
    transition: var(--transition);
    text-decoration: none;
}

.quick-action-btn:hover {
    background: var(--blue);
    color: #fff;
    border-color: var(--blue);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.quick-action-btn svg {
    width: 18px;
    height: 18px;
}

/* Section Cards */
.section-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    margin-bottom: 20px;
    overflow: hidden;
}

.section-card-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.section-card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.05rem;
    font-weight: 700;
}

.section-card-title svg {
    width: 20px;
    height: 20px;
    color: var(--blue);
}

.section-card-action {
    font-size: 0.85rem;
    color: var(--blue);
    font-weight: 600;
}

/* Upcoming Events List */
.upcoming-list {
    padding: 0;
}

.upcoming-item {
    display: flex;
    gap: 16px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
    transition: var(--transition);
}

.upcoming-item:last-child {
    border-bottom: none;
}

.upcoming-item:hover {
    background: var(--bg-secondary);
}

.upcoming-date {
    width: 56px;
    text-align: center;
    flex-shrink: 0;
}

.upcoming-date-day {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--blue);
    line-height: 1;
}

.upcoming-date-month {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    font-weight: 600;
}

.upcoming-info {
    flex: 1;
    min-width: 0;
}

.upcoming-title {
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--text-primary);
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.upcoming-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.8rem;
    color: var(--text-muted);
}

.upcoming-meta svg {
    width: 14px;
    height: 14px;
}

.upcoming-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

/* My Events */

/* Live Sessions */
.live-sessions-card {
    border: 2px solid transparent;
    background: linear-gradient(var(--bg-primary), var(--bg-primary)) padding-box,
                linear-gradient(135deg, var(--blue), var(--purple)) border-box;
}

.live-sessions-list {
    padding: 0;
}

.live-session-item {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
}

.live-session-item:last-child {
    border-bottom: none;
}

.live-session-item.is-live {
    background: linear-gradient(135deg, rgba(220, 38, 38, 0.05), rgba(220, 38, 38, 0.02));
}

.session-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.live-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: #dc2626;
    color: white;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    animation: livePulse 1.5s infinite;
}

@keyframes livePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.recording-badge {
    padding: 4px 10px;
    background: var(--green-light);
    color: var(--green);
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
}

.scheduled-badge {
    padding: 4px 10px;
    background: var(--blue-light);
    color: var(--blue);
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
}

.session-platform {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.session-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--text-primary);
}

.session-event {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin: 0 0 8px;
}

.session-instructor {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 12px;
}

.session-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-session {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    text-decoration: none;
}

.btn-success {
    background: #16a34a;
    color: white;
}

.btn-success:hover {
    background: #15803d;
}

.session-countdown {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.8rem;
    color: var(--text-muted);
}

.recording-expiry {
    font-size: 0.75rem;
    color: var(--text-muted);
}

/* My Events List */
.my-events-list {
    padding: 0;
}

.my-event-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
}

.my-event-card:last-child {
    border-bottom: none;
}

.my-event-card:hover {
    background: var(--bg-secondary);
}

.my-event-card:active {
    background: var(--bg-tertiary);
}

.my-event-thumb-wrapper {
    position: relative;
    flex-shrink: 0;
}

.my-event-thumb {
    width: 72px;
    height: 72px;
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}

.my-event-thumb.has-image {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.my-event-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.my-event-thumb.pastel-thumb {
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}

.pastel-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.85;
}

/* Status Chip - positioned outside and below the thumbnail */
.event-status-chip {
    position: absolute;
    bottom: -8px;
    left: 50%;
    transform: translateX(-50%);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-radius: 20px;
    white-space: nowrap;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.event-status-chip.status-upcoming {
    background: linear-gradient(135deg, #4950ba, #6366f1);
    color: white;
}

.event-status-chip.status-active {
    background: linear-gradient(135deg, #16a34a, #22c55e);
    color: white;
    animation: liveGlow 1.5s ease-in-out infinite;
}

@keyframes liveGlow {
    0%, 100% { box-shadow: 0 2px 6px rgba(22, 163, 74, 0.4); }
    50% { box-shadow: 0 2px 12px rgba(22, 163, 74, 0.7); }
}

.event-status-chip.status-completed {
    background: linear-gradient(135deg, #6b7280, #9ca3af);
    color: white;
}

.event-status-chip.status-pending {
    background: linear-gradient(135deg, #d97706, #fbbf24);
    color: white;
}

.event-status-chip.status-failed {
    background: linear-gradient(135deg, #dc2626, #f87171);
    color: white;
}

.my-event-content {
    flex: 1;
    min-width: 0;
    padding-top: 4px;
}

.my-event-title {
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--text-primary);
    margin: 0 0 8px;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.my-event-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 0.8rem;
    color: var(--text-muted);
}

.my-event-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.my-event-meta svg {
    width: 14px;
    height: 14px;
}

.my-event-arrow {
    color: var(--text-muted);
    flex-shrink: 0;
}

.recent-list {
    padding: 0;
}

.recent-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-light);
    transition: var(--transition);
}

.recent-item:last-child {
    border-bottom: none;
}

.recent-item:hover {
    background: var(--bg-secondary);
}

.recent-thumb {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    background: var(--bg-tertiary);
    overflow: hidden;
    flex-shrink: 0;
}

.recent-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.recent-info {
    flex: 1;
    min-width: 0;
}

.recent-title {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-primary);
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.recent-subtitle {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.recent-status {
    flex-shrink: 0;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 40px 20px;
}

.empty-state-icon {
    width: 64px;
    height: 64px;
    background: var(--bg-tertiary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

.empty-state-icon svg {
    width: 32px;
    height: 32px;
    color: var(--text-muted);
}

.empty-state-modern h3 {
    font-size: 1.1rem;
    margin-bottom: 8px;
}

.empty-state-modern p {
    color: var(--text-muted);
    font-size: 0.9rem;
    margin-bottom: 16px;
}

/* Live/Ongoing Event Badge */
.live-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    border-radius: 20px;
    animation: pulse-live 2s infinite;
}

.live-badge::before {
    content: '';
    width: 8px;
    height: 8px;
    background: #fff;
    border-radius: 50%;
    animation: blink 1s infinite;
}

@keyframes pulse-live {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
}

@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

.status-badge.upcoming {
    background: var(--blue-light);
    color: var(--blue);
}

.status-badge.completed {
    background: #e6f9f0;
    color: #0d7a42;
}

.btn-join-now {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
    color: #fff;
    font-weight: 600;
    font-size: 0.9rem;
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
}

.btn-join-now:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
    color: #fff;
}

/* Event Card with Status */
.event-status-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 16px;
    transition: all 0.3s ease;
}

.event-status-card:hover {
    box-shadow: var(--shadow-md);
}

.event-status-card.live-event {
    border: 2px solid #ef4444;
}

.event-status-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    gap: 12px;
    flex-wrap: wrap;
}

.event-status-info {
    flex: 1;
    min-width: 200px;
}

.event-status-title {
    font-weight: 700;
    font-size: 1rem;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.event-status-meta {
    display: flex;
    gap: 12px;
    font-size: 0.85rem;
    color: var(--text-muted);
    flex-wrap: wrap;
}

.event-status-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* Feedback Section */
.feedback-section {
    padding: 20px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-light);
}

.feedback-header {
    margin-bottom: 16px;
}

.feedback-header h4 {
    font-size: 0.95rem;
    font-weight: 600;
    margin-bottom: 4px;
}

.feedback-header p {
    font-size: 0.85rem;
    color: var(--text-muted);
}

.star-rating {
    display: flex;
    gap: 4px;
    margin-bottom: 16px;
}

.star-rating input {
    display: none;
}

.star-rating label {
    cursor: pointer;
    font-size: 28px;
    color: #ddd;
    transition: all 0.2s ease;
}

.star-rating label:hover,
.star-rating label:hover ~ label,
.star-rating input:checked ~ label {
    color: #fbbf24;
}

.star-rating:hover label {
    color: #ddd;
}

.star-rating label:hover,
.star-rating label:hover ~ label {
    color: #fbbf24;
}

.feedback-form textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.9rem;
    resize: vertical;
    min-height: 80px;
    margin-bottom: 12px;
}

.feedback-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.feedback-submitted {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    background: #e6f9f0;
    border-radius: var(--radius-md);
    color: #0d7a42;
    font-size: 0.9rem;
}

.did-not-participate-btn {
    padding: 8px 14px;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s ease;
}

.did-not-participate-btn:hover {
    background: #fef2f2;
    border-color: var(--red);
    color: var(--red);
}

/* Mobile Optimizations */
@media (max-width: 480px) {
    .welcome-content {
        flex-direction: column;
        text-align: center;
    }
    
    .welcome-text h1 {
        font-size: 1.2rem;
    }
    
    .stat-card {
        padding: 16px 12px;
    }
    
    .stat-value {
        font-size: 1.5rem;
    }
    
    .stat-label {
        font-size: 0.75rem;
    }
}
</style>

<div class="dashboard-modern animate-fade-in">
    <div class="dashboard-container">
        <!-- Welcome Card -->
        <div class="welcome-card animate-slide-up" style="animation-delay: 0.05s;">
            <div class="welcome-content">
                <div class="welcome-avatar">
                    <?php if ($user['profile_image']): ?>
                        <img src="/uploads/<?php echo sanitize($user['profile_image']); ?>" alt="<?php echo sanitize($user['name']); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>
                <div class="welcome-text">
                <div>
                    <h1><?php echo $greeting; ?>, <?php echo sanitize(explode(' ', $user['name'])[0]); ?></h1>
                </div>
                    <p>Welcome back to your learning journey</p>
                </div>
            </div>
        </div>
        
        <!-- Profile Completion Card -->
        <?php if ($completionPercent < 100): ?>
        <div class="completion-card animate-slide-up" style="animation-delay: 0.1s;">
            <div class="completion-top">
                <div class="completion-title">
                    <div id="completionLottie" class="completion-lottie"></div>
                    Profile Completion
                </div>
                <div class="completion-ring">
                    <svg width="64" height="64" viewBox="0 0 64 64">
                        <circle class="ring-bg" cx="32" cy="32" r="28"></circle>
                        <circle class="ring-fill <?php echo $completionPercent < 40 ? 'low' : ($completionPercent < 70 ? 'medium' : 'high'); ?>" 
                                cx="32" cy="32" r="28" 
                                stroke-dasharray="<?php echo ($completionPercent / 100) * 175.9; ?>, 175.9"></circle>
                    </svg>
                    <span class="ring-text"><?php echo $completionPercent; ?>%</span>
                </div>
            </div>
            
            <div class="completion-benefit-message">
                <span>Complete your profile for a better experience.</span>
            </div>
            
            <div class="completion-bar">
                <div class="completion-bar-fill <?php echo $completionPercent < 40 ? 'low' : ($completionPercent < 70 ? 'medium' : 'high'); ?>" style="width: <?php echo $completionPercent; ?>%;"></div>
            </div>
            
            <div class="completion-chips">
                <?php 
                $completionItems = [
                    ['label' => 'Name', 'filled' => !empty($user['name'])],
                    ['label' => 'Email', 'filled' => !empty($user['email'])],
                    ['label' => 'Phone', 'filled' => !empty($user['phone'])],
                    ['label' => 'DOB', 'filled' => !empty($user['dob'])],
                    ['label' => 'Gender', 'filled' => !empty($user['gender'])],
                    ['label' => 'Photo', 'filled' => !empty($user['profile_image'])],
                ];
                foreach ($completionItems as $item): 
                ?>
                    <span class="completion-chip <?php echo $item['filled'] ? 'done' : ''; ?>">
                        <?php if ($item['filled']): ?>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <?php else: ?>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>
                        <?php endif; ?>
                        <?php echo $item['label']; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Certificate Name Verification Card (shown only if not yet set) -->
        <?php if (empty($user['certificate_name'])): ?>
        <div class="cert-name-card animate-slide-up" style="animation-delay: 0.15s;">
            <div class="cert-name-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            </div>
            <div class="cert-name-content">
                <div class="cert-name-header">
                    <h3>Set Your Certificate Name</h3>
                    <span class="cert-hint-wrapper">
                        <span class="cert-hint-icon" title="Certificates will reflect this name. Contact support for future edits.">?</span>
                        <span class="cert-hint-tooltip">Certificates will reflect this name. Contact support for future edits.</span>
                    </span>
                </div>
                <p>Enter your full name exactly as you want it to appear on your certificates.</p>
                <form method="POST" action="/user/dashboard" class="cert-name-form" id="certNameForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="save_certificate_name" value="1">
                    <div class="cert-name-input-row">
                        <input type="text" name="certificate_name" class="cert-name-input" placeholder="Enter your full name" value="<?php echo sanitize($user['name'] ?? ''); ?>" required minlength="3" maxlength="120">
                        <button type="submit" class="cert-name-btn">Save Name</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats Grid (Registrations & Certificates only) -->
        <div class="stats-grid animate-slide-up" style="animation-delay: 0.2s;">
            <div class="stat-card">
                <div class="stat-icon purple">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <div class="stat-value"><?php echo $totalRegistrations; ?></div>
                <div class="stat-label">Registrations</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                </div>
                <div class="stat-value"><?php echo $certificateCount; ?></div>
                <div class="stat-label">Certificates</div>
            </div>
        </div>
        
        <!-- Quick Actions (desktop only - mobile uses bottom nav) -->
        <div class="quick-actions mobile-hidden">
            <a href="/user/certificates" class="quick-action-btn">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                Certificates
            </a>
            <a href="/user/profile.php" class="quick-action-btn">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Profile
            </a>
            <a href="/user/feedback" class="quick-action-btn">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                Feedback
            </a>
        </div>
        
        <!-- My Events with Status -->
        <?php if (!empty($allEvents)): ?>
        <div class="section-card mobile-hidden" style="margin-bottom: 24px;">
            <div class="section-card-header">
                <div class="section-card-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    My Events
                </div>
            </div>
            
            <div style="padding: 16px;">
                <?php foreach ($allEvents as $event): 
                    $eventStatus = getEventLiveStatus($event);
                    $eventDate = new DateTime($event['start_date']);
                    $eventEndDate = new DateTime($event['end_date']);
                    $isLive = ($eventStatus === 'live');
                ?>
                <div class="event-status-card <?php echo $isLive ? 'live-event' : ''; ?>">
                    <div class="event-status-header">
                        <div class="event-status-info">
                            <div class="event-status-title"><?php echo sanitize($event['title']); ?></div>
                            <div class="event-status-meta">
                                <span><?php echo $eventDate->format('M d, Y'); ?></span>
                                <span><?php echo $eventDate->format('h:i A'); ?> - <?php echo $eventEndDate->format('h:i A'); ?></span>
                                <span><?php echo $event['is_online'] ? 'Online' : sanitize($event['venue']); ?></span>
                            </div>
                        </div>
                        <div class="event-status-actions">
                            <?php 
                            $isPaidOrVerified = in_array($event['payment_status'], ['paid', 'verified']);
                            if (!$isPaidOrVerified): 
                                if (in_array($event['payment_status'], ['failed', 'rejected'])): ?>
                                    <span class="badge status-badge failed" style="background: #fef2f2; color: #ef4444; border: 1px solid #fecaca;">Payment Failed</span>
                                    <a href="/user/event-details?event_id=<?php echo $event['event_id']; ?>" class="quick-action-btn" style="padding: 6px 12px; font-size: 0.8rem; height: 32px; display: inline-flex; align-items: center; text-decoration: none;">Retry Payment</a>
                                <?php else: ?>
                                    <span class="badge status-badge pending" style="background: #fffbeb; color: #b45309; border: 1px solid #fcd34d;">Pending Payment</span>
                                    <a href="/user/event-details?event_id=<?php echo $event['event_id']; ?>" class="quick-action-btn" style="padding: 6px 12px; font-size: 0.8rem; height: 32px; display: inline-flex; align-items: center; text-decoration: none;">Complete Payment</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($eventStatus === 'live'): ?>
                                    <span class="live-badge">Live Now</span>
                                    <?php if ($event['is_online'] && !empty($event['meeting_link'])): ?>
                                        <a href="<?php echo sanitize($event['meeting_link']); ?>" target="_blank" class="btn-join-now">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                            Join Now
                                        </a>
                                    <?php elseif (!$event['is_online']): ?>
                                        <span class="live-badge" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                                            Session Ongoing
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($eventStatus === 'upcoming'): ?>
                                    <span class="badge status-badge upcoming">Upcoming</span>
                                <?php else: ?>
                                    <span class="badge status-badge completed">Completed</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($eventStatus === 'completed' && $isPaidOrVerified): ?>
                    <!-- Feedback Section for Completed Events -->
                    <div class="feedback-section">
                        <?php if (!empty($event['rating_id'])): ?>
                            <!-- Already Submitted -->
                            <?php if ($event['did_not_participate']): ?>
                                <div class="feedback-submitted" style="background: #fef2f2; color: var(--red);">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    Marked as: Did Not Participate
                                </div>
                            <?php else: ?>
                                <div class="feedback-submitted">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Feedback Submitted - Thank you!
                                    <span style="margin-left: 8px;">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <span style="color: <?php echo $i <= $event['rating'] ? '#fbbf24' : '#ddd'; ?>;">&#9733;</span>
                                        <?php endfor; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Feedback Form -->
                            <div class="feedback-header">
                                <h4>How was your experience?</h4>
                                <p>Your feedback helps us improve future events</p>
                            </div>
                            <form action="/user/submit-feedback" method="POST" class="feedback-form">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="registration_id" value="<?php echo $event['id']; ?>">
                                <input type="hidden" name="event_id" value="<?php echo $event['event_id']; ?>">
                                
                                <div class="star-rating" style="flex-direction: row-reverse; justify-content: flex-end;">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <input type="radio" name="rating" id="star<?php echo $event['id']; ?>-<?php echo $i; ?>" value="<?php echo $i; ?>" required>
                                        <label for="star<?php echo $event['id']; ?>-<?php echo $i; ?>">&#9733;</label>
                                    <?php endfor; ?>
                                </div>
                                
                                <textarea name="feedback" placeholder="Share your thoughts about this event (optional)..."></textarea>
                                
                                <div class="feedback-actions">
                                    <button type="submit" class="btn btn-primary">Submit Feedback</button>
                                    <button type="submit" name="did_not_participate" value="1" class="did-not-participate-btn">I Did Not Participate</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Two Column Layout for larger screens -->
        <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
            <?php if (!empty($upcomingEventsList)): ?>
            <!-- Upcoming Events -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Upcoming Events
                    </div>
                    <a href="/user/registrations" class="section-card-action">View All</a>
                </div>
                <div class="upcoming-list">
                    <?php foreach ($upcomingEventsList as $event): 
                        $eventDate = new DateTime($event['start_date']);
                    ?>
                                <a href="/events/detail?id=<?php echo $event['id']; ?>" class="upcoming-item">
                        <div class="upcoming-date">
                            <div class="upcoming-date-day"><?php echo $eventDate->format('d'); ?></div>
                            <div class="upcoming-date-month"><?php echo $eventDate->format('M'); ?></div>
                        </div>
                        <div class="upcoming-info">
                            <div class="upcoming-title"><?php echo sanitize($event['title']); ?></div>
                            <div class="upcoming-meta">
                                <span style="display: flex; align-items: center; gap: 4px;">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <?php echo $eventDate->format('h:i A'); ?>
                                </span>
                                <span style="display: flex; align-items: center; gap: 4px;">
                                    <?php if ($event['is_online']): ?>
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        Online
                                    <?php else: ?>
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                                        In-Person
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <span class="upcoming-badge <?php echo $event['type'] === 'workshop' ? 'badge-purple' : ($event['type'] === 'webinar' ? 'badge-blue' : 'badge-red'); ?>"><?php echo ucfirst($event['type']); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Live Sessions -->
            <?php if (!empty($liveSessions)): ?>
            <div class="section-card live-sessions-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        Live Sessions
                    </div>
                </div>
                <div class="live-sessions-list">
                    <?php foreach ($liveSessions as $session): 
                        $sessionStart = new DateTime($session['start_datetime']);
                        $sessionEnd = new DateTime($session['end_datetime']);
                        $now = new DateTime();
                        $isLive = $session['status'] === 'live';
                        $isCompleted = $session['status'] === 'completed';
                        $hasRecording = !empty($session['recording_url']);
                    ?>
                    <div class="live-session-item <?php echo $isLive ? 'is-live' : ($isCompleted ? 'is-completed' : ''); ?>">
                        <div class="session-header">
                            <?php if ($isLive): ?>
                            <span class="live-badge">LIVE NOW</span>
                            <?php elseif ($hasRecording): ?>
                            <span class="recording-badge">Recording Available</span>
                            <?php else: ?>
                            <span class="scheduled-badge"><?php echo $sessionStart->format('M d, h:i A'); ?></span>
                            <?php endif; ?>
                            <span class="session-platform"><?php echo ucfirst(str_replace('_', ' ', $session['platform'])); ?></span>
                        </div>
                        <h4 class="session-title"><?php echo sanitize($session['title']); ?></h4>
                        <p class="session-event"><?php echo sanitize(truncateText($session['event_title'], 40)); ?></p>
                        <?php if (!empty($session['instructor_name'])): ?>
                        <div class="session-instructor">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <?php echo sanitize($session['instructor_name']); ?>
                        </div>
                        <?php endif; ?>
                        <div class="session-actions">
                            <?php if ($isLive && !empty($session['meeting_link'])): ?>
                            <a href="<?php echo sanitize($session['meeting_link']); ?>" target="_blank" class="btn btn-success btn-session">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Join Now
                            </a>
                            <?php elseif ($hasRecording): ?>
                            <a href="<?php echo sanitize($session['recording_url']); ?>" target="_blank" class="btn btn-primary btn-session">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Watch Recording
                            </a>
                            <?php if (!empty($session['recording_expiry_date'])): ?>
                            <span class="recording-expiry">Access until <?php echo date('M d', strtotime($session['recording_expiry_date'])); ?></span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="session-countdown">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?php echo $sessionStart->format('M d, Y \a\t h:i A'); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- My Events -->
            <div class="section-card desktop-hidden">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        My Events
                    </div>
                </div>
                
                <?php if (empty($recentRegistrations)): ?>
                <div class="empty-state-modern">
                    <div class="empty-state-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <h3>No registrations yet</h3>
                    <p>Start your learning journey by registering for an event</p>
                    <a href="/events/" class="btn btn-primary">Browse Events</a>
                </div>
                <?php else: ?>
                <div class="my-events-list">
                    <?php 
                    // Pastel color palette for events without banners
                    $pastelColors = [
                        ['bg' => '#fef3e2', 'icon' => '#f59e0b', 'gradient' => 'linear-gradient(135deg, #fef3e2 0%, #fde68a 100%)'],
                        ['bg' => '#e0f2fe', 'icon' => '#0ea5e9', 'gradient' => 'linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%)'],
                        ['bg' => '#fce7f3', 'icon' => '#ec4899', 'gradient' => 'linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%)'],
                        ['bg' => '#dcfce7', 'icon' => '#22c55e', 'gradient' => 'linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%)'],
                        ['bg' => '#ede9fe', 'icon' => '#8b5cf6', 'gradient' => 'linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%)'],
                        ['bg' => '#fff7ed', 'icon' => '#ea580c', 'gradient' => 'linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%)'],
                        ['bg' => '#f0fdf4', 'icon' => '#16a34a', 'gradient' => 'linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%)'],
                        ['bg' => '#fdf2f8', 'icon' => '#db2777', 'gradient' => 'linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%)'],
                    ];
                    
                    foreach ($recentRegistrations as $index => $reg): 
                        // Determine event status
                        $now = new DateTime();
                        $startDate = new DateTime($reg['start_date']);
                        $endDate = new DateTime($reg['end_date']);
                        $eventStatus = 'upcoming';
                        $eventStatusText = 'Upcoming';
                        $statusIcon = '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                        
                        $isPaidOrVerified = in_array($reg['payment_status'], ['paid', 'verified']);
                        if (!$isPaidOrVerified) {
                            if (in_array($reg['payment_status'], ['failed', 'rejected'])) {
                                $eventStatus = 'failed';
                                $eventStatusText = 'Failed';
                                $statusIcon = '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
                            } else {
                                $eventStatus = 'pending';
                                $eventStatusText = 'Unpaid';
                                $statusIcon = '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                            }
                        } elseif ($now < $startDate) {
                            $eventStatus = 'upcoming';
                            $eventStatusText = 'Upcoming';
                            $statusIcon = '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                        } elseif ($now >= $startDate && $now <= $endDate) {
                            $eventStatus = 'active';
                            $eventStatusText = 'Live';
                            $statusIcon = '<svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/></svg>';
                        } else {
                            $eventStatus = 'completed';
                            $eventStatusText = 'Completed';
                            $statusIcon = '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>';
                        }
                        
                        // Get pastel color based on event ID for consistency
                        $colorIndex = $reg['event_id'] % count($pastelColors);
                        $pastel = $pastelColors[$colorIndex];
                    ?>
                    <a href="/user/event-details?event_id=<?php echo $reg['event_id']; ?>" class="my-event-card">
                        <div class="my-event-thumb-wrapper">
                            <?php if (!empty($reg['banner_image'])): ?>
                                <div class="my-event-thumb has-image">
                                    <img src="/uploads/banners/<?php echo sanitize($reg['banner_image']); ?>" alt="">
                                </div>
                            <?php else: ?>
                                <div class="my-event-thumb pastel-thumb" style="background: <?php echo $pastel['gradient']; ?>;">
                                    <div class="pastel-icon" style="color: <?php echo $pastel['icon']; ?>;">
                                        <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <span class="event-status-chip status-<?php echo $eventStatus; ?>">
                                <?php echo $statusIcon; ?>
                                <?php echo $eventStatusText; ?>
                            </span>
                        </div>
                        <div class="my-event-content">
                            <h4 class="my-event-title"><?php echo sanitize(truncateText($reg['title'], 55)); ?></h4>
                            <div class="my-event-meta">
                                <span>
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    <?php echo formatDate($reg['start_date']); ?>
                                </span>
                                <span>
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                    <?php echo ucfirst($reg['type']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="my-event-arrow">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- User Bottom Navigation -->
<?php include __DIR__ . '/../includes/user_nav.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php include __DIR__ . '/../includes/whatsapp_modal.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bodymovin/5.12.2/lottie.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const lottieContainer = document.getElementById('completionLottie');
    if (lottieContainer) {
        lottie.loadAnimation({
            container: lottieContainer,
            renderer: 'svg',
            loop: true,
            autoplay: true,
            path: '/assets/lottie/important-icon.json'
        });
    }
});
</script>
