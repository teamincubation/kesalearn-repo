<?php
/**
 * KESA Learn - User Feedback
 * Mobile-first professional design
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db = getDB();
$userId = $_SESSION['user_id'];
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/user/feedback');
    }
    
    $eventId = intval($_POST['event_id'] ?? 0);
    $feedbackText = sanitize($_POST['feedback_text'] ?? '');
    $rating = min(5, max(1, intval($_POST['rating'] ?? 5)));
    
    if (empty($feedbackText)) {
        setFlash('error', 'Please write your feedback.');
        redirect('/user/feedback');
    }
    
    if ($eventId <= 0) {
        setFlash('error', 'Please select an event.');
        redirect('/user/feedback');
    }
    
    // Verify user actually registered for this event (any payment status)
    $checkStmt = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ?");
    $checkStmt->execute([$userId, $eventId]);
    if (!$checkStmt->fetch()) {
        setFlash('error', 'Invalid event selection.');
        redirect('/user/feedback');
    }
    
    // Check if feedback already exists for this event
    $existingStmt = $db->prepare("SELECT id FROM feedbacks WHERE user_id = ? AND event_id = ?");
    $existingStmt->execute([$userId, $eventId]);
    if ($existingStmt->fetch()) {
        setFlash('error', 'You have already submitted feedback for this event.');
        redirect('/user/feedback');
    }
    
    $stmt = $db->prepare("INSERT INTO feedbacks (user_id, event_id, name, feedback_text, rating, is_approved, added_by_admin) VALUES (?, ?, ?, ?, ?, 0, 0)");
    $stmt->execute([$userId, $eventId, $user['name'], $feedbackText, $rating]);
    
    logActivity('feedback_submitted', "User submitted feedback for event ID: $eventId", $userId);
    setFlash('success', 'Thank you for your feedback! It will appear on the website after admin approval.');
    redirect('/user/feedback');
}

// Get all events the user has registered for
$completedEvents = $db->prepare("
    SELECT DISTINCT e.id, e.title, e.type, e.start_date, e.end_date, e.banner_image,
           (SELECT COUNT(*) FROM feedbacks WHERE user_id = ? AND event_id = e.id) as has_feedback
    FROM registrations r
    JOIN events e ON r.event_id = e.id
    WHERE r.user_id = ?
    ORDER BY e.start_date DESC
");
$completedEvents->execute([$userId, $userId]);
$availableEvents = $completedEvents->fetchAll();

// Separate events into pending (can submit) and completed (already submitted) feedback
$pendingFeedbackEvents = array_filter($availableEvents, fn($e) => $e['has_feedback'] == 0);
$submittedFeedbackEvents = array_filter($availableEvents, fn($e) => $e['has_feedback'] > 0);

// Check existing user feedbacks with full details
$myFeedbacks = $db->prepare("
    SELECT f.*, e.title as event_title FROM feedbacks f 
    LEFT JOIN events e ON f.event_id = e.id 
    WHERE f.user_id = ? 
    ORDER BY f.created_at DESC
");
$myFeedbacks->execute([$userId]);
$feedbacks = $myFeedbacks->fetchAll();

$pageTitle = 'Submit Feedback';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Feedback Page Mobile-First Styles */
.feedback-page {
    padding: 0 0 100px;
    background: var(--bg-secondary);
    min-height: calc(100vh - var(--nav-height));
}

.mobile-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-light);
    position: sticky;
    top: var(--nav-height);
    z-index: 100;
}

@media (min-width: 769px) {
    .mobile-page-header { display: none; }
}

.mobile-page-title {
    font-size: 1.1rem;
    font-weight: 700;
}

.dashboard-link-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--blue);
    color: #fff;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
}

.dashboard-link-btn:hover {
    background: var(--purple);
}

.dashboard-link-btn svg {
    width: 16px;
    height: 16px;
}

.feedback-container {
    max-width: 700px;
    margin: 0 auto;
    padding: 16px;
}

/* Desktop Header */
.feedback-header {
    display: none;
    margin-bottom: 24px;
}

@media (min-width: 769px) {
    .feedback-header {
        display: block;
    }
}

.feedback-header h1 {
    font-size: 1.5rem;
    margin-bottom: 4px;
}

.feedback-header p {
    color: var(--text-muted);
}

/* Feedback Form Card */
.feedback-form-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: 24px;
    margin-bottom: 24px;
}

.feedback-form-card h2 {
    font-size: 1.1rem;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.feedback-form-card h2 svg {
    width: 20px;
    height: 20px;
    color: var(--yellow);
}

/* Star Rating */
.star-rating-wrapper {
    margin-bottom: 20px;
}

.star-rating-wrapper label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 10px;
}

.star-rating-input {
    display: flex;
    gap: 6px;
    flex-direction: row-reverse;
    justify-content: flex-end;
}

.star-rating-input input {
    display: none;
}

.star-rating-input label {
    font-size: 2rem;
    color: #ddd;
    cursor: pointer;
    transition: color 0.2s ease;
}

