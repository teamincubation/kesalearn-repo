<?php
/**
 * KESA Learn - Admin: Bulk Event Upload
 */
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();

$db = getDB();
$adminPage = 'events';
$pageTitle = 'Bulk Event Upload';

$errors = [];
$successCount = 0;
$previewData = [];
$showPreview = false;

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="event_upload_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write headers (matching events table columns)
    fputcsv($output, [
        'title',
        'slug',
        'type',
        'short_description',
        'description',
        'is_online',
        'venue',
        'meeting_link',
        'start_date',
        'end_date',
        'registration_deadline',
        'price',
        'currency',
        'max_seats',
        'status'
    ]);
    
    // Sample row (matching header columns)
    fputcsv($output, [
        'Sample Workshop on Data Science',
        'sample-workshop-data-science',
        'workshop',
        'Learn data science fundamentals',
        'This is a comprehensive workshop covering data science basics including Python, pandas, and visualization.',
        '1',
        '',
        'https://zoom.us/j/123456789',
        '2026-04-15 10:00:00',
        '2026-04-15 16:00:00',
        '2026-04-14 23:59:59',
        '999',
        'INR',
        '50',
        'draft'
    ]);
    
    fclose($output);
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Invalid security token.');
        redirect('/admin/events/bulk-upload');
    }
    
    $action = $_POST['action'] ?? 'preview';
    
    if ($action === 'preview' && !empty($_FILES['csv_file']['tmp_name'])) {
        $file = $_FILES['csv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            $errors[] = 'Invalid file type. Please upload a CSV or Excel file.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File size must be less than 5MB.';
        } else {
            // Parse CSV
            if ($ext === 'csv') {
                $handle = fopen($file['tmp_name'], 'r');
                $headers = fgetcsv($handle);
                $headers = array_map('strtolower', array_map('trim', $headers));
                
                $rowNum = 1;
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    if (count($row) < 2) continue; // Skip empty rows
                    
                    $data = array_combine($headers, array_pad($row, count($headers), ''));
                    $data['_row'] = $rowNum;
                    $data['_errors'] = [];
                    
                    // Validation
                    if (empty($data['title'])) {
                        $data['_errors'][] = 'Title is required';
                    }
                    if (empty($data['type']) || !in_array($data['type'], ['workshop', 'webinar', 'course', 'bootcamp', 'seminar', 'conference'])) {
                        $data['_errors'][] = 'Invalid type. Use: workshop, webinar, course, bootcamp, seminar, conference';
                    }
                    if (empty($data['start_date'])) {
                        $data['_errors'][] = 'Start date is required';
                    } elseif (!strtotime($data['start_date'])) {
                        $data['_errors'][] = 'Invalid start date format. Use: YYYY-MM-DD HH:MM:SS';
                    }
                    if (empty($data['end_date'])) {
                        $data['_errors'][] = 'End date is required';
                    } elseif (!strtotime($data['end_date'])) {
                        $data['_errors'][] = 'Invalid end date format';
                    }
                    
                    // Check for duplicates
                    if (!empty($data['slug'])) {
                        $checkStmt = $db->prepare("SELECT id FROM events WHERE slug = ?");
                        $checkStmt->execute([trim($data['slug'])]);
                        if ($checkStmt->fetch()) {
                            $data['_errors'][] = 'Slug already exists';
                        }
                    }
                    
                    $previewData[] = $data;
                }
                fclose($handle);
                
                if (empty($previewData)) {
                    $errors[] = 'No valid data rows found in the file.';
                } else {
                    $showPreview = true;
                    // Store in session for import
                    $_SESSION['bulk_upload_data'] = $previewData;
                }
            } else {
                $errors[] = 'Excel files require the PhpSpreadsheet library. Please use CSV format for now.';
            }
        }
    } elseif ($action === 'import' && !empty($_SESSION['bulk_upload_data'])) {
        $previewData = $_SESSION['bulk_upload_data'];
        $selectedRows = $_POST['selected_rows'] ?? [];
        
        foreach ($previewData as $data) {
            if (!in_array($data['_row'], $selectedRows)) continue;
            if (!empty($data['_errors'])) continue;
            
            try {
                // Generate slug if not provided
                $slug = !empty($data['slug']) ? trim($data['slug']) : generateSlug($data['title']);
                
                // Check for duplicate slug and make unique
                $baseSlug = $slug;
                $counter = 1;
                while (true) {
                    $checkStmt = $db->prepare("SELECT id FROM events WHERE slug = ?");
                    $checkStmt->execute([$slug]);
                    if (!$checkStmt->fetch()) break;
                    $slug = $baseSlug . '-' . $counter++;
                }
                
                $isOnline = in_array(strtolower($data['is_online'] ?? ''), ['1', 'yes', 'true', 'online']);
                $isFree = floatval($data['price'] ?? 0) <= 0;
                
                $stmt = $db->prepare("
                    INSERT INTO events (
                        title, slug, type, short_description, description, is_online, venue, meeting_link,
                        start_date, end_date, registration_deadline, price, currency, is_free,
                        max_seats, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    trim($data['title']),
                    $slug,
                    trim($data['type']),
                    trim($data['short_description'] ?? ''),
                    trim($data['description'] ?? ''),
                    $isOnline ? 1 : 0,
                    trim($data['venue'] ?? ''),
                    trim($data['meeting_link'] ?? ''),
                    date('Y-m-d H:i:s', strtotime($data['start_date'])),
                    date('Y-m-d H:i:s', strtotime($data['end_date'])),
                    !empty($data['registration_deadline']) ? date('Y-m-d H:i:s', strtotime($data['registration_deadline'])) : null,
                    floatval($data['price'] ?? 0),
                    strtoupper(trim($data['currency'] ?? 'INR')),
                    $isFree ? 1 : 0,
                    !empty($data['max_seats']) ? intval($data['max_seats']) : null,
                    in_array($data['status'] ?? '', ['draft', 'published', 'completed']) ? $data['status'] : 'draft'
                ]);
                
                $successCount++;
            } catch (PDOException $e) {
                $errors[] = "Row {$data['_row']}: Database error - " . $e->getMessage();
            }
        }
        
        unset($_SESSION['bulk_upload_data']);
        
        if ($successCount > 0) {
            logActivity('bulk_events_upload', "$successCount events imported via bulk upload");
            setFlash('success', "$successCount event(s) imported successfully.");
        }
        if (!empty($errors)) {
            setFlash('error', implode('<br>', array_slice($errors, 0, 5)));
        }
        redirect('/admin/events/');
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.upload-container {
    max-width: 900px;
}

.upload-card {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: 24px;
}

.upload-header {
    padding: 24px;
    border-bottom: 1px solid var(--border-light);
}

.upload-header h1 {
    margin: 0 0 8px;
}

.upload-header p {
    color: var(--text-muted);
    margin: 0;
}

.upload-body {
    padding: 24px;
}

.steps-container {
    display: flex;
    gap: 16px;
    margin-bottom: 32px;
}

.step-card {
    flex: 1;
    padding: 20px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    text-align: center;
}

.step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--blue);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    margin: 0 auto 12px;
}

