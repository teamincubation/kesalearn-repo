<?php
/**
 * KESA Learn - Assignment Submission Page
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db    = getDB();
$userId = $_SESSION['user_id'];

$assignmentId = (int)($_GET['assignment_id'] ?? 0);
$eventId      = (int)($_GET['event_id'] ?? 0);

if (!$assignmentId || !$eventId) {
    setFlash('error', 'Invalid assignment link.');
    redirect('/user/dashboard');
}

// Fetch assignment with course title
$assignStmt = $db->prepare("
    SELECT a.*, e.title AS event_title
    FROM assignments a
    JOIN events e ON a.event_id = e.id
    WHERE a.id = ? AND a.is_active = 1
    LIMIT 1
");
$assignStmt->execute([$assignmentId]);
$assignment = $assignStmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    setFlash('error', 'Assignment not found or is no longer active.');
    redirect('/user/dashboard');
}

// Verify registration
$regStmt = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND event_id = ? LIMIT 1");
$regStmt->execute([$userId, $eventId]);
if (!$regStmt->fetch()) {
    setFlash('error', 'You are not registered for this course.');
    redirect('/user/dashboard');
}

// Fetch existing submission
$subStmt = $db->prepare("SELECT * FROM assignment_submissions WHERE user_id = ? AND assignment_id = ? LIMIT 1");
$subStmt->execute([$userId, $assignmentId]);
$existing = $subStmt->fetch(PDO::FETCH_ASSOC);

// Fetch materials (gracefully skip if table not yet created)
$materials = [];
try {
    $matStmt = $db->prepare("SELECT * FROM assignment_materials WHERE assignment_id = ? ORDER BY created_at DESC");
    $matStmt->execute([$assignmentId]);
    $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table may not exist yet */ }

// Deadline & submit eligibility
$deadlinePassed = !empty($assignment['deadline']) && strtotime($assignment['deadline']) < time();
$isApproved     = $existing && $existing['status'] === 'approved';
$canSubmit      = !$isApproved && !($deadlinePassed && !$existing);

// submission_type in DB ENUM is: file, text, photo  — url is stored as text_content
$subType = $assignment['submission_type'] ?? 'file';
// Admin may set 'url' as submission_type label even if ENUM doesn't include it;
// treat it as 'text' column-wise but handle display and validation separately.
$isUrl = ($subType === 'url');
if ($isUrl) $subType = 'text'; // map to DB column

