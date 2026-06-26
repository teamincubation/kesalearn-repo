<?php
require_once __DIR__ . '/../../includes/admin_check.php';
$db = getDB();
$adminPage = 'assignments';
$quizId = (int)($_GET['quiz_id'] ?? 0);
if (!$quizId) { redirect('/admin/assignments/quizzes.php'); }

$quiz = $db->prepare("SELECT q.*, e.title as event_title FROM quizzes q JOIN events e ON q.event_id = e.id WHERE q.id = ?");
$quiz->execute([$quizId]);
$quiz = $quiz->fetch(PDO::FETCH_ASSOC);
if (!$quiz) { redirect('/admin/assignments/quizzes.php'); }
$pageTitle = 'Results: ' . $quiz['title'];

// Handle delete attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_attempt') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $aid = (int)$_POST['attempt_id'];
        $db->prepare("DELETE FROM quiz_responses WHERE attempt_id = ?")->execute([$aid]);
        $db->prepare("DELETE FROM quiz_attempts WHERE id = ?")->execute([$aid]);
        setFlash('success', 'Attempt deleted. User can retake.');
    }
    redirect($_SERVER['REQUEST_URI']);
}

// Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $attempts = $db->prepare("SELECT qa.*, u.name, u.email FROM quiz_attempts qa JOIN users u ON qa.user_id = u.id WHERE qa.quiz_id = ? ORDER BY qa.completed_at DESC");
    $attempts->execute([$quizId]);
    $rows = $attempts->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quiz_results_' . $quizId . '.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Name','Email','Score','Max Score','Percentage','Status','Tab Switches','Started','Completed']);
    foreach ($rows as $r) {
        fputcsv($fp, [$r['name'],$r['email'],$r['total_score'],$r['max_score'],$r['percentage'].'%',$r['status'],$r['tab_switches'],$r['started_at'],$r['completed_at']]);
    }
    fclose($fp);
    exit;
}

// Fetch attempts
$attempts = $db->prepare("SELECT qa.*, u.name as user_name, u.email as user_email, u.profile_image FROM quiz_attempts qa JOIN users u ON qa.user_id = u.id WHERE qa.quiz_id = ? ORDER BY qa.completed_at DESC");
$attempts->execute([$quizId]);
$attempts = $attempts->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total' => count($attempts),
    'completed' => count(array_filter($attempts, fn($a) => $a['status'] !== 'in_progress')),
    'avg' => 0,
    'highest' => 0,
];
$completedAttempts = array_filter($attempts, fn($a) => $a['status'] !== 'in_progress');
if (!empty($completedAttempts)) {
    $stats['avg'] = round(array_sum(array_column($completedAttempts, 'percentage')) / count($completedAttempts), 1);
    $stats['highest'] = max(array_column($completedAttempts, 'percentage'));
}

