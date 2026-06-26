<?php
/**
 * KESA Learn - User Event Details
 * Shows registration details, live sessions, recordings, and certificates
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db = getDB();
$userId = $_SESSION['user_id'];
$eventId = intval($_GET['event_id'] ?? 0);

if (!$eventId) {
    redirect('/user/dashboard');
}

// Get registration and event details
$stmt = $db->prepare("
    SELECT r.*, r.id as registration_id, e.id as event_id, e.title, e.start_date, e.end_date, e.type, e.is_online, e.venue, 
           e.banner_image, e.status as event_status, e.description, e.meeting_link as event_meeting_link,
           e.support_phone, e.support_whatsapp, e.whatsapp_group_link, e.whatsapp_group_enabled
    FROM registrations r 
    JOIN events e ON r.event_id = e.id 
    WHERE r.user_id = ? AND r.event_id = ?
");
$stmt->execute([$userId, $eventId]);
$registration = $stmt->fetch();

if (!$registration) {
    setFlash('error', 'Registration not found.');
    redirect('/user/dashboard');
}

// Check if user has a certificate for this event
$certStmt = $db->prepare("SELECT * FROM certificates WHERE user_id = ? AND event_id = ?");
$certStmt->execute([$userId, $eventId]);
$certificate = $certStmt->fetch();

// Check if user has submitted feedback for this event
$existingFeedback = null;
try {
    $fbStmt = $db->prepare("SELECT * FROM feedbacks WHERE user_id = ? AND event_id = ?");
    $fbStmt->execute([$userId, $eventId]);
    $existingFeedback = $fbStmt->fetch();
} catch (PDOException $e) {
    $existingFeedback = null;
}

// Handle feedback submission from this page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_feedback') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $rating = intval($_POST['rating'] ?? 5);
        $feedbackText = trim($_POST['feedback_text'] ?? '');
        $roleTitle = trim($_POST['role_title'] ?? '');
        
        if ($feedbackText && !$existingFeedback) {
            $user = getUser($userId);
            $stmt = $db->prepare("INSERT INTO feedbacks (user_id, event_id, name, rating, feedback_text, role_title, is_approved, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
            $stmt->execute([$userId, $eventId, $user['name'], $rating, $feedbackText, $roleTitle]);
            
            logActivity('feedback_submitted', "User submitted feedback for event ID: $eventId", $userId);
            setFlash('success', 'Thank you for your feedback!');
            redirect('/user/event-details?event_id=' . $eventId);
        }
    }
}

// Get live sessions for this event
$liveSessions = [];
try {
    $sessionsStmt = $db->prepare("
        SELECT ls.*, i.name as instructor_name, i.photo as instructor_photo
        FROM live_sessions ls
        LEFT JOIN instructors i ON ls.instructor_id = i.id
        WHERE ls.event_id = ?
        ORDER BY 
            CASE WHEN ls.status = 'live' THEN 0 
                 WHEN ls.status = 'scheduled' THEN 1 
                 ELSE 2 END,
            ls.start_datetime ASC
    ");
    $sessionsStmt->execute([$eventId]);
    $liveSessions = $sessionsStmt->fetchAll();
} catch (PDOException $e) {
    $liveSessions = [];
}

// Get announcements for this event
$announcements = [];
try {
    $annStmt = $db->prepare("
        SELECT * FROM event_announcements 
        WHERE event_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $annStmt->execute([$eventId]);
    $announcements = $annStmt->fetchAll();
} catch (PDOException $e) {
    $announcements = [];
}

// Calculate event status
$now = new DateTime();
$startDate = new DateTime($registration['start_date']);
$endDate = new DateTime($registration['end_date']);
$eventStatusText = 'Upcoming';
$eventStatusClass = 'upcoming';

if ($now < $startDate) {
    $eventStatusText = 'Upcoming';
    $eventStatusClass = 'upcoming';
} elseif ($now >= $startDate && $now <= $endDate) {
    $eventStatusText = 'In Progress';
    $eventStatusClass = 'in-progress';
} else {
    $eventStatusText = 'Completed';
    $eventStatusClass = 'completed';
}

$pageTitle = $registration['title'];
include __DIR__ . '/../includes/header.php';
?>

<style>
.ed-page {
    padding: 0 0 100px;
    background: var(--bg-secondary);
    min-height: 100vh;
}

/* Header */
.ed-header {
    position: relative;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: white;
    overflow: hidden;
}

.ed-banner {
    width: 100%;
    height: 200px;
    object-fit: cover;
    opacity: 0.35;
}

.ed-banner-placeholder {
    width: 100%;
    height: 200px;
    position: relative;
}

.ed-header-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 24px 16px 20px;
    background: linear-gradient(to top, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.55), transparent);
}

/* Reading Materials */
.materials-list { display: flex; flex-direction: column; gap: 10px; }
.material-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: var(--bg-primary);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-lg);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.material-item:hover { border-color: #0ea5e9; box-shadow: 0 2px 8px rgba(14,165,233,0.08); }
.material-item.read { border-left: 3px solid #16a34a; }
.material-icon-wrap {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.material-info { flex: 1; min-width: 0; }
.material-info h4 { font-size: 0.92rem; font-weight: 700; color: var(--text-primary); margin: 0 0 3px; }
.material-info p { font-size: 0.78rem; color: var(--text-muted); margin: 0 0 6px; line-height: 1.4; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.material-type-badge {
    display: inline-block;
    padding: 2px 8px;
    background: var(--bg-tertiary);
    color: var(--text-muted);
    border-radius: 8px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.5px;
}
.material-action { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0; }
.material-read-check {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.72rem;
    font-weight: 600;
    color: #16a34a;
}
.material-open-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #0ea5e9;
    color: #fff;
    border-radius: var(--radius-md);
    font-size: 0.82rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.15s;
    white-space: nowrap;
}
.material-open-btn:hover { background: #0284c7; }

/* Session Attendance Button */
/* Attendance icon buttons — compact tick / cross / pending */
.att-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    flex-shrink: 0;
    transition: transform 0.15s, box-shadow 0.15s;
}
.att-icon--attended {
    background: #dcfce7;
    color: #16a34a;
    border: 1.5px solid #bbf7d0;
    cursor: default;
}
.att-icon--absent {
    background: #fee2e2;
    color: #dc2626;
    border: 1.5px solid #fecaca;
    cursor: default;
}
.att-icon--unmarked {
    background: var(--bg-secondary);
    color: var(--text-muted);
    border: 1.5px dashed var(--border-color);
    cursor: pointer;
}
.att-icon--unmarked:hover {
    background: #f0f9ff;
    color: #0ea5e9;
    border-color: #7dd3fc;
    transform: scale(1.08);
    box-shadow: 0 2px 8px rgba(14,165,233,0.15);
}
.att-icon--unmarked:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* Report icon button on banner top-right */
.ed-report-icon-btn {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,0.18);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    border: 1.5px solid rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    text-decoration: none;
    transition: background 0.2s, transform 0.15s;
    z-index: 10;
}
.ed-report-icon-btn:hover {
    background: rgba(255,255,255,0.3);
    transform: scale(1.08);
    color: white;
}
.ed-report-icon-btn svg { display: block; }

/* Burger button inside ed-header-overlay (top-right) */
.ed-burger-btn {
    position: absolute;
    top: 14px;
    right: 14px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 8px;
    width: 38px;
    height: 38px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    z-index: 20;
    transition: background 0.2s;
}
.ed-burger-btn:hover { background: rgba(255,255,255,0.22); }
.ed-burger-btn span {
    display: block;
    width: 18px;
    height: 2px;
    background: #fff;
    border-radius: 2px;
    transition: transform 0.25s, opacity 0.25s;
}
.ed-burger-btn.open span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
.ed-burger-btn.open span:nth-child(2) { opacity: 0; }
.ed-burger-btn.open span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

/* Slide-in nav drawer */
.ed-nav-drawer {
    position: fixed;
    top: 0;
    right: -280px;
    width: 280px;
    height: 100%;
    background: var(--bg-primary);
    z-index: 9999;
    box-shadow: -4px 0 24px rgba(0,0,0,0.18);
    transition: right 0.3s cubic-bezier(0.4,0,0.2,1);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}
.ed-nav-drawer.open { right: 0; }
.ed-nav-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 9998;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
}
.ed-nav-overlay.open { opacity: 1; pointer-events: all; }
.ed-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-light);
}
.ed-drawer-header strong { font-size: 1rem; color: var(--text-primary); font-weight: 700; }
.ed-drawer-close {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 4px;
    line-height: 1;
}
.ed-drawer-user {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-light);
}
.ed-drawer-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    background: var(--bg-secondary);
}
.ed-drawer-user-info strong { display: block; font-size: 0.9rem; color: var(--text-primary); font-weight: 600; }
.ed-drawer-user-info span { font-size: 0.78rem; color: var(--text-muted); }
.ed-drawer-nav { padding: 12px 0; flex: 1; }
.ed-drawer-nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 20px;
    font-size: 0.92rem;
    color: var(--text-secondary);
    text-decoration: none;
    font-weight: 500;
    transition: background 0.15s, color 0.15s;
    border-radius: 0;
}
.ed-drawer-nav a:hover { background: var(--bg-secondary); color: var(--text-primary); }
.ed-drawer-nav a.active { color: var(--blue); background: rgba(14,165,233,0.06); font-weight: 600; }
.ed-drawer-nav a svg { width: 20px; height: 20px; flex-shrink: 0; }
.ed-drawer-divider { height: 1px; background: var(--border-light); margin: 8px 0; }
.ed-drawer-logout {
    padding: 16px 20px;
    border-top: 1px solid var(--border-light);
}
.ed-drawer-logout a {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #ef4444;
    font-size: 0.92rem;
    text-decoration: none;
    font-weight: 500;
    padding: 10px 0;
}
.ed-drawer-logout a svg { width: 18px; height: 18px; }

.ed-title {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0 0 10px;
    line-height: 1.35;
    color: #ffffff;
}

.ed-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.85);
}

.ed-meta span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.ed-meta svg {
    width: 15px;
    height: 15px;
    opacity: 0.8;
}

.ed-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px 16px;
}

/* Info Cards Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.info-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 16px;
    box-shadow: var(--shadow-sm);
}

.info-card-label {
    font-size: 0.7rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.info-card-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.info-card-value.status-upcoming {
    color: var(--blue);
}

.info-card-value.status-in-progress {
    color: #16a34a;
}

.info-card-value.status-completed {
    color: var(--text-muted);
}

.info-card-value.status-paid {
    color: #16a34a;
}

.info-card-value.status-verified {
    color: var(--blue);
}

.info-card-value.status-pending {
    color: #d97706;
}

/* Certificate Card */
.cert-card {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border: 2px solid #0ea5e9;
    border-radius: var(--radius-xl);
    padding: 24px;
    margin-bottom: 24px;
    text-align: center;
}

.cert-card.unavailable {
    background: var(--bg-tertiary);
    border-color: var(--border-color);
}

.cert-card.unavailable .cert-icon,
.cert-card.unavailable .cert-title {
    color: var(--text-muted);
}

.cert-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 14px;
    color: #0ea5e9;
}

.cert-title {
    font-size: 1.15rem;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--text-primary);
}

.cert-subtitle {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 18px;
}

.cert-code {
    font-family: 'Courier New', monospace;
    background: white;
    padding: 10px 20px;
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    font-weight: 600;
    margin-bottom: 16px;
    display: inline-block;
    letter-spacing: 1px;
    border: 1px solid rgba(14, 165, 233, 0.2);
}

.cert-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 28px;
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    color: white;
    border-radius: var(--radius-lg);
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: all 0.2s;
    border: none;
    cursor: pointer;
}

.cert-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(14, 165, 233, 0.35);
}

