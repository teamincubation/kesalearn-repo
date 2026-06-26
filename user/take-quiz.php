<?php
/**
 * KESA Learn - Quiz Taking Interface (CBT-style)
 * Features: timer, question navigation, auto-save, tab-switch detection, auto-submit
 */
require_once __DIR__ . '/../includes/auth_check.php';
$db = getDB();
$userId = $_SESSION['user_id'];
$quizId = (int)($_GET['quiz_id'] ?? 0);
$eventId = (int)($_GET['event_id'] ?? 0);

if (!$quizId || !$eventId) { setFlash('error', 'Invalid quiz link.'); redirect('/user/dashboard'); }

// Fetch quiz
try {
    $quiz = $db->prepare("SELECT q.*, e.title as event_title FROM quizzes q JOIN events e ON q.event_id = e.id WHERE q.id = ? AND q.is_active = 1");
    $quiz->execute([$quizId]);
    $quiz = $quiz->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setFlash('error', 'Quiz system not yet initialized. Please contact your instructor.');
    redirect('/user/dashboard');
}

if (!$quiz) { setFlash('error', 'Quiz not found or not active.'); redirect('/user/dashboard'); }

// Verify registration
try {
    $reg = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ?");
    $reg->execute([$userId, $eventId]);
} catch (PDOException $e) {
    setFlash('error', 'Database error. Please try again later.');
    redirect('/user/dashboard');
}

if (!$reg->fetch()) { setFlash('error', 'Not registered for this course.'); redirect('/user/dashboard'); }

// Check attempt limits
try {
    $attemptCount = $db->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status != 'in_progress'");
    $attemptCount->execute([$quizId, $userId]);
    $completedAttempts = $attemptCount->fetchColumn();
} catch (PDOException $e) {
    $completedAttempts = 0;
}

// Check for existing in-progress attempt
try {
    $inProgress = $db->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $inProgress->execute([$quizId, $userId]);
    $attempt = $inProgress->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $attempt = null;
}

