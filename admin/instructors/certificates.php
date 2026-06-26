<?php
/**
 * KESA Learn - Admin: Instructor Certificates
 *
 * Upload, list, activate/deactivate certificates issued to instructors.
 * Files are stored in /uploads/instructors/certificates/ and are publicly
 * verifiable at /certificate/search using the KESA-INST-XXXXXX code.
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage  = 'instructor_certs';
$pageTitle  = 'Instructor Certificates';

if (function_exists('checkSectionPermission')) checkSectionPermission('instructors');

$uploadDir = __DIR__ . '/../../uploads/instructors/certificates/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$errors = [];
$success = '';

function genInstCertCode(PDO $db): string {
    do {
        $code = 'KESA-INST-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $st = $db->prepare("SELECT id FROM instructor_certificates WHERE certificate_code = ?");
        $st->execute([$code]);
    } while ($st->fetch());
    return $code;
}

/* ── Actions ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please retry.';
    } elseif (($_POST['action'] ?? '') === 'upload') {
        $instructorId = (int)($_POST['instructor_id'] ?? 0);
        $title        = trim($_POST['title'] ?? '');
        $certNumber   = trim($_POST['certificate_number'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $issueDate    = $_POST['issue_date'] ?: null;
        $eventId      = (int)($_POST['event_id'] ?? 0) ?: null;

        if (!$instructorId) $errors[] = 'Select an instructor.';
        if ($title === '')  $errors[] = 'Certificate title is required.';
        if ($certNumber === '') $errors[] = 'Certificate number (printed on the certificate) is required.';
        if ($certNumber !== '') {
            $chk = $db->prepare("SELECT id FROM instructor_certificates WHERE certificate_number = ?");
            $chk->execute([$certNumber]);
            if ($chk->fetch()) $errors[] = 'This certificate number already exists.';
        }

        $file = $_FILES['certificate_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Choose a certificate file (PDF, JPG or PNG, max 10 MB).';
        } else {
            if ($file['size'] > 10 * 1024 * 1024) $errors[] = 'File exceeds 10 MB.';
            $mime = mime_content_type($file['tmp_name']);
            $extMap = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($extMap[$mime])) $errors[] = 'Only PDF, JPG or PNG files are allowed.';
        }

        if (!$errors) {
            $code = genInstCertCode($db);
            $name = $code . '-' . time() . '.' . $extMap[$mime];
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $name)) {
                $errors[] = 'Could not save the file. Check folder permissions.';
            } else {
                $st = $db->prepare(
                    "INSERT INTO instructor_certificates
                     (instructor_id, certificate_code, certificate_number, title, description, event_id, certificate_file, issue_date, uploaded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $st->execute([
                    $instructorId, $code, $certNumber, $title, $description ?: null, $eventId,
                    'uploads/instructors/certificates/' . $name,
                    $issueDate, (int)($_SESSION['user_id'] ?? 0) ?: null,
                ]);
                logActivity('instructor_cert_uploaded', "Instructor certificate {$code} uploaded");
                $success = "Certificate uploaded. Verification code: {$code} | Certificate number: {$certNumber}. Both can be searched at /certificate/search.";
            }
        }
    } elseif (($_POST['action'] ?? '') === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE instructor_certificates SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        $success = 'Status updated.';
    }
}

$instructors = $db->query("SELECT id, name, designation FROM instructors WHERE is_active = 1 ORDER BY name")->fetchAll();
$events      = $db->query("SELECT id, title FROM events ORDER BY start_date DESC LIMIT 200")->fetchAll();

$certs = $db->query(
    "SELECT ic.*, i.name AS instructor_name, e.title AS event_title
     FROM instructor_certificates ic
     JOIN instructors i ON i.id = ic.instructor_id
     LEFT JOIN events e ON e.id = ic.event_id
     ORDER BY ic.id DESC LIMIT 300"
)->fetchAll();

require_once __DIR__ . '/../includes/sidebar.php';
?>
  <div class="admin-header">
    <h1>Instructor Certificates</h1>
    <p>Upload certificates issued to instructors. Each gets a public verification code (KESA-INST-…) checkable at <code>/certificate/search</code>.</p>
  </div>

  <?php foreach ($errors as $e): ?><div class="alert alert-error"><?php echo sanitize($e); ?></div><?php endforeach; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo sanitize($success); ?></div><?php endif; ?>

  <div class="admin-card" style="margin-bottom:24px;">
    <h2 style="font-size:1.05rem;margin-bottom:14px;">Upload New Certificate</h2>
    <form method="post" enctype="multipart/form-data" class="ic-form">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="upload">
      <div class="ic-grid">
        <div>
          <label>Instructor *</label>
          <select name="instructor_id" required>
            <option value="">— Select —</option>
            <?php foreach ($instructors as $i): ?>
              <option value="<?php echo (int)$i['id']; ?>"><?php echo sanitize($i['name'] . ($i['designation'] ? ' — ' . $i['designation'] : '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Certificate Title *</label>
          <input type="text" name="title" maxlength="255" placeholder="e.g. Lead Instructor — Python Bootcamp 2026" required>
        </div>
        <div>
          <label>Certificate Number * <small style="font-weight:400;color:#888;">(printed on the certificate)</small></label>
          <input type="text" name="certificate_number" maxlength="100" placeholder="e.g. KESA/INST/2026/014" required>
        </div>
        <div>
          <label>Related Event (optional)</label>
          <select name="event_id">
            <option value="">— None —</option>
            <?php foreach ($events as $ev): ?>
              <option value="<?php echo (int)$ev['id']; ?>"><?php echo sanitize($ev['title']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Issue Date</label>
          <input type="date" name="issue_date">
        </div>
        <div class="ic-full">
          <label>Description</label>
          <textarea name="description" rows="2" placeholder="Shown on the public verification page"></textarea>
        </div>
        <div class="ic-full">
          <label>Certificate File * (PDF / JPG / PNG, max 10 MB)</label>
          <input type="file" name="certificate_file" accept=".pdf,.jpg,.jpeg,.png" required>
        </div>
      </div>
      <button class="btn btn-primary" style="margin-top:14px;">Upload Certificate</button>
    </form>
  </div>

  <div class="admin-card">
    <h2 style="font-size:1.05rem;margin-bottom:14px;">Issued Certificates (<?php echo count($certs); ?>)</h2>
    <div style="overflow-x:auto;">
      <table class="admin-table">
        <thead><tr><th>Code</th><th>Cert. No</th><th>Instructor</th><th>Title</th><th>Event</th><th>Issued</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$certs): ?>
          <tr><td colspan="8" style="text-align:center;color:#888;">No instructor certificates yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($certs as $c): ?>
          <tr>
            <td><code><?php echo sanitize($c['certificate_code']); ?></code></td>
            <td><?php echo sanitize($c['certificate_number'] ?? '—'); ?></td>
            <td><?php echo sanitize($c['instructor_name']); ?></td>
            <td><?php echo sanitize($c['title']); ?></td>
            <td><?php echo sanitize($c['event_title'] ?? '—'); ?></td>
            <td><?php echo $c['issue_date'] ? date('d M Y', strtotime($c['issue_date'])) : '—'; ?></td>
            <td><span class="badge <?php echo $c['is_active'] ? 'badge-success' : 'badge-muted'; ?>"><?php echo $c['is_active'] ? 'Active' : 'Disabled'; ?></span></td>
            <td style="white-space:nowrap;">
              <a class="btn btn-sm btn-secondary" href="/<?php echo sanitize($c['certificate_file']); ?>" target="_blank" rel="noopener">View</a>
              <a class="btn btn-sm btn-secondary" href="/certificate/search?code=<?php echo urlencode($c['certificate_code']); ?>" target="_blank" rel="noopener">Verify Page</a>
              <form method="post" style="display:inline;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button class="btn btn-sm btn-secondary"><?php echo $c['is_active'] ? 'Disable' : 'Enable'; ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<style>
.ic-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ic-grid label{display:block;font-weight:600;font-size:.85rem;margin-bottom:6px}
.ic-grid input,.ic-grid select,.ic-grid textarea{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-family:inherit;font-size:.95rem}
.ic-full{grid-column:1/-1}
.badge{padding:3px 10px;border-radius:99px;font-size:.75rem;font-weight:700}
.badge-success{background:#dcfce7;color:#15803d}
.badge-muted{background:#f1f3f5;color:#6c757d}
@media(max-width:720px){.ic-grid{grid-template-columns:1fr}}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
