<?php
/**
 * KESA Learn - Privacy Policy Page
 */
require_once __DIR__ . '/../includes/functions.php';

checkMaintenanceMode();
$db = getDB();

$pageTitle = 'Privacy Policy';
include __DIR__ . '/../includes/header.php';

// Fetch Privacy Policy content
$privacyContent = '';
try {
    $stmt = $db->prepare("SELECT content_value FROM site_content WHERE content_key = 'privacy_policy_content' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    $privacyContent = $result['content_value'] ?? '<h1>Privacy Policy</h1><p>No content available.</p>';
} catch (PDOException $e) {
    $privacyContent = '<h1>Privacy Policy</h1><p>Unable to load content.</p>';
}
?>

<section class="section" style="background: #f8f9fa; padding: 60px 0;">
    <div class="container" style="max-width: 900px;">
        <div class="policy-content" style="background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <?php echo $privacyContent; ?>
        </div>
    </div>
</section>

<style>
.policy-content h1 {
    font-size: 2rem;
    margin-bottom: 24px;
    color: #212529;
}

.policy-content h2 {
    font-size: 1.4rem;
    margin-top: 32px;
    margin-bottom: 16px;
    color: #495057;
}

.policy-content h3 {
    font-size: 1.1rem;
    margin-top: 24px;
    margin-bottom: 12px;
    color: #495057;
}

.policy-content p {
    margin-bottom: 16px;
    line-height: 1.6;
    color: #6c757d;
}

.policy-content ul, .policy-content ol {
    margin: 16px 0 16px 24px;
    color: #6c757d;
}

.policy-content li {
    margin-bottom: 8px;
    line-height: 1.6;
}

.policy-content a {
    color: #0066cc;
    text-decoration: none;
}

.policy-content a:hover {
    text-decoration: underline;
}

.policy-content strong {
    color: #212529;
    font-weight: 600;
}

.policy-content em {
    font-style: italic;
}

.policy-content table {
    width: 100%;
    border-collapse: collapse;
    margin: 16px 0;
}

.policy-content table th,
.policy-content table td {
    border: 1px solid #dee2e6;
    padding: 12px;
    text-align: left;
}

.policy-content table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #212529;
}

.policy-content blockquote {
    border-left: 4px solid #dee2e6;
    padding-left: 20px;
    margin: 16px 0;
    color: #6c757d;
    font-style: italic;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