.cert-btn.disabled {
    background: var(--text-muted);
    cursor: not-allowed;
    pointer-events: none;
    opacity: 0.6;
}

.cert-btn svg {
    width: 20px;
    height: 20px;
}

/* Event Description Card */
.event-desc-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
}

.event-desc-content {
    font-size: 0.95rem;
    line-height: 1.7;
    color: var(--text-secondary);
}

.event-desc-content p {
    margin: 0 0 14px;
}

.event-desc-content p:last-child {
    margin-bottom: 0;
}

.event-desc-content h1, .event-desc-content h2, .event-desc-content h3, 
.event-desc-content h4, .event-desc-content h5, .event-desc-content h6 {
    color: var(--text-primary);
    margin: 18px 0 10px;
    font-weight: 700;
}

.event-desc-content h3 {
    font-size: 1.05rem;
}

.event-desc-content ul, .event-desc-content ol {
    margin: 12px 0;
    padding-left: 24px;
}

.event-desc-content li {
    margin-bottom: 8px;
}

.event-desc-content code {
    background: var(--bg-tertiary);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.9em;
    font-family: monospace;
}

.event-desc-content a {
    color: var(--blue);
    text-decoration: underline;
}

.event-desc-content strong, .event-desc-content b {
    font-weight: 700;
    color: var(--text-primary);
}

.event-desc-content blockquote {
    border-left: 3px solid var(--blue);
    margin: 14px 0;
    padding: 10px 16px;
    background: var(--bg-secondary);
    border-radius: 0 8px 8px 0;
}

/* Quick Actions Grid */
.quick-actions-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
    margin-bottom: 24px;
}

@media (min-width: 600px) {
    .quick-actions-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.quick-action-card {
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 18px 20px;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: var(--shadow-sm);
    border: 2px solid transparent;
}

.quick-action-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.quick-action-card.join-live {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    color: white;
    border-color: transparent;
}

.quick-action-card.join-live:hover {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

.quick-action-card.join-live .qa-icon {
    background: rgba(255,255,255,0.2);
    color: white;
}

.quick-action-card.certificate {
    border-color: #0ea5e9;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
}

.quick-action-card.certificate .qa-icon {
    background: #0ea5e9;
    color: white;
}

.quick-action-card.venue {
    background: linear-gradient(135deg, #fef3e2 0%, #fde68a 100%);
    border-color: #f59e0b;
}

.quick-action-card.venue .qa-icon {
    background: #f59e0b;
    color: white;
}

.qa-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.qa-icon svg {
    width: 24px;
    height: 24px;
}

.qa-content {
    flex: 1;
    min-width: 0;
}

.qa-content h4 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px;
}

.qa-content p {
    font-size: 0.85rem;
    margin: 0;
    opacity: 0.85;
}

.quick-action-card.join-live .qa-content h4,
.quick-action-card.join-live .qa-content p {
    color: white;
}

.qa-arrow {
    width: 20px;
    height: 20px;
    color: var(--text-muted);
    flex-shrink: 0;
}

.quick-action-card.join-live .qa-arrow {
    color: rgba(255,255,255,0.7);
}

/* Section Header */
.section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary);
}

.section-header svg {
    width: 22px;
    height: 22px;
    color: var(--blue);
}

/* Sessions Container */
.sessions-list {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
}

.session-item {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-light);
    transition: background 0.2s;
}

.session-item:last-child {
    border-bottom: none;
}

.session-item:hover {
    background: var(--bg-secondary);
}

.session-item.is-live {
    background: linear-gradient(135deg, rgba(220, 38, 38, 0.06), rgba(220, 38, 38, 0.02));
    border-left: 3px solid #dc2626;
}

.session-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}

.session-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 14px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.session-badge.live {
    background: #dc2626;
    color: white;
    animation: livePulse 1.5s infinite;
}

@keyframes livePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.75; }
}

.session-badge.scheduled {
    background: var(--blue-light);
    color: var(--blue);
}

.session-badge.completed {
    background: #f3f4f6;
    color: #6b7280;
}

.session-badge.recording {
    background: #dcfce7;
    color: #16a34a;
}

.session-platform {
    font-size: 0.75rem;
    color: var(--text-muted);
    background: var(--bg-tertiary);
    padding: 4px 10px;
    border-radius: 10px;
}

.session-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 8px;
    color: var(--text-primary);
}

.session-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 14px;
}

.session-meta span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.session-meta svg {
    width: 14px;
    height: 14px;
}

.session-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.session-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
}

.session-btn svg {
    width: 16px;
    height: 16px;
}

/* Empty Sessions State */
.empty-sessions {
    text-align: center;
    padding: 40px 24px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
}

.empty-sessions .empty-icon {
    width: 64px;
    height: 64px;
    background: var(--bg-tertiary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

.empty-sessions .empty-icon svg {
    width: 32px;
    height: 32px;
    color: var(--text-muted);
}

.empty-sessions h4 {
    font-size: 1.05rem;
    font-weight: 700;
    margin: 0 0 8px;
    color: var(--text-primary);
}

.empty-sessions p {
    font-size: 0.9rem;
    color: var(--text-muted);
    margin: 0;
    line-height: 1.5;
}

/* Recording Expiry */
.recording-expiry {
    font-size: 0.8rem;
    color: var(--orange);
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Learner Tabs */
.learner-tabs {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 24px;
}

.tabs-nav {
    display: flex;
    background: var(--bg-primary);
    border-bottom: 1.5px solid var(--border-light);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    gap: 0;
    padding: 0 4px;
    border-radius: 0;
}
.tabs-nav::-webkit-scrollbar { display: none; }

.tab-btn {
    flex: 0 0 auto;
    min-width: 100px;
    padding: 14px 20px;
    background: none;
    border: none;
    border-top: 2px solid transparent;
    font-size: 0.87rem;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: color 0.18s, border-color 0.18s, background 0.18s;
    border-bottom: 2px solid transparent;
    white-space: nowrap;
    letter-spacing: 0.1px;
    position: relative;
    background: transparent;
}

.tab-btn:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
}

.tab-btn.active {
    color: #0ea5e9;
    border-bottom-color: #0ea5e9;
    background: transparent;
}

.tab-btn:focus {
    outline: none;
}

.tab-btn svg {
    width: 16px;
    height: 16px;
    opacity: 0.7;
}

.tab-btn.active svg {
    opacity: 1;
}

.tab-badge {
    background: #e0f2fe;
    color: #0284c7;
    padding: 1px 7px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.tab-btn.active .tab-badge {
    background: #0ea5e9;
    color: #fff;
}

.tab-badge.live {
    background: #fef3c7;
    color: #d97706;
    animation: pulse-badge 2s infinite;
}

@keyframes pulse-badge {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

/* Responsive Design for Tabs */
@media (max-width: 768px) {
    .tabs-nav {
        gap: 0;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    .tabs-nav::-webkit-scrollbar { display: none; }
    
    .tab-btn {
        min-width: auto;
        padding: 12px 14px;
        font-size: 0.82rem;
    }
    
    .tab-btn svg {
        width: 18px;
        height: 18px;
    }
    
    .tab-badge {
        padding: 2px 7px;
        font-size: 0.65rem;
    }
}

@media (max-width: 480px) {
    .tab-btn {
        min-width: 85px;
        padding: 12px 12px;
        font-size: 0.8rem;
        gap: 6px;
    }
    
    .tab-btn svg {
        width: 16px;
        height: 16px;
    }
    
    .tab-badge {
        padding: 1px 6px;
        font-size: 0.6rem;
    }
    
    .tab-verified-badge-small {
        margin-left: 4px;
    }
}

/* Pay Now Button in Info Card */
.pay-now-card {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px;
}

.pay-now-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 28px;
    background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
    color: white;
    text-decoration: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    border: none;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(124, 58, 237, 0.35);
    width: 100%;
}

.pay-now-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(124, 58, 237, 0.45);
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
}

.pay-now-btn:active {
    transform: translateY(-1px);
}

.pay-now-btn svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

/* Content Locked Wrapper with Blur Effect */
.content-locked-wrapper {
    position: relative;
    margin-top: 24px;
}

.content-blurred {
    filter: blur(8px);
    pointer-events: none;
    user-select: none;
    opacity: 0.6;
}

.blur-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    min-height: 400px;
    background: linear-gradient(180deg, rgba(254, 226, 226, 0.3) 0%, rgba(254, 226, 226, 0.6) 100%);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    backdrop-filter: blur(2px);
}

.lock-icon-container {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 12px 40px rgba(124, 58, 237, 0.4);
    animation: pulse-lock 2s ease-in-out infinite;
}

.lock-icon-container svg {
    color: white;
    width: 40px;
    height: 40px;
}

@keyframes pulse-lock {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 12px 40px rgba(124, 58, 237, 0.4);
    }
    50% {
        transform: scale(1.05);
        box-shadow: 0 16px 50px rgba(124, 58, 237, 0.5);
    }
}

.tab-content {
    display: none;
    padding: 24px;
}

.tab-content.active {
    display: block;
}

/* Empty Tab State */
.empty-tab {
    text-align: center;
    padding: 48px 24px;
}

.empty-tab .empty-icon {
    width: 64px;
    height: 64px;
    background: var(--bg-tertiary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

.empty-tab .empty-icon svg {
    width: 32px;
    height: 32px;
    color: var(--text-muted);
}

.empty-tab h4 {
    margin: 0 0 8px;
    font-size: 1.1rem;
    color: var(--text-primary);
}

.empty-tab p {
    margin: 0;
    color: var(--text-muted);
    font-size: 0.9rem;
}

/* Sessions List */
.sessions-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.session-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    flex-wrap: wrap;
}

.session-item.live {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border-color: #fca5a5;
}

.session-item.upcoming {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-color: #7dd3fc;
}

.session-status {
    flex-shrink: 0;
}

.status-live {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #dc2626;
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
}

.status-live .pulse {
    width: 8px;
    height: 8px;
    background: white;
    border-radius: 50%;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.2); opacity: 0.7; }
}

.status-past, .status-upcoming {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-past {
    background: var(--bg-tertiary);
    color: var(--text-muted);
}

.status-upcoming {
    background: #dbeafe;
    color: #1d4ed8;
}

.session-info {
    flex: 1;
    min-width: 200px;
}

.session-info h4 {
    margin: 0 0 4px;
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.session-time {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-muted);
}

.session-time svg {
    width: 14px;
    height: 14px;
}

.session-actions {
    flex-shrink: 0;
}

.btn-join {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    color: white;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-join:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}

.btn-join svg {
    width: 16px;
    height: 16px;
}

.btn-watch {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    color: var(--text-primary);
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-watch:hover {
    border-color: var(--blue);
    color: var(--blue);
}

.btn-watch svg {
    width: 18px;
    height: 18px;
}

.session-countdown {
    font-size: 0.85rem;
    color: var(--text-muted);
}

.countdown-timer {
    font-weight: 700;
    color: var(--blue);
}

/* Assignments List */
.assignments-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.assignment-item {
    padding: 20px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
}

.assignment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.assignment-info h4 {
    margin: 0 0 6px;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary);
}

.assignment-title-row {
    display: flex;
    align-items: baseline;
    gap: 12px;
    flex-wrap: wrap;
}

.assignment-number {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    background: var(--blue-light);
    color: var(--blue);
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.assignment-desc {
    margin: 0;
    font-size: 0.9rem;
    color: var(--text-muted);
    line-height: 1.5;
}

.assignment-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge svg {
    width: 14px;
    height: 14px;
}

.status-badge.approved {
    background: #dcfce7;
    color: #16a34a;
}

.status-badge.rejected {
    background: #fef2f2;
    color: #dc2626;
}

.status-badge.pending {
    background: #fef3c7;
    color: #d97706;
}

.status-badge.not-submitted {
    background: var(--bg-tertiary);
    color: var(--text-muted);
}

.score {
    font-size: 1.1rem;
    font-weight: 700;
    color: #16a34a;
}

.deadline-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: var(--bg-tertiary);
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 0.85rem;
}

.deadline-row svg {
    width: 16px;
    height: 16px;
    color: var(--text-muted);
}

.deadline-text {
    color: var(--text-secondary);
}

.deadline-text.expired {
    color: #dc2626;
}

.countdown-badge {
    background: var(--blue);
    color: white;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
}

.feedback-box {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 0.9rem;
    line-height: 1.5;
}

.feedback-box.rejected {
    background: #fef2f2;
    border-left: 3px solid #dc2626;
    color: #991b1b;
}

.feedback-box.approved {
    background: #f0fdf4;
    border-left: 3px solid #16a34a;
    color: #166534;
}

.btn-submit-assignment {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.95rem;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(30, 136, 229, 0.3);
    border: none;
    cursor: pointer;
}

.btn-submit-assignment:hover {
    background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 136, 229, 0.4);
}

