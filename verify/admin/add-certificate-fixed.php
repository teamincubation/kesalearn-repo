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
    
    $debug_info[] = "Form submitted with certificate number: $certificate_number";
    
    if (empty($certificate_number) || empty($student_name) || empty($course_name) || empty($issue_date)) {
        $error_message = 'Please fill in all required fields.';
    } else {
        try {
            // Check if certificate number already exists
            $stmt = $pdo->prepare("SELECT id FROM certificates WHERE certificate_number = ?");
            $stmt->execute([$certificate_number]);
            if ($stmt->fetch()) {
                $error_message = 'Certificate number already exists.';
            } else {
                // Create upload directories if they don't exist
                $upload_dirs = ['../uploads', '../uploads/certificates', '../uploads/banners'];
                foreach ($upload_dirs as $dir) {
                    if (!file_exists($dir)) {
                        if (mkdir($dir, 0755, true)) {
                            $debug_info[] = "Created directory: $dir";
                        } else {
                            $debug_info[] = "Failed to create directory: $dir";
                        }
                    } else {
                        $debug_info[] = "Directory exists: $dir";
                    }
                }
                
                $certificate_image = '';
                $course_banner = '';
                
                // Handle certificate image upload with detailed debugging
                if (isset($_FILES['certificate_image']) && $_FILES['certificate_image']['error'] === UPLOAD_ERR_OK) {
                    $debug_info[] = "Certificate image upload detected";
                    
                    $image_tmp = $_FILES['certificate_image']['tmp_name'];
                    $image_name = $_FILES['certificate_image']['name'];
                    $image_size = $_FILES['certificate_image']['size'];
                    $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
                    
                    $debug_info[] = "Original filename: $image_name";
                    $debug_info[] = "File size: $image_size bytes";
                    $debug_info[] = "File extension: $image_ext";
                    $debug_info[] = "Temp file: $image_tmp";
                    
                    if (in_array($image_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $certificate_image = $certificate_number . '_certificate.' . $image_ext;
                        $target_path = '../uploads/certificates/' . $certificate_image;
                        
                        $debug_info[] = "Target path: $target_path";
                        
                        if (move_uploaded_file($image_tmp, $target_path)) {
                            $debug_info[] = "✅ Certificate image uploaded successfully";
                            
                            // Verify file exists and get size
                            if (file_exists($target_path)) {
                                $final_size = filesize($target_path);
                                $debug_info[] = "✅ File verified - Final size: $final_size bytes";
                            } else {
                                $debug_info[] = "❌ File not found after upload";
                            }
                        } else {
                            $error_message = 'Failed to upload certificate image.';
                            $debug_info[] = "❌ move_uploaded_file failed";
                        }
                    } else {
                        $error_message = 'Certificate file must be an image (JPG, PNG, GIF, WebP).';
                        $debug_info[] = "❌ Invalid file extension: $image_ext";
                    }
                } else {
                    $debug_info[] = "No certificate image uploaded or upload error";
                    if (isset($_FILES['certificate_image'])) {
                        $debug_info[] = "Upload error code: " . $_FILES['certificate_image']['error'];
                    }
                }
                
                // Handle course banner upload
                if (isset($_FILES['course_banner']) && $_FILES['course_banner']['error'] === UPLOAD_ERR_OK) {
                    $debug_info[] = "Course banner upload detected";
                    
                    $banner_tmp = $_FILES['course_banner']['tmp_name'];
                    $banner_name = $_FILES['course_banner']['name'];
                    $banner_ext = strtolower(pathinfo($banner_name, PATHINFO_EXTENSION));
                    
                    if (in_array($banner_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $course_banner = $certificate_number . '_banner.' . $banner_ext;
                        $banner_target = '../uploads/banners/' . $course_banner;
                        
                        if (move_uploaded_file($banner_tmp, $banner_target)) {
                            $debug_info[] = "✅ Course banner uploaded successfully";
                        } else {
                            $debug_info[] = "❌ Course banner upload failed";
                        }
                    } else {
                        $error_message = 'Course banner must be an image file (JPG, PNG, GIF, WebP).';
                    }
                }
                
                if (empty($error_message)) {
                    // Insert certificate into database with explicit column names
                    $sql = "INSERT INTO certificates (certificate_number, student_name, course_name, course_banner, course_url, certificate_image, issue_date) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $result = $stmt->execute([$certificate_number, $student_name, $course_name, $course_banner, $course_url, $certificate_image, $issue_date]);
                    
                    if ($result) {
                        $debug_info[] = "✅ Certificate saved to database";
                        $debug_info[] = "Database values - certificate_image: '$certificate_image', course_banner: '$course_banner'";
                        
                        $success_message = 'Certificate added successfully!';
                        
                        // Verify database entry
                        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE certificate_number = ?");
                        $stmt->execute([$certificate_number]);
                        $saved_cert = $stmt->fetch();
                        
                        if ($saved_cert) {
                            $debug_info[] = "✅ Certificate verified in database";
                            $debug_info[] = "Saved certificate_image: '" . $saved_cert['certificate_image'] . "'";
                        }
                        
                        // Clear form data
                        $_POST = [];
                    } else {
                        $error_message = 'Failed to save certificate to database.';
                        $debug_info[] = "❌ Database insert failed";
                    }
                }
            }
        } catch(PDOException $e) {
            $error_message = 'Database error occurred: ' . $e->getMessage();
            $debug_info[] = "❌ Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Certificate (Fixed) | KESA Learning Admin</title>
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
        }

        .debug-section h3 {
            color: #495057;
            margin-bottom: 0.5rem;
        }

        .debug-info {
            font-family: monospace;
            font-size: 0.9rem;
            line-height: 1.4;
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
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .file-input {
            width: 100%;
            padding: 12px;
            border: 2px dashed #e9ecef;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .file-input:hover {
            border-color: #667eea;
            background: #f8f9fa;
        }

        .file-input input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
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

        .file-preview {
            margin-top: 10px;
            text-align: center;
        }

        .file-preview img {
            max-width: 200px;
            max-height: 150px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
                <div class="logo">KESA Learning - Admin (Fixed Version)</div>
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
                    <h1>Add New Certificate (Debug Version)</h1>
                    <p>This version includes detailed debugging information</p>
                </div>

                <?php if (!empty($debug_info)): ?>
                    <div class="debug-section">
                        <h3>Debug Information:</h3>
                        <div class="debug-info">
                            <?php foreach ($debug_info as $info): ?>
                                <?php echo htmlspecialchars($info) . "<br>"; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="certificate_number">Certificate Number <span class="required">*</span></label>
                            <input type="text" id="certificate_number" name="certificate_number" 
                                   value="<?php echo htmlspecialchars($_POST['certificate_number'] ?? ''); ?>" required>
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
                               value="<?php echo htmlspecialchars($_POST['student_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="course_name">Course Name <span class="required">*</span></label>
                        <input type="text" id="course_name" name="course_name" 
                               value="<?php echo htmlspecialchars($_POST['course_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="course_url">Course URL</label>
                        <input type="url" id="course_url" name="course_url" 
                               value="<?php echo htmlspecialchars($_POST['course_url'] ?? ''); ?>" 
                               placeholder="https://kesalearn.com/course/...">
                        <div class="help-text">Link to the course page (optional)</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="certificate_image">Certificate Image <span class="required">*</span></label>
                            <div class="file-input">
                                <input type="file" id="certificate_image" name="certificate_image" accept="image/*" required>
                                <i class="fas fa-image"></i> Choose certificate image
                            </div>
                            <div class="help-text">Upload the certificate image (JPG, PNG, GIF, WebP) - REQUIRED</div>
                            <div id="cert-preview" class="file-preview"></div>
                        </div>

                        <div class="form-group">
                            <label for="course_banner">Course Banner</label>
                            <div class="file-input">
                                <input type="file" id="course_banner" name="course_banner" accept="image/*">
                                <i class="fas fa-image"></i> Choose banner image
                            </div>
                            <div class="help-text">Upload course banner image (optional)</div>
                            <div id="banner-preview" class="file-preview"></div>
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

    <script>
        function setupFileInput(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            
            input.addEventListener('change', function() {
                const wrapper = this.closest('.file-input');
                const file = this.files[0];
                
                if (file) {
                    wrapper.innerHTML = `<i class="fas fa-check"></i> ${file.name} (${Math.round(file.size/1024)}KB)`;
                    wrapper.style.borderColor = '#28a745';
                    wrapper.style.color = '#28a745';
                    
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                        };
                        reader.readAsDataURL(file);
                    }
                }
            });
        }

        setupFileInput('certificate_image', 'cert-preview');
        setupFileInput('course_banner', 'banner-preview');
    </script>
</body>
</html>
