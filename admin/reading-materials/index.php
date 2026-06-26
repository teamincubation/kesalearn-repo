<?php
/**
 * KESA Learn — Admin: Reading Materials
 */
$adminPage = 'reading_materials';
$pageTitle  = 'Reading Materials';
require_once __DIR__ . '/../../includes/admin_check.php';

$db      = getDB();
$flash   = getFlash();
$dbError = null;

// Ensure upload directory exists
$uploadDir = __DIR__ . '/../../uploads/reading_materials/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── POST: add / delete ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid security token.');
        redirect('/admin/reading-materials/');
    }

    $action = $_POST['action'];

    // ── ADD ──────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $eventId      = (int)($_POST['event_id']      ?? 0);
        $title        = trim($_POST['title']           ?? '');
        $description  = trim($_POST['description']     ?? '');
        $availableFrom = trim($_POST['available_from'] ?? '');
        $externalUrl  = trim($_POST['external_url']    ?? '');

        if (!$eventId || $title === '') {
            setFlash('error', 'Event and title are required.');
            redirect('/admin/reading-materials/');
        }

        $filePath = null;
        $fileType = null;
        $fileSize = null;

        if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['material_file'];

            // Check PHP upload error codes first
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errMap = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload size limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Server could not write the file.',
                ];
                setFlash('error', $errMap[$file['error']] ?? 'Upload error code: ' . $file['error']);
                redirect('/admin/reading-materials/');
            }

            if ($file['size'] > 10 * 1024 * 1024) {
                setFlash('error', 'File too large. Maximum is 10 MB.');
                redirect('/admin/reading-materials/');
            }

            // Use finfo for reliable server-side MIME detection
            $allowedMimes = [
                'application/pdf'      => 'pdf',
                'application/msword'   => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'image/jpeg'           => 'jpg',
                'image/png'            => 'png',
            ];
            $detectedMime = 'unknown';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $detectedMime = finfo_file($fi, $file['tmp_name']);
                finfo_close($fi);
            } else {
                $detectedMime = $file['type'];
            }

            if (!array_key_exists($detectedMime, $allowedMimes)) {
                setFlash('error', 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG.');
                redirect('/admin/reading-materials/');
            }

            $ext      = $allowedMimes[$detectedMime];
            $fileName = uniqid('mat_', true) . '.' . $ext;
            $dest     = $uploadDir . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                setFlash('error', 'File could not be saved. Check server upload directory permissions.');
                redirect('/admin/reading-materials/');
            }

            $filePath = '/uploads/reading_materials/' . $fileName;
            $fileType = $ext;
            $fileSize = $file['size'];

        } elseif ($externalUrl !== '') {
            $filePath = $externalUrl;
            $fileType = 'link';
        } else {
            setFlash('error', 'Please upload a file or provide an external URL.');
            redirect('/admin/reading-materials/');
        }

        try {
            $db->prepare(
                "INSERT INTO event_materials
                    (event_id, title, description, file_path, file_type, file_size, available_from, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $eventId,
                sanitize($title),
                sanitize($description),
                $filePath,
                $fileType,
                $fileSize,
                $availableFrom !== '' ? $availableFrom : null,
            ]);
            logActivity('material_added', "Reading material added: {$title} (event #{$eventId})");
            setFlash('success', "Material \"" . htmlspecialchars($title) . "\" added successfully.");
        } catch (PDOException $e) {
            setFlash('error', 'Database error: ' . $e->getMessage());
        }

    // ── DELETE ───────────────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $id = (int)($_POST['material_id'] ?? 0);
        if ($id) {
            try {
                $mat = $db->prepare("SELECT file_path, file_type FROM event_materials WHERE id = ?");
                $mat->execute([$id]);
                $mat = $mat->fetch();
                if ($mat && $mat['file_type'] !== 'link' && !empty($mat['file_path'])) {
                    $localFile = __DIR__ . '/../../' . ltrim($mat['file_path'], '/');
                    if (file_exists($localFile)) unlink($localFile);
                }
                $db->prepare("DELETE FROM event_materials WHERE id = ?")->execute([$id]);
                setFlash('success', 'Material deleted.');
            } catch (PDOException $e) {
                setFlash('error', 'Delete failed: ' . $e->getMessage());
            }
        }
    }

    redirect('/admin/reading-materials/');
}

// ── Fetch data ─────────────────────────────────────────────────────────────
$events      = [];
$materials   = [];
$filterEvent = (int)($_GET['event_id'] ?? 0);