.step-card h4 {
    margin: 0 0 4px;
    font-size: 0.95rem;
}

.step-card p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-muted);
}

.drop-zone {
    border: 2px dashed var(--border-color);
    border-radius: var(--radius-md);
    padding: 40px 20px;
    text-align: center;
    transition: all 0.3s;
    cursor: pointer;
}

.drop-zone:hover, .drop-zone.dragover {
    border-color: var(--blue);
    background: var(--blue-light);
}

.drop-zone svg {
    width: 48px;
    height: 48px;
    color: var(--text-light);
    margin-bottom: 16px;
}

.drop-zone h3 {
    margin: 0 0 8px;
    font-size: 1.1rem;
}

.drop-zone p {
    margin: 0;
    color: var(--text-muted);
    font-size: 0.9rem;
}

.preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.preview-table th, .preview-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border-light);
}

.preview-table th {
    background: var(--bg-secondary);
    font-weight: 600;
    position: sticky;
    top: 0;
}

.preview-table tr.has-error {
    background: #fef2f2;
}

.preview-table .row-errors {
    color: var(--red);
    font-size: 0.8rem;
}

.preview-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--border-light);
}

.table-scroll {
    max-height: 400px;
    overflow: auto;
    border: 1px solid var(--border-light);
    border-radius: var(--radius-sm);
}

.error-list {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 20px;
}

.error-list ul {
    margin: 0;
    padding-left: 20px;
}

.error-list li {
    color: #991b1b;
}

.select-all-row {
    background: var(--bg-tertiary);
}
</style>

