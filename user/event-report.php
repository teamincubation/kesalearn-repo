<?php
/**
 * KESA Learn — User Event Performance Report
 * Shows a full breakdown of a user's activity for one event.
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$eventId = (int)($_GET['event_id'] ?? 0);

if (!$eventId) {
    redirect('/user/dashboard');
}

// Verify registration
$stmt = $db->prepare("
    SELECT r.*, r.id AS registration_id, e.id AS event_id, e.title, e.start_date, e.end_date, e.type
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

// --- Gather all data in parallel ---

// 1. Live Sessions + attendance
$allSessions = [];
try {
    $s = $db->prepare("SELECT ls.id, ls.title, ls.start_datetime, ls.status,
        sa.attended, sa.marked_at
        FROM live_sessions ls
        LEFT JOIN session_attendance sa ON sa.session_id = ls.id AND sa.user_id = ?
        WHERE ls.event_id = ?
        ORDER BY ls.start_datetime ASC");
    $s->execute([$userId, $eventId]);
    $allSessions = $s->fetchAll();
} catch (PDOException $e) { $allSessions = []; }

// 2. Reading Materials + reads
$allMaterials = [];
try {
    $m = $db->prepare("SELECT em.id, em.title, em.file_type,
        mr.first_read_at
        FROM event_materials em
        LEFT JOIN material_reads mr ON mr.material_id = em.id AND mr.user_id = ?
        WHERE em.event_id = ? AND em.is_active = 1
        ORDER BY em.sort_order ASC, em.created_at ASC");
    $m->execute([$userId, $eventId]);
    $allMaterials = $m->fetchAll();
} catch (PDOException $e) { $allMaterials = []; }

// 3. Assignments
$allAssignments = [];
try {
    $a = $db->prepare("SELECT asn.id, asn.title, asn.max_score,
        sub.submitted_at, sub.score, sub.status AS sub_status
        FROM assignments asn
        LEFT JOIN assignment_submissions sub ON sub.assignment_id = asn.id AND sub.user_id = ?
        WHERE asn.event_id = ? AND asn.is_active = 1
        ORDER BY asn.created_at ASC");
    $a->execute([$userId, $eventId]);
    $allAssignments = $a->fetchAll();
} catch (PDOException $e) { $allAssignments = []; }

// 4. Quizzes
$allQuizzes = [];
try {
    $q = $db->prepare("SELECT qz.id, qz.title, qz.max_score,
        MAX(qa.percentage) AS best_score,
        MAX(CASE WHEN qa.status = 'completed' THEN 1 ELSE 0 END) AS completed,
        COUNT(qa.id) AS attempts,
        MAX(qa.completed_at) AS last_attempt_at
        FROM quizzes qz
        LEFT JOIN quiz_attempts qa ON qa.quiz_id = qz.id AND qa.user_id = ?
        WHERE qz.event_id = ? AND qz.is_active = 1
        GROUP BY qz.id, qz.title, qz.max_score
        ORDER BY qz.created_at ASC");
    $q->execute([$userId, $eventId]);
    $allQuizzes = $q->fetchAll();
} catch (PDOException $e) { $allQuizzes = []; }

// 5. Certificate
$certificate = null;
try {
    $cs = $db->prepare("SELECT * FROM certificates WHERE user_id = ? AND event_id = ?");
    $cs->execute([$userId, $eventId]);
    $certificate = $cs->fetch();
} catch (PDOException $e) {}

// --- Progress Calculation ---
$totalActivities = count($allSessions) + count($allMaterials) + count($allAssignments) + count($allQuizzes);
$completedActivities = 0;

$attendedCount = 0;
foreach ($allSessions as $s) { if ($s['attended'] === '1' || $s['attended'] === 1) { $completedActivities++; $attendedCount++; } }
$readCount = 0;
foreach ($allMaterials as $m) { if ($m['first_read_at']) { $completedActivities++; $readCount++; } }
$submittedCount = 0;
$totalScore = 0; $scorableItems = 0;
foreach ($allAssignments as $a) {
    if ($a['submitted_at']) { $completedActivities++; $submittedCount++; }
    if ($a['score'] !== null) { $totalScore += $a['score']; $scorableItems++; }
}
$quizDoneCount = 0;
foreach ($allQuizzes as $q) {
    if ($q['completed']) { $completedActivities++; $quizDoneCount++; }
    if ($q['best_score'] !== null) { $totalScore += $q['best_score']; $scorableItems++; }
}

$progressPct = $totalActivities > 0 ? round(($completedActivities / $totalActivities) * 100) : 0;
$avgScore = $scorableItems > 0 ? round($totalScore / $scorableItems, 1) : null;

// Log report view
try {
    logActivity('report_viewed', "Viewed performance report for event #{$eventId}");
} catch (Exception $e) {}

$pageTitle = 'My Progress Report — ' . sanitize($registration['title']);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<style>
.rpt-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 24px 16px 60px;
}
.rpt-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 28px;
    flex-wrap: wrap;
}
.rpt-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-muted);
    font-size: 0.85rem;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.15s;
    margin-bottom: 10px;
}
.rpt-back:hover { color: var(--text-primary); }
.rpt-title { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); margin: 0 0 4px; line-height: 1.2; }
.rpt-event-name { font-size: 0.9rem; color: var(--text-muted); }
.rpt-print-btn {
    padding: 9px 16px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--text-secondary);
    text-decoration: none;
    transition: all 0.15s;
}
.rpt-print-btn:hover { background: var(--bg-tertiary); }

/* Summary Cards */
.rpt-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}
.rpt-stat-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-xl);
    padding: 18px;
    text-align: center;
    box-shadow: var(--shadow-sm);
}
.rpt-stat-value { font-size: 2rem; font-weight: 800; color: var(--text-primary); line-height: 1; margin-bottom: 4px; }
.rpt-stat-label { font-size: 0.76rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
.rpt-stat-card.highlight { background: #0ea5e9; border-color: #0ea5e9; }
.rpt-stat-card.highlight .rpt-stat-value,
.rpt-stat-card.highlight .rpt-stat-label { color: #fff; }
.rpt-stat-card.success { background: #dcfce7; border-color: #86efac; }
.rpt-stat-card.success .rpt-stat-value { color: #15803d; }

/* Progress Bar */
.rpt-progress-wrap {
    background: var(--bg-primary);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-xl);
    padding: 20px 22px;
    margin-bottom: 28px;
    box-shadow: var(--shadow-sm);
}
.rpt-progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.rpt-progress-label { font-size: 0.92rem; font-weight: 700; color: var(--text-primary); }
.rpt-progress-pct { font-size: 1.2rem; font-weight: 800; color: #0ea5e9; }
.rpt-progress-bar { height: 12px; background: var(--bg-tertiary); border-radius: 6px; overflow: hidden; }
.rpt-progress-fill { height: 100%; border-radius: 6px; background: #0ea5e9; transition: width 1s ease; }
.rpt-progress-fill.high { background: #16a34a; }

/* Section */
.rpt-section {
    background: var(--bg-primary);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-xl);
    margin-bottom: 18px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.rpt-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-secondary);
}
.rpt-section-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.rpt-section-title { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); flex: 1; }
.rpt-section-count { font-size: 0.8rem; color: var(--text-muted); font-weight: 500; }
.rpt-section-body { padding: 0; }
.rpt-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 13px 20px;
    border-bottom: 1px solid var(--border-light);
}
.rpt-row:last-child { border-bottom: none; }
.rpt-row-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.75rem; font-weight: 700; }
.rpt-row-info { flex: 1; min-width: 0; }
.rpt-row-title { font-size: 0.88rem; font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rpt-row-meta { font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; }
.rpt-row-status { flex-shrink: 0; }
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 12px;
    font-size: 0.74rem;
    font-weight: 700;
}
.pill-success { background: #dcfce7; color: #15803d; }
.pill-danger  { background: #fee2e2; color: #dc2626; }
.pill-muted   { background: #f3f4f6; color: #6b7280; }
.rpt-score { font-size: 0.85rem; font-weight: 700; color: var(--text-primary); }
.rpt-empty-row { padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.85rem; }
@media print {
    .rpt-back, .rpt-print-btn, header, footer, .user-bottom-nav { display: none !important; }
    .rpt-container { max-width: 100%; padding: 0; }
    .rpt-section { break-inside: avoid; }
}
</style>

<main class="rpt-container">
    <a href="/user/event-details.php?event_id=<?php echo $eventId; ?>" class="rpt-back">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to Event
    </a>

    <div class="rpt-header">
        <div>
            <h1 class="rpt-title">My Progress Report</h1>
            <div class="rpt-event-name"><?php echo sanitize($registration['title']); ?></div>
        </div>
        <button class="rpt-print-btn" onclick="window.print()">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print Report
        </button>
    </div>

    <!-- Summary Cards -->
    <div class="rpt-summary">
        <div class="rpt-stat-card highlight">
            <div class="rpt-stat-value"><?php echo $progressPct; ?>%</div>
            <div class="rpt-stat-label">Overall Progress</div>
        </div>
        <div class="rpt-stat-card">
            <div class="rpt-stat-value"><?php echo $attendedCount; ?>/<?php echo count($allSessions); ?></div>
            <div class="rpt-stat-label">Sessions Attended</div>
        </div>
        <div class="rpt-stat-card">
            <div class="rpt-stat-value"><?php echo $readCount; ?>/<?php echo count($allMaterials); ?></div>
            <div class="rpt-stat-label">Materials Read</div>
        </div>
        <div class="rpt-stat-card">
            <div class="rpt-stat-value"><?php echo $submittedCount + $quizDoneCount; ?>/<?php echo count($allAssignments) + count($allQuizzes); ?></div>
            <div class="rpt-stat-label">Assignments & Quizzes</div>
        </div>
        <?php if ($avgScore !== null): ?>
        <div class="rpt-stat-card success">
            <div class="rpt-stat-value"><?php echo $avgScore; ?>%</div>
            <div class="rpt-stat-label">Average Score</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Progress Bar -->
    <div class="rpt-progress-wrap">
        <div class="rpt-progress-header">
            <span class="rpt-progress-label">Course Completion</span>
            <span class="rpt-progress-pct"><?php echo $progressPct; ?>%</span>
        </div>
        <div class="rpt-progress-bar">
            <div class="rpt-progress-fill <?php echo $progressPct >= 80 ? 'high' : ''; ?>"
                 style="width: 0%;"
                 data-target="<?php echo $progressPct; ?>%">
            </div>
        </div>
    </div>

    <!-- Sessions Section -->
    <div class="rpt-section">
        <div class="rpt-section-header">
            <div class="rpt-section-icon" style="background:#dbeafe;color:#1d4ed8;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </div>
            <span class="rpt-section-title">Live Sessions</span>
            <span class="rpt-section-count"><?php echo $attendedCount; ?>/<?php echo count($allSessions); ?> attended</span>
        </div>
        <div class="rpt-section-body">
            <?php if (empty($allSessions)): ?>
                <div class="rpt-empty-row">No sessions scheduled for this event.</div>
            <?php else: foreach ($allSessions as $s):
                $isCompleted = $s['status'] === 'completed';
                $attended = ($s['attended'] === 1 || $s['attended'] === '1');
                $notAttended = ($s['attended'] === 0 || $s['attended'] === '0');
                $marked = ($s['attended'] !== null);
            ?>
            <div class="rpt-row">
                <div class="rpt-row-icon" style="background:<?php echo $attended ? '#dcfce7' : ($notAttended ? '#fee2e2' : '#f3f4f6'); ?>;color:<?php echo $attended ? '#15803d' : ($notAttended ? '#dc2626' : '#6b7280'); ?>;">
                    <?php if ($attended): ?>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                    <?php elseif ($notAttended): ?>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                    <?php else: ?>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php endif; ?>
                </div>
                <div class="rpt-row-info">
                    <div class="rpt-row-title"><?php echo sanitize($s['title']); ?></div>
                    <div class="rpt-row-meta"><?php echo date('d M Y, g:i A', strtotime($s['start_datetime'])); ?></div>
                </div>
                <div class="rpt-row-status">
                    <?php if (!$isCompleted): ?>
                        <span class="status-pill pill-muted">Upcoming</span>
                    <?php elseif ($attended): ?>
                        <span class="status-pill pill-success">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                            Attended
                        </span>
                    <?php elseif ($notAttended): ?>
                        <span class="status-pill pill-danger">Absent</span>
                    <?php else: ?>
                        <span class="status-pill pill-muted">Not Marked</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Reading Materials Section -->
    <div class="rpt-section">
        <div class="rpt-section-header">
            <div class="rpt-section-icon" style="background:#fef3c7;color:#92400e;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            </div>
            <span class="rpt-section-title">Reading Materials</span>
            <span class="rpt-section-count"><?php echo $readCount; ?>/<?php echo count($allMaterials); ?> read</span>
        </div>
        <div class="rpt-section-body">
            <?php if (empty($allMaterials)): ?>
                <div class="rpt-empty-row">No materials uploaded for this event.</div>
            <?php else: foreach ($allMaterials as $m): ?>
            <div class="rpt-row">
                <div class="rpt-row-icon" style="background:<?php echo $m['first_read_at'] ? '#dcfce7' : '#f3f4f6'; ?>;color:<?php echo $m['first_read_at'] ? '#15803d' : '#6b7280'; ?>;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div class="rpt-row-info">
                    <div class="rpt-row-title"><?php echo sanitize($m['title']); ?></div>
                    <div class="rpt-row-meta">
                        <?php echo strtoupper($m['file_type']); ?>
                        <?php if ($m['first_read_at']): ?> &nbsp;·&nbsp; First read <?php echo date('d M Y', strtotime($m['first_read_at'])); ?><?php endif; ?>
                    </div>
                </div>
                <div class="rpt-row-status">
                    <?php if ($m['first_read_at']): ?>
                        <span class="status-pill pill-success">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                            Read
                        </span>
                    <?php else: ?>
                        <span class="status-pill pill-muted">Not Read</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Assignments Section -->
    <?php if (!empty($allAssignments)): ?>
    <div class="rpt-section">
        <div class="rpt-section-header">
            <div class="rpt-section-icon" style="background:#ede9fe;color:#6d28d9;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <span class="rpt-section-title">Assignments</span>
            <span class="rpt-section-count"><?php echo $submittedCount; ?>/<?php echo count($allAssignments); ?> submitted</span>
        </div>
        <div class="rpt-section-body">
            <?php foreach ($allAssignments as $a): ?>
            <div class="rpt-row">
                <div class="rpt-row-icon" style="background:<?php echo $a['submitted_at'] ? '#ede9fe' : '#f3f4f6'; ?>;color:<?php echo $a['submitted_at'] ? '#6d28d9' : '#6b7280'; ?>;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <div class="rpt-row-info">
                    <div class="rpt-row-title"><?php echo sanitize($a['title']); ?></div>
                    <div class="rpt-row-meta">
                        <?php if ($a['submitted_at']): ?>Submitted <?php echo date('d M Y', strtotime($a['submitted_at'])); ?><?php endif; ?>
                    </div>
                </div>
                <div class="rpt-row-status" style="display:flex;align-items:center;gap:10px;">
                    <?php if ($a['score'] !== null): ?>
                        <span class="rpt-score"><?php echo round($a['score']); ?><?php echo $a['max_score'] ? '/'.(int)$a['max_score'] : ''; ?></span>
                    <?php endif; ?>
                    <?php if ($a['submitted_at']): ?>
                        <span class="status-pill pill-success">Submitted</span>
                    <?php else: ?>
                        <span class="status-pill pill-muted">Pending</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quizzes Section -->
    <?php if (!empty($allQuizzes)): ?>
    <div class="rpt-section">
        <div class="rpt-section-header">
            <div class="rpt-section-icon" style="background:#fce7f3;color:#9d174d;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <span class="rpt-section-title">Quizzes</span>
            <span class="rpt-section-count"><?php echo $quizDoneCount; ?>/<?php echo count($allQuizzes); ?> completed</span>
        </div>
        <div class="rpt-section-body">
            <?php foreach ($allQuizzes as $q): ?>
            <div class="rpt-row">
                <div class="rpt-row-icon" style="background:<?php echo $q['completed'] ? '#fce7f3' : '#f3f4f6'; ?>;color:<?php echo $q['completed'] ? '#9d174d' : '#6b7280'; ?>;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
                </div>
                <div class="rpt-row-info">
                    <div class="rpt-row-title"><?php echo sanitize($q['title']); ?></div>
                    <div class="rpt-row-meta">
                        <?php echo (int)$q['attempts']; ?> attempt<?php echo $q['attempts'] != 1 ? 's' : ''; ?>
                        <?php if ($q['last_attempt_at']): ?> &nbsp;·&nbsp; Last: <?php echo date('d M Y', strtotime($q['last_attempt_at'])); ?><?php endif; ?>
                    </div>
                </div>
                <div class="rpt-row-status" style="display:flex;align-items:center;gap:10px;">
                    <?php if ($q['best_score'] !== null): ?>
                        <span class="rpt-score"><?php echo round($q['best_score']); ?>%</span>
                    <?php endif; ?>
                    <?php if ($q['completed']): ?>
                        <span class="status-pill pill-success">Completed</span>
                    <?php elseif ($q['attempts'] > 0): ?>
                        <span class="status-pill pill-muted">In Progress</span>
                    <?php else: ?>
                        <span class="status-pill pill-muted">Not Started</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Certificate Status -->
    <div class="rpt-section">
        <div class="rpt-section-header">
            <div class="rpt-section-icon" style="background:#d1fae5;color:#065f46;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            </div>
            <span class="rpt-section-title">Certificate</span>
        </div>
        <div class="rpt-section-body">
            <div class="rpt-row">
                <div class="rpt-row-icon" style="background:<?php echo $certificate ? '#d1fae5' : '#f3f4f6'; ?>;color:<?php echo $certificate ? '#065f46' : '#6b7280'; ?>;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                </div>
                <div class="rpt-row-info">
                    <div class="rpt-row-title"><?php echo $certificate ? 'Certificate Issued' : 'Certificate Not Yet Issued'; ?></div>
                    <?php if ($certificate && !empty($certificate['issued_at'])): ?>
                        <div class="rpt-row-meta">Issued on <?php echo date('d M Y', strtotime($certificate['issued_at'])); ?></div>
                    <?php endif; ?>
                </div>
                <div class="rpt-row-status">
                    <?php if ($certificate): ?>
                        <a href="/user/event-details.php?event_id=<?php echo $eventId; ?>" class="status-pill pill-success" style="text-decoration:none;">Download</a>
                    <?php else: ?>
                        <span class="status-pill pill-muted">Pending</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</main>

<script>
// Animate progress bar on load
document.addEventListener('DOMContentLoaded', function() {
    var bar = document.querySelector('.rpt-progress-fill');
    if (bar) {
        var target = bar.getAttribute('data-target');
        setTimeout(function() { bar.style.width = target; }, 100);
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