try {
    $events = $db->query("SELECT id, title FROM events ORDER BY start_date DESC")->fetchAll();

    $sql    = "SELECT em.*,
                      e.title AS event_title,
                      COALESCE((SELECT COUNT(*) FROM material_reads mr WHERE mr.material_id = em.id), 0) AS read_count
               FROM event_materials em
               JOIN events e ON e.id = em.event_id";
    $params = [];
    if ($filterEvent) {
        $sql   .= " WHERE em.event_id = ?";
        $params[] = $filterEvent;
    }
    $sql .= " ORDER BY em.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $materials = $stmt->fetchAll();

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

include __DIR__ . '/../includes/sidebar.php';
?>
<style>
/* ── Page header ─────────────────────────────────────────────────────────── */
.rm-header { margin-bottom: 24px; }
.rm-header h1 { margin: 0 0 4px; font-size: 1.4rem; font-weight: 800; color: var(--text-primary); }
.rm-header p  { margin: 0; font-size: 0.87rem; color: var(--text-muted); }

/* ── DB error banner ────────────────────────────────────────────────────── */
.rm-db-error {
    background: #fff7ed; border: 1.5px solid #fed7aa;
    border-radius: 12px; padding: 18px 20px; margin-bottom: 24px;
    display: flex; gap: 14px; align-items: flex-start;
}
.rm-db-error svg { color: #ea580c; flex-shrink: 0; margin-top: 2px; }
.rm-db-error strong { display: block; color: #9a3412; font-size: 0.9rem; margin-bottom: 4px; }
.rm-db-error p { margin: 0; font-size: 0.83rem; color: #c2410c; }
.rm-db-error a { color: #ea580c; font-weight: 600; }

/* ── Two-column layout ───────────────────────────────────────────────────── */
.rm-layout { display: flex; gap: 22px; align-items: flex-start; }

/* ── Left: add-material card ─────────────────────────────────────────────── */
.rm-form-card {
    width: 340px; flex-shrink: 0;
    background: var(--bg-primary);
    border: 1px solid var(--border-light);
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    position: sticky; top: 88px;
}
.rm-form-title {
    font-size: 0.95rem; font-weight: 700; color: var(--text-primary);
    margin: 0 0 20px; padding-bottom: 14px;
    border-bottom: 1px solid var(--border-light);
    display: flex; align-items: center; gap: 8px;
}
.rm-form-title svg { color: #0ea5e9; }
.rm-field { margin-bottom: 15px; }
.rm-label {
    display: block; font-size: 0.8rem; font-weight: 600;
    color: var(--text-secondary); margin-bottom: 5px; letter-spacing: 0.02em;
}
.rm-required { color: #e53e3e; }
.rm-ctrl {
    width: 100%; padding: 8px 11px;
    border: 1.5px solid var(--border-color);
    border-radius: 8px; font-size: 0.87rem;
    background: var(--bg-secondary); color: var(--text-primary);
    outline: none; box-sizing: border-box; transition: border-color 0.15s;
    font-family: inherit;
}
.rm-ctrl:focus { border-color: #0ea5e9; background: var(--bg-primary); }
textarea.rm-ctrl { resize: vertical; min-height: 66px; }

.rm-upload-zone {
    border: 2px dashed var(--border-color); border-radius: 9px;
    padding: 18px 12px; text-align: center; cursor: pointer;
    background: var(--bg-secondary); transition: all 0.2s;
}
.rm-upload-zone:hover { border-color: #0ea5e9; background: #f0f9ff; }
.rm-upload-zone svg { color: #94a3b8; margin-bottom: 6px; }
.rm-upload-label { font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; }
.rm-upload-label strong { color: var(--text-secondary); }
.rm-file-chosen { font-size: 0.8rem; color: #0ea5e9; font-weight: 600; margin-top: 6px; }

.rm-or { text-align: center; font-size: 0.77rem; color: var(--text-muted); margin: 12px 0; position: relative; }
.rm-or::before, .rm-or::after {
    content: ''; position: absolute; top: 50%; width: calc(50% - 22px);
    height: 1px; background: var(--border-light);
}
.rm-or::before { left: 0; } .rm-or::after { right: 0; }

.rm-submit {
    width: 100%; padding: 11px; margin-top: 4px;
    background: #0ea5e9; color: #fff; border: none;
    border-radius: 9px; font-size: 0.9rem; font-weight: 700;
    cursor: pointer; transition: background 0.15s;
    display: flex; align-items: center; justify-content: center; gap: 7px;
}
.rm-submit:hover { background: #0284c7; }

/* ── Right: materials table card ─────────────────────────────────────────── */
.rm-table-card {
    flex: 1; min-width: 0;
    background: var(--bg-primary);
    border: 1px solid var(--border-light);
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    overflow: hidden;
}
.rm-table-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--border-light);
    gap: 12px; flex-wrap: wrap;
}
.rm-table-title { font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin: 0; }
.rm-filter-sel {
    padding: 7px 11px; border: 1.5px solid var(--border-color);
    border-radius: 8px; font-size: 0.83rem; background: var(--bg-secondary);
    color: var(--text-primary); outline: none; cursor: pointer;
}
.rm-filter-sel:focus { border-color: #0ea5e9; }

.rm-table { width: 100%; border-collapse: collapse; }
.rm-table th {
    padding: 10px 16px; text-align: left;
    font-size: 0.75rem; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    background: var(--bg-secondary); border-bottom: 1px solid var(--border-light);
    white-space: nowrap;
}
.rm-table td {
    padding: 12px 16px; font-size: 0.86rem; color: var(--text-primary);
    border-bottom: 1px solid var(--border-light); vertical-align: middle;
}
.rm-table tr:last-child td { border-bottom: none; }
.rm-table tr:hover td { background: var(--bg-secondary, #f9fafb); }

.rm-mat-title { font-weight: 600; color: var(--text-primary); }
.rm-mat-desc  { font-size: 0.76rem; color: var(--text-muted); margin-top: 2px; }
.rm-event-name { font-size: 0.82rem; color: var(--text-secondary); white-space: nowrap; }

.rm-type-pill {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 3px 8px; border-radius: 20px;
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    white-space: nowrap;
}
.rm-type-pdf  { background: #fee2e2; color: #dc2626; }
.rm-type-doc,
.rm-type-docx { background: #dbeafe; color: #1d4ed8; }
.rm-type-link { background: #d1fae5; color: #065f46; }
.rm-type-jpg,
.rm-type-jpeg,
.rm-type-png  { background: #fef3c7; color: #92400e; }
.rm-type-other{ background: var(--bg-secondary); color: var(--text-muted); }

.rm-avail-now  { font-size: 0.78rem; color: #16a34a; }
.rm-avail-soon { font-size: 0.78rem; color: #d97706; font-weight: 600; }
.rm-reads-count{ font-size: 0.82rem; color: var(--text-muted); }

.rm-view-link {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.79rem; color: #0ea5e9; text-decoration: none; font-weight: 600;
    margin-right: 8px;
}
.rm-view-link:hover { text-decoration: underline; }
.rm-del-btn {
    background: none; border: 1px solid #fecaca; color: #dc2626;
    border-radius: 6px; padding: 4px 9px; font-size: 0.77rem;
    cursor: pointer; transition: all 0.15s; font-weight: 600;
}
.rm-del-btn:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

.rm-empty {
    padding: 52px 24px; text-align: center; color: var(--text-muted);
}
.rm-empty svg { opacity: 0.3; margin-bottom: 14px; }
.rm-empty h3 { font-size: 1rem; font-weight: 700; color: var(--text-secondary); margin: 0 0 6px; }
.rm-empty p  { margin: 0; font-size: 0.84rem; }

@media (max-width: 900px) {
    .rm-layout    { flex-direction: column; }
    .rm-form-card { width: 100%; position: static; }
}
</style>

<?php if ($flash): ?>
<div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
<?php endif; ?>

<div class="rm-header">
    <h1>Reading Materials</h1>
    <p>Upload and manage learning resources for event participants.</p>
</div>

<?php if ($dbError): ?>
<div class="rm-db-error">
    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
    <div>
        <strong>Database tables missing</strong>
        <p>The <code>event_materials</code> table does not exist yet.
        <a href="/admin/tools/run-setup.php">Run the DB setup migration</a> to create all required tables, then return here.</p>
        <p style="margin-top:6px;font-size:0.78rem;opacity:.7;">Detail: <?php echo htmlspecialchars($dbError); ?></p>
    </div>
</div>
<?php else: ?>

<div class="rm-layout">

    <!-- ── Add Material Form ──────────────────────────────────────────────── -->
    <div class="rm-form-card">
        <div class="rm-form-title">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add New Material
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add">

            <div class="rm-field">
                <label class="rm-label">Event <span class="rm-required">*</span></label>
                <select name="event_id" class="rm-ctrl" required>
                    <option value="">-- Select Event --</option>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?php echo (int)$ev['id']; ?>"><?php echo htmlspecialchars($ev['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rm-field">
                <label class="rm-label">Title <span class="rm-required">*</span></label>
                <input type="text" name="title" class="rm-ctrl" placeholder="e.g. Module 1 – Introduction" required maxlength="255">
            </div>

            <div class="rm-field">
                <label class="rm-label">Description</label>
                <textarea name="description" class="rm-ctrl" placeholder="Optional description..."></textarea>
            </div>

            <div class="rm-field">
                <label class="rm-label">Available From <span style="font-weight:400;color:var(--text-muted);">(blank = immediate)</span></label>
                <input type="datetime-local" name="available_from" class="rm-ctrl">
            </div>

            <div class="rm-field">
                <label class="rm-label">Upload File</label>
                <div class="rm-upload-zone" onclick="document.getElementById('matFileInput').click()">
                    <svg width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    <div class="rm-upload-label"><strong>Click to upload</strong><br>PDF, DOC, DOCX, JPG, PNG — max 10 MB</div>
                    <div class="rm-file-chosen" id="rmFileName"></div>
                </div>
                <input type="file" id="matFileInput" name="material_file"
                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                       style="display:none"
                       onchange="document.getElementById('rmFileName').textContent = this.files[0]?.name || ''">
            </div>

            <div class="rm-or">OR</div>

            <div class="rm-field">
                <label class="rm-label">External URL <span style="font-weight:400;color:var(--text-muted);">(YouTube, Drive, etc.)</span></label>
                <input type="url" name="external_url" class="rm-ctrl" placeholder="https://...">
            </div>

            <button type="submit" class="rm-submit">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Material
            </button>
        </form>
    </div>

    <!-- ── Materials Table ───────────────────────────────────────────────── -->
    <div class="rm-table-card">
        <div class="rm-table-head">
            <h3 class="rm-table-title">
                All Materials
                <span style="font-size:0.82rem;font-weight:600;color:var(--text-muted);margin-left:6px;">(<?php echo count($materials); ?>)</span>
            </h3>
            <select class="rm-filter-sel" onchange="location.href='/admin/reading-materials/?event_id='+this.value">
                <option value="">All Events</option>
                <?php foreach ($events as $ev): ?>
                <option value="<?php echo (int)$ev['id']; ?>" <?php echo $filterEvent === (int)$ev['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ev['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (empty($materials)): ?>
        <div class="rm-empty">
            <svg width="44" height="44" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            <h3>No materials yet</h3>
            <p>Use the form on the left to add the first resource.</p>
        </div>
        <?php else: ?>
        <table class="rm-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Event</th>
                    <th>Type</th>
                    <th>Available</th>
                    <th>Reads</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $now = new DateTime();
            foreach ($materials as $mat):
                $avail = !empty($mat['available_from']) ? new DateTime($mat['available_from']) : null;
                $isLive = !$avail || $avail <= $now;
                $typeKey = strtolower($mat['file_type'] ?? 'other');
                $typeClass = in_array($typeKey, ['pdf','doc','docx','link','jpg','jpeg','png']) ? "rm-type-{$typeKey}" : 'rm-type-other';
            ?>
            <tr>
                <td>
                    <div class="rm-mat-title"><?php echo htmlspecialchars($mat['title']); ?></div>
                    <?php if (!empty($mat['description'])): ?>
                    <div class="rm-mat-desc"><?php echo htmlspecialchars(mb_substr($mat['description'], 0, 80)); ?><?php echo mb_strlen($mat['description']) > 80 ? '…' : ''; ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="rm-event-name"><?php echo htmlspecialchars($mat['event_title']); ?></span></td>
                <td><span class="rm-type-pill <?php echo $typeClass; ?>"><?php echo strtoupper($typeKey); ?></span></td>
                <td>
                    <?php if ($isLive): ?>
                        <span class="rm-avail-now">Available now</span>
                    <?php else: ?>
                        <span class="rm-avail-soon"><?php echo $avail->format('d M Y, H:i'); ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="rm-reads-count"><?php echo (int)$mat['read_count']; ?></span></td>
                <td style="white-space:nowrap;">
                    <?php if ($mat['file_type'] === 'link'): ?>
                    <a href="<?php echo htmlspecialchars($mat['file_path']); ?>" target="_blank" class="rm-view-link">
                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        Open
                    </a>
                    <?php else: ?>
                    <a href="<?php echo htmlspecialchars($mat['file_path']); ?>" target="_blank" class="rm-view-link">
                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        View
                    </a>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this material permanently?')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="material_id" value="<?php echo (int)$mat['id']; ?>">
                        <button type="submit" class="rm-del-btn">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div><!-- .rm-layout -->

<?php endif; /* end dbError check */ ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
