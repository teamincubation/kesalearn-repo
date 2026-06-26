<?php
session_start();
require_once '../config/database.php';
require_once 'includes/functions.php';

$message = '';
$message_type = '';
$preview_data = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        
        // Validate file upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = 'File upload error';
            $message_type = 'error';
        } else {
            // Check file type
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($file_ext, array('csv', 'xls', 'xlsx'))) {
                $message = 'Invalid file type. Please upload CSV or XLS file.';
                $message_type = 'error';
            } else {
                // Parse file
                if ($file_ext === 'csv') {
                    $result = parseCSVFile($file['tmp_name']);
                } else {
                    $result = parseXLSFile($file['tmp_name']);
                }
                
                // Check for parsing errors
                if (!empty($result['errors'])) {
                    $message = 'File parsing errors: ' . implode('; ', array_slice($result['errors'], 0, 5));
                    $message_type = 'error';
                } else if ($result['count'] === 0) {
                    $message = 'No valid records found in file.';
                    $message_type = 'error';
                } else {
                    // Check for duplicates
                    $duplicates = checkDuplicates($conn, $result['data']);
                    
                    if (!empty($duplicates)) {
                        $message = 'Found ' . count($duplicates) . ' duplicate certificate numbers. These will be skipped.';
                        $message_type = 'warning';
                        
                        // Remove duplicates
                        $result['data'] = array_filter($result['data'], function($cert) use ($duplicates) {
                            return !in_array($cert['Certificate Number'], $duplicates);
                        });
                    }
                    
                    if (count($result['data']) > 0) {
                        // Show preview
                        $preview_data = $result['data'];
                        $message = 'Preview: ' . count($result['data']) . ' certificate(s) ready to import';
                        $message_type = 'success';
                    }
                }
            }
        }
    }
    
    // Handle import confirmation
    if (isset($_POST['confirm_import']) && !empty($preview_data)) {
        $import_result = saveBulkCertificates($conn, $preview_data);
        
        if ($import_result['saved'] > 0) {
            $message = $import_result['saved'] . ' certificate(s) imported successfully!';
            $message_type = 'success';
            $preview_data = array();
        }
        
        if ($import_result['failed'] > 0) {
            $message .= ' (' . $import_result['failed'] . ' failed)';
            $message_type = 'warning';
        }
    }
}

$stats = getDashboardStats($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Certificate Upload - KESA Learning</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #667eea;
        }
        
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .stat-card .number {
            font-size: 32px;
            color: #667eea;
            font-weight: bold;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #f5c6cb;
        }
        
        .message.warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }
        
        .upload-section {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .upload-section h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .drop-zone {
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .drop-zone:hover,
        .drop-zone.active {
            background: #f0f4ff;
            border-color: #764ba2;
        }
        
        .drop-zone.active {
            transform: scale(1.02);
        }
        
        .drop-zone p {
            margin: 10px 0;
            color: #666;
        }
        
        .drop-zone .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        input[type="file"] {
            display: none;
        }
        
        .button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            transition: transform 0.2s ease;
        }
        
        .button:hover {
            transform: translateY(-2px);
        }
        
        .button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .button.secondary {
            background: #6c757d;
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .preview-table th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .preview-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        
        .preview-table tr:hover {
            background: #f8f9fa;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .nav-links {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 10px;
        }
        
        .nav-links a {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        
        .nav-links a:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Bulk Certificate Upload</h1>
            <p>Import multiple certificates from CSV or XLS files</p>
        </div>
        
        <div class="content">
            <!-- Statistics -->
            <div class="stats">
                <div class="stat-card">
                    <h3>Total Certificates</h3>
                    <div class="number"><?php echo $stats['total']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending Upload</h3>
                    <div class="number"><?php echo $stats['pending']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Uploaded</h3>
                    <div class="number"><?php echo $stats['uploaded']; ?></div>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Upload Section -->
            <div class="upload-section">
                <h2>Upload CSV or XLS File</h2>
                <p style="margin-bottom: 20px; color: #666;">Required columns: Certificate Number, Student Name, Issue Date, Course URL</p>
                
                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <div class="drop-zone" id="dropZone">
                        <div class="icon">📁</div>
                        <p><strong>Drag and drop your CSV/XLS file here</strong></p>
                        <p>or click to browse</p>
                        <input type="file" id="fileInput" name="csv_file" accept=".csv,.xls,.xlsx" required>
                    </div>
                    
                    <div class="button-group">
                        <button type="submit" class="button">Upload & Preview</button>
                        <button type="reset" class="button secondary">Clear</button>
                    </div>
                </form>
            </div>
            
            <!-- Preview Table -->
            <?php if (!empty($preview_data)): ?>
            <div class="upload-section">
                <h2>Preview: <?php echo count($preview_data); ?> Certificate(s)</h2>
                
                <form method="POST">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th>Certificate Number</th>
                                <th>Student Name</th>
                                <th>Issue Date</th>
                                <th>Course URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($preview_data, 0, 10) as $cert): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cert['Certificate Number']); ?></td>
                                <td><?php echo htmlspecialchars($cert['Student Name']); ?></td>
                                <td><?php echo htmlspecialchars($cert['Issue Date']); ?></td>
                                <td><?php echo htmlspecialchars($cert['Course URL']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if (count($preview_data) > 10): ?>
                    <p style="margin-top: 10px; color: #666;">... and <?php echo count($preview_data) - 10; ?> more records</p>
                    <?php endif; ?>
                    
                    <div class="button-group" style="margin-top: 20px;">
                        <button type="submit" name="confirm_import" value="1" class="button">Confirm Import</button>
                        <button type="reset" class="button secondary">Cancel</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- Navigation -->
            <div class="nav-links">
                <a href="dashboard.php">← Back to Dashboard</a>
                <a href="certificate-upload.php">Go to File Upload →</a>
            </div>
        </div>
    </div>
    
    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        
        dropZone.addEventListener('click', () => fileInput.click());
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('active');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('active');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('active');
            fileInput.files = e.dataTransfer.files;
            document.getElementById('uploadForm').submit();
        });
    </script>
</body>
</html>
