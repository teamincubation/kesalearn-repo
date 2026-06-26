<?php
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'assignments';
$pageTitle = 'Quiz Management';

// Events and instructors for form
try { $events = $db->query("SELECT id, title FROM events ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { $events = []; }
try { $instructors = $db->query("SELECT id, name FROM instructors WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { $instructors = []; }

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) { setFlash('error', 'Invalid request.'); redirect('/admin/assignments/quizzes.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_quiz') {
        $stmt = $db->prepare("INSERT INTO quizzes (event_id, title, description, duration_minutes, max_attempts, is_active, show_results, assigned_instructor_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            (int)$_POST['event_id'], sanitize($_POST['title']), $_POST['description'] ?? '',
            (int)($_POST['duration'] ?? 30), (int)($_POST['max_attempts'] ?? 1),
            isset($_POST['is_active']) ? 1 : 0, isset($_POST['show_results']) ? 1 : 0,
            !empty($_POST['assigned_instructor']) ? (int)$_POST['assigned_instructor'] : null
        ]);
        setFlash('success', 'Quiz created! Now add questions.');
        redirect('/admin/assignments/quiz-edit.php?id=' . $db->lastInsertId());
    }

    if ($action === 'delete_quiz') {
        $qid = (int)$_POST['quiz_id'];
        $db->prepare("DELETE FROM quiz_responses WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id = ?)")->execute([$qid]);
        $db->prepare("DELETE FROM quiz_attempts WHERE quiz_id = ?")->execute([$qid]);
        $db->prepare("DELETE FROM quiz_options WHERE question_id IN (SELECT id FROM quiz_questions WHERE quiz_id = ?)")->execute([$qid]);
        $db->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?")->execute([$qid]);
        $db->prepare("DELETE FROM quizzes WHERE id = ?")->execute([$qid]);
        setFlash('success', 'Quiz deleted.');
        redirect('/admin/assignments/quizzes.php');
    }

    if ($action === 'toggle_quiz') {
        $qid = (int)$_POST['quiz_id'];
        $db->prepare("UPDATE quizzes SET is_active = NOT is_active WHERE id = ?")->execute([$qid]);
        setFlash('success', 'Quiz status updated.');
        redirect('/admin/assignments/quizzes.php');
    }
}

// Fetch quizzes
$filterEvent = (int)($_GET['event_id'] ?? 0);
$sql = "SELECT q.*, e.title as event_title,
        (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as question_count,
        (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id) as attempt_count,
        (SELECT ROUND(AVG(percentage),1) FROM quiz_attempts WHERE quiz_id = q.id AND status != 'in_progress') as avg_score
        FROM quizzes q JOIN events e ON q.event_id = e.id";
if ($filterEvent > 0) $sql .= " WHERE q.event_id = " . $filterEvent;
$sql .= " ORDER BY q.created_at DESC";
try { $quizzes = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { $quizzes = []; $tableError = 'Quiz tables not found. Run migrations/create_quiz_tables.sql first.'; }

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.quiz-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
.quiz-grid { display: grid; gap: 16px; }
.quiz-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; transition: all 0.2s; }
.quiz-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.quiz-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
.quiz-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }
.quiz-event { font-size: 0.85rem; color: #64748b; margin-top: 4px; }
.quiz-badges { display: flex; gap: 8px; flex-wrap: wrap; }
.qbadge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.qbadge-active { background: #dcfce7; color: #16a34a; }
.qbadge-inactive { background: #fef3c7; color: #d97706; }
.qbadge-purple { background: #ede9fe; color: #7c3aed; }
.quiz-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 16px 0; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; margin: 12px 0; }
.qstat { text-align: center; }
.qstat-val { font-size: 1.25rem; font-weight: 700; color: #1e293b; }
.qstat-lbl { font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.quiz-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
.qbtn { padding: 8px 14px; border-radius: 6px; font-size: 0.85rem; font-weight: 500; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.qbtn-edit { background: #f1f5f9; color: #475569; }
.qbtn-edit:hover { background: #e2e8f0; }
.qbtn-delete { background: #fef2f2; color: #dc2626; }
.qbtn-delete:hover { background: #fee2e2; }
.qbtn-results { background: #eff6ff; color: #2563eb; }
.qbtn-results:hover { background: #dbeafe; }
.btn-create-quiz { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: linear-gradient(135deg, #9B59B6, #5B7FD1); color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s; }
.btn-create-quiz:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(155,89,182,0.3); }
/* Modal reuses assignment styles */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal-content { background: #fff; border-radius: 16px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.25); }
.modal-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
.modal-header h2 { margin: 0; font-size: 1.25rem; font-weight: 700; }
.modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
.modal-body { padding: 24px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #374151; font-size: 0.9rem; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #9B59B6; box-shadow: 0 0 0 3px rgba(155,89,182,0.1); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-hint { font-size: 0.8rem; color: #64748b; margin-top: 4px; }
.form-check { display: flex; align-items: center; gap: 10px; }
.form-check input { width: 18px; height: 18px; }
.modal-footer { padding: 16px 24px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 12px; }
.btn-cancel { padding: 10px 20px; background: #f1f5f9; color: #475569; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
.btn-submit { padding: 10px 24px; background: linear-gradient(135deg, #9B59B6, #5B7FD1); color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
.empty-state { text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; border: 2px dashed #e2e8f0; }
.empty-state h3 { margin: 0 0 8px; color: #475569; }
.empty-state p { color: #64748b; margin: 0; }
.filter-form { display: flex; gap: 12px; align-items: center; }
.filter-form select { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; min-width: 200px; }
</style>

<div class="quiz-header">
    <div>
        <h1 style="margin:0 0 4px;font-size:1.6rem;font-weight:800;color:#0f172a;">Quiz Management</h1>
        <p style="margin:0;color:#64748b;font-size:0.92rem;">Create and manage CBT-style quizzes for your courses</p>
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
        <a href="/admin/assignments/" class="qbtn qbtn-edit" style="padding:11px 18px;border:1px solid #e2e8f0;border-radius:10px;">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Assignments
        </a>
        <button class="btn-create-quiz" onclick="document.getElementById('quizModal').classList.add('active')">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Quiz
        </button>
    </div>
</div>

<?php if (!empty($tableError)): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
    <strong style="color:#991b1b;">Database Setup Required</strong>
    <p style="color:#7f1d1d;margin:8px 0 0;font-size:0.9rem;"><?php echo $tableError; ?></p>
</div>
<?php endif; ?>

<?php if (empty($quizzes)): ?>
<div class="empty-state">
    <svg width="64" height="64" fill="none" stroke="#94a3b8" viewBox="0 0 24 24" style="margin-bottom:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <h3>No quizzes yet</h3>
    <p>Create your first quiz to get started</p>
</div>
<?php else: ?>
<div class="quiz-grid">
    <?php foreach ($quizzes as $quiz): ?>
    <div class="quiz-card">
        <div class="quiz-card-header">
            <div>
                <h3 class="quiz-title"><?php echo sanitize($quiz['title']); ?></h3>
                <p class="quiz-event"><?php echo sanitize($quiz['event_title']); ?></p>
            </div>
            <div class="quiz-badges">
                <span class="qbadge <?php echo $quiz['is_active'] ? 'qbadge-active' : 'qbadge-inactive'; ?>"><?php echo $quiz['is_active'] ? 'Active' : 'Inactive'; ?></span>
                <span class="qbadge qbadge-purple"><?php echo $quiz['question_count']; ?> Questions</span>
            </div>
        </div>
        <div class="quiz-stats">
            <div class="qstat"><div class="qstat-val"><?php echo $quiz['duration_minutes']; ?>m</div><div class="qstat-lbl">Duration</div></div>
            <div class="qstat"><div class="qstat-val"><?php echo $quiz['max_attempts']; ?></div><div class="qstat-lbl">Max Attempts</div></div>
            <div class="qstat"><div class="qstat-val"><?php echo $quiz['attempt_count']; ?></div><div class="qstat-lbl">Attempts</div></div>
            <div class="qstat"><div class="qstat-val"><?php echo $quiz['avg_score'] ? $quiz['avg_score'].'%' : '-'; ?></div><div class="qstat-lbl">Avg Score</div></div>
        </div>
        <div class="quiz-actions">
            <?php if ($quiz['attempt_count'] > 0): ?>
            <a href="/admin/assignments/quiz-results.php?quiz_id=<?php echo $quiz['id']; ?>" class="qbtn qbtn-results">View Results</a>
            <?php endif; ?>
            <a href="/admin/assignments/quiz-edit.php?id=<?php echo $quiz['id']; ?>" class="qbtn qbtn-edit">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Edit Questions
            </a>
            <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="action" value="toggle_quiz"><input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>"><button type="submit" class="qbtn qbtn-edit"><?php echo $quiz['is_active'] ? 'Deactivate' : 'Activate'; ?></button></form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this quiz and all its data?');"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="action" value="delete_quiz"><input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>"><button type="submit" class="qbtn qbtn-delete"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Create Quiz Modal -->
<div class="modal-overlay" id="quizModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Create New Quiz</h2>
            <button class="modal-close" onclick="document.getElementById('quizModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_quiz">
            <div class="modal-body">
                <div class="form-group">
                    <label>Course/Event *</label>
                    <select name="event_id" required>
                        <option value="">Select a course</option>
                        <?php foreach ($events as $event): ?>
                        <option value="<?php echo $event['id']; ?>"><?php echo sanitize($event['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quiz Title *</label>
                    <input type="text" name="title" required placeholder="e.g., Module 1 Assessment">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Brief instructions for learners..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Duration (minutes) *</label>
                        <input type="number" name="duration" value="30" min="1" max="300" required>
                    </div>
                    <div class="form-group">
                        <label>Max Attempts</label>
                        <input type="number" name="max_attempts" value="1" min="1" max="10">
                    </div>
                </div>
                <div class="form-group">
                    <label>Assign to Instructor</label>
                    <select name="assigned_instructor">
                        <option value="">-- No specific instructor --</option>
                        <?php foreach ($instructors as $ins): ?>
                        <option value="<?php echo $ins['id']; ?>"><?php echo sanitize($ins['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-check"><input type="checkbox" name="is_active" checked><span>Active (visible to learners)</span></label>
                </div>
                <div class="form-group">
                    <label class="form-check"><input type="checkbox" name="show_results" checked><span>Show results to learners after submission</span></label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="document.getElementById('quizModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn-submit">Create Quiz</button>
            </div>
        </form>
    </div>
</div>
<script>document.getElementById('quizModal').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