.btn-submit-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.95rem;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(30, 136, 229, 0.3);
}

.btn-submit-link:hover {
    background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 136, 229, 0.4);
    text-decoration: none;
}

.btn-submit-assignment svg,
.btn-submit-link svg {
    width: 20px;
    height: 20px;
    stroke-width: 2.5;
}

.btn-submit-assignment:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37,99,235,0.4);
}

.btn-submit-assignment:active {
    transform: translateY(0);
}

.btn-submit-assignment:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
}

.btn-submit-assignment svg {
    width: 18px;
    height: 18px;
}

/* Certificate Display */
.certificate-display {
    text-align: center;
    padding: 40px 24px;
}

.cert-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.cert-icon svg {
    width: 40px;
    height: 40px;
    color: white;
}

.certificate-display h3 {
    margin: 0 0 8px;
    font-size: 1.3rem;
    color: var(--text-primary);
}

.certificate-display p {
    margin: 0 0 20px;
    color: var(--text-muted);
}

.cert-code {
    display: inline-block;
    padding: 12px 24px;
    background: var(--bg-tertiary);
    border-radius: 8px;
    font-family: monospace;
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: var(--text-primary);
    margin-bottom: 24px;
}

.btn-download-cert {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    color: white;
    border-radius: 10px;
    font-weight: 600;
    font-size: 1rem;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-download-cert:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
}

.btn-download-cert svg {
    width: 20px;
    height: 20px;
}

.session-btn.join {
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    color: white;
}

.session-btn.join:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
}

.session-btn.watch {
    background: linear-gradient(135deg, var(--blue) 0%, var(--purple) 100%);
    color: white;
}

.session-btn.watch:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(73, 80, 186, 0.3);
}

.recording-expiry {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.empty-sessions {
    padding: 50px 24px;
    text-align: center;
    color: var(--text-muted);
}

.empty-sessions svg {
    width: 52px;
    height: 52px;
    margin-bottom: 14px;
    opacity: 0.4;
}

.empty-sessions p {
    font-size: 0.9rem;
}

/* Announcements */
.announcements-list {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
}

.ann-item {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-light);
    border-left: 4px solid var(--blue);
}

.ann-item:last-child {
    border-bottom: none;
}

.ann-item.important {
    border-left-color: #dc2626;
    background: #fef2f2;
}

.ann-title {
    font-weight: 700;
    margin-bottom: 6px;
    font-size: 0.95rem;
}

.ann-content {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin-bottom: 10px;
    line-height: 1.6;
}

.ann-date {
    font-size: 0.75rem;
    color: var(--text-muted);
}

/* Support Contact */
.support-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    padding: 20px;
    box-shadow: var(--shadow-sm);
}

.support-title {
    font-size: 0.9rem;
    font-weight: 700;
    margin-bottom: 14px;
    color: var(--text-primary);
}

.support-links {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.support-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
}

.support-btn.whatsapp {
    background: #25D366;
    color: white;
}

.support-btn.whatsapp:hover {
    background: #128C7E;
}

.support-btn.phone {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.support-btn.phone:hover {
    background: var(--blue);
    color: white;
    border-color: var(--blue);
}

.support-btn svg {
    width: 16px;
    height: 16px;
}

/* WhatsApp Group Card */
.whatsapp-group-card {
    background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
    border-radius: var(--radius-xl);
    padding: 24px;
    box-shadow: var(--shadow-sm);
    color: white;
    margin-bottom: 24px;
}

.whatsapp-group-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.whatsapp-group-header svg {
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    filter: drop-shadow(0 1px 3px rgba(0,0,0,0.1));
}

.whatsapp-group-header h4 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
}

.whatsapp-group-header p {
    margin: 0;
    font-size: 0.85rem;
    opacity: 0.95;
}

.whatsapp-group-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: white;
    color: #25D366;
    padding: 12px 24px;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    transition: all 0.2s;
    margin-bottom: 12px;
}

.whatsapp-group-btn:hover {
    background: rgba(255, 255, 255, 0.95);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.whatsapp-group-btn svg {
    width: 18px;
    height: 18px;
}

.whatsapp-group-hint {
    font-size: 0.85rem;
    opacity: 0.9;
    margin: 0;
    line-height: 1.5;
}

/* Feedback Section */
.feedback-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    border: 2px solid #8b5cf6;
}

.feedback-card.submitted {
    border-color: #16a34a;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
}

.feedback-card h4 {
    font-size: 1.05rem;
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.feedback-card h4 svg {
    width: 22px;
    height: 22px;
    color: #8b5cf6;
}

.feedback-card.submitted h4 svg {
    color: #16a34a;
}

.feedback-card > p {
    color: var(--text-muted);
    font-size: 0.85rem;
    margin: 0 0 18px;
}

.fb-star-rating {
    display: flex;
    gap: 6px;
    flex-direction: row-reverse;
    justify-content: flex-end;
    margin-bottom: 16px;
}

.fb-star-rating input {
    display: none;
}

.fb-star-rating label {
    font-size: 2rem;
    color: #e5e7eb;
    cursor: pointer;
    transition: color 0.2s, transform 0.15s;
}

.fb-star-rating label:hover,
.fb-star-rating label:hover ~ label,
.fb-star-rating input:checked ~ label {
    color: #fbbf24;
}

.fb-star-rating label:hover {
    transform: scale(1.15);
}

.fb-form-group {
    margin-bottom: 14px;
}

.fb-form-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
}

.fb-form-group input,
.fb-form-group textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    transition: border-color 0.2s;
}

.fb-form-group input:focus,
.fb-form-group textarea:focus {
    outline: none;
    border-color: #8b5cf6;
}

.fb-form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.fb-submitted-view {
    text-align: center;
    padding: 10px 0;
}

.fb-submitted-rating {
    display: flex;
    justify-content: center;
    gap: 4px;
    margin-bottom: 14px;
}

.fb-submitted-rating span {
    font-size: 1.5rem;
    color: #fbbf24;
}

.fb-submitted-rating span.empty {
    color: #e5e7eb;
}

.fb-submitted-text {
    color: var(--text-secondary);
    font-size: 0.95rem;
    line-height: 1.6;
    font-style: italic;
    margin-bottom: 10px;
}

.fb-submitted-meta {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.fb-status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 8px;
}

.fb-status-badge.published {
    background: #dcfce7;
    color: #16a34a;
}

.fb-status-badge.pending {
    background: #fef3c7;
    color: #d97706;
}

.fb-unavailable {
    text-align: center;
    padding: 20px;
    background: var(--bg-tertiary);
    border-radius: var(--radius-lg);
}

.fb-unavailable svg {
    width: 40px;
    height: 40px;
    color: var(--text-muted);
    opacity: 0.5;
    margin-bottom: 10px;
}

.fb-unavailable p {
    color: var(--text-muted);
    font-size: 0.9rem;
    margin: 0;
}

/* ── Verified tab badge ── */
.tab-verified-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 6px;
    vertical-align: middle;
    filter: drop-shadow(0 1px 2px rgba(22,163,74,0.35));
    transition: transform 0.2s;
}
.tab-verified-badge-small {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 8px;
    vertical-align: middle;
    transition: transform 0.15s;
    background: rgba(255, 255, 255, 0.25);
    padding: 4px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.4);
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15));
}
.tab-btn:hover .tab-verified-badge,
.tab-btn.active .tab-verified-badge,
.tab-btn:hover .tab-verified-badge-small,
.tab-btn.active .tab-verified-badge-small {
    transform: scale(1.15);
}

/* ── Certificate Cards ── */
.cert-available-card,
.cert-unavailable-card {
    border-radius: 24px;
    padding: 48px 36px;
    text-align: center;
    width: 100%;
    max-width: 520px;
    margin: 20px auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
}

.cert-available-card {
    background: #e3f2fd;
    border: 2.5px solid #90caf9;
}

.cert-unavailable-card {
    background: #f5f5f5;
    border: 2px solid #e0e0e0;
}

.cert-card-header {
    margin-bottom: 8px;
}

.cert-check-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #1e88e5;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    color: white;
    box-shadow: 0 2px 12px rgba(30,136,229,0.3);
}

.cert-check-icon svg {
    width: 36px;
    height: 36px;
    stroke-width: 2.5;
}

.cert-check-icon-gray {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #9ca3af;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    color: white;
    box-shadow: 0 2px 8px rgba(156,163,175,0.2);
}

.cert-check-icon-gray svg {
    width: 36px;
    height: 36px;
    stroke-width: 2;
}

.cert-badge-img {
    width: 80px;
    height: 80px;
    object-fit: contain;
    margin: 0 auto;
}

.cert-badge-verified {
    filter: drop-shadow(0 2px 8px rgba(30,136,229,0.2));
}

.cert-badge-notverified {
    filter: drop-shadow(0 1px 4px rgba(156,163,175,0.2));
}

.cert-card-title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 900;
    color: #1f2937;
}

.cert-card-title-gray {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 900;
    color: #6b7280;
}

.cert-card-subtitle {
    margin: 0;
    font-size: 0.95rem;
    color: #6b7280;
    line-height: 1.5;
}

.cert-card-subtitle-gray {
    margin: 0;
    font-size: 0.95rem;
    color: #9ca3af;
    line-height: 1.5;
}

.cert-code-box {
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    padding: 16px 24px;
    font-family: 'Courier New', monospace;
    font-size: 1.15rem;
    font-weight: 900;
    color: #1f2937;
    letter-spacing: 2px;
    word-break: break-all;
    margin: 16px 0;
    width: 100%;
}

.cert-download-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 40px;
    background: #1e88e5;
    color: white;
    border-radius: 16px;
    font-size: 1rem;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(30,136,229,0.3);
}

.cert-download-btn:hover {
    background: #1565c0;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30,136,229,0.4);
}

.cert-download-btn svg {
    width: 20px;
    height: 20px;
}

.cert-locked-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 32px;
    background: #9ca3af;
    color: white;
    border-radius: 16px;
    font-size: 0.9rem;
    font-weight: 700;
    border: none;
    cursor: not-allowed;
    opacity: 0.85;
}

.cert-locked-btn svg {
    width: 18px;
    height: 18px;
}

