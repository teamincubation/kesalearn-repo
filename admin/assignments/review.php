<?php
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'assignment-review';
$pageTitle = 'Review Submissions';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect($_SERVER['REQUEST_URI']);
    }
    
    $action = $_POST['action'] ?? '';
    $submissionId = (int)$_POST['submission_id'];
    
    if ($action === 'approve') {
        $score = (int)$_POST['score'];
        $feedback = sanitize($_POST['feedback'] ?? '');
        try {
            $stmt = $db->prepare("UPDATE assignment_submissions SET status = 'approved', score = ?, feedback = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->execute([$score, $feedback, $_SESSION['admin_id'], $submissionId]);
            setFlash('success', 'Submission approved.');
        } catch (PDOException $e) { setFlash('error', 'Database error. Please run the migration first.'); }
    }
    
    if ($action === 'reject') {
        $feedback = sanitize($_POST['feedback'] ?? '');
        try {
            $stmt = $db->prepare("UPDATE assignment_submissions SET status = 'rejected', feedback = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->execute([$feedback, $_SESSION['admin_id'], $submissionId]);
            setFlash('success', 'Submission rejected.');
        } catch (PDOException $e) { setFlash('error', 'Database error. Please run the migration first.'); }
    }
    
    if ($action === 'delete_submission') {
        try {
            // Get submission to delete associated file
            $stmt = $db->prepare("SELECT file_path FROM assignment_submissions WHERE id = ?");
            $stmt->execute([$submissionId]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sub && !empty($sub['file_path'])) {
                @unlink(__DIR__ . '/../../uploads/' . $sub['file_path']);
            }
            
            $db->prepare("DELETE FROM assignment_submissions WHERE id = ?")->execute([$submissionId]);
            setFlash('success', 'Submission deleted. User can now resubmit.');
        } catch (PDOException $e) { setFlash('error', 'Could not delete submission.'); }
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get assignment if specified
$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$statusFilter = $_GET['status'] ?? 'all';

// Build query
$sql = "SELECT s.*, a.title as assignment_title, a.max_score, a.submission_type, 
        e.title as event_title, u.name as user_name, u.email as user_email, u.profile_image
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        JOIN events e ON a.event_id = e.id
        JOIN users u ON s.user_id = u.id
        WHERE 1=1";

$params = [];
if ($assignmentId > 0) {
    $sql .= " AND s.assignment_id = ?";
    $params[] = $assignmentId;
}
if ($statusFilter !== 'all') {
    $sql .= " AND s.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY s.submitted_at DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $submissions = []; }

// Get assignments for filter
try {
    $assignments = $db->query("SELECT a.id, a.title, e.title as event_title FROM assignments a JOIN events e ON a.event_id = e.id ORDER BY a.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $assignments = []; }

// Stats
try {
    $stats = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM assignment_submissions")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.review-header {
    margin-bottom: 24px;
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    border-radius: 14px;
    padding: 22px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    border: 1px solid #f1f5f9;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-icon.total   { background: #eff6ff; color: #2563eb; }
.stat-icon.pending { background: #fffbeb; color: #d97706; }
.stat-icon.approved{ background: #f0fdf4; color: #16a34a; }
.stat-icon.rejected{ background: #fef2f2; color: #dc2626; }

.stat-body { display: flex; flex-direction: column; }

.stat-value {
    font-size: 1.75rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}

.stat-label {
    font-size: 0.78rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-top: 4px;
    font-weight: 600;
}

.stat-card.pending .stat-value { color: #d97706; }
.stat-card.approved .stat-value { color: #16a34a; }
.stat-card.rejected .stat-value { color: #dc2626; }

.filters-row {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    align-items: center;
}

.filters-row select {
    padding: 10px 36px 10px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.9rem;
    min-width: 200px;
    background: white;
    color: #374151;
    appearance: auto;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.filters-row select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.submissions-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.submission-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border: 1px solid #f1f5f9;
    transition: box-shadow 0.2s;
}

.submission-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.submission-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #64748b;
    overflow: hidden;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-details h4 {
    margin: 0 0 4px;
    font-size: 1rem;
    font-weight: 700;
}

.user-details p {
    margin: 0;
    font-size: 0.85rem;
    color: #64748b;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-pending {
    background: #fef3c7;
    color: #d97706;
}

.status-approved {
    background: #dcfce7;
    color: #16a34a;
}

.status-rejected {
    background: #fef2f2;
    color: #dc2626;
}

.submission-info {
    background: #f8fafc;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.info-row:last-child {
    margin-bottom: 0;
}

.info-label {
    color: #64748b;
    font-size: 0.85rem;
}

.info-value {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.9rem;
}

.submission-content {
    margin-bottom: 16px;
}

.submission-content h5 {
    font-size: 0.85rem;
    color: #64748b;
    margin: 0 0 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.text-content {
    background: #f8fafc;
    padding: 16px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    max-height: 200px;
    overflow-y: auto;
    white-space: pre-wrap;
    font-size: 0.9rem;
    line-height: 1.6;
}

.file-preview {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #f8fafc;
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.file-icon {
    width: 40px;
    height: 40px;
    background: #e0e7ff;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4f46e5;
}

.file-details {
    flex: 1;
}

.file-name {
    font-weight: 600;
    font-size: 0.9rem;
}

.file-size {
    font-size: 0.8rem;
    color: #64748b;
}

.btn-download {
    padding: 8px 14px;
    background: #0ea5e9;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.photo-preview {
    max-width: 300px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.review-form {
    border-top: 1px solid #e2e8f0;
    padding-top: 16px;
    margin-top: 16px;
}

.review-form h5 {
    font-size: 0.9rem;
    margin: 0 0 12px;
    color: #1e293b;
}

.review-form .form-row {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 12px;
    margin-bottom: 12px;
}

.review-form input, .review-form textarea {
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.9rem;
}

.review-form textarea {
    resize: vertical;
    min-height: 80px;
}

.review-actions {
    display: flex;
    gap: 10px;
    margin-top: 12px;
}

.btn-approve {
    padding: 10px 20px;
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-reject {
    padding: 10px 20px;
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.reviewed-info {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 16px;
}

.reviewed-info.rejected {
    background: #fef2f2;
    border-color: #fecaca;
}

.reviewed-info h5 {
    margin: 0 0 8px;
    font-size: 0.9rem;
}

.score-display {
    font-size: 1.5rem;
    font-weight: 700;
    color: #16a34a;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
}

.empty-state svg {
    width: 64px;
    height: 64px;
    color: #94a3b8;
    margin-bottom: 16px;
}
</style>

<div class="review-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:28px;">
        <div>
            <p style="margin:0 0 4px;font-size:0.82rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                <a href="/admin/assignments/" style="color:#2563eb;text-decoration:none;">Assignments</a>
                &rsaquo; Review
            </p>
            <h1 style="margin:0 0 4px;font-size:1.6rem;font-weight:800;color:#0f172a;">Review Submissions</h1>
            <p style="margin:0;color:#64748b;font-size:0.92rem;">Review and grade learner submissions</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="/admin/assignments/export-excel.php?<?php echo http_build_query($_GET); ?>" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:#16a34a;border-radius:10px;font-size:0.88rem;font-weight:600;color:#fff;text-decoration:none;transition:all 0.2s;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export to Excel
            </a>
            <a href="/admin/assignments/" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:0.88rem;font-weight:600;color:#374151;text-decoration:none;transition:all 0.2s;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Assignments
            </a>
        </div>
    </div>
    
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon total">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="stat-card pending">
            <div class="stat-icon pending">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="stat-card approved">
            <div class="stat-icon approved">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-icon rejected">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?php echo $stats['rejected'] ?? 0; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
    </div>
    
    <form class="filters-row" method="GET">
        <select name="assignment_id" onchange="this.form.submit()">
            <option value="0">All Assignments</option>
            <?php foreach ($assignments as $a): ?>
            <option value="<?php echo $a['id']; ?>" <?php echo $assignmentId == $a['id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($a['title'] . ' - ' . $a['event_title']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
        </select>
    </form>
    
    <?php if (empty($submissions)): ?>
    <div class="empty-state">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <h3>No submissions found</h3>
        <p style="color:#64748b;">Submissions will appear here when learners submit assignments</p>
    </div>
    <?php else: ?>
    <div class="submissions-list">
        <?php foreach ($submissions as $sub): ?>
        <div class="submission-card">
            <div class="submission-header">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($sub['profile_image'])): ?>
                        <img src="/uploads/profiles/<?php echo sanitize($sub['profile_image']); ?>" alt="" onerror="this.style.display='none';this.parentNode.textContent='<?php echo strtoupper(substr($sub['user_name'], 0, 1)); ?>';">
                        <?php else: ?>
                        <?php echo strtoupper(substr($sub['user_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo sanitize($sub['user_name']); ?></h4>
                        <p><?php echo sanitize($sub['user_email']); ?></p>
                    </div>
                </div>
                <span class="status-badge status-<?php echo $sub['status']; ?>">
                    <?php echo ucfirst($sub['status']); ?>
                </span>
            </div>
            
            <div class="submission-info">
                <div class="info-row">
                    <span class="info-label">Assignment</span>
                    <span class="info-value"><?php echo sanitize($sub['assignment_title']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Course</span>
                    <span class="info-value"><?php echo sanitize($sub['event_title']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Submitted</span>
                    <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($sub['submitted_at'])); ?></span>
                </div>
            </div>
            
            <div class="submission-content">
                <h5>Submission</h5>
                <?php if ($sub['submission_type'] === 'text'): ?>
                <div class="text-content"><?php echo nl2br(sanitize($sub['text_content'])); ?></div>
                <?php elseif ($sub['submission_type'] === 'photo'): ?>
                <img src="/uploads/<?php echo sanitize($sub['file_path']); ?>" alt="Photo submission" class="photo-preview">
                <?php else: ?>
                <div class="file-preview">
                    <div class="file-icon">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <div class="file-details">
                        <div class="file-name"><?php echo sanitize($sub['file_name']); ?></div>
                        <div class="file-size"><?php echo number_format($sub['file_size'] / 1024, 1); ?> KB</div>
                    </div>
                    <a href="/admin/assignments/download.php?id=<?php echo $sub['id']; ?>" class="btn-download">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Download
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($sub['status'] === 'pending'): ?>
            <div class="review-form">
                <h5>Review This Submission</h5>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="submission_id" value="<?php echo $sub['id']; ?>">
                    
                    <div class="form-row">
                        <label>Score (max <?php echo $sub['max_score']; ?>)</label>
                        <input type="number" name="score" min="0" max="<?php echo $sub['max_score']; ?>" value="<?php echo $sub['max_score']; ?>">
                    </div>
                    
                    <div class="form-row" style="grid-template-columns:120px 1fr;">
                        <label>Feedback</label>
                        <textarea name="feedback" placeholder="Optional feedback for the learner..."></textarea>
                    </div>
                    
                    <div class="review-actions">
                        <button type="submit" name="action" value="approve" class="btn-approve">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Approve
                        </button>
                        <button type="submit" name="action" value="reject" class="btn-reject">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            Reject
                        </button>
                        <button type="submit" name="action" value="delete_submission" onclick="return confirm('Delete this submission?')" style="background:#64748b;color:#fff;border:none;padding:10px 16px;border-radius:8px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;margin-left:auto;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Delete
                        </button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="reviewed-info <?php echo $sub['status'] === 'rejected' ? 'rejected' : ''; ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div>
                        <h5><?php echo $sub['status'] === 'approved' ? 'Approved' : 'Rejected'; ?></h5>
                        <?php if ($sub['status'] === 'approved' && $sub['score'] !== null): ?>
                        <p>Score: <span class="score-display"><?php echo $sub['score']; ?>/<?php echo $sub['max_score']; ?></span></p>
                        <?php endif; ?>
                        <?php if (!empty($sub['feedback'])): ?>
                        <p style="margin-top:8px;color:#64748b;"><strong>Feedback:</strong> <?php echo sanitize($sub['feedback']); ?></p>
                        <?php endif; ?>
                        <p style="font-size:0.8rem;color:#94a3b8;margin-top:8px;">
                            Reviewed on <?php echo date('M j, Y g:i A', strtotime($sub['reviewed_at'])); ?>
                        </p>
                    </div>
                    <form method="POST" onsubmit="return confirm('Delete this submission? The user will be able to resubmit.');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="submission_id" value="<?php echo $sub['id']; ?>">
                        <button type="submit" name="action" value="delete_submission" style="background:#dc2626;color:#fff;border:none;padding:8px 14px;border-radius:6px;font-size:0.8rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Delete
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