// Fetch questions
try {
    $questions = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id");
    $questions->execute([$quizId]);
    $questions = $questions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($questions as &$q) {
        $opts = $db->prepare("SELECT id, option_text, sort_order FROM quiz_options WHERE question_id = ? ORDER BY sort_order, id");
        $opts->execute([$q['id']]);
        $q['options'] = $opts->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($q);
} catch (PDOException $e) {
    $questions = [];
}

$totalMarks = array_sum(array_column($questions, 'marks'));

// Get last result if exists
try {
    $lastRes = $db->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status = 'submitted' ORDER BY id DESC LIMIT 1");
    $lastRes->execute([$quizId, $userId]);
    $lastResult = $lastRes->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $lastResult = null;
}

$canTake = ($completedAttempts < $quiz['max_attempts']) || $attempt;


// Handle API calls (AJAX auto-save and submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    if (!verifyCSRFToken($data['csrf_token'] ?? null)) {
        echo json_encode(['error' => 'Security token expired. Please reload the page.']);
        exit;
    }
    
    $action = $data['action'] ?? '';

    if ($action === 'start_quiz') {
        if ($completedAttempts >= $quiz['max_attempts']) {
            echo json_encode(['error' => 'Max attempts reached']); exit;
        }
        if ($attempt) {
            echo json_encode(['attempt_id' => $attempt['id'], 'resumed' => true]); exit;
        }
        $stmt = $db->prepare("INSERT INTO quiz_attempts (quiz_id, user_id, max_score) VALUES (?, ?, ?)");
        $stmt->execute([$quizId, $userId, $totalMarks]);
        echo json_encode(['attempt_id' => $db->lastInsertId()]); exit;
    }

    if ($action === 'save_answer') {
        $attemptId = (int)($data['attempt_id'] ?? 0);
        $questionId = (int)($data['question_id'] ?? 0);
        $optionId = (int)($data['option_id'] ?? 0);
        // Verify attempt belongs to user
        $verify = $db->prepare("SELECT id FROM quiz_attempts WHERE id = ? AND user_id = ? AND status = 'in_progress'");
        $verify->execute([$attemptId, $userId]);
        if (!$verify->fetch()) { echo json_encode(['error' => 'Invalid attempt']); exit; }
        // Check if correct
        $isCorrect = $db->prepare("SELECT is_correct FROM quiz_options WHERE id = ? AND question_id = ?");
        $isCorrect->execute([$optionId, $questionId]);
        $correct = (int)$isCorrect->fetchColumn();
        $marks = $db->prepare("SELECT marks FROM quiz_questions WHERE id = ?");
        $marks->execute([$questionId]);
        $qMarks = (int)$marks->fetchColumn();
        // Upsert
        $db->prepare("INSERT INTO quiz_responses (attempt_id, question_id, selected_option_id, is_correct, marks_awarded) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE selected_option_id = VALUES(selected_option_id), is_correct = VALUES(is_correct), marks_awarded = VALUES(marks_awarded), answered_at = NOW()")
           ->execute([$attemptId, $questionId, $optionId, $correct, $correct ? $qMarks : 0]);
        echo json_encode(['saved' => true, 'is_correct' => $correct]); exit;
    }

    if ($action === 'submit_quiz') {
        $attemptId = (int)($data['attempt_id'] ?? 0);
        $status = $data['status'] ?? 'completed';
        $tabSwitches = (int)($data['tab_switches'] ?? 0);
        $verify = $db->prepare("SELECT id, started_at FROM quiz_attempts WHERE id = ? AND user_id = ? AND status = 'in_progress'");
        $verify->execute([$attemptId, $userId]);
        $att = $verify->fetch(PDO::FETCH_ASSOC);
        if (!$att) { echo json_encode(['error' => 'Invalid attempt']); exit; }
        // Calculate score
        $scoreStmt = $db->prepare("SELECT SUM(marks_awarded) FROM quiz_responses WHERE attempt_id = ?");
        $scoreStmt->execute([$attemptId]);
        $totalScore = (int)$scoreStmt->fetchColumn();
        $pct = $totalMarks > 0 ? round(($totalScore / $totalMarks) * 100, 2) : 0;
        $timeSpent = time() - strtotime($att['started_at']);
        $db->prepare("UPDATE quiz_attempts SET status = ?, total_score = ?, percentage = ?, completed_at = NOW(), time_spent_seconds = ?, tab_switches = ? WHERE id = ?")
           ->execute([$status, $totalScore, $pct, $timeSpent, $tabSwitches, $attemptId]);
        echo json_encode(['score' => $totalScore, 'max' => $totalMarks, 'percentage' => $pct, 'show_results' => $quiz['show_results']]); exit;
    }

    if ($action === 'report_tab_switch') {
        $attemptId = (int)($data['attempt_id'] ?? 0);
        $db->prepare("UPDATE quiz_attempts SET tab_switches = tab_switches + 1 WHERE id = ? AND user_id = ?")->execute([$attemptId, $userId]);
        $ts = $db->prepare("SELECT tab_switches FROM quiz_attempts WHERE id = ?");
        $ts->execute([$attemptId]);
        echo json_encode(['tab_switches' => (int)$ts->fetchColumn()]); exit;
    }

    echo json_encode(['error' => 'Unknown action']); exit;
}

// Get existing responses for resume
$savedResponses = [];
if ($attempt) {
    $resp = $db->prepare("SELECT question_id, selected_option_id FROM quiz_responses WHERE attempt_id = ?");
    $resp->execute([$attempt['id']]);
    while ($r = $resp->fetch(PDO::FETCH_ASSOC)) {
        $savedResponses[$r['question_id']] = $r['selected_option_id'];
    }
}

// Check if max attempts reached
$canTake = $completedAttempts < $quiz['max_attempts'] || $attempt;

// Get last completed result
$lastResult = null;
if ($completedAttempts > 0 && $quiz['show_results']) {
    $lr = $db->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? AND status != 'in_progress' ORDER BY completed_at DESC LIMIT 1");
    $lr->execute([$quizId, $userId]);
    $lastResult = $lr->fetch(PDO::FETCH_ASSOC);
}