<div class="upload-container">
    <div class="upload-card">
        <div class="upload-header">
            <h1>Bulk Event Upload</h1>
            <p>Upload multiple events at once using a CSV or Excel file</p>
        </div>
        
        <div class="upload-body">
            <?php if (!empty($errors) && !$showPreview): ?>
            <div class="error-list">
                <strong>Errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php if (!$showPreview): ?>
            <!-- Steps -->
            <div class="steps-container">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <h4>Download Template</h4>
                    <p>Get the CSV template with correct columns</p>
                </div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <h4>Fill Data</h4>
                    <p>Add your event details to the file</p>
                </div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <h4>Upload & Preview</h4>
                    <p>Review before importing</p>
                </div>
            </div>
            
            <!-- Download Template -->
            <div style="margin-bottom: 24px;">
                <a href="?download_template=1" class="btn btn-secondary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download CSV Template
                </a>
            </div>
            
            <!-- Upload Form -->
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="preview">
                
                <div class="drop-zone" id="dropZone">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    <h3>Drop your CSV file here</h3>
                    <p>or click to browse files</p>
                    <input type="file" name="csv_file" id="csvFile" accept=".csv,.xlsx,.xls" style="display: none;">
                </div>
                
                <div id="selectedFile" style="display: none; margin-top: 16px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm);">
                    <strong>Selected:</strong> <span id="fileName"></span>
                    <button type="submit" class="btn btn-primary" style="margin-left: 16px;">Preview Data</button>
                </div>
            </form>
            
            <?php else: ?>
            <!-- Preview Table -->
            <form method="POST">
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="import">
                
                <div style="margin-bottom: 16px;">
                    <strong><?php echo count($previewData); ?> row(s)</strong> found in the file.
                    <?php 
                    $errorRows = array_filter($previewData, fn($d) => !empty($d['_errors']));
                    if (count($errorRows) > 0): 
                    ?>
                        <span style="color: var(--red);"><?php echo count($errorRows); ?> row(s) have errors.</span>
                    <?php endif; ?>
                </div>
                
                <div class="table-scroll">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="selectAll" checked>
                                </th>
                                <th>Row</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Start Date</th>
                                <th>Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewData as $data): ?>
                            <tr class="<?php echo !empty($data['_errors']) ? 'has-error' : ''; ?>">
                                <td>
                                    <?php if (empty($data['_errors'])): ?>
                                    <input type="checkbox" name="selected_rows[]" value="<?php echo $data['_row']; ?>" checked>
                                    <?php else: ?>
                                    <span title="Cannot import due to errors">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $data['_row']; ?></td>
                                <td>
                                    <?php echo sanitize(truncateText($data['title'] ?? '', 40)); ?>
                                    <?php if (!empty($data['_errors'])): ?>
                                    <div class="row-errors"><?php echo implode(', ', $data['_errors']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo sanitize($data['type'] ?? ''); ?></td>
                                <td><?php echo !empty($data['start_date']) ? date('M d, Y H:i', strtotime($data['start_date'])) : '-'; ?></td>
                                <td><?php echo !empty($data['price']) ? formatPrice($data['price'], $data['currency'] ?? 'INR') : 'Free'; ?></td>
                                <td><span class="badge"><?php echo sanitize($data['status'] ?? 'draft'); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="preview-actions">
                    <button type="submit" class="btn btn-primary">Import Selected Events</button>
                    <a href="/admin/events/bulk-upload" class="btn btn-secondary">Cancel & Re-upload</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Help Section -->
    <div class="upload-card">
        <div class="upload-header">
            <h3 style="margin: 0;">Column Reference</h3>
        </div>
        <div class="upload-body" style="padding: 16px 24px;">
            <table style="width: 100%; font-size: 0.85rem;">
                <tr><td><strong>title</strong> *</td><td>Event title (required)</td></tr>
                <tr><td><strong>slug</strong></td><td>URL-friendly slug (auto-generated if empty)</td></tr>
                <tr><td><strong>type</strong> *</td><td>workshop, webinar, course, bootcamp, seminar, conference</td></tr>
                <tr><td><strong>start_date</strong> *</td><td>Format: YYYY-MM-DD HH:MM:SS</td></tr>
                <tr><td><strong>end_date</strong> *</td><td>Format: YYYY-MM-DD HH:MM:SS</td></tr>
                <tr><td><strong>is_online</strong></td><td>1 or yes for online, 0 or no for offline</td></tr>
                <tr><td><strong>price</strong></td><td>Numeric value (0 for free events)</td></tr>
                <tr><td><strong>status</strong></td><td>draft, published, or completed</td></tr>
            </table>
            <p style="margin: 12px 0 0; font-size: 0.85rem; color: var(--text-muted);">
                * Required fields. Banners can be uploaded separately through the event edit page.
            </p>
        </div>
    </div>
</div>

<script>
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('csvFile');
const selectedFile = document.getElementById('selectedFile');
const fileName = document.getElementById('fileName');
const selectAll = document.getElementById('selectAll');

dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('dragover');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('dragover');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        showSelectedFile();
    }
});

fileInput.addEventListener('change', showSelectedFile);

function showSelectedFile() {
    if (fileInput.files.length) {
        fileName.textContent = fileInput.files[0].name;
        selectedFile.style.display = 'block';
        dropZone.style.display = 'none';
    }
}

if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('input[name="selected_rows[]"]').forEach(cb => {
            cb.checked = this.checked;
        });
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