include __DIR__ . '/../includes/sidebar.php';
?>
<style>
.qr-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; flex-wrap:wrap; gap:16px; }
.qr-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:24px; }
.qr-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; text-align:center; }
.qr-stat-val { font-size:1.5rem; font-weight:800; color:#1e293b; }
.qr-stat-lbl { font-size:0.78rem; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-top:4px; }
.qr-table { width:100%; border-collapse:separate; border-spacing:0; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
.qr-table th { background:#f8fafc; padding:14px 16px; text-align:left; font-size:0.82rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0; }
.qr-table td { padding:14px 16px; border-bottom:1px solid #f1f5f9; font-size:0.9rem; color:#1e293b; }
.qr-table tr:last-child td { border-bottom:none; }
.qr-table tr:hover td { background:#f8fafc; }
.qr-user { display:flex; align-items:center; gap:10px; }
.qr-avatar { width:36px; height:36px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-weight:700; color:#475569; font-size:0.85rem; overflow:hidden; }
.qr-avatar img { width:100%; height:100%; object-fit:cover; }
.score-bar { height:6px; background:#e2e8f0; border-radius:3px; width:80px; overflow:hidden; display:inline-block; vertical-align:middle; margin-left:8px; }
.score-fill { height:100%; border-radius:3px; }
.status-badge { padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
.status-completed { background:#dcfce7; color:#16a34a; }
.status-timed_out { background:#fef3c7; color:#d97706; }
.status-auto_submitted { background:#fee2e2; color:#dc2626; }
.status-in_progress { background:#e0e7ff; color:#4f46e5; }
.qr-btn { padding:6px 12px; border-radius:6px; font-size:0.82rem; font-weight:600; cursor:pointer; border:none; }
.qr-btn-del { background:#fef2f2; color:#dc2626; }
@media (max-width:768px) { .qr-stats { grid-template-columns:repeat(2,1fr); } .qr-table { display:block; overflow-x:auto; } }
</style>

<div class="qr-header">
    <div>
        <a href="/admin/assignments/quizzes.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#475569;font-size:0.88rem;font-weight:600;">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Back
        </a>
        <h1 style="margin:12px 0 4px;font-size:1.5rem;font-weight:800;"><?php echo sanitize($quiz['title']); ?> - Results</h1>
        <p style="margin:0;color:#64748b;"><?php echo sanitize($quiz['event_title']); ?></p>
    </div>
    <a href="?quiz_id=<?php echo $quizId; ?>&export=csv" style="display:inline-flex;align-items:center;gap:8px;padding:11px 18px;background:#16a34a;color:#fff;border-radius:10px;font-weight:600;text-decoration:none;">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Export CSV
    </a>
</div>

<div class="qr-stats">
    <div class="qr-stat"><div class="qr-stat-val"><?php echo $stats['total']; ?></div><div class="qr-stat-lbl">Total Attempts</div></div>
    <div class="qr-stat"><div class="qr-stat-val"><?php echo $stats['completed']; ?></div><div class="qr-stat-lbl">Completed</div></div>
    <div class="qr-stat"><div class="qr-stat-val"><?php echo $stats['avg']; ?>%</div><div class="qr-stat-lbl">Average Score</div></div>
    <div class="qr-stat"><div class="qr-stat-val"><?php echo $stats['highest']; ?>%</div><div class="qr-stat-lbl">Highest Score</div></div>
</div>

<?php if (empty($attempts)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:12px;border:2px dashed #e2e8f0;">
    <h3 style="color:#475569;">No Attempts Yet</h3>
    <p style="color:#64748b;">Results will appear here when learners take the quiz.</p>
</div>
<?php else: ?>
<table class="qr-table">
    <thead><tr><th>Learner</th><th>Score</th><th>Percentage</th><th>Status</th><th>Tab Switches</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($attempts as $a): 
        $pct = (float)$a['percentage'];
        $color = $pct >= 70 ? '#16a34a' : ($pct >= 50 ? '#d97706' : '#dc2626');
    ?>
    <tr>
        <td>
            <div class="qr-user">
                <div class="qr-avatar">
                    <?php if (!empty($a['profile_image'])): ?><img src="/uploads/profiles/<?php echo sanitize($a['profile_image']); ?>" alt=""><?php else: echo strtoupper(substr($a['user_name'],0,1)); endif; ?>
                </div>
                <div><div style="font-weight:600;"><?php echo sanitize($a['user_name']); ?></div><div style="font-size:0.8rem;color:#64748b;"><?php echo sanitize($a['user_email']); ?></div></div>
            </div>
        </td>
        <td><strong><?php echo $a['total_score']; ?>/<?php echo $a['max_score']; ?></strong></td>
        <td>
            <span style="font-weight:700;color:<?php echo $color; ?>;"><?php echo $pct; ?>%</span>
            <span class="score-bar"><span class="score-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></span></span>
        </td>
        <td><span class="status-badge status-<?php echo $a['status']; ?>"><?php echo ucfirst(str_replace('_',' ',$a['status'])); ?></span></td>
        <td><?php echo $a['tab_switches']; ?></td>
        <td><?php echo $a['completed_at'] ? date('M j, Y g:i A', strtotime($a['completed_at'])) : 'In progress'; ?></td>
        <td>
            <form method="POST" onsubmit="return confirm('Delete this attempt? User can retake.');">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_attempt"><input type="hidden" name="attempt_id" value="<?php echo $a['id']; ?>">
                <button type="submit" class="qr-btn qr-btn-del">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
