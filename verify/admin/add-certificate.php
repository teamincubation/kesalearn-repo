<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$success_message = '';
$error_message = '';
$debug_info = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $certificate_number = trim($_POST['certificate_number'] ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $course_name = trim($_POST['course_name'] ?? '');
    $course_url = trim($_POST['course_url'] ?? '');
    $issue_date = $_POST['issue_date'] ?? '';
    
    // Debug: Log form data
    $debug_info[] = "Form submitted with certificate number: $certificate_number";
    $debug_info[] = "Student name: $student_name";
    $debug_info[] = "Course name: $course_name";
    
    // Basic validation
    if (empty($certificate_number) || empty($student_name) || empty($course_name) || empty($issue_date)) {
        $error_message = 'Please fill in all required fields.';
        $debug_info[] = "❌ Required fields missing";
    } else {
        // Debug: Check file upload
        $debug_info[] = "Checking file upload...";
        $debug_info[] = "FILES array: " . print_r($_FILES, true);
        
        if (!isset($_FILES['certificate_image'])) {
            $error_message = 'No file upload detected. Please select a certificate image.';
            $debug_info[] = "❌ No FILES[certificate_image] found";
        } elseif ($_FILES['certificate_image']['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'File too large (exceeds php.ini limit)',
                UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds form limit)', 
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            
            $error_code = $_FILES['certificate_image']['error'];
            $error_message = 'File upload error: ' . ($upload_errors[$error_code] ?? "Unknown error code: $error_code");
            $debug_info[] = "❌ Upload error code: $error_code";
        } elseif ($_FILES['certificate_image']['size'] == 0) {
            $error_message = 'The uploaded file is empty. Please select a valid image file.';
            $debug_info[] = "❌ File size is 0 bytes";
        } else {
            $debug_info[] = "✅ File upload looks good, proceeding...";
            
            try {
                // Check if certificate number already exists
                $stmt = $pdo->prepare("SELECT id FROM certificates WHERE certificate_number = ?");
                $stmt->execute([$certificate_number]);
                if ($stmt->fetch()) {
                    $error_message = 'Certificate number already exists. Please use a different number.';
                    $debug_info[] = "❌ Certificate number already exists";
                } else {
                    // Create upload directory if it doesn't exist
                    $upload_dir = '../uploads/certificates/';
                    if (!file_exists($upload_dir)) {
                        if (mkdir($upload_dir, 0755, true)) {
                            $debug_info[] = "✅ Created upload directory: $upload_dir";
                        } else {
                            $error_message = 'Failed to create upload directory.';
                            $debug_info[] = "❌ Failed to create directory: $upload_dir";
                        }
                    } else {
                        $debug_info[] = "✅ Upload directory exists: $upload_dir";
                    }
                    
                    if (empty($error_message)) {
                        // Handle certificate image upload
                        $image_tmp = $_FILES['certificate_image']['tmp_name'];
                        $image_name = $_FILES['certificate_image']['name'];
                        $image_size = $_FILES['certificate_image']['size'];
                        $image_type = $_FILES['certificate_image']['type'];
                        $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
                        
                        $debug_info[] = "File details - Name: $image_name, Size: $image_size, Type: $image_type, Extension: $image_ext";
                        
                        // Validate file type
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        if (!in_array($image_ext, $allowed_extensions)) {
                            $error_message = 'Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.';
                            $debug_info[] = "❌ Invalid file extension: $image_ext";
                        } elseif ($image_size > 10 * 1024 * 1024) { // 10MB limit
                            $error_message = 'File is too large. Maximum size allowed is 10MB.';
                            $debug_info[] = "❌ File too large: " . ($image_size / 1024 / 1024) . "MB";
                        } else {
                            // Generate unique filename
                            $certificate_image = $certificate_number . '_' . time() . '.' . $image_ext;
                            $target_path = $upload_dir . $certificate_image;
                            
                            $debug_info[] = "Target path: $target_path";
                            
                            // Move uploaded file
                            if (move_uploaded_file($image_tmp, $target_path)) {
                                $debug_info[] = "✅ File moved successfully to: $target_path";
                                
                                // Verify file exists and get final size
                                if (file_exists($target_path)) {
                                    $final_size = filesize($target_path);
                                    $debug_info[] = "✅ File verified - Final size: $final_size bytes";
                                    
                                    // Insert certificate into database
                                    $stmt = $pdo->prepare("INSERT INTO certificates (certificate_number, student_name, course_name, course_url, certificate_image, issue_date) VALUES (?, ?, ?, ?, ?, ?)");
                                    $result = $stmt->execute([$certificate_number, $student_name, $course_name, $course_url, $certificate_image, $issue_date]);
                                    
                                    if ($result) {
                                        $debug_info[] = "✅ Certificate saved to database successfully";
                                        $success_message = 'Certificate added successfully! <a href="../certificates/?cert=' . urlencode($certificate_number) . '" target="_blank" style="color: #155724; font-weight: bold;">View Certificate</a>';
                                        
                                        // Clear form data on success
                                        $_POST = [];
                                    } else {
                                        $error_message = 'Failed to save certificate to database.';
                                        $debug_info[] = "❌ Database insert failed";
                                        
                                        // Delete uploaded file if database insert failed
                                        if (file_exists($target_path)) {
                                            unlink($target_path);
                                            $debug_info[] = "🧹 Cleaned up uploaded file due to database error";
                                        }
                                    }
                                } else {
                                    $error_message = 'File upload completed but file verification failed.';
                                    $debug_info[] = "❌ File not found after upload";
                                }
                            } else {
                                $error_message = 'Failed to move uploaded file. Please check directory permissions.';
                                $debug_info[] = "❌ move_uploaded_file() failed";
                                $debug_info[] = "Source: $image_tmp";
                                $debug_info[] = "Destination: $target_path";
                                $debug_info[] = "Directory writable: " . (is_writable($upload_dir) ? 'Yes' : 'No');
                            }
                        }
                    }
                }
            } catch(PDOException $e) {
                $error_message = 'Database error occurred: ' . $e->getMessage();
                $debug_info[] = "❌ Database error: " . $e->getMessage();
                error_log("Add certificate error: " . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Certificate | KESA Learning Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .main-content {
            padding: 2rem 0;
        }

        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            padding: 2rem;
            max-width: 800px;
            margin: 0 auto;
        }

        .form-header {
            margin-bottom: 2rem;
        }

        .form-header h1 {
            color: #333;
            margin-bottom: 0.5rem;
        }

        .debug-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-family: monospace;
            font-size: 0.9rem;
            max-height: 300px;
            overflow-y: auto;
        }

        .debug-section h3 {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin-bottom: 0.5rem;
            color: #495057;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }

        .form-group label .required {
            color: #dc3545;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .file-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            background: white;
            cursor: pointer;
        }

        .file-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e9ecef;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .help-text {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }

        .upload-requirements {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            font-size: 0.875rem;
        }

        .upload-requirements h4 {
            color: #0066cc;
            margin-bottom: 0.5rem;
        }

        .upload-requirements ul {
            margin-left: 1rem;
        }

        .upload-requirements li {
            margin-bottom: 0.25rem;
        }

        .php-config {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .php-config h4 {
            color: #856404;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">KESA Learning - Admin (PHP Only)</div>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <div class="form-container">
                <div class="form-header">
                    <h1>Add New Certificate</h1>
                    <p>Pure PHP version - No JavaScript required</p>
                </div>

                 PHP Configuration Info 
                <div class="php-config">
                    <h4>Server Upload Configuration:</h4>
                    <p><strong>Max Upload Size:</strong> <?php echo ini_get('upload_max_filesize'); ?></p>
                    <p><strong>Max POST Size:</strong> <?php echo ini_get('post_max_size'); ?></p>
                    <p><strong>File Uploads:</strong> <?php echo ini_get('file_uploads') ? 'Enabled' : 'Disabled'; ?></p>
                </div>

                <?php if (!empty($debug_info)): ?>
                    <div class="debug-section">
                        <h3>Debug Information:</h3>
                        <?php foreach ($debug_info as $info): ?>
                            <?php echo htmlspecialchars($info) . "<br>"; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="certificate_number">Certificate Number <span class="required">*</span></label>
                            <input type="text" id="certificate_number" name="certificate_number" 
                                   value="<?php echo htmlspecialchars($_POST['certificate_number'] ?? ''); ?>" 
                                   placeholder="e.g., KES001" required>
                            <div class="help-text">Unique identifier for the certificate</div>
                        </div>

                        <div class="form-group">
                            <label for="issue_date">Issue Date <span class="required">*</span></label>
                            <input type="date" id="issue_date" name="issue_date" 
                                   value="<?php echo htmlspecialchars($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="student_name">Student Name <span class="required">*</span></label>
                        <input type="text" id="student_name" name="student_name" 
                               value="<?php echo htmlspecialchars($_POST['student_name'] ?? ''); ?>" 
                               placeholder="e.g., John Doe" required>
                    </div>

                    <div class="form-group">
                        <label for="course_name">Course Name <span class="required">*</span></label>
                        <input type="text" id="course_name" name="course_name" 
                               value="<?php echo htmlspecialchars($_POST['course_name'] ?? ''); ?>" 
                               placeholder="e.g., Digital Marketing Fundamentals" required>
                    </div>

                    <div class="form-group">
                        <label for="course_url">Course URL</label>
                        <input type="url" id="course_url" name="course_url" 
                               value="<?php echo htmlspecialchars($_POST['course_url'] ?? ''); ?>" 
                               placeholder="https://kesalearn.com/course/...">
                        <div class="help-text">Link to the course page (optional)</div>
                    </div>

                    <div class="form-group full-width">
                        <label for="certificate_image">Certificate Image <span class="required">*</span></label>
                        <input type="file" id="certificate_image" name="certificate_image" 
                               class="file-input" accept="image/*" required>
                        <div class="help-text">Select the certificate image file</div>
                        
                        <div class="upload-requirements">
                            <h4>Upload Requirements:</h4>
                            <ul>
                                <li>Supported formats: JPG, PNG, GIF, WebP</li>
                                <li>Maximum file size: 10MB</li>
                                <li>Recommended resolution: 1920x1080 or higher</li>
                                <li>Make sure the certificate is clear and readable</li>
                            </ul>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