.cert-eligibility-note {
    margin: 12px 0 0;
    font-size: 0.65rem;
    color: #a1a1a1;
    font-weight: 500;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.cert-verified-badge {
    display: flex;
    align-items: center;
    gap: 16px;
    background: linear-gradient(135deg, #1e7c74 0%, #2a9b8b 50%, #0d9488 100%);
    border-radius: 20px;
    padding: 24px 28px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 8px 32px rgba(29,124,116,0.35), 0 0 1px rgba(0,0,0,0.1);
}

.cert-verified-ring {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.cert-verified-label {
    margin: 0 0 2px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1.3px;
    color: rgba(255,255,255,0.75);
    text-transform: uppercase;
}

.cert-verified-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: #fff;
    line-height: 1.3;
}

.cert-code-row {
    display: flex;
    align-items: center;
    gap: 14px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 20px 24px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.cert-code-label {
    font-size: 0.72rem;
    font-weight: 800;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}

.cert-code {
    flex: 1;
    font-family: 'Courier New', monospace;
    font-size: 1rem;
    font-weight: 800;
    color: #1f2937;
    letter-spacing: 1px;
    word-break: break-all;
}

.cert-copy-btn {
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 9px 18px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(37,99,235,0.25);
}
.cert-copy-btn:hover {
    background: #1d4ed8;
    box-shadow: 0 4px 12px rgba(37,99,235,0.4);
    transform: translateY(-1px);
}

.cert-issued-date {
    margin: 0;
    font-size: 0.85rem;
    color: #6b7280;
    font-weight: 500;
}

.btn-download-cert {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 32px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #fff;
    border-radius: 14px;
    font-size: 1rem;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 4px 16px rgba(37,99,235,0.3);
    border: none;
    cursor: pointer;
}
.btn-download-cert:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(37,99,235,0.4);
}
.btn-download-cert:active {
    transform: translateY(0);
}
.btn-download-cert svg { width: 20px; height: 20px; }

/* ── Certificate pending display ── */
.cert-pending-display {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 48px 24px;
    gap: 14px;
}

.cert-pending-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0284c7;
    margin-bottom: 8px;
    box-shadow: 0 2px 12px rgba(2,132,199,0.1);
}

.cert-pending-display h3 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 800;
    color: #1f2937;
}

.cert-pending-display p {
    margin: 0;
    font-size: 0.92rem;
    color: #6b7280;
    max-width: 360px;
    line-height: 1.6;
}

.cert-pending-steps {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
    max-width: 280px;
    margin-top: 12px;
}

.cert-step {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #9ca3af;
}

.cert-step-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #d1d5db;
    flex-shrink: 0;
}

.cert-step.done { 
    color: #059669;
}
.cert-step.done .cert-step-dot { 
    background: #059669;
}

/* Hide title and position banner on mobile */
.hidden-mobile {
    display: none;
}

.ed-header {
    position: relative;
    margin-top: -16px;
}

@media (min-width: 769px) {
    .ed-header-overlay {
        padding: 32px 28px;
    }
    
    .ed-title {
        font-size: 1.7rem;
    }
    
    .ed-container {
        padding: 28px;
    }
    
    .info-grid {
        grid-template-columns: repeat(4, 1fr);
    }
    
    /* Show title on desktop */
    .hidden-mobile {
        display: block;
    }
    
    /* Reset banner position on desktop */
    .ed-header {
        margin-top: 0;
    }
}
</style>

<?php
// Pastel color palette for events without banners
$pastelColors = [
    ['gradient' => 'linear-gradient(135deg, #fef3e2 0%, #fde68a 100%)', 'icon' => '#f59e0b'],
    ['gradient' => 'linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%)', 'icon' => '#0ea5e9'],
    ['gradient' => 'linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%)', 'icon' => '#ec4899'],
    ['gradient' => 'linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%)', 'icon' => '#22c55e'],
    ['gradient' => 'linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%)', 'icon' => '#8b5cf6'],
    ['gradient' => 'linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%)', 'icon' => '#ea580c'],
    ['gradient' => 'linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%)', 'icon' => '#16a34a'],
    ['gradient' => 'linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%)', 'icon' => '#db2777'],
];
$pastelIndex = $registration['event_id'] % count($pastelColors);
$pastel = $pastelColors[$pastelIndex];
?>

