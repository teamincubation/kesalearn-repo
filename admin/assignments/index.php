<?php
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'assignments';
$pageTitle = 'Assignments & Tasks';

// Get all events for dropdown
try {
    $events = $db->query("SELECT id, title FROM events ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $events = []; }

// Get active instructors for dropdown
try {
    $instructors = $db->query("SELECT id, name FROM instructors WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $instructors = []; }

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/assignments/');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $eventId = (int)$_POST['event_id'];
        $title = sanitize($_POST['title']);
        $description = $_POST['description'] ?? '';
        $submissionType = $_POST['submission_type'];
        $maxFileSize = (int)($_POST['max_file_size'] ?? 10);
        $allowedExtensions = $_POST['allowed_extensions'] ?? '';
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $maxScore = (int)($_POST['max_score'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $assignedInstructor = !empty($_POST['assigned_instructor']) ? (int)$_POST['assigned_instructor'] : null;
        
        $stmt = $db->prepare("
            INSERT INTO assignments (event_id, title, description, submission_type, max_file_size_mb, allowed_extensions, deadline, max_score, is_active, assigned_instructor_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$eventId, $title, $description, $submissionType, $maxFileSize, $allowedExtensions, $deadline, $maxScore, $isActive, $assignedInstructor]);
        $newAssignmentId = $db->lastInsertId();
        
        // Handle material uploads
        if (!empty($_FILES['materials']['name'][0])) {
            $uploadDir = __DIR__ . '/../../uploads/materials/';
            @mkdir($uploadDir, 0755, true);
            foreach ($_FILES['materials']['name'] as $i => $name) {
                if ($_FILES['materials']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $stored = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['materials']['tmp_name'][$i], $uploadDir . $stored)) {
                        try {
                            $db->prepare("INSERT INTO assignment_materials (assignment_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?)")
                               ->execute([$newAssignmentId, $name, $stored, $_FILES['materials']['size'][$i]]);
                        } catch (PDOException $e) { /* table may not exist */ }
                    }
                }
            }
        }
        
        setFlash('success', 'Assignment created successfully.');
        redirect('/admin/assignments/');
    }
    
    if ($action === 'update') {
        $id = (int)$_POST['assignment_id'];
        $title = sanitize($_POST['title']);
        $description = $_POST['description'] ?? '';
        $submissionType = $_POST['submission_type'];
        $maxFileSize = (int)($_POST['max_file_size'] ?? 10);
        $allowedExtensions = $_POST['allowed_extensions'] ?? '';
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $maxScore = (int)($_POST['max_score'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $assignedInstructor = !empty($_POST['assigned_instructor']) ? (int)$_POST['assigned_instructor'] : null;
        
        $stmt = $db->prepare("
            UPDATE assignments SET title = ?, description = ?, submission_type = ?, max_file_size_mb = ?, 
            allowed_extensions = ?, deadline = ?, max_score = ?, is_active = ?, assigned_instructor_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$title, $description, $submissionType, $maxFileSize, $allowedExtensions, $deadline, $maxScore, $isActive, $assignedInstructor, $id]);
        
        // Handle new material uploads
        if (!empty($_FILES['materials']['name'][0])) {
            $uploadDir = __DIR__ . '/../../uploads/materials/';
            @mkdir($uploadDir, 0755, true);
            foreach ($_FILES['materials']['name'] as $i => $name) {
                if ($_FILES['materials']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $stored = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['materials']['tmp_name'][$i], $uploadDir . $stored)) {
                        try {
                            $db->prepare("INSERT INTO assignment_materials (assignment_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?)")
                               ->execute([$id, $name, $stored, $_FILES['materials']['size'][$i]]);
                        } catch (PDOException $e) { /* table may not exist */ }
                    }
                }
            }
        }
        
        setFlash('success', 'Assignment updated successfully.');
        redirect('/admin/assignments/');
    }
    
    // Handle material deletion
    if ($action === 'delete_material') {
        $materialId = (int)$_POST['material_id'];
        try {
            $matStmt = $db->prepare("SELECT file_path FROM assignment_materials WHERE id = ?");
            $matStmt->execute([$materialId]);
            $mat = $matStmt->fetch(PDO::FETCH_ASSOC);
            if ($mat) {
                @unlink(__DIR__ . '/../../uploads/materials/' . $mat['file_path']);
                $db->prepare("DELETE FROM assignment_materials WHERE id = ?")->execute([$materialId]);
            }
        } catch (PDOException $e) {}
        setFlash('success', 'Material deleted.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/admin/assignments/');
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['assignment_id'];
        $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        
        setFlash('success', 'Assignment deleted.');
        redirect('/admin/assignments/');
    }
}

// Filter by event
$filterEvent = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// Get assignments
$sql = "SELECT a.*, e.title as event_title,
        (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count,
        (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND status = 'pending') as pending_count
        FROM assignments a
        JOIN events e ON a.event_id = e.id";
if ($filterEvent > 0) {
    $sql .= " WHERE a.event_id = " . (int)$filterEvent;
}
$sql .= " ORDER BY a.created_at DESC";
try {
    $assignments = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assignments = [];
    $tableError = 'The assignments table does not exist yet. Please run the migration script (migration_assignments_v2.sql) in phpMyAdmin first.';
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.assignments-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.filter-form {
    display: flex;
    gap: 12px;
    align-items: center;
}

.filter-form select {
    padding: 10px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.9rem;
    min-width: 200px;
}

.btn-create {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-create:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
}

.assignments-grid {
    display: grid;
    gap: 16px;
}

.assignment-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
}

.assignment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.assignment-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.assignment-event {
    font-size: 0.85rem;
    color: #64748b;
    margin-top: 4px;
}

.assignment-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-active {
    background: #dcfce7;
    color: #16a34a;
}

.badge-inactive {
    background: #fef3c7;
    color: #d97706;
}

.badge-type {
    background: #e0e7ff;
    color: #4f46e5;
}

.assignment-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    padding: 16px 0;
    border-top: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    margin: 12px 0;
}

.meta-item {
    text-align: center;
}

.meta-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
}

