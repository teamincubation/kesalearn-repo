<?php
require_once __DIR__ . '/../../includes/admin_check.php';
$db = getDB();
$adminPage = 'assignments';
$quizId = (int)($_GET['id'] ?? 0);
if (!$quizId) { setFlash('error', 'Quiz not found.'); redirect('/admin/assignments/quizzes.php'); }

// Fetch quiz
$quiz = $db->prepare("SELECT q.*, e.title as event_title FROM quizzes q JOIN events e ON q.event_id = e.id WHERE q.id = ?");
$quiz->execute([$quizId]);
$quiz = $quiz->fetch(PDO::FETCH_ASSOC);
if (!$quiz) { setFlash('error', 'Quiz not found.'); redirect('/admin/assignments/quizzes.php'); }
$pageTitle = 'Edit: ' . $quiz['title'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) { setFlash('error', 'Invalid request.'); redirect($_SERVER['REQUEST_URI']); }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_quiz') {
        $stmt = $db->prepare("UPDATE quizzes SET title=?, description=?, duration_minutes=?, max_attempts=?, is_active=?, show_results=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([sanitize($_POST['title']), $_POST['description'] ?? '', (int)$_POST['duration'], (int)$_POST['max_attempts'], isset($_POST['is_active'])?1:0, isset($_POST['show_results'])?1:0, $quizId]);
        setFlash('success', 'Quiz settings saved.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'add_question') {
        $qText = trim($_POST['question_text'] ?? '');
        $marks = (int)($_POST['marks'] ?? 1);
        if ($qText) {
            $maxOrder = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE quiz_id = ?");
            $maxOrder->execute([$quizId]);
            $order = $maxOrder->fetchColumn();
            $stmt = $db->prepare("INSERT INTO quiz_questions (quiz_id, question_text, marks, sort_order) VALUES (?,?,?,?)");
            $stmt->execute([$quizId, $qText, $marks, $order]);
            $newQId = $db->lastInsertId();
            // Add options
            $options = $_POST['options'] ?? [];
            $correct = (int)($_POST['correct_option'] ?? 0);
            foreach ($options as $i => $optText) {
                $optText = trim($optText);
                if ($optText !== '') {
                    $db->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?,?,?,?)")
                       ->execute([$newQId, $optText, ($i == $correct) ? 1 : 0, $i + 1]);
                }
            }
            setFlash('success', 'Question added.');
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'delete_question') {
        $qid = (int)$_POST['question_id'];
        $db->prepare("DELETE FROM quiz_options WHERE question_id = ?")->execute([$qid]);
        $db->prepare("DELETE FROM quiz_responses WHERE question_id = ?")->execute([$qid]);
        $db->prepare("DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?")->execute([$qid, $quizId]);
        setFlash('success', 'Question deleted.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'update_question') {
        $qid = (int)$_POST['question_id'];
        $qText = trim($_POST['question_text'] ?? '');
        $marks = (int)($_POST['marks'] ?? 1);
        if ($qText) {
            $db->prepare("UPDATE quiz_questions SET question_text=?, marks=? WHERE id=? AND quiz_id=?")->execute([$qText, $marks, $qid, $quizId]);
            // Delete old options and re-insert
            $db->prepare("DELETE FROM quiz_options WHERE question_id = ?")->execute([$qid]);
            $options = $_POST['options'] ?? [];
            $correct = (int)($_POST['correct_option'] ?? 0);
            foreach ($options as $i => $optText) {
                $optText = trim($optText);
                if ($optText !== '') {
                    $db->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?,?,?,?)")
                       ->execute([$qid, $optText, ($i == $correct) ? 1 : 0, $i + 1]);
                }
            }
            setFlash('success', 'Question updated.');
        }
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Fetch questions with options
$questions = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order ASC, id ASC");
$questions->execute([$quizId]);
$questions = $questions->fetchAll(PDO::FETCH_ASSOC);

foreach ($questions as &$q) {
    $opts = $db->prepare("SELECT * FROM quiz_options WHERE question_id = ? ORDER BY sort_order ASC, id ASC");
    $opts->execute([$q['id']]);
    $q['options'] = $opts->fetchAll(PDO::FETCH_ASSOC);
}
unset($q);

$totalMarks = array_sum(array_column($questions, 'marks'));

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.qe-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; flex-wrap:wrap; gap:16px; }
.qe-back { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; text-decoration:none; color:#475569; font-size:0.88rem; font-weight:600; }
.qe-back:hover { background:#e2e8f0; }
.qe-info { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.qe-chip { padding:8px 16px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; font-size:0.88rem; color:#475569; display:flex; align-items:center; gap:6px; }
.qe-chip strong { color:#1e293b; }
.q-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:16px; }
.q-card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; }
.q-number { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; background:linear-gradient(135deg,#9B59B6,#5B7FD1); color:#fff; border-radius:8px; font-size:0.85rem; font-weight:700; flex-shrink:0; }
.q-text { font-size:1rem; font-weight:600; color:#1e293b; margin:0 12px; flex:1; line-height:1.5; }
.q-marks { font-size:0.8rem; color:#64748b; background:#f1f5f9; padding:4px 10px; border-radius:6px; white-space:nowrap; }
.q-options { display:grid; gap:8px; margin-top:12px; }
.q-opt { display:flex; align-items:center; gap:10px; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:0.9rem; }
.q-opt.correct { background:#f0fdf4; border-color:#86efac; }
.q-opt-letter { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.8rem; font-weight:700; flex-shrink:0; background:#e2e8f0; color:#475569; }
.q-opt.correct .q-opt-letter { background:#16a34a; color:#fff; }
.q-actions { display:flex; gap:8px; margin-top:12px; justify-content:flex-end; }
.q-btn { padding:6px 14px; border-radius:6px; font-size:0.82rem; font-weight:600; cursor:pointer; border:none; display:inline-flex; align-items:center; gap:4px; }
.q-btn-edit { background:#eff6ff; color:#2563eb; }
.q-btn-delete { background:#fef2f2; color:#dc2626; }
/* Add question form */
.add-q-form { background:#fff; border:2px dashed #e2e8f0; border-radius:12px; padding:24px; margin-top:24px; }
.add-q-form h3 { font-size:1.1rem; font-weight:700; margin:0 0 16px; color:#1e293b; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; margin-bottom:6px; font-weight:600; color:#374151; font-size:0.9rem; }
.form-group input, .form-group textarea { width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.95rem; }
.form-group input:focus, .form-group textarea:focus { outline:none; border-color:#9B59B6; box-shadow:0 0 0 3px rgba(155,89,182,0.1); }
.opt-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.opt-row input[type="text"] { flex:1; }
.opt-row input[type="radio"] { width:20px; height:20px; accent-color:#16a34a; }
.btn-add-opt { padding:8px 14px; background:#f1f5f9; border:1px dashed #cbd5e1; border-radius:6px; cursor:pointer; font-size:0.85rem; color:#475569; }
.btn-submit-q { padding:12px 24px; background:linear-gradient(135deg,#9B59B6,#5B7FD1); color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.95rem; }
.btn-submit-q:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(155,89,182,0.3); }
/* Settings form */
.settings-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; margin-bottom:24px; }
.settings-card h3 { font-size:1.1rem; font-weight:700; margin:0 0 16px; color:#1e293b; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-check { display:flex; align-items:center; gap:10px; }
.form-check input { width:18px; height:18px; }
.btn-save { padding:10px 20px; background:#1e293b; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; }
/* Edit modal */
.edit-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:20px; }
.edit-overlay.active { display:flex; }
.edit-modal { background:#fff; border-radius:16px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; padding:24px; box-shadow:0 25px 50px rgba(0,0,0,0.25); }
</style>

<div class="qe-header">
    <div>
        <a href="/admin/assignments/quizzes.php" class="qe-back">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Quizzes
        </a>
        <h1 style="margin:12px 0 4px;font-size:1.5rem;font-weight:800;color:#0f172a;"><?php echo sanitize($quiz['title']); ?></h1>
        <p style="margin:0;color:#64748b;font-size:0.92rem;"><?php echo sanitize($quiz['event_title']); ?></p>
    </div>
</div>

<div class="qe-info">
    <div class="qe-chip"><strong><?php echo count($questions); ?></strong> Questions</div>
    <div class="qe-chip"><strong><?php echo $totalMarks; ?></strong> Total Marks</div>
    <div class="qe-chip"><strong><?php echo $quiz['duration_minutes']; ?></strong> Minutes</div>
    <div class="qe-chip"><strong><?php echo $quiz['max_attempts']; ?></strong> Attempts Allowed</div>
    <div class="qe-chip" style="background:<?php echo $quiz['is_active'] ? '#dcfce7' : '#fef3c7'; ?>; border-color:<?php echo $quiz['is_active'] ? '#86efac' : '#fde68a'; ?>;">
        <strong><?php echo $quiz['is_active'] ? 'Active' : 'Inactive'; ?></strong>
    </div>
</div>

<!-- Quiz Settings -->
<div class="settings-card">
    <h3>Quiz Settings</h3>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="update_quiz">
        <div class="form-group"><label>Title</label><input type="text" name="title" value="<?php echo sanitize($quiz['title']); ?>" required></div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="2"><?php echo sanitize($quiz['description']); ?></textarea></div>
        <div class="form-row">
            <div class="form-group"><label>Duration (minutes)</label><input type="number" name="duration" value="<?php echo $quiz['duration_minutes']; ?>" min="1"></div>
            <div class="form-group"><label>Max Attempts</label><input type="number" name="max_attempts" value="<?php echo $quiz['max_attempts']; ?>" min="1"></div>
        </div>
        <div class="form-group"><label class="form-check"><input type="checkbox" name="is_active" <?php echo $quiz['is_active']?'checked':''; ?>><span>Active</span></label></div>
        <div class="form-group"><label class="form-check"><input type="checkbox" name="show_results" <?php echo $quiz['show_results']?'checked':''; ?>><span>Show results to learners</span></label></div>
        <button type="submit" class="btn-save">Save Settings</button>
    </form>
</div>

<!-- Questions List -->
<h2 style="font-size:1.2rem;font-weight:700;margin-bottom:16px;">Questions (<?php echo count($questions); ?>)</h2>

<?php foreach ($questions as $idx => $q): ?>
<div class="q-card" id="q-<?php echo $q['id']; ?>">
    <div class="q-card-header">
        <span class="q-number"><?php echo $idx + 1; ?></span>
        <span class="q-text"><?php echo sanitize($q['question_text']); ?></span>
        <span class="q-marks"><?php echo $q['marks']; ?> mark<?php echo $q['marks']>1?'s':''; ?></span>
    </div>
    <div class="q-options">
        <?php foreach ($q['options'] as $oi => $opt): ?>
        <div class="q-opt <?php echo $opt['is_correct'] ? 'correct' : ''; ?>">
            <span class="q-opt-letter"><?php echo chr(65 + $oi); ?></span>
            <?php echo sanitize($opt['option_text']); ?>
            <?php if ($opt['is_correct']): ?><span style="margin-left:auto;font-size:0.78rem;color:#16a34a;font-weight:700;">Correct</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="q-actions">
        <button class="q-btn q-btn-edit" onclick="editQuestion(<?php echo htmlspecialchars(json_encode($q), ENT_QUOTES); ?>)">Edit</button>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this question?');">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
            <button type="submit" class="q-btn q-btn-delete">Delete</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Add Question Form -->
<div class="add-q-form">
    <h3>Add New Question</h3>
    <form method="POST" id="addQuestionForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_question">
        <div class="form-group">
            <label>Question Text *</label>
            <textarea name="question_text" rows="2" placeholder="Enter your MCQ question..." required></textarea>
        </div>
        <div class="form-group">
            <label>Marks</label>
            <input type="number" name="marks" value="1" min="1" max="100" style="width:100px;">
        </div>
        <div class="form-group">
            <label>Options (select the correct answer)</label>
            <div id="optionsContainer">
                <div class="opt-row"><input type="radio" name="correct_option" value="0" checked><input type="text" name="options[]" placeholder="Option A" required></div>
                <div class="opt-row"><input type="radio" name="correct_option" value="1"><input type="text" name="options[]" placeholder="Option B" required></div>
                <div class="opt-row"><input type="radio" name="correct_option" value="2"><input type="text" name="options[]" placeholder="Option C"></div>
                <div class="opt-row"><input type="radio" name="correct_option" value="3"><input type="text" name="options[]" placeholder="Option D"></div>
            </div>
            <button type="button" class="btn-add-opt" onclick="addOption()">+ Add Option</button>
        </div>
        <button type="submit" class="btn-submit-q">Add Question</button>
    </form>
</div>

<!-- Edit Question Modal -->
<div class="edit-overlay" id="editModal">
    <div class="edit-modal">
        <h3 style="margin:0 0 20px;">Edit Question</h3>
        <form method="POST" id="editForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_question">
            <input type="hidden" name="question_id" id="editQId">
            <div class="form-group"><label>Question</label><textarea name="question_text" id="editQText" rows="2" required></textarea></div>
            <div class="form-group"><label>Marks</label><input type="number" name="marks" id="editQMarks" min="1" style="width:100px;"></div>
            <div class="form-group"><label>Options</label><div id="editOptions"></div></div>
            <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="document.getElementById('editModal').classList.remove('active')" style="padding:10px 20px;background:#f1f5f9;border:none;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
                <button type="submit" class="btn-submit-q">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
var optCount = 4;
function addOption() {
    var c = document.getElementById('optionsContainer');
    var row = document.createElement('div');
    row.className = 'opt-row';
    row.innerHTML = '<input type="radio" name="correct_option" value="'+optCount+'"><input type="text" name="options[]" placeholder="Option '+ String.fromCharCode(65+optCount) +'">';
    c.appendChild(row);
    optCount++;
}

function editQuestion(q) {
    document.getElementById('editQId').value = q.id;
    document.getElementById('editQText').value = q.question_text;
    document.getElementById('editQMarks').value = q.marks;
    var html = '';
    q.options.forEach(function(o, i) {
        html += '<div class="opt-row"><input type="radio" name="correct_option" value="'+i+'" '+(o.is_correct?'checked':'')+'><input type="text" name="options[]" value="'+o.option_text.replace(/"/g,'&quot;')+'" required></div>';
    });
    document.getElementById('editOptions').innerHTML = html;
    document.getElementById('editModal').classList.add('active');
}

document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