<div class="ed-page">
    <!-- Header -->
    <div class="ed-header">
        <?php if (!empty($registration['banner_image'])): ?>
        <img src="/uploads/banners/<?php echo sanitize($registration['banner_image']); ?>" alt="" class="ed-banner">
        <?php else: ?>
        <div class="ed-banner-placeholder" style="background: <?php echo $pastel['gradient']; ?>;">
            <svg width="48" height="48" fill="none" stroke="<?php echo $pastel['icon']; ?>" viewBox="0 0 24 24" style="opacity: 0.5; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <?php endif; ?>
        
        <div class="ed-header-overlay">
            <h1 class="ed-title" style="padding-right:52px;"><?php echo sanitize($registration['title']); ?></h1>
            <a href="/user/event-report.php?event_id=<?php echo $eventId; ?>" class="ed-report-icon-btn" title="View My Progress Report" aria-label="View Progress Report">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </a>
            <div class="ed-meta">
                <span>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <?php echo date('M d, Y', strtotime($registration['start_date'])); ?>
                </span>
                <span>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    <?php echo ucfirst($registration['type']); ?>
                </span>
                <?php if ($registration['is_online']): ?>
                <span>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    Online
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="ed-container">
        <!-- Info Cards Grid -->
        <div class="info-grid">
            <div class="info-card">
                <div class="info-card-label">Event Status</div>
                <div class="info-card-value status-<?php echo $eventStatusClass; ?>"><?php echo $eventStatusText; ?></div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Payment Status</div>
                <?php
                // Show user-friendly payment status label
                $payStatusLabel = ucfirst($registration['payment_status']);
                $payStatusClass = $registration['payment_status'];
                if ($registration['payment_status'] === 'pending' && !empty($registration['payment_proof'])) {
                    $payStatusLabel = 'Verification Pending';
                    $payStatusClass = 'pending';
                }
                ?>
                <div class="info-card-value status-<?php echo $payStatusClass; ?>">
                    <?php echo $payStatusLabel; ?>
                </div>
            </div>
            <div class="info-card">
                <div class="info-card-label">Joined Date</div>
                <div class="info-card-value"><?php echo date('M d, Y', strtotime($registration['registered_at'])); ?></div>
            </div>
            <?php
            // Determine proof-submitted state (pending + proof uploaded = awaiting verification)
            $proofSubmitted     = ($registration['payment_status'] === 'pending' && !empty($registration['payment_proof']));
            // Needs payment only when pending AND no proof uploaded yet, OR when explicitly rejected
            $isPaymentPending   = ($registration['payment_status'] === 'rejected')
                                  || ($registration['payment_status'] === 'pending' && !$proofSubmitted);
            // Amount shown: prefer final_amount (actual charged), fall back to amount
            $displayAmount = (!empty($registration['final_amount']) && $registration['final_amount'] > 0)
                ? $registration['final_amount']
                : $registration['amount'];
            if ($isPaymentPending):
            ?>
            <div class="info-card pay-now-card">
                <a href="/events/payment?registration_id=<?php echo $registration['registration_id']; ?>" class="pay-now-btn">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span><?php echo $registration['payment_status'] === 'rejected' ? 'Pay Again' : 'Pay Now'; ?></span>
                </a>
            </div>
            <?php elseif ($proofSubmitted): ?>
            <div class="info-card" style="background:#fefce8;border:1px solid #fde047;">
                <div class="info-card-label" style="color:#713f12;">Payment Status</div>
                <div class="info-card-value" style="font-size:0.85rem;color:#854d0e;font-weight:700;">Verification Pending</div>
            </div>
            <?php else: ?>
            <div class="info-card">
                <div class="info-card-label">Amount Paid</div>
                <div class="info-card-value"><?php echo $displayAmount > 0 ? formatPrice($displayAmount) : 'Free'; ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Learner Content Tabs -->
        <?php
        // Get live sessions for this event (uses correct column names from live_sessions table)
        try {
            $tabSessionsStmt = $db->prepare("SELECT * FROM live_sessions WHERE event_id = ? ORDER BY start_datetime DESC");
            $tabSessionsStmt->execute([$eventId]);
            $tabSessions = $tabSessionsStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $tabSessions = []; }
        
        // Get assignments for this event
        try {
            $assignmentsStmt = $db->prepare("SELECT * FROM assignments WHERE event_id = ? AND is_active = 1 ORDER BY created_at DESC");
            $assignmentsStmt->execute([$eventId]);
            $assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $assignments = []; }
        
        // Get user's submissions for this event
        $userSubmissions = [];
        try {
            $submissionsStmt = $db->prepare("SELECT * FROM assignment_submissions WHERE user_id = ? AND assignment_id IN (SELECT id FROM assignments WHERE event_id = ?)");
            $submissionsStmt->execute([$_SESSION['user_id'], $eventId]);
            while ($sub = $submissionsStmt->fetch(PDO::FETCH_ASSOC)) {
                $userSubmissions[$sub['assignment_id']] = $sub;
            }
        } catch (PDOException $e) {}
        
        // Get quizzes for this event
        $quizzes = [];
        $quizError = false;
        try {
            $quizStmt = $db->prepare("SELECT q.*, (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as question_count FROM quizzes q WHERE q.event_id = ? AND q.is_active = 1 ORDER BY q.created_at DESC");
            $quizStmt->execute([$eventId]);
            $quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Quiz tables may not be created yet - this is OK
            $quizError = true;
        }
        
        // Get user's quiz attempts
        $userQuizAttempts = [];
        try {
            $qaStmt = $db->prepare("SELECT quiz_id, COUNT(*) as attempts, MAX(percentage) as best_score, MAX(CASE WHEN status != 'in_progress' THEN 1 ELSE 0 END) as has_completed FROM quiz_attempts WHERE user_id = ? GROUP BY quiz_id");
            $qaStmt->execute([$_SESSION['user_id']]);
            while ($qa = $qaStmt->fetch(PDO::FETCH_ASSOC)) {
                $userQuizAttempts[$qa['quiz_id']] = $qa;
            }
        } catch (PDOException $e) {}
        
        // --- Task 4: Reading Materials ---
        $eventMaterials = [];
        try {
            $matStmt = $db->prepare("SELECT em.*,
                (SELECT 1 FROM material_reads mr WHERE mr.material_id = em.id AND mr.user_id = ? LIMIT 1) AS user_read
                FROM event_materials em
                WHERE em.event_id = ? AND em.is_active = 1
                  AND (em.available_from IS NULL OR em.available_from <= NOW())
                ORDER BY em.sort_order ASC, em.created_at ASC");
            $matStmt->execute([$_SESSION['user_id'], $eventId]);
            $eventMaterials = $matStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $eventMaterials = []; }

        // --- Task 5: Session Attendance (for completed sessions) ---
        $userAttendance = [];
        try {
            $attStmt = $db->prepare("SELECT session_id, attended FROM session_attendance WHERE user_id = ? AND event_id = ?");
            $attStmt->execute([$_SESSION['user_id'], $eventId]);
            while ($att = $attStmt->fetch(PDO::FETCH_ASSOC)) {
                $userAttendance[$att['session_id']] = (bool)$att['attended'];
            }
        } catch (PDOException $e) { $userAttendance = []; }

        // Count sessions by type using correct column names
        $tabLiveSessions = array_filter($tabSessions, function($s) { return $s['status'] === 'live'; });
        $tabUpcomingSessions = array_filter($tabSessions, function($s) { return strtotime($s['start_datetime']) > time() && $s['status'] !== 'completed'; });
        $tabRecordedSessions = array_filter($tabSessions, function($s) { return !empty($s['recording_url']); });
        
        // Content tabs are locked for any unpaid state (pending or rejected).
        // Proof-submitted users are still 'pending' in DB so they remain locked until admin verifies.
        $paymentLocked = in_array($registration['payment_status'], ['pending', 'rejected']);
        $registrationId = $registration['registration_id'];
        ?>
        
        <!-- Payment Lock Modal/Banner -->
        <script>
        function switchTab(tabName, clickedBtn) {
            document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
            clickedBtn.classList.add('active');
            var panel = document.getElementById('tab-' + tabName);
            if (panel) panel.classList.add('active');
        }
        </script>

        <!-- Locked Content Section with Blur Overlay -->
        <?php if ($paymentLocked): ?>
        <div class="content-locked-wrapper">
            <div class="blur-overlay">
                <div class="lock-icon-container">
                    <svg width="48" height="48" fill="none" viewBox="0 0 24 24">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" fill="currentColor"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
                    </svg>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="learner-tabs <?php echo $paymentLocked ? 'content-blurred' : ''; ?>">
            <div class="tabs-nav">
                <button class="tab-btn active" data-tab="sessions" onclick="switchTab('sessions', this)">
                    <?php if (count($tabSessions) > 0): ?>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <?php endif; ?>
                    Sessions
                    <?php if (count($tabLiveSessions) > 0): ?>
                    <span class="tab-badge live">LIVE</span>
                    <?php elseif (count($tabUpcomingSessions) > 0): ?>
                    <span class="tab-badge"><?php echo count($tabUpcomingSessions); ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" data-tab="assignments" onclick="switchTab('assignments', this)">
                    <?php $totalItems = count($assignments) + count($quizzes); if ($totalItems > 0): ?>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <?php endif; ?>
                    Assignments
                    <?php if ($totalItems > 0): ?>
                    <span class="tab-badge"><?php echo $totalItems; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" data-tab="materials" onclick="switchTab('materials', this)">
                    <?php if (!empty($eventMaterials)): ?>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    <?php endif; ?>
                    Materials
                    <?php if (!empty($eventMaterials)): ?>
                    <span class="tab-badge"><?php echo count($eventMaterials); ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" data-tab="certificate" onclick="switchTab('certificate', this)">
                    <?php if ($certificate): ?>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
                    <?php endif; ?>
                    Certificate
                </button>
            </div>
            
            <!-- Sessions Tab -->
            <div class="tab-content active" id="tab-sessions">
                <?php if (empty($tabSessions)): ?>
                <div class="empty-tab">
                    <div class="empty-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </div>
                    <h4>No Sessions Scheduled</h4>
                    <p>Sessions will appear here when scheduled by the instructor.</p>
                </div>
                <?php else: ?>
                <div class="sessions-list">
                    <?php foreach ($tabSessions as $session): 
                        $sessionTime = strtotime($session['start_datetime']);
                        $isLive = $session['status'] === 'live';
                        $isPast = $session['status'] === 'completed';
                        $hasRecording = !empty($session['recording_url']);
                    ?>
                    <div class="session-item <?php echo $isLive ? 'live' : ($isPast ? 'past' : 'upcoming'); ?>">
                        <div class="session-status">
                            <?php if ($isLive): ?>
                            <span class="status-live">
                                <span class="pulse"></span>LIVE
                            </span>
                            <?php elseif ($isPast): ?>
                            <span class="status-past"><?php echo $hasRecording ? 'Recorded' : 'Completed'; ?></span>
                            <?php else: ?>
                            <span class="status-upcoming">Upcoming</span>
                            <?php endif; ?>
                        </div>
                        <div class="session-info">
                            <h4><?php echo sanitize($session['title']); ?></h4>
                            <p class="session-time">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?php echo date('M j, Y \a\t g:i A', strtotime($session['start_datetime'])); ?>
                            </p>
                        </div>
                        <div class="session-actions">
                            <?php if ($isLive && !empty($session['meeting_link'])): ?>
                            <a href="<?php echo sanitize($session['meeting_link']); ?>" target="_blank" class="btn-join">
                                Join Now
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>
                            <?php elseif ($hasRecording): ?>
                            <a href="<?php echo sanitize($session['recording_url']); ?>" target="_blank" class="btn-watch">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Watch Recording
                            </a>
                            <?php elseif (!$isPast && !empty($session['meeting_link'])): ?>
                            <span class="session-countdown" data-time="<?php echo $session['start_datetime']; ?>">
                                Starts in <span class="countdown-timer"></span>
                            </span>
                            <?php endif; ?>
                            <?php if ($isPast):
                                $attended = $userAttendance[$session['id']] ?? null;
                            ?>
                            <?php if ($attended === true): ?>
                            <!-- Attended: solid green tick -->
                            <span class="att-icon att-icon--attended" title="You attended this session">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            </span>
                            <?php elseif ($attended === false): ?>
                            <!-- Absent: solid red cross -->
                            <span class="att-icon att-icon--absent" title="Marked as not attended">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            </span>
                            <?php else: ?>
                            <!-- Unmarked: tap to pick -->
                            <button class="att-icon att-icon--unmarked"
                                    data-session-id="<?php echo $session['id']; ?>"
                                    data-event-id="<?php echo $eventId; ?>"
                                    onclick="markAttendance(this)"
                                    title="Mark your attendance">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Materials Tab -->
            <div class="tab-content" id="tab-materials">
                <?php if (empty($eventMaterials)): ?>
                <div class="empty-tab">
                    <div class="empty-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <h4>No Materials Yet</h4>
                    <p>Reading materials will appear here when uploaded by the instructor.</p>
                </div>
                <?php else: ?>
                <div class="materials-list">
                    <?php foreach ($eventMaterials as $mat):
                        $isRead = (bool)$mat['user_read'];
                        $isLink = $mat['file_type'] === 'link';
                        $iconColor = match(strtolower($mat['file_type'])) {
                            'pdf'  => '#dc2626',
                            'doc', 'docx' => '#1d4ed8',
                            'ppt', 'pptx' => '#ea580c',
                            'link' => '#16a34a',
                            default => '#6b7280'
                        };
                    ?>
                    <div class="material-item <?php echo $isRead ? 'read' : ''; ?>" data-material-id="<?php echo $mat['id']; ?>">
                        <div class="material-icon-wrap" style="background:<?php echo $iconColor; ?>22; color:<?php echo $iconColor; ?>;">
                            <?php if ($isLink): ?>
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            <?php else: ?>
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="material-info">
                            <h4><?php echo sanitize($mat['title']); ?></h4>
                            <?php if ($mat['description']): ?>
                                <p><?php echo sanitize($mat['description']); ?></p>
                            <?php endif; ?>
                            <span class="material-type-badge"><?php echo strtoupper($mat['file_type']); ?></span>
                        </div>
                        <div class="material-action">
                            <?php if ($isRead): ?>
                                <span class="material-read-check" title="Already read">
                                    <svg width="16" height="16" fill="none" stroke="#16a34a" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    Read
                                </span>
                            <?php endif; ?>
                            <a href="<?php echo sanitize($mat['file_path']); ?>" target="_blank"
                               class="material-open-btn"
                               data-material-id="<?php echo $mat['id']; ?>"
                               data-event-id="<?php echo $eventId; ?>"
                               onclick="trackMaterialRead(this)">
                                <?php echo $isLink ? 'Open Link' : 'Open File'; ?>
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Assignments Tab -->
            <div class="tab-content" id="tab-assignments">
                <?php if (empty($assignments) && empty($quizzes)): ?>
                <div class="empty-tab">
                    <div class="empty-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h4>No Assignments Yet</h4>
                    <p>Assignments and quizzes will appear here when added by the instructor.</p>
                </div>
                <?php else: ?>
                
                <!-- Quizzes Section -->
                <?php if (!empty($quizzes)): ?>
                <div style="margin-bottom:20px;">
                    <h4 style="font-size:0.88rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                        <svg width="18" height="18" fill="none" stroke="#9B59B6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Quizzes
                    </h4>
                    <?php foreach ($quizzes as $qz):
                        $qAttempt = $userQuizAttempts[$qz['id']] ?? null;
                        $hasCompleted = $qAttempt && $qAttempt['has_completed'];
                        $attemptsUsed = $qAttempt ? (int)$qAttempt['attempts'] : 0;
                        $bestScore = $qAttempt ? $qAttempt['best_score'] : null;
                        $canTake = $attemptsUsed < $qz['max_attempts'] || !$hasCompleted;
                    ?>
                    <div style="background:var(--bg-primary);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:12px;border-left:4px solid #9B59B6;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <span style="background:linear-gradient(135deg,#9B59B6,#5B7FD1);color:#fff;padding:3px 10px;border-radius:12px;font-size:0.72rem;font-weight:700;">QUIZ</span>
                                    <h4 style="margin:0;font-size:1rem;font-weight:700;"><?php echo sanitize($qz['title']); ?></h4>
                                </div>
                                <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:6px;font-size:0.82rem;color:var(--text-muted);">
                                    <span><?php echo $qz['question_count']; ?> questions</span>
                                    <span><?php echo $qz['duration_minutes']; ?> min</span>
                                    <span><?php echo $attemptsUsed; ?>/<?php echo $qz['max_attempts']; ?> attempts</span>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <?php if ($hasCompleted && $bestScore !== null): ?>
                                <span style="background:#dcfce7;color:#16a34a;padding:6px 14px;border-radius:20px;font-size:0.82rem;font-weight:700;">Best: <?php echo round($bestScore); ?>%</span>
                                <?php endif; ?>
                                <?php if ($canTake): ?>
                                <a href="/user/take-quiz.php?quiz_id=<?php echo $qz['id']; ?>&event_id=<?php echo $eventId; ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 18px;background:linear-gradient(135deg,#9B59B6,#5B7FD1);color:#fff;border-radius:8px;font-weight:600;font-size:0.88rem;text-decoration:none;transition:all 0.2s;">
                                    <?php echo $hasCompleted ? 'Retake Quiz' : 'Start Quiz'; ?>
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </a>
                                <?php else: ?>
                                <span style="background:#f1f5f9;color:#64748b;padding:8px 14px;border-radius:8px;font-size:0.82rem;font-weight:600;">Max attempts reached</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif (!$quizError): ?>
                <!-- No quizzes but tables exist - just continue to assignments -->
                <?php endif; ?>
                
                <?php if ($quizError): ?>
                <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:12px;padding:16px;margin-bottom:20px;">
                    <div style="display:flex;gap:12px;align-items:flex-start;">
                        <svg width="20" height="20" fill="none" stroke="#d97706" style="flex-shrink:0;margin-top:2px;" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0-11a9 9 0 110 18 9 9 0 010-18z"/></svg>
                        <div>
                            <p style="margin:0;font-size:0.9rem;color:#92400e;font-weight:600;">Quiz system not yet initialized</p>
                            <p style="margin:4px 0 0;font-size:0.82rem;color:#b45309;">Quizzes will be available soon. Contact your instructor if this persists.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Assignments Section -->
                <?php if (!empty($assignments)): ?>
                <?php if (!empty($quizzes)): ?>
                <h4 style="font-size:0.88rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                    <svg width="18" height="18" fill="none" stroke="#5B7FD1" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Assignments
                </h4>
                <?php endif; ?>
                <div class="assignments-list">
                    <?php foreach ($assignments as $assignment): 
                        $submission = $userSubmissions[$assignment['id']] ?? null;
                        $hasDeadline = !empty($assignment['deadline']);
                        $deadlinePassed = $hasDeadline && strtotime($assignment['deadline']) < time();
                        $canSubmit = !$deadlinePassed && (!$submission || $submission['status'] === 'pending' || $submission['status'] === 'rejected');
                        $assignmentNumber = $assignment['number'] ?? ($assignments[0]['id'] == $assignment['id'] ? 1 : 2); // Use assignment number from DB or fallback
                    ?>
                    <div class="assignment-item">
                        <div class="assignment-header">
                            <div class="assignment-info">
                                <div class="assignment-title-row">
                                    <span class="assignment-number">Assignment <?php echo $assignmentNumber; ?></span>
                                    <h4><?php echo sanitize($assignment['title']); ?></h4>
                                </div>
                                <?php if ($assignment['description']): ?>
                                <p class="assignment-desc"><?php echo sanitize(substr($assignment['description'], 0, 150)); ?><?php echo strlen($assignment['description']) > 150 ? '...' : ''; ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="assignment-meta">
                                <?php if ($submission): ?>
                                    <?php if ($submission['status'] === 'approved'): ?>
                                    <span class="status-badge approved">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        Approved
                                    </span>
                                    <span class="score"><?php echo $submission['score']; ?>/<?php echo $assignment['max_score']; ?></span>
                                    <?php elseif ($submission['status'] === 'rejected'): ?>
                                    <span class="status-badge rejected">Rejected</span>
                                    <?php else: ?>
                                    <span class="status-badge pending">Under Review</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                <span class="status-badge not-submitted">Not Submitted</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($hasDeadline): ?>
                        <div class="deadline-row">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?php if ($deadlinePassed): ?>
                            <span class="deadline-text expired">Deadline passed: <?php echo date('M j, Y g:i A', strtotime($assignment['deadline'])); ?></span>
                            <?php else: ?>
                            <span class="deadline-text">Due: <?php echo date('M j, Y g:i A', strtotime($assignment['deadline'])); ?></span>
                            <span class="countdown-badge" data-deadline="<?php echo $assignment['deadline']; ?>"></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($submission && $submission['status'] === 'rejected' && !empty($submission['feedback'])): ?>
                        <div class="feedback-box rejected">
                            <strong>Feedback:</strong> <?php echo sanitize($submission['feedback']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($submission && $submission['status'] === 'approved' && !empty($submission['feedback'])): ?>
                        <div class="feedback-box approved">
                            <strong>Feedback:</strong> <?php echo sanitize($submission['feedback']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($canSubmit): ?>
                        <a href="/user/submit-assignment.php?assignment_id=<?php echo (int)$assignment['id']; ?>&event_id=<?php echo (int)$eventId; ?>&type=<?php echo urlencode($assignment['submission_type'] ?? 'file'); ?>" class="btn-submit-link">
                            <?php echo $submission ? 'Resubmit Assignment' : 'Submit Assignment'; ?>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Certificate Tab -->
            <div class="tab-content" id="tab-certificate">
                <?php if ($certificate): ?>
                <!-- Certificate Available Card -->
                <div class="cert-available-card">
                    <div class="cert-card-header">
                        <img src="/images/certificates/verified.png" alt="Certificate Verified" class="cert-badge-img cert-badge-verified">
                    </div>
                    <h3 class="cert-card-title">Certificate Available</h3>
                    <p class="cert-card-subtitle">Congratulations! You have earned your certificate for this event.</p>
                    <div class="cert-code-box"><?php echo sanitize($certificate['certificate_code']); ?></div>
                    <a href="/certificate/download?code=<?php echo urlencode($certificate['certificate_code']); ?>" class="cert-download-btn">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Download Certificate
                    </a>
                </div>
                <?php else: ?>
                <!-- Certificate Not Available Card -->
                <div class="cert-unavailable-card">
                    <div class="cert-card-header">
                        <img src="/images/certificates/notverified.png" alt="Certificate Not Available" class="cert-badge-img cert-badge-notverified">
                    </div>
                    <h3 class="cert-card-title-gray">Certificate Not Available</h3>
                    <p class="cert-card-subtitle-gray">Your certificate will be issued after the event is completed.</p>
                    <p class="cert-eligibility-note">Please note that only this event is eligible for certification</p>
                    <button class="cert-locked-btn" disabled>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Certificate Not Yet Issued
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
        // Global variables
        let currentAssignment = null;
        
        // Tab switching function - defined early so onclick handlers work
        function switchTab(tabName, clickedBtn) {
            document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
            clickedBtn.classList.add('active');
            var panel = document.getElementById('tab-' + tabName);
            if (panel) panel.classList.add('active');
        }
        
            const fileDropArea = document.getElementById('fileDropArea');
            const fileInput = document.getElementById('submissionFile');
            
            if (fileDropArea && fileInput) {
                // Browse button click
                fileDropArea.querySelector('span')?.addEventListener('click', () => fileInput.click());
                
                // Drag and drop
                fileDropArea.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    fileDropArea.style.opacity = '0.8';
                });
                
                fileDropArea.addEventListener('dragleave', () => {
                    fileDropArea.style.opacity = '1';
                });
                
                fileDropArea.addEventListener('drop', (e) => {
                    e.preventDefault();
                    fileDropArea.style.opacity = '1';
                    if (e.dataTransfer.files.length) {
                        fileInput.files = e.dataTransfer.files;
                        updateFilePreview();
                    }
                });
                
                fileInput.addEventListener('change', updateFilePreview);
            }
        });
        
        function updateFilePreview() {
            const fileInput = document.getElementById('submissionFile');
            const filePreview = document.getElementById('filePreview');
            const fileDropArea = document.getElementById('fileDropArea');
            
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                filePreview.innerHTML = '<div style="padding:12px;background:#f0f0f0;border-radius:8px;font-size:0.9rem;"><strong>' + file.name + '</strong> (' + fileSize + ' MB)</div>';
                filePreview.style.display = 'block';
                fileDropArea.style.opacity = '0.5';
            } else {
                filePreview.style.display = 'none';
                fileDropArea.style.opacity = '1';
            }
        }
        </script>

        
        <!-- Feedback Section -->
        <?php echo showFlash(); ?>
        
        <div class="feedback-card <?php echo $existingFeedback ? 'submitted' : ''; ?>">
            <?php if ($existingFeedback): ?>
            <!-- Already submitted feedback -->
            <h4>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Feedback Submitted
                <span class="fb-status-badge <?php echo $existingFeedback['is_approved'] ? 'published' : 'pending'; ?>">
                    <?php echo $existingFeedback['is_approved'] ? 'Published' : 'Under Review'; ?>
                </span>
            </h4>
            <p>Thank you for sharing your experience!</p>
            
            <div class="fb-submitted-view">
                <div class="fb-submitted-rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="<?php echo $i > $existingFeedback['rating'] ? 'empty' : ''; ?>">&#9733;</span>
                    <?php endfor; ?>
                </div>
                <p class="fb-submitted-text">"<?php echo sanitize($existingFeedback['feedback_text']); ?>"</p>
                <span class="fb-submitted-meta">Submitted <?php echo timeAgo($existingFeedback['created_at']); ?></span>
            </div>
            
            <?php elseif ($eventStatusClass === 'completed'): ?>
            <!-- Show feedback form for completed events -->
            <h4>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                Share Your Feedback
            </h4>
            <p>How was your experience at this event? Your feedback helps us improve!</p>
            
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="submit_feedback">
                
                <div class="fb-form-group">
                    <label>Your Rating</label>
                    <div class="fb-star-rating">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" name="rating" id="fb_star<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $i === 5 ? 'checked' : ''; ?>>
                        <label for="fb_star<?php echo $i; ?>">&#9733;</label>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="fb-form-group">
                    <label>Your Role/Title (Optional)</label>
                    <input type="text" name="role_title" placeholder="e.g. Student, Professional, Entrepreneur">
                </div>
                
                <div class="fb-form-group">
                    <label>Your Feedback <span style="color:#dc2626">*</span></label>
                    <textarea name="feedback_text" required placeholder="Share what you liked, what you learned, and any suggestions..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary btn-full">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    Submit Feedback
                </button>
            </form>
            
            <?php else: ?>
            <!-- Event not completed yet -->
            <h4>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                Event Feedback
            </h4>
            <div class="fb-unavailable">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p>Feedback will be available after the event is completed.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Announcements -->
        <?php if (!empty($announcements)): ?>
        <h3 class="section-header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
            Announcements
        </h3>
        
        <div class="announcements-list">
            <?php foreach ($announcements as $ann): ?>
            <div class="ann-item <?php echo ($ann['label'] ?? '') === 'important' ? 'important' : ''; ?>">
                <h4 class="ann-title"><?php echo sanitize($ann['title']); ?></h4>
                <p class="ann-content"><?php echo nl2br(sanitize($ann['content'])); ?></p>
                <span class="ann-date"><?php echo date('M d, Y \a\t h:i A', strtotime($ann['created_at'])); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- WhatsApp Group Link -->
        <?php 
        // Debug: Check WhatsApp conditions
        $isFreeEvent = $registration['amount'] == 0;
        $paymentStatuses = ['success', 'paid', 'verified', 'completed'];
        $isPaid = in_array($registration['payment_status'], $paymentStatuses);
        $hasWhatsAppLink = !empty($registration['whatsapp_group_link']);
        
        $shouldShowWhatsApp = $hasWhatsAppLink && ($isPaid || $isFreeEvent);
        ?>
        <?php if ($shouldShowWhatsApp): ?>
        <div class="whatsapp-group-card">
            <div class="whatsapp-group-header">
                <svg viewBox="0 0 24 24" fill="currentColor" width="28" height="28"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                <h4>Join WhatsApp Group</h4>
                <p>Connect with fellow participants</p>
            </div>
            <button onclick="openWhatsAppModal()" class="whatsapp-group-btn">
                <span>Join Now</span>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            </button>
            <p class="whatsapp-group-hint">Get real-time updates, connect with participants, and get event reminders in the group.</p>
        </div>
        
        <!-- WhatsApp Popup Modal -->
        <div id="whatsappModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 999; align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 16px; padding: 32px; max-width: 500px; width: 90%; animation: slideUp 0.3s ease-out; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                <div style="text-align: center;">
                    <button onclick="closeWhatsAppModal()" style="position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 28px; cursor: pointer; color: #999;">×</button>
                    <svg viewBox="0 0 24 24" fill="#25D366" width="64" height="64" style="margin: 0 auto 16px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    <h3 style="margin: 16px 0 8px; font-size: 1.3rem; color: #1a1a1a;">Join WhatsApp Group</h3>
                    <p style="color: #666; margin: 0 0 24px; line-height: 1.6;">Connect with fellow participants, get event updates, and access exclusive group resources.</p>
                    
                    <a href="<?php echo htmlspecialchars($registration['whatsapp_group_link']); ?>" target="_blank" rel="noopener noreferrer" onclick="closeWhatsAppModal()" style="display: inline-block; background: #25D366; color: white; padding: 14px 48px; text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.2s; margin-bottom: 12px;">
                        Open WhatsApp Group
                    </a>
                    
                    <p style="color: #999; font-size: 0.85rem; margin-top: 16px;">You'll be directed to WhatsApp to join the group.</p>
                </div>
            </div>
        </div>
        
        <style>
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
        </style>
        
        <script>
        function openWhatsAppModal() {
            document.getElementById('whatsappModal').style.display = 'flex';
        }
        function closeWhatsAppModal() {
            document.getElementById('whatsappModal').style.display = 'none';
        }
        document.getElementById('whatsappModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeWhatsAppModal();
        });
        
        // Auto-open WhatsApp modal if returning from payment success
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('show_whatsapp') === '1') {
                setTimeout(() => openWhatsAppModal(), 300);
            }
        });
        </script>
        <?php endif; ?>
        
        <!-- Support Contact -->
        <?php if (!empty($registration['support_whatsapp']) || !empty($registration['support_phone'])): ?>
        <div class="support-card">
            <h4 class="support-title">Need Help?</h4>
            <div class="support-links">
                <?php if (!empty($registration['support_whatsapp'])): ?>
                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $registration['support_whatsapp']); ?>" target="_blank" class="support-btn whatsapp">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    WhatsApp Support
                </a>
                <?php endif; ?>
                <?php if (!empty($registration['support_phone'])): ?>
                <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $registration['support_phone']); ?>" class="support-btn phone">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    Call Support
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Assignment Submission Modal -->
<div class="modal-overlay" id="submissionModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <div class="modal-assignment-number" id="submissionModalNumber"></div>
                <h2 id="submissionModalTitle">Submit Assignment</h2>
                <p class="modal-submission-type" id="submissionModalType"></p>
            </div>
            <button class="modal-close" onclick="closeSubmissionModal()">&times;</button>
        </div>
        <form method="POST" action="/user/submit-assignment.php" enctype="multipart/form-data" id="submissionForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="assignment_id" id="submissionAssignmentId">
            <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
            
            <div class="modal-body">
                <p id="submissionDesc" class="submission-description"></p>
                <div class="submission-progress" id="submissionProgress">
                    <div class="progress-step active">
                        <span>1</span>
                        <p>Fill Details</p>
                    </div>
                    <div class="progress-step">
                        <span>2</span>
                        <p>Review & Submit</p>
                    </div>
                </div>
                
                <!-- File Upload -->
                <div id="fileUploadSection" class="submission-section" style="display:none;">
                    <label class="form-label">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        Upload Your Assignment File
                    </label>
                    <div class="file-upload-area" id="fileDropArea">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        <p class="file-upload-main">Drag and drop your file here, or <span style="color:var(--blue);cursor:pointer;font-weight:600;">browse</span></p>
                        <p class="file-hint" id="fileHint"><span id="allowedExtensions">PDF, DOC, DOCX, PPT, XLS, etc.</span> (Max: <span id="maxSize">50</span>MB)</p>
                        <input type="file" name="submission_file" id="submissionFile" style="display:none;">
                    </div>
                    <div id="filePreview" class="file-preview-box" style="display:none;"></div>
                    <div class="field-error" id="fileError" style="display:none;"></div>
                </div>
                
                <!-- Text Entry -->
                <div id="textEntrySection" class="submission-section" style="display:none;">
                    <label class="form-label">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Write Your Response
                    </label>
                    <div class="text-editor-wrapper">
                        <textarea name="text_content" id="textContent" rows="8" placeholder="Write your response here... (minimum 10 characters)" class="text-editor" maxlength="5000"></textarea>
                        <div class="char-count"><span id="charCount">0</span>/5000</div>
                    </div>
                    <div class="field-error" id="textError" style="display:none;"></div>
                </div>
                
                <!-- URL Entry -->
                <div id="urlEntrySection" class="submission-section" style="display:none;">
                    <label class="form-label">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        Submission Link
                    </label>
                    <input type="url" name="url_content" id="urlContent" placeholder="https://example.com/your-submission" class="form-input" autocomplete="url">
                    <p class="form-hint">Provide a valid link to your submission (GitHub, Google Drive, portfolio, etc.)</p>
                    <div class="field-error" id="urlError" style="display:none;"></div>
                </div>
                
                <!-- Photo Capture -->
                <div id="photoCaptureSection" class="submission-section" style="display:none;">
                    <label class="form-label">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Capture Photo
                    </label>
                    <div id="cameraContainer">
                        <video id="cameraPreview" autoplay playsinline class="camera-preview" style="display:none;"></video>
                        <canvas id="photoCanvas" style="display:none;"></canvas>
                        <img id="capturedPhoto" class="captured-photo-preview" style="display:none;">
                        <input type="hidden" name="photo_data" id="photoData">
                    </div>
                    <div class="camera-controls">
                        <button type="button" id="startCameraBtn" class="btn-camera-primary" onclick="startCamera()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Start Camera
                        </button>
                        <button type="button" id="captureBtn" class="btn-camera-capture" onclick="capturePhoto()" style="display:none;">
                            <svg fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="6"/><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 18a8 8 0 110-16 8 8 0 010 16z"/></svg>
                            Capture Photo
                        </button>
                        <button type="button" id="retakeBtn" class="btn-camera-secondary" onclick="retakePhoto()" style="display:none;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Retake Photo
                        </button>
                    </div>
                    <div class="field-error" id="photoError" style="display:none;"></div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeSubmissionModal()">Cancel</button>
                <button type="submit" class="btn-submit-modal" id="submitAssignmentBtn">
                    <span class="btn-text">Submit Assignment for Review</span>
                    <span class="btn-loader" style="display:none;">
                        <svg class="spinner" viewBox="0 0 50 50"><circle cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle></svg>
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.2s ease;
}
.modal-overlay.active { display: flex; }
.modal-content {
    background: var(--bg-primary);
    border-radius: 16px;
    max-width: 540px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    animation: slideUp 0.3s ease;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.modal-header {
    padding: 24px 24px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: relative;
}

.modal-header > div { flex: 1; }
.modal-assignment-number {
    display: inline-block;
    padding: 4px 10px;
    background: #e3f2fd;
    color: #1e88e5;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}
.modal-header h2 { 
    margin: 0 0 4px; 
    font-size: 1.3rem; 
    font-weight: 800;
    color: var(--text-primary);
}
.modal-submission-type {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.modal-submission-type svg {
    width: 16px;
    height: 16px;
    color: var(--blue);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.8rem;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}
.modal-close:hover {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
}

.modal-body { 
    padding: 24px;
}

.submission-description {
    color: var(--text-muted);
    margin-bottom: 20px;
    line-height: 1.5;
    font-size: 0.95rem;
}

.submission-progress {
    display: flex;
    gap: 20px;
    margin-bottom: 28px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    opacity: 0.4;
    transition: opacity 0.3s;
}

.progress-step.active {
    opacity: 1;
}

.progress-step span {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #e3f2fd;
    color: #1e88e5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.9rem;
}

.progress-step.active span {
    background: #1e88e5;
    color: white;
}

.progress-step p {
    margin: 0;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-align: center;
}

.submission-section {
    display: none;
}

.submission-section.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

.form-label {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    font-weight: 700;
    color: var(--text-primary);
    font-size: 0.95rem;
}

.form-label svg {
    width: 20px;
    height: 20px;
    color: var(--blue);
    flex-shrink: 0;
}

.form-input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
    font-family: inherit;
    transition: all 0.2s;
}

.form-input:focus {
    outline: none;
    border-color: var(--blue);
    background: rgba(30, 136, 229, 0.02);
}

.form-hint {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-top: 8px;
    display: block;
}

.field-error {
    padding: 10px 12px;
    background: #ffebee;
    color: #c62828;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.file-upload-area {
    border: 2px dashed var(--border);
    border-radius: 12px;
    padding: 40px 24px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: var(--bg-tertiary);
}

.file-upload-area:hover {
    border-color: var(--blue);
    background: rgba(30, 136, 229, 0.05);
}

.file-upload-area.dragover {
    border-color: var(--blue);
    background: rgba(30, 136, 229, 0.1);
    transform: scale(1.02);
}

.file-upload-area svg {
    width: 48px;
    height: 48px;
    color: var(--blue);
    margin-bottom: 12px;
    opacity: 0.7;
}

.file-upload-main {
    margin: 0 0 8px;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 0.95rem;
}

.file-hint { 
    font-size: 0.85rem; 
    color: var(--text-muted);
    margin: 0;
}

.file-preview-box {
    padding: 12px;
    background: #f5f5f5;
    border-radius: 8px;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
}

.file-preview-box svg {
    width: 20px;
    height: 20px;
    color: var(--green);
    flex-shrink: 0;
}

.file-preview-box strong {
    color: var(--text-primary);
}

.text-editor-wrapper {
    position: relative;
}

.text-editor {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
    font-family: inherit;
    resize: vertical;
    transition: all 0.2s;
}

.text-editor:focus {
    outline: none;
    border-color: var(--blue);
    background: rgba(30, 136, 229, 0.02);
}

.char-count {
    font-size: 0.8rem;
    color: var(--text-muted);
    text-align: right;
    margin-top: 6px;
}

.camera-preview {
    width: 100%;
    border-radius: 12px;
    background: #000;
    aspect-ratio: 4/3;
    object-fit: cover;
}

.captured-photo-preview {
    width: 100%;
    border-radius: 12px;
    aspect-ratio: 4/3;
    object-fit: cover;
}

.camera-controls {
    margin-top: 12px;
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

.btn-camera-primary,
.btn-camera-capture,
.btn-camera-secondary {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.btn-camera-primary {
    background: var(--blue);
    color: white;
}

.btn-camera-primary:hover {
    background: #1565c0;
    transform: translateY(-1px);
}

.btn-camera-primary svg {
    width: 18px;
    height: 18px;
}

.btn-camera-capture {
    background: #e74c3c;
    color: white;
}

.btn-camera-capture:hover {
    background: #c0392b;
}

.btn-camera-capture svg {
    width: 20px;
    height: 20px;
}

.btn-camera-secondary {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
}

.btn-camera-secondary:hover {
    background: var(--border);
}

.btn-camera-secondary svg {
    width: 18px;
    height: 18px;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--bg-tertiary);
}

.btn-cancel {
    padding: 10px 20px;
    background: transparent;
    color: var(--text-secondary);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.btn-cancel:hover {
    background: var(--bg-tertiary);
    border-color: var(--text-muted);
}

.btn-submit-modal {
    padding: 10px 24px;
    background: var(--blue);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 200px;
}

.btn-submit-modal:hover:not(:disabled) {
    background: #1565c0;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(30, 136, 229, 0.3);
}

.btn-submit-modal:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.btn-text {
    display: inline;
}

.btn-loader {
    display: inline;
}

.spinner {
    width: 18px;
    height: 18px;
    stroke: white;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

</style>
    padding: 12px 28px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(37,99,235,0.3);
}
.btn-submit-modal:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(37,99,235,0.4);
}
.btn-submit-modal:active {
    transform: translateY(0);
}
.btn-camera {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: var(--blue);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}
.btn-camera.secondary {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
}
.btn-camera svg { width: 18px; height: 18px; }
</style>

<script>
// Tab Navigation — explicit function so onclick works without event binding timing issues
function switchTab(tabName, clickedBtn) {
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
    clickedBtn.classList.add('active');
    var panel = document.getElementById('tab-' + tabName);
    if (panel) panel.classList.add('active');
}
// Also wire up via querySelectorAll as a fallback
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            switchTab(btn.dataset.tab, btn);
        });
    });
});

