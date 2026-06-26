<?php
/**
 * KESA Learn - User Certificates
 * Mobile-first professional design
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get user email for potential future linking
$userStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userEmail = $userStmt->fetchColumn();

// Check available columns in certificates table for compatibility
$columnsStmt = $db->query("SHOW COLUMNS FROM certificates");
$columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
$hasUserEmail = in_array('user_email', $columns);
$hasCertFile = in_array('certificate_file', $columns);
$hasCertNumber = in_array('certificate_number', $columns);

// Fetch certificates - use compatible query based on available columns
if ($hasUserEmail) {
    $stmt = $db->prepare("
        SELECT c.*, e.title, e.start_date, e.type, ct.template_image
        FROM certificates c 
        LEFT JOIN events e ON c.event_id = e.id 
        LEFT JOIN certificate_templates ct ON c.template_id = ct.id
        WHERE c.user_id = ? OR c.user_email = ?
        ORDER BY c.generated_at DESC
    ");
    $stmt->execute([$userId, $userEmail]);
    
    // Auto-link any certificates that match email but don't have user_id
    $db->prepare("UPDATE certificates SET user_id = ? WHERE user_email = ? AND user_id IS NULL")->execute([$userId, $userEmail]);
} else {
    $stmt = $db->prepare("
        SELECT c.*, e.title, e.start_date, e.type, ct.template_image
        FROM certificates c 
        LEFT JOIN events e ON c.event_id = e.id 
        LEFT JOIN certificate_templates ct ON c.template_id = ct.id
        WHERE c.user_id = ?
        ORDER BY c.generated_at DESC
    ");
    $stmt->execute([$userId]);
}
$certificates = $stmt->fetchAll();

$pageTitle = 'My Certificates';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Certificates Page Mobile-First Styles */
.certs-page {
    padding: 0 0 100px;
    background: var(--bg-secondary);
    min-height: calc(100vh - var(--nav-height));
}

.mobile-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-light);
    position: sticky;
    top: var(--nav-height);
    z-index: 100;
}

@media (min-width: 769px) {
    .mobile-page-header { display: none; }
}

.mobile-page-title {
    font-size: 1.1rem;
    font-weight: 700;
}

.dashboard-link-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--blue);
    color: #fff;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
}

.dashboard-link-btn:hover {
    background: var(--purple);
}

.dashboard-link-btn svg {
    width: 16px;
    height: 16px;
}

.certs-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 16px;
}

/* Desktop Header */
.certs-header {
    display: none;
    margin-bottom: 24px;
}

@media (min-width: 769px) {
    .certs-header {
        display: block;
    }
}

.certs-header h1 {
    font-size: 1.5rem;
    margin-bottom: 4px;
}

.certs-header p {
    color: var(--text-muted);
}

/* Certificate Cards Grid */
.cert-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

@media (min-width: 600px) {
    .cert-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.cert-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: all 0.2s ease;
}

.cert-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.cert-card-header {
    padding: 16px;
    background: linear-gradient(135deg, var(--bg-warm), var(--yellow-light));
    border-bottom: 1px solid var(--border-light);
}

.cert-card-header-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.cert-code {
    font-family: monospace;
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--text-primary);
    background: rgba(255,255,255,0.7);
    padding: 4px 10px;
    border-radius: var(--radius-sm);
}

.cert-type-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.cert-card-body {
    padding: 16px;
}

.cert-title {
    font-weight: 700;
    font-size: 1rem;
    color: var(--text-primary);
    margin-bottom: 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.cert-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 16px;
}

.cert-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.cert-meta-item svg {
    width: 14px;
    height: 14px;
}

.cert-download-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px 16px;
    background: var(--blue);
    color: #fff;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.cert-download-btn:hover {
    background: var(--purple);
}

.cert-download-btn svg {
    width: 18px;
    height: 18px;
}

.cert-downloads {
    text-align: center;
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 10px;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}