.star-rating-input label:hover,
.star-rating-input label:hover ~ label,
.star-rating-input input:checked ~ label {
    color: var(--yellow);
}

.form-group-m {
    margin-bottom: 16px;
}

.form-group-m label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
}

.form-group-m .form-control {
    padding: 12px 14px;
    font-size: 1rem;
    border-radius: var(--radius-md);
}

.form-group-m select.form-control {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 20px;
    padding-right: 36px;
}

.form-group-m textarea.form-control {
    min-height: 120px;
    resize: vertical;
}

/* Previous Feedbacks */
.feedback-history {
    margin-top: 32px;
}

.feedback-history h3 {
    font-size: 1.05rem;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.feedback-history h3 svg {
    width: 18px;
    height: 18px;
    color: var(--blue);
}

.feedback-cards {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.feedback-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: 16px;
}

.feedback-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}

.feedback-stars {
    display: flex;
    gap: 2px;
}

.feedback-stars span {
    font-size: 1rem;
    color: var(--yellow);
}

.feedback-stars span.empty {
    color: #ddd;
}

.feedback-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.feedback-badge.approved {
    background: #e6f9f0;
    color: #0d7a42;
}

.feedback-badge.pending {
    background: var(--yellow-light);
    color: #b8860b;
}

.feedback-text {
    color: var(--text-secondary);
    line-height: 1.6;
    font-size: 0.95rem;
    margin-bottom: 10px;
}

.feedback-time {
    font-size: 0.8rem;
    color: var(--text-muted);
}

/* Empty State */
.empty-feedback {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}

.empty-feedback svg {
    width: 48px;
    height: 48px;
    margin-bottom: 12px;
    color: var(--text-light);
}
</style>

<div class="feedback-page">
    <!-- Mobile Page Header -->
    <div class="mobile-page-header">
        <span class="mobile-page-title">Feedback</span>
    </div>
    
    <div class="feedback-container">
        <!-- Desktop Header -->
        <div class="feedback-header">
            <h1>Share Your Feedback</h1>
            <p>Tell us about your experience with KESA Learn. Your feedback helps us improve!</p>
        </div>
        
        <!-- Feedback Form Card -->
        <div class="feedback-form-card">
            <h2>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                Rate Your Experience
            </h2>
            
            <form method="POST">
                <?php echo csrfField(); ?>
                
                <?php if (!empty($availableEvents)): ?>
                <div class="form-group-m">
                    <label for="event_id">Select Event <span class="required">*</span></label>
                    <select id="event_id" name="event_id" class="form-control" required>
                        <option value="">-- Choose an event --</option>
                        <?php foreach ($availableEvents as $event): ?>
                            <?php if ($event['has_feedback'] == 0): ?>
                            <option value="<?php echo intval($event['id']); ?>">
                                <?php echo sanitize($event['title']); ?>
                                <?php echo !empty($event['start_date']) ? '(' . date('M d, Y', strtotime($event['start_date'])) . ')' : ''; ?>
                            </option>
                            <?php else: ?>
                            <option value="" disabled>
                                <?php echo sanitize($event['title']); ?> — Feedback submitted
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="star-rating-wrapper">
                    <label>How would you rate this event? <span class="required">*</span></label>
                    <div class="star-rating-input">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" name="rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $i === 5 ? 'checked' : ''; ?>>
                            <label for="star<?php echo $i; ?>">&#9733;</label>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="form-group-m">
                    <label for="feedback_text">Your Feedback <span class="required">*</span></label>
                    <textarea id="feedback_text" name="feedback_text" class="form-control" placeholder="Share your experience..." required></textarea>
                </div>
                
                <?php if (!empty($pendingFeedbackEvents)): ?>
                <button type="submit" class="btn btn-primary btn-lg btn-full">Post Feedback</button>
                <?php elseif (!empty($availableEvents)): ?>
                <p style="text-align: center; color: var(--text-muted); padding: 20px 0;">You have already submitted feedback for all your registered events.</p>
                <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); padding: 20px 0;">You have no registered events yet. Register for an event to submit feedback.</p>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Previous Feedbacks -->
        <?php if (!empty($feedbacks)): ?>
        <div class="feedback-history">
            <h3>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Your Previous Feedbacks
            </h3>
            <div class="feedback-cards">
                <?php foreach ($feedbacks as $fb): ?>
                <div class="feedback-card">
                    <div class="feedback-card-top">
                        <div>
                            <strong style="display: block; font-size: 0.95rem; margin-bottom: 4px;">
                                <?php echo sanitize($fb['event_title'] ?? 'General Feedback'); ?>
                            </strong>
                            <div class="feedback-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span <?php echo $i > $fb['rating'] ? 'class="empty"' : ''; ?>>&#9733;</span>
                                <?php endfor; ?>
                        </div>
                    </div>
                    <p class="feedback-text">"<?php echo sanitize($fb['feedback_text']); ?>"</p>
                    <span class="feedback-time"><?php echo timeAgo($fb['created_at']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-feedback">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
            <p>You haven't submitted any feedback yet.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- User Bottom Navigation -->
<?php include __DIR__ . '/../includes/user_nav.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