// Countdown timers for deadlines
document.querySelectorAll('[data-deadline]').forEach(el => {
    const deadline = new Date(el.dataset.deadline);
    function updateCountdown() {
        const now = new Date();
        const diff = deadline - now;
        if (diff <= 0) {
            el.textContent = 'Deadline passed';
            el.style.background = '#dc2626';
            return;
        }
        const days = Math.floor(diff / 86400000);
        const hours = Math.floor((diff % 86400000) / 3600000);
        const mins = Math.floor((diff % 3600000) / 60000);
        if (days > 0) el.textContent = days + 'd ' + hours + 'h left';
        else if (hours > 0) el.textContent = hours + 'h ' + mins + 'm left';
        else el.textContent = mins + 'm left';
    }
    updateCountdown();
    setInterval(updateCountdown, 60000);
});

// Session countdown timers
document.querySelectorAll('.session-countdown').forEach(el => {
    const time = new Date(el.dataset.time);
    const timerSpan = el.querySelector('.countdown-timer');
    function update() {
        const diff = time - new Date();
        if (diff <= 0) { el.innerHTML = 'Starting soon...'; return; }
        const d = Math.floor(diff / 86400000);
        const h = Math.floor((diff % 86400000) / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        if (d > 0) timerSpan.textContent = d + 'd ' + h + 'h';
        else if (h > 0) timerSpan.textContent = h + 'h ' + m + 'm';
        else timerSpan.textContent = m + ' min';
    }
    update();
    setInterval(update, 60000);
});

// Assignment Submission Modal - functions defined in early script block above

// Close modal when clicking outside the modal-content
document.addEventListener('click', function(e) {
    const modal = document.getElementById('submissionModal');
    if (e.target === modal) {
        closeSubmissionModal();
    }
});

// File upload handling
const fileDropArea = document.getElementById('fileDropArea');
const fileInput = document.getElementById('submissionFile');
const filePreview = document.getElementById('filePreview');

if (fileDropArea) {
    fileDropArea.addEventListener('click', () => fileInput.click());
    fileDropArea.addEventListener('dragover', e => { e.preventDefault(); fileDropArea.classList.add('dragover'); });
    fileDropArea.addEventListener('dragleave', () => fileDropArea.classList.remove('dragover'));
    fileDropArea.addEventListener('drop', e => {
        e.preventDefault();
        fileDropArea.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            showFilePreview(e.dataTransfer.files[0]);
        }
    });
    fileInput.addEventListener('change', e => {
        if (e.target.files.length) showFilePreview(e.target.files[0]);
    });
}