.empty-state-icon {
    width: 80px;
    height: 80px;
    background: var(--yellow-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.empty-state-icon svg {
    width: 40px;
    height: 40px;
    color: var(--yellow);
}

.empty-state-modern h3 {
    font-size: 1.2rem;
    margin-bottom: 8px;
}

.empty-state-modern p {
    color: var(--text-muted);
    font-size: 0.95rem;
    margin-bottom: 20px;
}
</style>

<div class="certs-page">
    <!-- Mobile Page Header -->
    <div class="mobile-page-header">
        <span class="mobile-page-title">My Certificates</span>
    </div>
    
    <div class="certs-container">
        <!-- Desktop Header -->
        <div class="certs-header">
            <h1>My Certificates</h1>
            <p>Download your participation and achievement certificates</p>
        </div>
        
        <?php if (empty($certificates)): ?>
            <div class="empty-state-modern">
                <div class="empty-state-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                </div>
                <h3>No certificates yet</h3>
                <p>Certificates are issued after event completion. Keep attending events to earn your certificates!</p>
                <a href="/events/" class="btn btn-primary">Browse Events</a>
            </div>
        <?php else: ?>
            <div class="cert-grid">
                <?php foreach ($certificates as $cert): ?>
                <div class="cert-card">
                    <div class="cert-card-header">
                        <div class="cert-card-header-top">
                            <span class="cert-code"><?php echo sanitize($cert['certificate_code'] ?? ''); ?></span>
                            <?php echo getEventTypeBadge($cert['type']); ?>
                        </div>
                    </div>
                    <div class="cert-card-body">
                        <h4 class="cert-title"><?php echo sanitize($cert['title']); ?></h4>
                        <div class="cert-meta">
                            <span class="cert-meta-item">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <?php echo formatDate($cert['start_date']); ?>
                            </span>
                            <span class="cert-meta-item">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?php echo timeAgo($cert['generated_at']); ?>
                            </span>
                        </div>
                        <?php
                        // Get certificate code - check both column names for compatibility
                        $certCode = $cert['certificate_code'] ?? '';
                        if ($hasCertNumber && isset($cert['certificate_number'])) {
                            $certCode = $cert['certificate_number'];
                        }
                        $certName = $cert['title'] ?? 'Certificate of Achievement';
                        $issueDate = $cert['generated_at'];
                        if (isset($cert['issue_date']) && $cert['issue_date']) {
                            $issueDate = $cert['issue_date'];
                        }
                        $issueYear = date('Y', strtotime($issueDate));
                        $issueMonth = intval(date('n', strtotime($issueDate))); // Use 'n' for month without leading zeros (1-12)
                        $linkedInOrgName = getSetting('linkedin_org_name', 'KESA Learn');
                        $certUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'kesalearn.com') . '/certificate/verify?code=' . urlencode($certCode);
                        // LinkedIn certification URL - correct parameters
                        $linkedInUrl = 'https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME'
                            . '&name=' . urlencode($certName)
                            . '&organizationName=' . urlencode($linkedInOrgName)
                            . '&issueYear=' . $issueYear
                            . '&issueMonth=' . $issueMonth
                            . '&certId=' . urlencode($certCode)
                            . '&certUrl=' . urlencode($certUrl);
                        
                        // Check if certificate file exists
                        $certFileExists = $hasCertFile && !empty($cert['certificate_file']);
                        ?>
                        
                        <!-- Download Button -->
                        <a href="/user/download_certificate?id=<?php echo $cert['id']; ?>" class="cert-download-btn">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download Certificate
                        </a>
                        
                        <!-- Preview & Validate Buttons Row -->
                        <div style="display: flex; gap: 8px; margin-top: 12px;">
                            <a href="/certificate/preview?id=<?php echo $cert['id']; ?>" target="_blank" style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); border-radius: var(--radius-md); font-size: 0.8rem; font-weight: 600; color: #fff; text-decoration: none; transition: all 0.2s;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                Preview
                            </a>
                            <a href="/certificate/verify?code=<?php echo urlencode($certCode); ?>" target="_blank" style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px; background: linear-gradient(135deg, #059669 0%, #10b981 100%); border-radius: var(--radius-md); font-size: 0.8rem; font-weight: 600; color: #fff; text-decoration: none; transition: all 0.2s;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                Validate
                            </a>
                        </div>
                        
                        <!-- LinkedIn Share Button -->
                        <div style="margin-top: 8px;">
                            <a href="<?php echo $linkedInUrl; ?>" target="_blank" style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px; background: #0a66c2; border-radius: var(--radius-md); font-size: 0.8rem; font-weight: 600; color: #fff; text-decoration: none; transition: all 0.2s;">
                                <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                                Add to LinkedIn Profile
                            </a>
                        </div>
                        
                        <p class="cert-downloads">Downloaded <?php echo $cert['download_count']; ?> time(s)</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- User Bottom Navigation -->
<?php include __DIR__ . '/../includes/user_nav.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
