<?php
/**
 * KESA Learn - Setup Activity Tracking Tables
 * Admin tool to create tracking tables for certificate downloads, event views, etc.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();

$db = getDB();
$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_tables'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        // Read and execute migration SQL
        $migrationFile = __DIR__ . '/../../sql/migration-add-activity-tracking.sql';
        
        if (!file_exists($migrationFile)) {
            $errors[] = 'Migration file not found: ' . $migrationFile;
        } else {
            $sql = file_get_contents($migrationFile);
            
            // Split SQL by semicolon and execute each statement
            $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => !empty($s));
            
            foreach ($statements as $statement) {
                try {
                    $db->exec($statement);
                    $success[] = 'Created/Updated table: ' . preg_replace('/.*`(\w+)`.*/i', '$1', $statement);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        $errors[] = 'SQL Error: ' . $e->getMessage();
                    } else {
                        $success[] = 'Table already exists (skipped): ' . preg_replace('/.*`(\w+)`.*/i', '$1', $statement);
                    }
                }
            }
            
            if (empty($errors)) {
                logActivity('tracking_tables_setup', 'Activity tracking tables successfully created');
                setFlash('success', 'Activity tracking tables have been successfully created!');
                redirect('/admin/tools/setup-tracking-tables.php');
            }
        }
    }
}

// Check which tables exist
$tablesStatus = [
    'user_ip_tracking' => false,
    'certificate_downloads' => false,
    'event_page_views' => false,
    'register_clicks' => false,
    'incomplete_payments' => false
];

foreach (array_keys($tablesStatus) as $table) {
    try {
        $result = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = '$table' LIMIT 1");
        $tablesStatus[$table] = $result->rowCount() > 0;
    } catch (Exception $e) {
        // Table doesn't exist
    }
}

$allTablesExist = array_reduce($tablesStatus, fn($carry, $exists) => $carry && $exists, true);

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
    .setup-container {
        max-width: 800px;
        margin: 40px auto;
        padding: 24px;
    }
    .status-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        padding: 20px;
        margin-bottom: 20px;
    }
    .table-status {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        background: var(--bg-secondary);
        border-radius: var(--radius-sm);
        margin-bottom: 8px;
        font-size: 0.95rem;
    }
    .table-status.exists {
        border-left: 4px solid var(--green, #10b981);
    }
    .table-status.missing {
        border-left: 4px solid var(--red, #ef4444);
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: var(--radius-sm);
        font-weight: 500;
        font-size: 0.85rem;
    }
    .status-badge.exists {
        background: var(--green, #d1fae5);
        color: var(--text-primary);
    }
    .status-badge.missing {
        background: var(--red-light, #fee2e2);
        color: var(--text-primary);
    }
    .message-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .message-list li {
        padding: 12px;
        margin-bottom: 8px;
        border-radius: var(--radius-sm);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .message-list li.success {
        background: #d1fae5;
        color: #065f46;
    }
    .message-list li.error {
        background: #fee2e2;
        color: #991b1b;
    }
    .setup-button {
        background: var(--blue, #3b82f6);
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: var(--radius-md);
        font-size: 1rem;
        cursor: pointer;
        margin-top: 20px;
    }
    .setup-button:hover {
        background: var(--blue-dark, #2563eb);
    }
    .setup-button:disabled {
        background: var(--text-muted);
        cursor: not-allowed;
    }
</style>

<main>
    <div class="setup-container">
        <h1>Activity Tracking Tables Setup</h1>
        <p style="color: var(--text-muted); margin-bottom: 24px;">
            Configure the database tables required for tracking certificate downloads, event page views, registration clicks, and incomplete payments.
        </p>

        <?php if (!empty($errors)): ?>
            <div class="status-card">
                <h3 style="margin-top: 0; color: var(--red, #ef4444);">Errors</h3>
                <ul class="message-list">
                    <?php foreach ($errors as $error): ?>
                        <li class="error">
                            <span>❌</span> <?php echo sanitize($error); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="status-card">
                <h3 style="margin-top: 0; color: var(--green, #10b981);">Success</h3>
                <ul class="message-list">
                    <?php foreach ($success as $msg): ?>
                        <li class="success">
                            <span>✅</span> <?php echo sanitize($msg); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="status-card">
            <h3 style="margin-top: 0;">Database Tables Status</h3>
            
            <?php foreach ($tablesStatus as $table => $exists): ?>
                <div class="table-status <?php echo $exists ? 'exists' : 'missing'; ?>">
                    <span><?php echo $table; ?></span>
                    <span class="status-badge <?php echo $exists ? 'exists' : 'missing'; ?>">
                        <?php echo $exists ? '✓ Exists' : '✗ Missing'; ?>
                    </span>
                </div>
            <?php endforeach; ?>

            <div style="margin-top: 20px; padding: 16px; background: var(--bg-secondary); border-radius: var(--radius-sm);">
                <p style="margin: 0; font-size: 0.95rem; color: var(--text-muted);">
                    <strong>Status:</strong> 
                    <?php if ($allTablesExist): ?>
                        <span style="color: var(--green, #10b981);">✓ All tables are ready for tracking activities</span>
                    <?php else: ?>
                        <span style="color: var(--red, #ef4444);">✗ Some tables are missing. Click the button below to create them.</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if (!$allTablesExist): ?>
            <form method="POST" class="status-card">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <button type="submit" name="setup_tables" value="1" class="setup-button">
                    Create Tracking Tables
                </button>
            </form>
        <?php else: ?>
            <div class="status-card" style="background: #f0fdf4; border-color: var(--green, #10b981);">
                <p style="margin: 0; color: var(--green, #10b981);">
                    <strong>✓ Setup Complete!</strong> All activity tracking tables have been created successfully.
                    The system will now track certificate downloads, event page views, registration clicks, and incomplete payments.
                </p>
            </div>
        <?php endif; ?>

        <div class="status-card" style="background: var(--bg-secondary); margin-top: 30px;">
            <h3 style="margin-top: 0;">What Gets Tracked?</h3>
            <ul style="margin: 0; padding-left: 20px;">
                <li><strong>Certificate Downloads</strong> - When users/guests download certificates (with IP matching)</li>
                <li><strong>Event Page Views</strong> - When users visit published event detail pages</li>
                <li><strong>Registration Clicks</strong> - When users click the "Register Now" button</li>
                <li><strong>Incomplete Payments</strong> - When users start payment but don't complete checkout</li>
                <li><strong>IP Tracking</strong> - Guest activities tracked by IP address and matched to users</li>
            </ul>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