function showFilePreview(file) {
    const sizeMB = (file.size / 1048576).toFixed(2);
    filePreview.innerHTML = '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg-tertiary);border-radius:8px;"><svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg><div><strong>' + file.name + '</strong><br><span style="font-size:0.85rem;color:var(--text-muted);">' + sizeMB + ' MB</span></div></div>';
    filePreview.style.display = 'block';
}

// Camera handling
function startCamera() {
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(stream => {
            cameraStream = stream;
            const video = document.getElementById('cameraPreview');
            video.srcObject = stream;
            video.style.display = 'block';
            document.getElementById('startCameraBtn').style.display = 'none';
            document.getElementById('captureBtn').style.display = 'inline-flex';
        })
        .catch(err => alert('Camera access denied: ' + err.message));
}

function capturePhoto() {
    const video = document.getElementById('cameraPreview');
    const canvas = document.getElementById('photoCanvas');
    const img = document.getElementById('capturedPhoto');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    
    const dataUrl = canvas.toDataURL('image/jpeg', 0.8);
    document.getElementById('photoData').value = dataUrl;
    img.src = dataUrl;
    img.style.display = 'block';
    video.style.display = 'none';
    
    document.getElementById('captureBtn').style.display = 'none';
    document.getElementById('retakeBtn').style.display = 'inline-flex';
    stopCamera();
}