$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo sanitize($quiz['title']); ?> - KESA Learn</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--kesa-red:#E94E4E;--kesa-purple:#9B59B6;--kesa-blue:#5B7FD1;--kesa-yellow:#F4C542;--text:#1e293b;--text-muted:#64748b;--border:#e2e8f0;--surface:#fff;--bg:#f8fafc;--radius:12px}
body{font-family:'Montserrat',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* Quiz header bar */
.quiz-bar{background:linear-gradient(135deg,var(--kesa-red),var(--kesa-purple),var(--kesa-blue));color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;position:sticky;top:0;z-index:100}
.quiz-bar-title{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.quiz-bar-timer{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.2);backdrop-filter:blur(4px);padding:8px 16px;border-radius:8px;font-weight:700;font-size:1.1rem}
.quiz-bar-timer.warning{background:rgba(220,38,38,.3);animation:timerPulse 1s infinite}
@keyframes timerPulse{0%,100%{opacity:1}50%{opacity:.7}}
.quiz-bar-progress{font-size:.85rem;opacity:.9}

/* Layout */
.quiz-layout{display:grid;grid-template-columns:240px 1fr;max-width:1100px;margin:0 auto;gap:20px;padding:20px}
@media(max-width:768px){.quiz-layout{grid-template-columns:1fr;padding:12px}.quiz-nav{position:fixed;bottom:0;left:0;right:0;z-index:99;border-radius:16px 16px 0 0;max-height:50vh;overflow-y:auto}}

/* Question navigation */
.quiz-nav{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;align-self:start;position:sticky;top:80px}
.quiz-nav h3{font-size:.85rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
.nav-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.nav-btn{width:100%;aspect-ratio:1;border:2px solid var(--border);border-radius:8px;background:var(--surface);font-weight:700;font-size:.85rem;cursor:pointer;transition:all .2s;color:var(--text)}
.nav-btn.answered{background:#dcfce7;border-color:#86efac;color:#16a34a}
.nav-btn.current{border-color:var(--kesa-blue);background:#eff6ff;color:var(--kesa-blue)}
.nav-btn:hover{border-color:var(--kesa-purple)}
.nav-legend{display:flex;gap:12px;margin-top:14px;font-size:.78rem;color:var(--text-muted)}
.nav-legend span{display:flex;align-items:center;gap:4px}
.legend-dot{width:10px;height:10px;border-radius:3px}
.legend-current{background:#eff6ff;border:2px solid var(--kesa-blue)}
.legend-answered{background:#dcfce7;border:2px solid #86efac}
.legend-unanswered{background:var(--surface);border:2px solid var(--border)}

/* Question card */
.question-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:28px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.q-header{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.q-badge{background:linear-gradient(135deg,var(--kesa-purple),var(--kesa-blue));color:#fff;padding:8px 14px;border-radius:8px;font-weight:800;font-size:.9rem;flex-shrink:0}
.q-marks-badge{background:#f1f5f9;padding:6px 12px;border-radius:6px;font-size:.82rem;font-weight:600;color:var(--text-muted);margin-left:auto}
.q-text{font-size:1.1rem;font-weight:600;line-height:1.6;color:var(--text);margin-bottom:24px}
.options-list{display:grid;gap:10px}
.option-btn{display:flex;align-items:center;gap:14px;padding:16px 18px;border:2px solid var(--border);border-radius:10px;background:var(--surface);cursor:pointer;transition:all .15s;text-align:left;font-size:.95rem;color:var(--text);width:100%;font-family:inherit}
.option-btn:hover{border-color:var(--kesa-blue);background:rgba(91,127,209,.05)}
.option-btn.selected{border-color:var(--kesa-blue);background:rgba(91,127,209,.1);font-weight:600}
.option-letter{width:36px;height:36px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;flex-shrink:0;transition:all .15s}
.option-btn.selected .option-letter{background:var(--kesa-blue);color:#fff;border-color:var(--kesa-blue)}

/* Navigation buttons */
.q-nav{display:flex;justify-content:space-between;margin-top:24px;gap:12px}
.q-nav-btn{padding:12px 24px;border:none;border-radius:10px;font-weight:700;font-size:.9rem;cursor:pointer;display:inline-flex;align-items:center;gap:8px;font-family:inherit;transition:all .2s}
.btn-prev{background:#f1f5f9;color:var(--text)}
.btn-prev:hover{background:#e2e8f0}
.btn-next{background:var(--kesa-blue);color:#fff}
.btn-next:hover{background:var(--kesa-purple)}
.btn-finish{background:linear-gradient(135deg,var(--kesa-red),var(--kesa-purple));color:#fff;box-shadow:0 4px 14px rgba(233,78,78,.25)}
.btn-finish:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(233,78,78,.35)}

/* Pre-quiz screen */
.quiz-intro{max-width:600px;margin:40px auto;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:40px;text-align:center}
.quiz-intro h1{font-size:1.5rem;font-weight:800;margin-bottom:8px}
.quiz-intro .course{color:var(--text-muted);margin-bottom:24px}
.qi-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:24px 0}
.qi-stat{background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center}
.qi-stat-val{font-size:1.3rem;font-weight:800;color:var(--text)}
.qi-stat-lbl{font-size:.78rem;color:var(--text-muted);margin-top:4px}
.qi-desc{color:var(--text-muted);font-size:.92rem;line-height:1.6;margin:20px 0;text-align:left}
.qi-rules{text-align:left;margin:20px 0;padding:16px;background:rgba(233,78,78,.05);border:1px solid rgba(233,78,78,.15);border-radius:10px}
.qi-rules h4{font-size:.88rem;font-weight:700;color:var(--kesa-red);margin-bottom:8px}
.qi-rules li{font-size:.85rem;color:var(--text-muted);margin-bottom:6px}
.btn-start{padding:16px 40px;background:linear-gradient(135deg,var(--kesa-red),var(--kesa-purple));color:#fff;border:none;border-radius:12px;font-size:1.05rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 6px 20px rgba(233,78,78,.3);transition:all .2s}
.btn-start:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(233,78,78,.4)}
.btn-start:disabled{opacity:.5;cursor:not-allowed}

/* Result */
.result-card{max-width:500px;margin:40px auto;background:var(--surface);border-radius:var(--radius);padding:40px;text-align:center;border:1px solid var(--border)}
.result-score{font-size:3rem;font-weight:900;margin:20px 0 8px}
.result-pct{font-size:1.2rem;font-weight:700;color:var(--text-muted)}

/* Tab warning */
.tab-warning{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:16px;padding:40px;text-align:center;z-index:9999;box-shadow:0 25px 50px rgba(0,0,0,.3);max-width:400px;width:90%;display:none}
.tab-warning.show{display:block}
.tab-warning-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9998;display:none}
.tab-warning-overlay.show{display:block}
</style>
</head>
<body>

<!-- Pre-quiz intro -->
<div id="introScreen">
    <div class="quiz-intro">
        <h1><?php echo sanitize($quiz['title']); ?></h1>
        <p class="course"><?php echo sanitize($quiz['event_title']); ?></p>
        
        <div class="qi-stats">
            <div class="qi-stat"><div class="qi-stat-val"><?php echo count($questions); ?></div><div class="qi-stat-lbl">Questions</div></div>
            <div class="qi-stat"><div class="qi-stat-val"><?php echo $quiz['duration_minutes']; ?>m</div><div class="qi-stat-lbl">Duration</div></div>
            <div class="qi-stat"><div class="qi-stat-val"><?php echo $totalMarks; ?></div><div class="qi-stat-lbl">Total Marks</div></div>
        </div>
        
        <?php if ($quiz['description']): ?>
        <div class="qi-desc"><?php echo nl2br(sanitize($quiz['description'])); ?></div>
        <?php endif; ?>
        
        <div class="qi-rules">
            <h4>Quiz Rules</h4>
            <ul>
                <li>You have <?php echo $quiz['duration_minutes']; ?> minutes to complete the quiz.</li>
                <li>Answers are saved automatically when you select an option.</li>
                <li>The quiz will auto-submit when time runs out.</li>
                <li>Switching tabs will trigger a warning. Repeated violations may auto-submit.</li>
                <li>You have <?php echo $quiz['max_attempts']; ?> attempt(s). Used: <?php echo $completedAttempts; ?>/<?php echo $quiz['max_attempts']; ?></li>
            </ul>
        </div>
        
        <?php if ($lastResult): ?>
        <div style="margin:16px 0;padding:14px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;">
            <p style="font-size:.9rem;color:#166534;font-weight:600;">Last Score: <?php echo $lastResult['total_score']; ?>/<?php echo $lastResult['max_score']; ?> (<?php echo $lastResult['percentage']; ?>%)</p>
        </div>
        <?php endif; ?>
        
        <?php if ($canTake): ?>
        <button class="btn-start" onclick="startQuiz()" id="startBtn">
            <?php echo $attempt ? 'Resume Quiz' : 'Start Quiz'; ?>
        </button>
        <?php else: ?>
        <button class="btn-start" disabled>Maximum Attempts Reached</button>
        <a href="/user/event-details.php?event_id=<?php echo $eventId; ?>" style="display:block;margin-top:16px;color:var(--kesa-blue);font-weight:600;">Back to Course</a>
        <?php endif; ?>
    </div>
</div>

<!-- Quiz interface (hidden until started) -->
<div id="quizScreen" style="display:none;">
    <div class="quiz-bar">
        <div class="quiz-bar-title">
            <a href="javascript:void(0)" onclick="confirmExit()" style="color:#fff;text-decoration:none;font-size:1.2rem;">&#10094;</a>
            <?php echo sanitize($quiz['title']); ?>
        </div>
        <div class="quiz-bar-progress">
            <span id="answeredCount">0</span>/<?php echo count($questions); ?> answered
        </div>
        <div class="quiz-bar-timer" id="timerDisplay">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span id="timerText">--:--</span>
        </div>
    </div>
    
    <div class="quiz-layout">
        <div class="quiz-nav" id="questionNav">
            <h3>Questions</h3>
            <div class="nav-grid" id="navGrid"></div>
            <div class="nav-legend">
                <span><span class="legend-dot legend-current"></span>Current</span>
                <span><span class="legend-dot legend-answered"></span>Answered</span>
                <span><span class="legend-dot legend-unanswered"></span>Unanswered</span>
            </div>
        </div>
        
        <div id="questionArea"></div>
    </div>
</div>

<!-- Result screen -->
<div id="resultScreen" style="display:none;">
    <div class="result-card">
        <h2>Quiz Complete!</h2>
        <div class="result-score" id="resultScore">-/-</div>
        <div class="result-pct" id="resultPct">--%</div>
        <p style="color:var(--text-muted);margin:16px 0;">Your answers have been submitted for review.</p>
        <a href="/user/event-details.php?event_id=<?php echo $eventId; ?>" class="btn-start" style="display:inline-block;text-decoration:none;font-size:.95rem;padding:14px 32px;margin-top:8px;">Back to Course</a>
    </div>
</div>

<!-- Tab warning modal -->
<div class="tab-warning-overlay" id="tabWarningOverlay"></div>
<div class="tab-warning" id="tabWarning">
    <div style="font-size:2rem;margin-bottom:12px;">&#9888;</div>
    <h3 style="margin-bottom:8px;color:var(--kesa-red);">Tab Switch Detected!</h3>
    <p style="color:var(--text-muted);margin-bottom:16px;font-size:.9rem;" id="tabWarningText">Switching tabs during a quiz is not allowed. Further violations may auto-submit your quiz.</p>
    <button onclick="dismissTabWarning()" style="padding:12px 24px;background:var(--kesa-red);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:inherit;">I Understand</button>
</div>

<script>
var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
var questions = <?php echo json_encode($questions); ?>;
var savedResponses = <?php echo json_encode($savedResponses); ?>;
var quizDuration = <?php echo $quiz['duration_minutes']; ?> * 60;
var attemptId = <?php echo $attempt ? $attempt['id'] : 'null'; ?>;
var resumeStartedAt = <?php echo $attempt ? "'" . $attempt['started_at'] . "'" : 'null'; ?>;
var currentQ = 0;
var answers = {};
var tabSwitches = 0;
var maxTabSwitches = 3;
var timerInterval = null;
var remainingSeconds = quizDuration;

// Restore saved answers
for (var qId in savedResponses) {
    answers[qId] = savedResponses[qId];
}

function startQuiz() {
    document.getElementById('startBtn').disabled = true;
    document.getElementById('startBtn').textContent = 'Starting...';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'start_quiz', csrf_token: csrfToken})
    }).then(r => r.json()).then(data => {
        if (data.error) { alert(data.error); return; }
        attemptId = data.attempt_id;
        
        // Calculate remaining time if resuming
        if (data.resumed && resumeStartedAt) {
            var elapsed = Math.floor((Date.now() - new Date(resumeStartedAt).getTime()) / 1000);
            remainingSeconds = Math.max(0, quizDuration - elapsed);
            if (remainingSeconds <= 0) { submitQuiz('timed_out'); return; }
        }
        
        document.getElementById('introScreen').style.display = 'none';
        document.getElementById('quizScreen').style.display = 'block';
        buildNav();
        showQuestion(0);
        startTimer();
        setupAntiCheat();
    });
}

function buildNav() {
    var html = '';
    questions.forEach(function(q, i) {
        var cls = i === 0 ? 'current' : '';
        if (answers[q.id]) cls += ' answered';
        html += '<button class="nav-btn '+cls+'" onclick="showQuestion('+i+')" id="nav-'+i+'">'+(i+1)+'</button>';
    });
    document.getElementById('navGrid').innerHTML = html;
}

function updateNav() {
    var answeredCount = 0;
    questions.forEach(function(q, i) {
        var btn = document.getElementById('nav-'+i);
        btn.className = 'nav-btn';
        if (i === currentQ) btn.classList.add('current');
        if (answers[q.id]) { btn.classList.add('answered'); answeredCount++; }
    });
    document.getElementById('answeredCount').textContent = answeredCount;
}

function showQuestion(idx) {
    currentQ = idx;
    var q = questions[idx];
    var html = '<div class="question-card">';
    html += '<div class="q-header"><span class="q-badge">Q'+(idx+1)+'</span>';
    html += '<span class="q-marks-badge">'+q.marks+' mark'+(q.marks>1?'s':'')+'</span></div>';
    html += '<div class="q-text">'+escHtml(q.question_text)+'</div>';
    html += '<div class="options-list">';
    q.options.forEach(function(opt, oi) {
        var sel = answers[q.id] == opt.id ? ' selected' : '';
        html += '<button class="option-btn'+sel+'" onclick="selectOption('+q.id+','+opt.id+',this)">';
        html += '<span class="option-letter">'+String.fromCharCode(65+oi)+'</span>';
        html += '<span>'+escHtml(opt.option_text)+'</span></button>';
    });
    html += '</div><div class="q-nav">';
    if (idx > 0) html += '<button class="q-nav-btn btn-prev" onclick="showQuestion('+(idx-1)+')">&#8592; Previous</button>';
    else html += '<span></span>';
    if (idx < questions.length - 1) html += '<button class="q-nav-btn btn-next" onclick="showQuestion('+(idx+1)+')">Next &#8594;</button>';
    else html += '<button class="q-nav-btn btn-finish" onclick="confirmSubmit()">Finish Quiz</button>';
    html += '</div></div>';
    document.getElementById('questionArea').innerHTML = html;
    updateNav();
}

function selectOption(qId, optId, btn) {
    answers[qId] = optId;
    // Visual
    btn.parentNode.querySelectorAll('.option-btn').forEach(function(b){b.classList.remove('selected');});
    btn.classList.add('selected');
    updateNav();
    // Auto-save via AJAX
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'save_answer', attempt_id:attemptId, question_id:qId, option_id:optId, csrf_token: csrfToken})
    });
}

function startTimer() {
    updateTimerDisplay();
    timerInterval = setInterval(function() {
        remainingSeconds--;
        updateTimerDisplay();
        if (remainingSeconds <= 0) {
            clearInterval(timerInterval);
            submitQuiz('timed_out');
        }
    }, 1000);
}

function updateTimerDisplay() {
    var m = Math.floor(remainingSeconds / 60);
    var s = remainingSeconds % 60;
    document.getElementById('timerText').textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    var el = document.getElementById('timerDisplay');
    if (remainingSeconds <= 60) el.classList.add('warning');
    else el.classList.remove('warning');
}

function setupAntiCheat() {
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && attemptId) {
            tabSwitches++;
            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action:'report_tab_switch', attempt_id:attemptId, csrf_token: csrfToken})
            });
            if (tabSwitches >= maxTabSwitches) {
                submitQuiz('auto_submitted');
            } else {
                document.getElementById('tabWarningText').textContent = 
                    'Warning ' + tabSwitches + '/' + maxTabSwitches + '. Switching tabs is not allowed. Your quiz will be auto-submitted after ' + maxTabSwitches + ' violations.';
                document.getElementById('tabWarning').classList.add('show');
                document.getElementById('tabWarningOverlay').classList.add('show');
            }
        }
    });
}