.meta-label {
    font-size: 0.75rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.assignment-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn-sm {
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-edit {
    background: #f1f5f9;
    color: #475569;
}

.btn-edit:hover {
    background: #e2e8f0;
}

.btn-delete {
    background: #fef2f2;
    color: #dc2626;
}

.btn-delete:hover {
    background: #fee2e2;
}

.btn-review {
    background: #eff6ff;
    color: #2563eb;
}

.btn-review:hover {
    background: #dbeafe;
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 16px;
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px rgba(0,0,0,0.25);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #64748b;
    padding: 4px;
}

.modal-body {
    padding: 24px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #374151;
    font-size: 0.9rem;
}

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: border-color 0.2s;
}

.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none;
    border-color: #0ea5e9;
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-hint {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 4px;
}

.form-check {
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-check input {
    width: 18px;
    height: 18px;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.btn-cancel {
    padding: 10px 20px;
    background: #f1f5f9;
    color: #475569;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.btn-submit {
    padding: 10px 24px;
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
    border: 2px dashed #e2e8f0;
}

.empty-state svg {
    width: 64px;
    height: 64px;
    color: #94a3b8;
    margin-bottom: 16px;
}

.empty-state h3 {
    margin: 0 0 8px;
    color: #475569;
}

.empty-state p {
    color: #64748b;
    margin: 0;
}
</style>

<div class="assignments-header">
        <div>
            <h1 style="margin:0 0 4px;font-size:1.6rem;font-weight:800;color:#0f172a;">Assignments & Tasks</h1>
            <p style="margin:0;color:#64748b;font-size:0.92rem;">Create and manage assignments for your courses</p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <form class="filter-form" method="GET">
                <select name="event_id" onchange="this.form.submit()">
                    <option value="0">All Courses</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?php echo $event['id']; ?>" <?php echo $filterEvent == $event['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($event['title']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="/admin/assignments/review.php" style="display:inline-flex;align-items:center;gap:8px;padding:11px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:0.88rem;font-weight:600;color:#374151;text-decoration:none;white-space:nowrap;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                Review Submissions
            </a>
            <a href="/admin/assignments/quizzes.php" class="btn-create" style="background:linear-gradient(135deg,#9B59B6 0%,#5B7FD1 100%);text-decoration:none;color:#fff;">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Add Quiz
            </a>
            <button class="btn-create" onclick="openCreateModal()">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Assignment
            </button>
        </div>
    </div>
    
    <?php if (!empty($tableError)): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;padding:20px 24px;margin-bottom:24px;display:flex;gap:16px;align-items:flex-start;">
        <svg width="24" height="24" fill="none" stroke="#dc2626" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <div>
            <strong style="color:#991b1b;display:block;margin-bottom:4px;">Database Setup Required</strong>
            <p style="color:#7f1d1d;margin:0;font-size:0.9rem;"><?php echo $tableError; ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (empty($assignments)): ?>
    <div class="empty-state">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <h3>No assignments yet</h3>
        <p>Create your first assignment to get started</p>
    </div>
    <?php else: ?>
    <div class="assignments-grid">
        <?php foreach ($assignments as $assignment): ?>
        <div class="assignment-card">
            <div class="assignment-header">
                <div>
                    <h3 class="assignment-title"><?php echo sanitize($assignment['title']); ?></h3>
                    <p class="assignment-event"><?php echo sanitize($assignment['event_title']); ?></p>
                </div>
                <div class="assignment-badges">
                    <span class="badge <?php echo $assignment['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                        <?php echo $assignment['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                    <span class="badge badge-type">
                        <?php 
                        $types = ['file' => 'File Upload', 'text' => 'Text Entry', 'url' => 'Enter URL', 'photo' => 'Photo Capture'];
                        echo $types[$assignment['submission_type']] ?? $assignment['submission_type'];
                        ?>
                    </span>
                </div>
            </div>
            
            <div class="assignment-meta">
                <div class="meta-item">
                    <div class="meta-value"><?php echo $assignment['submission_count']; ?></div>
                    <div class="meta-label">Submissions</div>
                </div>
                <div class="meta-item">
                    <div class="meta-value" style="color:#f59e0b;"><?php echo $assignment['pending_count']; ?></div>
                    <div class="meta-label">Pending</div>
                </div>
                <div class="meta-item">
                    <div class="meta-value"><?php echo $assignment['max_score']; ?></div>
                    <div class="meta-label">Max Score</div>
                </div>
                <div class="meta-item">
                    <div class="meta-value" style="font-size:0.9rem;">
                        <?php echo $assignment['deadline'] ? date('M j, Y', strtotime($assignment['deadline'])) : 'No deadline'; ?>
                    </div>
                    <div class="meta-label">Deadline</div>
                </div>
            </div>
            
            <div class="assignment-actions">
                <?php if ($assignment['pending_count'] > 0): ?>
                <a href="/admin/assignments/review.php?assignment_id=<?php echo $assignment['id']; ?>" class="btn-sm btn-review">
                    Review Submissions
                </a>
                <?php endif; ?>
                <button class="btn-sm btn-edit" onclick='openEditModal(<?php echo json_encode($assignment); ?>)'>
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit
                </button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this assignment?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                    <button type="submit" class="btn-sm btn-delete">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<!-- Create/Edit Modal -->
<div class="modal-overlay" id="assignmentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Create Assignment</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" id="assignmentForm" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="assignment_id" id="assignmentId">
            
            <div class="modal-body">
                <div class="form-group">
                    <label>Course/Event *</label>
                    <select name="event_id" id="eventId" required>
                        <option value="">Select a course</option>
                        <?php foreach ($events as $event): ?>
                        <option value="<?php echo $event['id']; ?>"><?php echo sanitize($event['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Assignment Title *</label>
                    <input type="text" name="title" id="assignTitle" required placeholder="e.g., Case Study Analysis">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="assignDesc" rows="3" placeholder="Describe the assignment requirements..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Submission Type *</label>
                    <select name="submission_type" id="submissionType" required onchange="toggleFileOptions()">
                        <option value="file">File Upload (PDF, Word, PPT, etc.)</option>
                        <option value="text">Text Entry (Write and Submit)</option>
                        <option value="url">Enter URL (Link Submission)</option>
                        <option value="photo">Photo Capture (Camera)</option>
                    </select>
                </div>
                
                <div id="fileOptions">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Max File Size (MB)</label>
                            <input type="number" name="max_file_size" id="maxFileSize" value="10" min="1" max="50">
                        </div>
                        <div class="form-group">
                            <label>Allowed Extensions</label>
                            <input type="text" name="allowed_extensions" id="allowedExt" placeholder="pdf,doc,docx,ppt,mp3,mp4">
                            <p class="form-hint">Comma-separated. Leave blank for all types.</p>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Deadline (IST)</label>
                        <input type="datetime-local" name="deadline" id="deadline">
                        <p class="form-hint">Leave blank for no deadline</p>
                    </div>
                    <div class="form-group">
                        <label>Maximum Score</label>
                        <input type="number" name="max_score" id="maxScore" value="100" min="1">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Assign to Instructor</label>
                    <select name="assigned_instructor" id="assignedInstructor">
                        <option value="">-- No specific instructor --</option>
                        <?php foreach ($instructors as $ins): ?>
                        <option value="<?php echo $ins['id']; ?>"><?php echo sanitize($ins['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Optionally assign this to a specific instructor for review</p>
                </div>
                
                <div class="form-group">
                    <label>Assignment Materials (PDF, files for learners)</label>
                    <input type="file" name="materials[]" id="materials" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.mp3,.mp4">
                    <p class="form-hint">Upload reference materials that learners can download</p>
                    <div id="existingMaterials" style="margin-top:10px;"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" id="isActive" checked>
                        <span>Active (visible to learners)</span>
                    </label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-submit" id="submitBtn">Create Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Create Assignment';
    document.getElementById('formAction').value = 'create';
    document.getElementById('assignmentId').value = '';
    document.getElementById('assignmentForm').reset();
    document.getElementById('isActive').checked = true;
    document.getElementById('submitBtn').textContent = 'Create Assignment';
    toggleFileOptions();
    document.getElementById('assignmentModal').classList.add('active');
}

function openEditModal(assignment) {
    document.getElementById('modalTitle').textContent = 'Edit Assignment';
    document.getElementById('formAction').value = 'update';
    document.getElementById('assignmentId').value = assignment.id;
    document.getElementById('eventId').value = assignment.event_id;
    document.getElementById('assignTitle').value = assignment.title;
    document.getElementById('assignDesc').value = assignment.description || '';
    document.getElementById('submissionType').value = assignment.submission_type;
    document.getElementById('maxFileSize').value = assignment.max_file_size_mb;
    document.getElementById('allowedExt').value = assignment.allowed_extensions || '';
    document.getElementById('deadline').value = assignment.deadline ? assignment.deadline.replace(' ', 'T').slice(0, 16) : '';
    document.getElementById('assignedInstructor').value = assignment.assigned_instructor_id || '';
    
    // Load existing materials via AJAX
    fetch('/admin/assignments/get-materials.php?id=' + assignment.id)
        .then(r => r.json())
        .then(mats => {
            let html = '';
            mats.forEach(m => {
                html += `<div style="display:flex;align-items:center;gap:8px;padding:8px;background:#f1f5f9;border-radius:6px;margin-bottom:6px;">
                    <span style="flex:1;font-size:0.85rem;">${m.file_name}</span>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="delete_material">
                        <input type="hidden" name="material_id" value="${m.id}">
                        <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:0.75rem;" onclick="return confirm('Delete this material?')">Delete</button>
                    </form>
                </div>`;
            });
            document.getElementById('existingMaterials').innerHTML = html;
        }).catch(() => {});
    document.getElementById('maxScore').value = assignment.max_score;
    document.getElementById('isActive').checked = assignment.is_active == 1;
    document.getElementById('submitBtn').textContent = 'Save Changes';
    toggleFileOptions();
    document.getElementById('assignmentModal').classList.add('active');
}

function closeModal() {
    document.getElementById('assignmentModal').classList.remove('active');
}

function toggleFileOptions() {
    const type = document.getElementById('submissionType').value;
    document.getElementById('fileOptions').style.display = type === 'file' ? 'block' : 'none';
}


document.getElementById('assignmentModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