function retakePhoto() {
    document.getElementById('capturedPhoto').style.display = 'none';
    document.getElementById('photoData').value = '';
    document.getElementById('retakeBtn').style.display = 'none';
    startCamera();
}

function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(t => t.stop());
        cameraStream = null;
    }
}

// Form submission validation
document.getElementById('submissionForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const fileSection = document.getElementById('fileUploadSection');
    const textSection = document.getElementById('textEntrySection');
    const urlSection = document.getElementById('urlEntrySection');
    const photoSection = document.getElementById('photoCaptureSection');
    
    const submitBtn = document.getElementById('submitAssignmentBtn');
    let isValid = true;
    let errorField = null;
    
    // Clear previous errors
    document.querySelectorAll('.field-error').forEach(el => el.style.display = 'none');
    
    // Validate file upload
    if (fileSection && fileSection.style.display !== 'none') {
        const fileInput = document.getElementById('submissionFile');
        const fileError = document.getElementById('fileError');
        
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            fileError.textContent = '✕ Please select a file to upload.';
            fileError.style.display = 'block';
            isValid = false;
            errorField = fileError;
        } else {
            const file = fileInput.files[0];
            const maxSizeMB = parseInt(document.getElementById('maxSize').textContent) || 50;
            const maxSizeBytes = maxSizeMB * 1024 * 1024;
            
            if (file.size > maxSizeBytes) {
                fileError.textContent = '✕ File size exceeds ' + maxSizeMB + 'MB limit.';
                fileError.style.display = 'block';
                isValid = false;
                errorField = fileError;
            }
        }
    }
    // Validate text submission
    else if (textSection && textSection.style.display !== 'none') {
        const textInput = document.getElementById('textContent');
        const textError = document.getElementById('textError');
        const text = textInput?.value.trim() || '';
        
        if (!text) {
            textError.textContent = '✕ Please enter your response.';
            textError.style.display = 'block';
            isValid = false;
            errorField = textError;
        } else if (text.length < 10) {
            textError.textContent = '✕ Response must be at least 10 characters long.';
            textError.style.display = 'block';
            isValid = false;
            errorField = textError;
        }
    }
    // Validate URL submission
    else if (urlSection && urlSection.style.display !== 'none') {
        const urlInput = document.getElementById('urlContent');
        const urlError = document.getElementById('urlError');
        const url = urlInput?.value.trim() || '';
        
        if (!url) {
            urlError.textContent = '✕ Please enter a submission URL.';
            urlError.style.display = 'block';
            isValid = false;
            errorField = urlError;
        } else if (!isValidUrl(url)) {
            urlError.textContent = '✕ Please enter a valid URL (must start with http:// or https://).';
            urlError.style.display = 'block';
            isValid = false;
            errorField = urlError;
        }
    }
    // Validate photo capture
    else if (photoSection && photoSection.style.display !== 'none') {
        const photoData = document.getElementById('photoData');
        const photoError = document.getElementById('photoError');
        
        if (!photoData || !photoData.value) {
            photoError.textContent = '✕ Please capture a photo first.';
            photoError.style.display = 'block';
            isValid = false;
            errorField = photoError;
        }
    }
    
    if (!isValid) {
        if (errorField) {
            errorField.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        return false;
    }
    
    // All validation passed - submit the form
    submitBtn.disabled = true;
    submitBtn.querySelector('.btn-text').style.display = 'none';
    submitBtn.querySelector('.btn-loader').style.display = 'inline';
    
    // Submit the form
    setTimeout(() => {
        this.submit();
    }, 300);
});

// Real-time text counter
document.getElementById('textContent')?.addEventListener('input', function() {
    const count = this.value.length;
    document.getElementById('charCount').textContent = count;
});

// URL validation function
function isValidUrl(string) {
    try {
        const url = new URL(string);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch (_) {
        return false;
    }
}

document.getElementById('submissionModal').addEventListener('click', e => {
    if (e.target === document.getElementById('submissionModal')) closeSubmissionModal();
});
</script>

<!-- Ed Nav Drawer Overlay -->
<div class="ed-nav-overlay" id="edNavOverlay"></div>

<!-- Ed Slide-in Nav Drawer -->
<div class="ed-nav-drawer" id="edNavDrawer" role="dialog" aria-modal="true" aria-label="Navigation menu">
    <div class="ed-drawer-header">
        <strong>Menu</strong>
        <button class="ed-drawer-close" id="edDrawerClose" aria-label="Close menu">
            <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="ed-drawer-user">
        <?php if (!empty($user['profile_image'])): ?>
            <img src="<?php echo sanitize($user['profile_image']); ?>" alt="Profile" class="ed-drawer-avatar">
        <?php else: ?>
            <div class="ed-drawer-avatar" style="background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;"><?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?></div>
        <?php endif; ?>
        <div class="ed-drawer-user-info">
            <strong><?php echo sanitize($user['name']); ?></strong>
            <span><?php echo sanitize($user['email']); ?></span>
        </div>
    </div>
    <nav class="ed-drawer-nav">
        <a href="/user/dashboard">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            My Events
        </a>
        <a href="/user/certificates">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            Certificates
        </a>
        <a href="/user/feedback" class="active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            Feedback
        </a>
        <a href="/user/profile">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
    </nav>
    <div class="ed-drawer-logout">
        <a href="/auth/logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </div>
</div>

<script>
// Task 5: Session Attendance marking
function markAttendance(btn) {
    var sessionId = btn.getAttribute('data-session-id');
    var eventId   = btn.getAttribute('data-event-id');

    // Show inline picker
    var picker = document.createElement('div');
    picker.className = 'att-picker';
    picker.style.cssText = 'position:absolute;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.15);padding:6px;display:flex;flex-direction:column;gap:2px;z-index:999;min-width:168px;right:0;top:42px;';
    picker.innerHTML =
        '<button onclick="setAttendance('+sessionId+','+eventId+',1,this.closest(\'.att-picker\'))" style="padding:9px 14px;border:none;background:none;color:#15803d;font-weight:600;font-size:0.84rem;text-align:left;cursor:pointer;border-radius:7px;display:flex;align-items:center;gap:9px;" onmouseover="this.style.background=\'#f0fdf4\'" onmouseout="this.style.background=\'none\'">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>I Attended</button>' +
        '<button onclick="setAttendance('+sessionId+','+eventId+',0,this.closest(\'.att-picker\'))" style="padding:9px 14px;border:none;background:none;color:#dc2626;font-weight:600;font-size:0.84rem;text-align:left;cursor:pointer;border-radius:7px;display:flex;align-items:center;gap:9px;" onmouseover="this.style.background=\'#fef2f2\'" onmouseout="this.style.background=\'none\'">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>I Did Not Attend</button>';

    btn.style.position = 'relative';
    btn.appendChild(picker);

    // Close picker on outside click
    setTimeout(function() {
        document.addEventListener('click', function removePicker(e) {
            if (!btn.contains(e.target)) {
                picker.remove();
                document.removeEventListener('click', removePicker);
            }
        });
    }, 0);
}

function setAttendance(sessionId, eventId, attended, picker) {
    if (picker) picker.remove();
    var btn = document.querySelector('.att-icon--unmarked[data-session-id="'+sessionId+'"]');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }

    fetch('/api/user/mark-attendance.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'session_id='+sessionId+'&event_id='+eventId+'&attended='+attended+'&csrf_token=<?php echo generateCSRFToken(); ?>'
    }).then(r => r.json()).then(data => {
        if (data.success && btn) {
            // Replace the button element with a static icon span
            var icon = document.createElement('span');
            if (attended) {
                icon.className = 'att-icon att-icon--attended';
                icon.title = 'You attended this session';
                icon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
            } else {
                icon.className = 'att-icon att-icon--absent';
                icon.title = 'Marked as not attended';
                icon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>';
            }
            btn.replaceWith(icon);
        } else if (!data.success) {
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
            alert(data.error || 'Could not save attendance. Please try again.');
        }
    }).catch(() => {
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        alert('Network error. Please check your connection and try again.');
    });
}

// Task 4: Track material read on first click
function trackMaterialRead(link) {
    var materialId = link.getAttribute('data-material-id');
    var eventId = link.getAttribute('data-event-id');
    var item = link.closest('.material-item');
    if (item && !item.classList.contains('read')) {
        fetch('/api/user/track-material-read.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'material_id='+materialId+'&event_id='+eventId+'&csrf_token=<?php echo generateCSRFToken(); ?>'
        }).then(r => r.json()).then(data => {
            if (data.success) {
                item.classList.add('read');
                var action = item.querySelector('.material-action');
                if (action && !action.querySelector('.material-read-check')) {
                    var readSpan = document.createElement('span');
                    readSpan.className = 'material-read-check';
                    readSpan.innerHTML = '<svg width="16" height="16" fill="none" stroke="#16a34a" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>Read';
                    action.insertBefore(readSpan, action.firstChild);
                }
            }
        }).catch(() => {});
    }
}

// Ed page burger menu drawer
(function() {
    const burgerBtn = document.getElementById('edBurgerBtn');
    const drawer = document.getElementById('edNavDrawer');
    const overlay = document.getElementById('edNavOverlay');
    const closeBtn = document.getElementById('edDrawerClose');

    function openDrawer() {
        drawer.classList.add('open');
        overlay.classList.add('open');
        burgerBtn && burgerBtn.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        drawer.classList.remove('open');
        overlay.classList.remove('open');
        burgerBtn && burgerBtn.classList.remove('open');
        document.body.style.overflow = '';
    }

    burgerBtn && burgerBtn.addEventListener('click', openDrawer);
    closeBtn && closeBtn.addEventListener('click', closeDrawer);
    overlay && overlay.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDrawer();
    });
})();
</script>

<!-- User Bottom Navigation -->
<?php include __DIR__ . '/../includes/user_nav.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