function dismissTabWarning() {
    document.getElementById('tabWarning').classList.remove('show');
    document.getElementById('tabWarningOverlay').classList.remove('show');
}

function confirmSubmit() {
    var unanswered = questions.length - Object.keys(answers).length;
    var msg = 'Submit your quiz?';
    if (unanswered > 0) msg = 'You have ' + unanswered + ' unanswered question(s). Submit anyway?';
    if (confirm(msg)) submitQuiz('completed');
}

function confirmExit() {
    if (confirm('Are you sure? Your progress is saved but the timer will continue.')) {
        window.location.href = '/user/event-details.php?event_id=<?php echo $eventId; ?>';
    }
}

function submitQuiz(status) {
    if (timerInterval) clearInterval(timerInterval);
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'submit_quiz', attempt_id:attemptId, status:status, tab_switches:tabSwitches, csrf_token: csrfToken})
    }).then(r => r.json()).then(data => {
        document.getElementById('quizScreen').style.display = 'none';
        document.getElementById('resultScreen').style.display = 'block';
        if (data.show_results) {
            document.getElementById('resultScore').textContent = data.score + '/' + data.max;
            document.getElementById('resultPct').textContent = data.percentage + '%';
        } else {
            document.getElementById('resultScore').textContent = 'Submitted';
            document.getElementById('resultPct').textContent = 'Results will be shared by your instructor.';
        }
    });
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
</body>
</html>