// Handle POST
$submitSuccess = false;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSubmit) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        try {
            $dbType      = $assignment['submission_type'];
            $filePath    = null;
            $fileName    = null;
            $fileSize    = null;
            $textContent = null;

            if ($dbType === 'file') {
                // ---- File Upload ----
                if (empty($_FILES['file']['name'])) throw new Exception('Please select a file to upload.');
                $f = $_FILES['file'];
                if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload failed (error ' . $f['error'] . '). Please try again.');

                $maxBytes = ($assignment['max_file_size_mb'] ?? 50) * 1024 * 1024;
                if ($f['size'] > $maxBytes) {
                    throw new Exception('File exceeds the ' . ($assignment['max_file_size_mb'] ?? 50) . ' MB limit.');
                }

                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!empty($assignment['allowed_extensions'])) {
                    $allowed = array_map('trim', explode(',', strtolower($assignment['allowed_extensions'])));
                    if (!in_array($ext, $allowed)) {
                        throw new Exception('.' . $ext . ' files are not allowed. Accepted: ' . $assignment['allowed_extensions']);
                    }
                }

                $dir = __DIR__ . '/../uploads/submissions/';
                @mkdir($dir, 0755, true);
                $stored = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) {
                    throw new Exception('Could not save the file. Please contact support.');
                }
                $filePath = 'submissions/' . $stored;
                $fileName = $f['name'];
                $fileSize = $f['size'];

            } elseif ($dbType === 'url') {
                // ---- URL ----
                $url = filter_var(trim($_POST['url'] ?? ''), FILTER_VALIDATE_URL);
                if (!$url) throw new Exception('Please enter a valid URL (must start with https:// or http://).');
                $textContent = $url;

            } elseif ($dbType === 'text') {
                // ---- Text ----
                $text = trim($_POST['text'] ?? '');
                if (empty($text)) throw new Exception('Please write your response before submitting.');
                $textContent = sanitizeHTML($text);

            } elseif ($dbType === 'photo') {
                // ---- Photo ----
                $photoB64 = $_POST['photo'] ?? '';
                if (empty($photoB64)) throw new Exception('Please capture a photo before submitting.');
                $imgData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $photoB64));
                if (!$imgData) throw new Exception('Invalid photo data. Please retake the photo.');
                $dir = __DIR__ . '/../uploads/submissions/';
                @mkdir($dir, 0755, true);
                $stored = time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
                file_put_contents($dir . $stored, $imgData);
                $filePath = 'submissions/' . $stored;
                $fileName = 'photo_' . date('Ymd_His') . '.jpg';
                $fileSize = strlen($imgData);
            }

            // Map 'url' label to 'text' for the ENUM column
            $dbTypeForInsert = ($dbType === 'url') ? 'text' : $dbType;

            if ($existing) {
                $upd = $db->prepare("
                    UPDATE assignment_submissions
                    SET submission_type = ?, file_path = ?, file_name = ?, file_size = ?,
                        text_content = ?, status = 'pending', submitted_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([$dbTypeForInsert, $filePath, $fileName, $fileSize, $textContent, $existing['id']]);
            } else {
                $ins = $db->prepare("
                    INSERT INTO assignment_submissions
                        (assignment_id, user_id, submission_type, file_path, file_name, file_size, text_content, status, submitted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $ins->execute([$assignmentId, $userId, $dbTypeForInsert, $filePath, $fileName, $fileSize, $textContent]);
            }
            $submitSuccess = true;

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Helpers
$csrf      = generateCSRFToken();
$typeLabel = ['file' => 'File Upload', 'text' => 'Text Entry', 'url' => 'Enter URL', 'photo' => 'Photo Capture'];
$rawType   = $assignment['submission_type'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit: <?php echo sanitize($assignment['title']); ?> - KESA Learn</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        /* KESA Brand Colors */
        --kesa-red:    #E94E4E;
        --kesa-purple: #9B59B6;
        --kesa-blue:   #5B7FD1;
        --kesa-yellow: #F4C542;
        /* Semantic */
        --kesa-primary: #E94E4E;
        --kesa-accent:  #5B7FD1;
        --text:        #1e293b;
        --text-muted:  #64748b;
        --border:      #e2e8f0;
        --surface:     #ffffff;
        --bg:          #f8fafc;
        --radius:      12px;
    }

    body {
        font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: var(--bg);
        color: var(--text);
        line-height: 1.6;
        min-height: 100vh;
    }

    /* Header with KESA gradient */
    .sa-header {
        background: linear-gradient(135deg, var(--kesa-red) 0%, var(--kesa-purple) 50%, var(--kesa-blue) 100%);
        color: #fff;
        padding: 24px 16px 28px;
        position: relative;
        overflow: hidden;
    }
    .sa-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 300px;
        height: 300px;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
    }
    .sa-header-inner { max-width: 860px; margin: 0 auto; position: relative; z-index: 1; }
    .back-link {
        display: inline-flex; align-items: center; gap: 6px;
        color: rgba(255,255,255,.9); text-decoration: none;
        font-size: .85rem; font-weight: 600;
        padding: 8px 16px; border-radius: 8px;
        background: rgba(255,255,255,.15);
        backdrop-filter: blur(4px);
        margin-bottom: 16px; transition: all .2s;
    }
    .back-link:hover { background: rgba(255,255,255,.25); transform: translateX(-2px); }
    .sa-header h1 { font-size: 1.6rem; font-weight: 800; line-height: 1.3; margin-bottom: 6px; letter-spacing: -0.02em; }
    .sa-header-course { font-size: .95rem; color: rgba(255,255,255,.85); font-weight: 500; }

    /* ── Layout ── */
    .sa-body { max-width: 860px; margin: 0 auto; padding: 24px 16px 48px; }
    .sa-grid {
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 20px;
        align-items: start;
    }
    @media (max-width: 700px) {
        .sa-grid { grid-template-columns: 1fr; }
        .sa-header h1 { font-size: 1.2rem; }
    }

    /* ── Card ── */
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        box-shadow: 0 1px 4px rgba(0,0,0,.07);
    }
    .card + .card { margin-top: 16px; }
    .card-heading {
        font-size: .8rem; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--kesa-purple);
        border-bottom: 2px solid var(--border);
        padding-bottom: 12px; margin-bottom: 18px;
    }

    /* ── Info rows ── */
    .info-list { display: grid; gap: 10px; }
    .info-row { display: flex; justify-content: space-between; gap: 12px; font-size: .9rem; }
    .info-key { color: var(--text-muted); font-weight: 500; flex-shrink: 0; }
    .info-val { color: var(--text); font-weight: 600; text-align: right; }

    /* ── Alerts ── */
    .alert {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 13px 16px; border-radius: var(--radius);
        font-size: .9rem; margin-bottom: 16px;
    }
    .alert-error   { background: #fef2f2; color: #991b1b; border-left: 4px solid #dc2626; }
    .alert-success { background: #f0fdf4; color: #166534; border-left: 4px solid #16a34a; }
    .alert-warning { background: #fffbeb; color: #92400e; border-left: 4px solid #f59e0b; }
    .alert-info    { background: #eff6ff; color: #1e40af; border-left: 4px solid #3b82f6; }

    /* ── Badge ── */
    .badge {
        display: inline-block; padding: 3px 10px;
        border-radius: 20px; font-size: .78rem; font-weight: 700;
    }
    .badge-pending  { background: #fef3c7; color: #92400e; }
    .badge-approved { background: #d1fae5; color: #065f46; }
    .badge-rejected { background: #fee2e2; color: #991b1b; }

    /* Description */
    .description-box {
        background: linear-gradient(135deg, rgba(233,78,78,0.05) 0%, rgba(91,127,209,0.05) 100%);
        border: 1px solid var(--border);
        border-left: 4px solid var(--kesa-blue);
        border-radius: 10px;
        padding: 18px 20px;
        font-size: .95rem;
        color: var(--text);
        line-height: 1.8;
        white-space: pre-wrap;
        word-break: break-word;
    }

    /* Materials */
    .material-row {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 16px; border-radius: 10px;
        background: linear-gradient(135deg, rgba(155,89,182,0.06) 0%, rgba(91,127,209,0.06) 100%);
        border: 1px solid rgba(155,89,182,0.15);
        margin-bottom: 10px; transition: all .2s;
    }
    .material-row:hover { transform: translateX(4px); box-shadow: 0 4px 12px rgba(155,89,182,0.1); }
    .material-icon {
        width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0;
        background: linear-gradient(135deg, var(--kesa-purple), var(--kesa-blue));
        display: flex; align-items: center; justify-content: center;
        font-size: .75rem; font-weight: 800; color: #fff;
        text-transform: uppercase;
    }
    .material-meta { flex: 1; min-width: 0; }
    .material-name { font-size: .9rem; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .material-size { font-size: .8rem; color: var(--text-muted); margin-top: 2px; }
    .material-dl {
        padding: 8px 14px; border-radius: 8px; font-size: .82rem; font-weight: 700;
        background: var(--kesa-blue); color: #fff; text-decoration: none; flex-shrink: 0;
        transition: all .2s;
    }
    .material-dl:hover { background: var(--kesa-purple); transform: scale(1.02); }

    /* ── Form elements ── */
    .form-group { margin-bottom: 18px; }
    .form-label {
        display: block; font-size: .88rem; font-weight: 700;
        color: var(--text); margin-bottom: 7px;
    }
    .form-hint { font-size: .8rem; color: var(--text-muted); margin-top: 5px; }

    /* File drop zone */
    .file-drop {
        border: 2px dashed var(--border); border-radius: var(--radius);
        padding: 40px 20px; text-align: center; cursor: pointer;
        background: linear-gradient(135deg, rgba(233,78,78,0.02) 0%, rgba(91,127,209,0.02) 100%);
        transition: all .25s;
    }
    .file-drop:hover, .file-drop.over { border-color: var(--kesa-blue); background: rgba(91,127,209,0.08); }
    .file-drop-icon { font-size: 2.2rem; margin-bottom: 6px; opacity: .7; }
    .file-drop-label { font-weight: 600; color: var(--text); font-size: .95rem; }
    .file-drop-sub { font-size: .82rem; color: var(--text-muted); margin-top: 3px; }
    .file-drop input[type="file"] { display: none; }
    .file-chosen {
        display: none; margin-top: 10px; padding: 10px 14px;
        background: #f0fdf4; border: 1px solid #86efac;
        border-radius: 7px; font-size: .88rem; color: #166534;
    }
    .file-chosen.show { display: block; }

    /* Extensions pill list */
    .ext-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .ext-pill {
        padding: 2px 9px; border-radius: 20px; font-size: .75rem; font-weight: 700;
        background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;
    }

    /* Textarea */
    .rich-area {
        width: 100%; min-height: 220px; padding: 12px 14px;
        border: 1px solid var(--border); border-radius: var(--radius);
        font-family: inherit; font-size: .93rem; resize: vertical;
        transition: border-color .2s, box-shadow .2s; color: var(--text);
    }
    .rich-area:focus {
        outline: none; border-color: var(--kesa-blue);
        box-shadow: 0 0 0 3px rgba(91,127,209,.15);
    }
    .char-row { font-size: .78rem; color: var(--text-muted); text-align: right; margin-top: 4px; }

    /* URL input */
    .url-field {
        width: 100%; padding: 12px 14px;
        border: 1px solid var(--border); border-radius: var(--radius);
        font-size: .93rem; color: var(--text);
        transition: border-color .2s, box-shadow .2s;
    }
    .url-field:focus {
        outline: none; border-color: var(--kesa-blue);
        box-shadow: 0 0 0 3px rgba(91,127,209,.15);
    }

    /* Camera */
    .camera-wrap { position: relative; border-radius: var(--radius); overflow: hidden; background: #000; min-height: 180px; }
    #cameraFeed { width: 100%; display: block; }
    #photoSnap { width: 100%; display: none; border-radius: var(--radius); }
    #photoCanvas { display: none; }
    .camera-bar { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }

    /* Permission prompt */
    .cam-permission {
        background: #f0fdfa; border: 1px solid #99f6e4;
        border-radius: var(--radius); padding: 20px;
        text-align: center;
    }
    .cam-permission p { color: var(--text-muted); font-size: .88rem; margin-bottom: 12px; }

    /* Buttons */
    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: 12px 24px; border: none; border-radius: 10px;
        font-size: .9rem; font-weight: 700; cursor: pointer;
        text-decoration: none; transition: all .2s; white-space: nowrap;
        font-family: 'Montserrat', sans-serif;
    }
    .btn-primary {
        background: linear-gradient(135deg, var(--kesa-red) 0%, var(--kesa-purple) 100%);
        color: #fff;
        box-shadow: 0 4px 14px rgba(233,78,78,.25);
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(233,78,78,.35); }
    .btn-blue   { background: var(--kesa-blue); color: #fff; }
    .btn-blue:hover { background: var(--kesa-purple); }
    .btn-ghost  { background: #f1f5f9; color: var(--text); border: 1px solid var(--border); }
    .btn-ghost:hover { background: #e2e8f0; }
    .btn-danger { background: var(--kesa-red); color: #fff; }
    .btn-danger:hover { background: #d63c3c; }
    .btn[disabled] { opacity: .5; cursor: not-allowed; pointer-events: none; }

    /* Form actions */
    .form-actions {
        display: flex; gap: 10px; padding-top: 16px;
        border-top: 1px solid var(--border); margin-top: 20px;
    }
    .form-actions .btn-primary { flex: 1; padding: 12px; }

    /* Success state */
    .success-block { text-align: center; padding: 32px 20px; }
    .success-icon { font-size: 3rem; margin-bottom: 10px; }
    .success-block h2 { font-size: 1.3rem; color: #166534; margin-bottom: 6px; }
    .success-block p { color: var(--text-muted); font-size: .9rem; margin-bottom: 20px; }
    </style>
</head>
<body>

<!-- Header -->
<div class="sa-header">
    <div class="sa-header-inner">
        <a href="/user/event-details.php?event_id=<?php echo $eventId; ?>" class="back-link">
            &#8592; Back to Course
        </a>
        <h1><?php echo sanitize($assignment['title']); ?></h1>
        <div class="sa-header-course"><?php echo sanitize($assignment['event_title']); ?></div>
    </div>
</div>

<div class="sa-body">

<?php if ($submitSuccess): ?>
    <!-- Success -->
    <div class="card">
        <div class="success-block">
            <div class="success-icon">&#10003;</div>
            <h2>Assignment Submitted!</h2>
            <p>Your submission has been received and is pending review.</p>
            <a href="/user/event-details.php?event_id=<?php echo $eventId; ?>" class="btn btn-primary">Back to Course</a>
        </div>
    </div>

<?php else: ?>

    <!-- Alerts -->
    <?php if ($error): ?>
        <div class="alert alert-error">&#10005; <?php echo sanitize($error); ?></div>
    <?php endif; ?>
    <?php if ($isApproved): ?>
        <div class="alert alert-success">&#10003; This assignment has been approved. No further submission needed.</div>
    <?php elseif ($deadlinePassed && !$existing): ?>
        <div class="alert alert-warning">&#9888; The deadline for this assignment has passed.</div>
    <?php elseif ($existing): ?>
        <div class="alert alert-info">&#8505; You have already submitted. You can resubmit to update your answer.</div>
    <?php endif; ?>

    <div class="sa-grid">

        <!-- LEFT COLUMN -->
        <div>

            <!-- Assignment info -->
            <div class="card">
                <div class="card-heading">Assignment Details</div>
                <div class="info-list">
                    <div class="info-row">
                        <span class="info-key">Course</span>
                        <span class="info-val"><?php echo sanitize($assignment['event_title']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-key">Submission type</span>
                        <span class="info-val"><?php echo $typeLabel[$rawType] ?? ucfirst($rawType); ?></span>
                    </div>
                    <?php if (!empty($assignment['deadline'])): ?>
                    <div class="info-row">
                        <span class="info-key">Deadline</span>
                        <span class="info-val" style="color:<?php echo $deadlinePassed ? '#dc2626' : 'inherit'; ?>">
                            <?php echo formatDateTime($assignment['deadline'], 'M d, Y h:i A'); ?>
                            <?php if ($deadlinePassed) echo ' <small>(passed)</small>'; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($assignment['max_score'])): ?>
                    <div class="info-row">
                        <span class="info-key">Max score</span>
                        <span class="info-val"><?php echo (int)$assignment['max_score']; ?> pts</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <?php if (!empty(trim($assignment['description']))): ?>
            <div class="card">
                <div class="card-heading">Assignment Description</div>
                <div class="description-box"><?php echo nl2br(sanitize($assignment['description'])); ?></div>
            </div>
            <?php endif; ?>

            <!-- Materials -->
            <?php if (!empty($materials)): ?>
            <div class="card">
                <div class="card-heading">Reference Materials</div>
                <?php foreach ($materials as $m):
                    $ext = strtoupper(pathinfo($m['file_name'], PATHINFO_EXTENSION));
                ?>
                <div class="material-row">
                    <div class="material-icon"><?php echo $ext ?: 'FILE'; ?></div>
                    <div class="material-meta">
                        <div class="material-name"><?php echo sanitize($m['file_name']); ?></div>
                        <div class="material-size">
                            <?php echo !empty($m['file_size']) ? round($m['file_size'] / 1024, 1) . ' KB' : ''; ?>
                        </div>
                    </div>
                    <a href="/user/download-material.php?id=<?php echo (int)$m['id']; ?>" class="material-dl" target="_blank">Download</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Submission form -->
            <?php if ($canSubmit): ?>
            <div class="card">
                <div class="card-heading">Your Submission</div>

                <form method="POST" enctype="multipart/form-data" id="submitForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                    <?php if ($rawType === 'file'): ?>
                    <!-- FILE UPLOAD -->
                    <div class="form-group">
                        <label class="form-label">Upload your file</label>
                        <div class="file-drop" id="fileDrop">
                            <div class="file-drop-icon">&#128196;</div>
                            <div class="file-drop-label">Click to browse or drag &amp; drop</div>
                            <div class="file-drop-sub">Opens your file manager &mdash; any file type</div>
                            <input type="file" name="file" id="fileInput" required>
                        </div>
                        <div class="file-chosen" id="fileChosen"></div>
                        <?php if (!empty($assignment['allowed_extensions'])): ?>
                        <div style="margin-top:8px;">
                            <div class="form-hint" style="margin-bottom:5px;">Accepted formats:</div>
                            <div class="ext-list">
                                <?php foreach (array_map('trim', explode(',', $assignment['allowed_extensions'])) as $e): ?>
                                <span class="ext-pill">.<?php echo sanitize($e); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="form-hint">Max file size: <?php echo $assignment['max_file_size_mb'] ?? 50; ?> MB</div>
                    </div>

                    <?php elseif ($rawType === 'url'): ?>
                    <!-- URL SUBMISSION -->
                    <div class="form-group">
                        <label class="form-label" for="urlField">Paste or type your submission URL</label>
                        <input type="url" name="url" id="urlField" class="url-field"
                            placeholder="https://github.com/yourname/project  or  https://drive.google.com/..."
                            value="<?php echo $existing ? sanitize($existing['text_content'] ?? '') : ''; ?>"
                            required>
                        <div class="form-hint">Link to your work &mdash; GitHub, Google Drive, Notion, portfolio, etc.</div>
                    </div>

                    <?php elseif ($rawType === 'text'): ?>
                    <!-- TEXT ENTRY -->
                    <div class="form-group">
                        <label class="form-label" for="textArea">Write your response</label>
                        <textarea name="text" id="textArea" class="rich-area"
                            placeholder="Type your answer here..."
                            required><?php echo $existing ? sanitize($existing['text_content'] ?? '') : ''; ?></textarea>
                        <div class="char-row"><span id="charCount">0</span> characters</div>
                    </div>

                    <?php elseif ($rawType === 'photo'): ?>
                    <!-- PHOTO CAPTURE -->
                    <div class="form-group">
                        <label class="form-label">Take a photo</label>
                        <div id="camPermission" class="cam-permission">
                            <p>This assignment requires a photo. Click below to allow camera access.</p>
                            <button type="button" class="btn btn-teal" id="startCamBtn">&#128247; Allow Camera &amp; Start</button>
                        </div>
                        <div id="camArea" style="display:none;">
                            <div class="camera-wrap">
                                <video id="cameraFeed" autoplay playsinline></video>
                            </div>
                            <div class="camera-bar">
                                <button type="button" class="btn btn-danger" id="captureBtn">&#11096; Capture Photo</button>
                                <button type="button" class="btn btn-ghost" id="stopCamBtn">Stop Camera</button>
                            </div>
                        </div>
                        <div id="photoPreviewArea" style="display:none; margin-top:12px;">
                            <img id="photoSnap" src="" alt="Captured photo" style="width:100%; border-radius:var(--radius);">
                            <div class="camera-bar">
                                <button type="button" class="btn btn-ghost" id="retakeBtn">&#8635; Retake</button>
                            </div>
                        </div>
                        <canvas id="photoCanvas"></canvas>
                        <input type="hidden" name="photo" id="photoData">
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <a href="/user/event-details.php?event_id=<?php echo $eventId; ?>" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <?php echo $existing ? 'Resubmit' : 'Submit Assignment'; ?>
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div><!-- /left -->

        <!-- RIGHT COLUMN -->
        <div>

            <!-- Submission status card -->
            <?php if ($existing): ?>
            <div class="card">
                <div class="card-heading">Your Last Submission</div>
                <div class="info-list">
                    <div class="info-row">
                        <span class="info-key">Status</span>
                        <span class="info-val">
                            <span class="badge badge-<?php echo $existing['status']; ?>">
                                <?php echo ucfirst($existing['status']); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-key">Submitted</span>
                        <span class="info-val"><?php echo formatDateTime($existing['submitted_at'], 'M d, Y h:i A'); ?></span>
                    </div>
                    <?php if (!empty($existing['score'])): ?>
                    <div class="info-row">
                        <span class="info-key">Score</span>
                        <span class="info-val"><?php echo (int)$existing['score']; ?> / <?php echo (int)$assignment['max_score']; ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($existing['feedback'])): ?>
                    <div style="margin-top:10px; padding:10px; background:#f8fafc; border-radius:7px; font-size:.85rem; color:var(--text-muted);">
                        <strong style="color:var(--text);">Feedback:</strong><br>
                        <?php echo nl2br(sanitize($existing['feedback'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-heading">Status</div>
                <p style="font-size:.88rem; color:var(--text-muted);">Not yet submitted. Fill in the form and click Submit.</p>
            </div>
            <?php endif; ?>

            <!-- Tips card -->
            <div class="card" style="margin-top:16px;">
                <div class="card-heading">Tips</div>
                <ul style="font-size:.85rem; color:var(--text-muted); padding-left:16px; display:grid; gap:6px; line-height:1.5;">
                    <?php if ($rawType === 'file'): ?>
                    <li>Click the upload box or drag a file onto it.</li>
                    <li>Accepted formats are listed below the box.</li>
                    <li>Max <?php echo $assignment['max_file_size_mb'] ?? 50; ?> MB per file.</li>
                    <?php elseif ($rawType === 'url'): ?>
                    <li>Paste the full URL including https://</li>
                    <li>Make sure the link is set to public access.</li>
                    <?php elseif ($rawType === 'text'): ?>
                    <li>Write your full answer in the text box.</li>
                    <li>You can resubmit to update your answer.</li>
                    <?php elseif ($rawType === 'photo'): ?>
                    <li>Click &ldquo;Allow Camera &amp; Start&rdquo; and accept the browser permission prompt.</li>
                    <li>Position your work and click Capture.</li>
                    <li>Use Retake if needed before submitting.</li>
                    <?php endif; ?>
                </ul>
            </div>

        </div><!-- /right -->

    </div><!-- /grid -->

<?php endif; ?>
</div><!-- /sa-body -->

<script>
// ── File drop zone ──
(function () {
    var drop  = document.getElementById('fileDrop');
    var input = document.getElementById('fileInput');
    var chosen = document.getElementById('fileChosen');
    if (!drop) return;

    drop.addEventListener('click', function () { input.click(); });

    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', function ()  { drop.classList.remove('over'); });
    drop.addEventListener('drop', function (e) {
        e.preventDefault(); drop.classList.remove('over');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            showFile(input.files[0]);
        }
    });
    input.addEventListener('change', function () {
        if (input.files.length) showFile(input.files[0]);
    });

    function showFile(f) {
        var mb = (f.size / 1024 / 1024).toFixed(2);
        chosen.textContent = '\u2713 ' + f.name + ' (' + mb + ' MB)';
        chosen.classList.add('show');
    }
}());

// ── Character counter ──
(function () {
    var ta = document.getElementById('textArea');
    var cc = document.getElementById('charCount');
    if (!ta) return;
    ta.addEventListener('input', function () { cc.textContent = ta.value.length; });
    cc.textContent = ta.value.length;
}());

// ── Camera ──
(function () {
    var startBtn   = document.getElementById('startCamBtn');
    var captureBtn = document.getElementById('captureBtn');
    var retakeBtn  = document.getElementById('retakeBtn');
    var stopBtn    = document.getElementById('stopCamBtn');
    var permission = document.getElementById('camPermission');
    var camArea    = document.getElementById('camArea');
    var previewArea = document.getElementById('photoPreviewArea');
    var video      = document.getElementById('cameraFeed');
    var snap       = document.getElementById('photoSnap');
    var canvas     = document.getElementById('photoCanvas');
    var photoData  = document.getElementById('photoData');
    var submitBtn  = document.getElementById('submitBtn');
    if (!startBtn) return;

    var stream = null;

    startBtn.addEventListener('click', function () {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                permission.style.display = 'none';
                camArea.style.display    = 'block';
                if (submitBtn) submitBtn.setAttribute('disabled', '');
            })
            .catch(function (e) {
                alert('Camera access was denied or is unavailable.\n\nPlease allow camera access in your browser settings and try again.\n\nError: ' + e.message);
            });
    });

    captureBtn.addEventListener('click', function () {
        canvas.width  = video.videoWidth  || 640;
        canvas.height = video.videoHeight || 480;
        canvas.getContext('2d').drawImage(video, 0, 0);
        var dataUrl = canvas.toDataURL('image/jpeg', 0.92);
        snap.src        = dataUrl;
        photoData.value = dataUrl;

        // Stop stream
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        camArea.style.display     = 'none';
        previewArea.style.display = 'block';
        if (submitBtn) submitBtn.removeAttribute('disabled');
    });

    retakeBtn.addEventListener('click', function () {
        photoData.value = '';
        snap.src        = '';
        previewArea.style.display = 'none';
        if (submitBtn) submitBtn.setAttribute('disabled', '');
        // Restart camera
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                camArea.style.display = 'block';
            })
            .catch(function (e) { alert('Could not restart camera: ' + e.message); });
    });

    stopBtn.addEventListener('click', function () {
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        camArea.style.display = 'none';
        permission.style.display = 'block';
        if (submitBtn) submitBtn.setAttribute('disabled', '');
    });

    // Disable submit until photo taken
    if (submitBtn) submitBtn.setAttribute('disabled', '');
}());

// ── Submit guard for photo type ──
(function () {
    var form = document.getElementById('submitForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        var photoData = document.getElementById('photoData');
        if (photoData && !photoData.value) {
            e.preventDefault();
            alert('Please capture a photo before submitting.');
        }
    });
}());
</script>

</body>
</html>
