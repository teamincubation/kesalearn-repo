<?php
/**
 * KESA Learn - Certificate Repair Tool
 * Diagnoses and helps fix certificate file path issues
 */
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();

$db = getDB();
$adminPage = 'certificates';
$pageTitle = 'Certificate Repair Tool';

$uploadDir = __DIR__ . '/../../uploads/certificates/issued/';
$uploadDirAlt = __DIR__ . '/../../uploads/certificates/';

// Get all certificates
$certificates = $db->query("SELECT * FROM certificates ORDER BY generated_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Analyze each certificate
$issues = [];
$healthy = 0;
$fixable = [];

foreach ($certificates as $cert) {
    $certFile = $cert['certificate_file'] ?? '';
    $certCode = $cert['certificate_code'] ?? '';
    $certNumber = $cert['certificate_number'] ?? '';
    
    $status = 'missing';
    $foundPath = null;
    
    // Check if file exists in expected location
    if (!empty($certFile)) {
        $paths = [
            $uploadDir . $certFile,
            $uploadDir . basename($certFile),
            $uploadDirAlt . $certFile,
            $uploadDirAlt . basename($certFile),
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $status = 'ok';
                $foundPath = $path;
                break;
            }
        }
    }
    
    // If not found, try to locate by pattern
    if ($status === 'missing') {
        $searchDirs = [$uploadDir, $uploadDirAlt];
        $identifiers = array_filter([$certCode, $certNumber]);
        
        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) continue;
            
            foreach ($identifiers as $id) {
                $cleanId = preg_replace('/[^a-zA-Z0-9]/', '_', $id);
                $patterns = [
                    $dir . '*' . $id . '*',
                    $dir . '*' . $cleanId . '*',
                    $dir . 'cert*' . $cleanId . '*',
                ];
                
                foreach ($patterns as $pattern) {
                    $files = glob($pattern);
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $status = 'fixable';
                            $foundPath = $file;
                            $fixable[$cert['id']] = [
                                'cert' => $cert,
                                'found_file' => basename($file),
                                'found_path' => $file,
                            ];
                            break 3;
                        }
                    }
                }
            }
        }
    }
    
    if ($status === 'ok') {
        $healthy++;
    } elseif ($status === 'missing') {
        $issues[] = $cert;
    }
}

// Handle repair action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repair']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $repaired = 0;
    
    foreach ($fixable as $certId => $data) {
        $newFileName = basename($data['found_path']);
        $stmt = $db->prepare("UPDATE certificates SET certificate_file = ? WHERE id = ?");
        $stmt->execute([$newFileName, $certId]);
        $repaired++;
    }
    
    setFlash('success', "Repaired $repaired certificates!");
    redirect('/admin/certificates/repair.php');
}

// Handle delete all missing certificates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_missing']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    // Get IDs of all missing certificates (after analysis)
    $missingIds = array_column($issues, 'id');
    
    if (!empty($missingIds)) {
        $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
        $stmt = $db->prepare("DELETE FROM certificates WHERE id IN ($placeholders)");
        $stmt->execute($missingIds);
        $deleted = $stmt->rowCount();
        
        logActivity('certificates_missing_deleted', "Deleted $deleted certificates with missing files");
        setFlash('success', "$deleted certificate record(s) with missing files deleted. You can now re-upload them.");
    }
    redirect('/admin/certificates/repair.php');
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.repair-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
.stat-card { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; text-align: center; }
.stat-card.healthy { border-left: 4px solid #10b981; }
.stat-card.fixable { border-left: 4px solid #f59e0b; }
.stat-card.missing { border-left: 4px solid #ef4444; }
.stat-number { font-size: 2rem; font-weight: 700; }
.stat-label { color: var(--text-muted); font-size: 0.9rem; }
.cert-list { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; }
.cert-item { padding: 16px 20px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; }
.cert-item:last-child { border-bottom: none; }
</style>

<h2 style="margin-bottom: 24px;">Certificate File Repair Tool</h2>

<div class="repair-stats">
    <div class="stat-card healthy">
        <div class="stat-number" style="color: #10b981;"><?php echo $healthy; ?></div>
        <div class="stat-label">Healthy</div>
    </div>
    <div class="stat-card fixable">
        <div class="stat-number" style="color: #f59e0b;"><?php echo count($fixable); ?></div>
        <div class="stat-label">Fixable</div>
    </div>
    <div class="stat-card missing">
        <div class="stat-number" style="color: #ef4444;"><?php echo count($issues); ?></div>
        <div class="stat-label">Missing Files</div>
    </div>
</div>

<?php if (!empty($fixable)): ?>
<div class="cert-list" style="margin-bottom: 24px;">
    <div style="padding: 16px 20px; background: #fef3c7; border-bottom: 1px solid #f59e0b;">
        <strong>Fixable Certificates</strong> - These certificates have files that can be linked.
    </div>
    <?php foreach ($fixable as $certId => $data): ?>
    <div class="cert-item">
        <div>
            <strong><?php echo sanitize($data['cert']['certificate_code']); ?></strong>
            <span style="color: var(--text-muted);">- <?php echo sanitize($data['cert']['recipient_name']); ?></span>
            <br>
            <small style="color: #059669;">Found: <?php echo sanitize($data['found_file']); ?></small>
        </div>
    </div>
    <?php endforeach; ?>
    <div style="padding: 16px 20px; background: var(--bg-secondary);">
        <form method="POST">
            <?php echo csrfField(); ?>
            <button type="submit" name="repair" value="1" class="btn btn-primary">
                Repair All <?php echo count($fixable); ?> Certificates
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($issues)): ?>
<div class="cert-list">
    <div style="padding: 16px 20px; background: #fee2e2; border-bottom: 1px solid #ef4444; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <strong>Missing Files</strong> - These certificates need files to be re-uploaded.
        </div>
        <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete ALL <?php echo count($issues); ?> certificate records with missing files?\n\nThis action cannot be undone.\n\nYou will need to re-upload these certificates fresh.');">
            <?php echo csrfField(); ?>
            <button type="submit" name="delete_missing" value="1" class="btn btn-danger btn-sm" style="display: flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete All <?php echo count($issues); ?> Missing Records
            </button>
        </form>
    </div>
    <?php foreach (array_slice($issues, 0, 20) as $cert): ?>
    <div class="cert-item">
        <div>
            <strong><?php echo sanitize($cert['certificate_code']); ?></strong>
            <span style="color: var(--text-muted);">- <?php echo sanitize($cert['recipient_name']); ?></span>
            <br>
            <small style="color: #ef4444;">DB file: <?php echo sanitize($cert['certificate_file'] ?: 'EMPTY'); ?></small>
        </div>
        <a href="/admin/certificates/?edit=<?php echo $cert['id']; ?>" class="btn btn-secondary btn-sm">Edit</a>
    </div>
    <?php endforeach; ?>
    <?php if (count($issues) > 20): ?>
    <div style="padding: 16px 20px; color: var(--text-muted);">
        ... and <?php echo count($issues) - 20; ?> more
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($healthy === count($certificates) && count($certificates) > 0): ?>
<div style="text-align: center; padding: 40px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
    <svg width="48" height="48" fill="none" stroke="#10b981" viewBox="0 0 24 24" style="margin-bottom: 16px;">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <h3 style="color: #10b981;">All Certificates Are Healthy!</h3>
    <p style="color: var(--text-muted);">All <?php echo $healthy; ?> certificates have valid file paths.</p>
</div>
<?php endif; ?>

<div style="margin-top: 24px;">
    <a href="/admin/certificates/" class="btn btn-secondary">Back to Certificates</a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
