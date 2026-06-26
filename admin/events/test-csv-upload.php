<?php
/**
 * KESA Learn - CSV Upload Debug Tool
 * This file helps diagnose CSV upload issues
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'events';
$pageTitle = 'CSV Upload Debug';

$debugInfo = [];
$testResult = null;

// Check PHP configuration
$debugInfo['php_config'] = [
    'file_uploads' => ini_get('file_uploads'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_file_uploads' => ini_get('max_file_uploads'),
    'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    'max_execution_time' => ini_get('max_execution_time'),
];

// Check tmp directory is writable
$tmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$debugInfo['tmp_writable'] = is_writable($tmpDir);

// Check database tables
try {
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $debugInfo['tables_exist'] = [
        'users' => in_array('users', $tables),
        'events' => in_array('events', $tables),
        'registrations' => in_array('registrations', $tables),
    ];
    
    // Check registrations table structure
    $cols = $db->query("DESCRIBE registrations")->fetchAll(PDO::FETCH_COLUMN);
    $debugInfo['registration_columns'] = $cols;
} catch (Exception $e) {
    $debugInfo['db_error'] = $e->getMessage();
}

// Process test upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_csv'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $testResult = ['error' => 'CSRF token invalid'];
    } else {
        $file = $_FILES['test_csv'];
        $testResult = [
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'file_type' => $file['type'],
            'file_error' => $file['error'],
            'tmp_name' => $file['tmp_name'],
            'tmp_exists' => file_exists($file['tmp_name']),
        ];
        
        if ($file['error'] === UPLOAD_ERR_OK && file_exists($file['tmp_name'])) {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $header = fgetcsv($handle);
                $testResult['csv_header'] = $header;
                $testResult['csv_header_normalized'] = array_map(function($h) {
                    return strtolower(trim($h));
                }, $header);
                
                $rows = [];
                $rowCount = 0;
                while (($data = fgetcsv($handle)) !== false && $rowCount < 5) {
                    $rows[] = $data;
                    $rowCount++;
                }
                $testResult['sample_rows'] = $rows;
                fclose($handle);
            }
        }
        
        // Error code meanings
        $errorCodes = [
            UPLOAD_ERR_OK => 'No error',
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload',
        ];
        $testResult['error_meaning'] = $errorCodes[$file['error']] ?? 'Unknown';
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.debug-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
}
.debug-card h3 {
    margin: 0 0 16px 0;
    font-size: 1rem;
    color: var(--text-primary);
}
.debug-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-light);
}
.debug-item:last-child {
    border-bottom: none;
}
.debug-label {
    font-weight: 500;
    color: var(--text-secondary);
}
.debug-value {
    font-family: monospace;
    color: var(--text-primary);
}
.debug-value.success { color: var(--green); }
.debug-value.error { color: var(--red); }
pre {
    background: var(--bg-tertiary);
    padding: 12px;
    border-radius: var(--radius-sm);
    overflow-x: auto;
    font-size: 0.85rem;
}
</style>

<div style="margin-bottom: 24px;">
    <a href="/admin/events/past-participants" class="btn btn-secondary btn-sm">&larr; Back to Past Participants</a>
</div>

<h2 style="margin-bottom: 20px;">CSV Upload Debug Tool</h2>

<div class="debug-card">
    <h3>PHP Configuration</h3>
    <?php foreach ($debugInfo['php_config'] as $key => $value): ?>
    <div class="debug-item">
        <span class="debug-label"><?php echo $key; ?></span>
        <span class="debug-value"><?php echo $value ?: 'Not set'; ?></span>
    </div>
    <?php endforeach; ?>
    <div class="debug-item">
        <span class="debug-label">Temp Dir Writable</span>
        <span class="debug-value <?php echo $debugInfo['tmp_writable'] ? 'success' : 'error'; ?>">
            <?php echo $debugInfo['tmp_writable'] ? 'Yes' : 'No'; ?>
        </span>
    </div>
</div>

<div class="debug-card">
    <h3>Database Tables</h3>
    <?php if (isset($debugInfo['tables_exist'])): ?>
    <?php foreach ($debugInfo['tables_exist'] as $table => $exists): ?>
    <div class="debug-item">
        <span class="debug-label"><?php echo $table; ?></span>
        <span class="debug-value <?php echo $exists ? 'success' : 'error'; ?>">
            <?php echo $exists ? 'Exists' : 'Missing'; ?>
        </span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($debugInfo['registration_columns'])): ?>
    <div style="margin-top: 16px;">
        <strong>Registration Columns:</strong>
        <pre><?php echo implode(', ', $debugInfo['registration_columns']); ?></pre>
    </div>
    <?php endif; ?>
    
    <?php if (isset($debugInfo['db_error'])): ?>
    <div class="debug-item">
        <span class="debug-value error"><?php echo $debugInfo['db_error']; ?></span>
    </div>
    <?php endif; ?>
</div>

<div class="debug-card">
    <h3>Test CSV Upload</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div style="margin-bottom: 16px;">
            <input type="file" name="test_csv" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary">Test Upload</button>
    </form>
    
    <?php if ($testResult): ?>
    <div style="margin-top: 20px;">
        <h4>Upload Result:</h4>
        <pre><?php echo htmlspecialchars(print_r($testResult, true)); ?></pre>
    </div>
    <?php endif; ?>
</div>

<div class="debug-card">
    <h3>Expected CSV Format</h3>
    <p style="margin-bottom: 12px;">The CSV file should have these columns (order matters):</p>
    <pre>email,event_id,registration_date
example@email.com,1,2024-01-15
another@email.com,2,2024-02-20</pre>
    <p style="margin-top: 12px; color: var(--text-muted);">
        Supported date formats: YYYY-MM-DD, DD-MM-YYYY, MM/DD/YYYY, DD/MM/YYYY
    </p>
</div>

<div class="debug-card">
    <h3>Common Issues</h3>
    <ul style="margin: 0; padding-left: 20px; color: var(--text-secondary);">
        <li>CSV file must have exactly: <code>email</code>, <code>event_id</code>, <code>registration_date</code> columns</li>
        <li>Column headers are case-insensitive but must match exactly</li>
        <li>Event ID must exist in the events table</li>
        <li>Email must be a valid email format</li>
        <li>Date must be in a supported format (YYYY-MM-DD recommended)</li>
        <li>Maximum file size: 5MB</li>
    </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
