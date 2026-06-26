<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$certificate_id = $_GET['id'] ?? '';
if (empty($certificate_id)) {
    header('Location: dashboard.php');
    exit;
}

// Get certificate data
try {
    $stmt = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
    $stmt->execute([$certificate_id]);
    $certificate = $stmt->fetch();
    
    if (!$certificate) {
        header('Location: dashboard.php');
        exit;
    }
} catch(PDOException $e) {
    header('Location: dashboard.php');
    exit;
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $certificate_number = trim($_POST['certificate_number'] ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $course_name = trim($_POST['course_name'] ?? '');
    $course_url = trim($_POST['course_url'] ?? '');
    $issue_date = $_POST['issue_date'] ?? '';
    
    if (empty($certificate_number) || empty($student_name) || empty($course_name) || empty($issue_date)) {
        $error_message = 'Please fill in all required fields.';
    } else {
        try {
            // Check if certificate number already exists (excluding current certificate)
            $stmt = $pdo->prepare("SELECT id FROM certificates WHERE certificate_number = ? AND id != ?");
            $stmt->execute([$certificate_number, $certificate_id]);
            if ($stmt->fetch()) {
                $error_message = 'Certificate number already exists.';
            } else {
                $certificate_image = $certificate['certificate_image'];
                
                // Handle certificate image upload
                if (isset($_FILES['certificate_image']) && $_FILES['certificate_image']['error'] === UPLOAD_ERR_OK) {
                    $image_tmp = $_FILES['certificate_image']['tmp_name'];
                    $image_name = $_FILES['certificate_image']['name'];
                    $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
                    
                    if (in_array($image_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        // Delete old file
                        if ($certificate_image && file_exists('../uploads/certificates/' . $certificate_image)) {
                            unlink('../uploads/certificates/' . $certificate_image);
                        }
                        
                        $certificate_image = $certificate_number . '_certificate.' . $image_ext;
                        $target_path = '../uploads/certificates/' . $certificate_image;
                        
                        if (!move_uploaded_file($image_tmp, $target_path)) {
                            $error_message = 'Failed to upload certificate image.';
                        }
                    } else {
                        $error_message = 'Certificate file must be an image (JPG, PNG, GIF, WebP).';
                    }
                }
                
                if (empty($error_message)) {
                    // Update certificate in database
                    $stmt = $pdo->prepare("UPDATE certificates SET certificate_number = ?, student_name = ?, course_name = ?, course_url = ?, certificate_image = ?, issue_date = ? WHERE id = ?");
                    $stmt->execute([$certificate_number, $student_name, $course_name, $course_url, $certificate_image, $issue_date, $certificate_id]);
                    
                    $success_message = 'Certificate updated successfully!';
                    
                    // Refresh certificate data
                    $stmt = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
                    $stmt->execute([$certificate_id]);
                    $certificate = $stmt->fetch();
                }
            }
        } catch(PDOException $e) {
            $error_message = 'Database error occurred.';
            error_log("Edit certificate error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Certificate | KESA Learning Admin</title>
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

        .current-file {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 0.875rem;
            color: #0066cc;
        }

        .current-file img {
            max-width: 150px;
            max-height: 100px;
            border-radius: 4px;
            margin-top: 8px;
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
                <div class="logo">KESA Learning - Admin</div>
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
                    <h1>Edit Certificate</h1>
                    <p>Update certificate details</p>
                </div>

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
                                   value="<?php echo htmlspecialchars($certificate['certificate_number']); ?>" required>
                            <div class="help-text">Unique identifier for the certificate</div>
                        </div>

                        <div class="form-group">
                            <label for="issue_date">Issue Date <span class="required">*</span></label>
                            <input type="date" id="issue_date" name="issue_date" 
                                   value="<?php echo htmlspecialchars($certificate['issue_date']); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="student_name">Student Name <span class="required">*</span></label>
                        <input type="text" id="student_name" name="student_name" 
                               value="<?php echo htmlspecialchars($certificate['student_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="course_name">Course Name <span class="required">*</span></label>
                        <input type="text" id="course_name" name="course_name" 
                               value="<?php echo htmlspecialchars($certificate['course_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="course_url">Course URL</label>
                        <input type="url" id="course_url" name="course_url" 
                               value="<?php echo htmlspecialchars($certificate['course_url']); ?>" 
                               placeholder="https://kesalearn.com/course/...">
                        <div class="help-text">Link to the course page (optional)</div>
                    </div>

                    <div class="form-group">
                        <label for="certificate_image">Certificate Image</label>
                        <?php if ($certificate['certificate_image']): ?>
                            <div class="current-file">
                                <i class="fas fa-image"></i> Current: <?php echo htmlspecialchars($certificate['certificate_image']); ?>
                                <?php if (file_exists('../uploads/certificates/' . $certificate['certificate_image'])): ?>
                                    <br><img src="../uploads/certificates/<?php echo htmlspecialchars($certificate['certificate_image']); ?>" alt="Current Certificate">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="file-input">
                            <input type="file" id="certificate_image" name="certificate_image" accept="image/*">
                            <i class="fas fa-image"></i> Choose new certificate image
                        </div>
                        <div class="help-text">Upload new certificate image (optional)</div>
                        <div id="cert-preview" class="file-preview"></div>
                    </div>

                    <div class="form-actions">
                        <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        document.getElementById('certificate_image').addEventListener('change', function() {
            const wrapper = this.closest('.file-input');
            const preview = document.getElementById('cert-preview');
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
    </script>
</body>
</html>
