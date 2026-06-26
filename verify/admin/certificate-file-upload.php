<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$success_message = '';
$error_message = '';

// Handle single certificate file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['certificate_file']) && isset($_POST['cert_id'])) {
    try {
        $cert_id = (int)$_POST['cert_id'];
        $file = $_FILES['certificate_file'];

        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error");
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/pdf'];
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception("Invalid file type. Only JPG, PNG, PDF allowed");
        }

        if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
            throw new Exception("File too large. Max 5MB");
        }

        // Get certificate info
        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
        $stmt->execute([$cert_id]);
        $cert = $stmt->fetch();

        if (!$cert) {
            throw new Exception("Certificate not found");
        }

        // Delete old file if exists
        if ($cert['certificate_image'] && file_exists('../uploads/certificates/' . $cert['certificate_image'])) {
            unlink('../uploads/certificates/' . $cert['certificate_image']);
        }

        // Create uploads directory
        $upload_dir = '../uploads/certificates/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate filename
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $new_filename = $cert['certificate_number'] . '_' . time() . '.' . $ext;
        $upload_path = $upload_dir . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            throw new Exception("Failed to save file");
        }

        // Update database
        $stmt = $pdo->prepare("UPDATE certificates SET certificate_image = ? WHERE id = ?");
        $stmt->execute([$new_filename, $cert_id]);

        $success_message = "Certificate file uploaded successfully for " . htmlspecialchars($cert['student_name']);

    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get pending certificates (without images)
try {
    $stmt = $pdo->query("
        SELECT * FROM certificates 
        WHERE certificate_image IS NULL OR certificate_image = '' 
        ORDER BY created_at DESC
    ");
    $pending_certificates = $stmt->fetchAll();
} catch (PDOException $e) {
    $pending_certificates = [];
    $error_message = "Error fetching certificates";
}

// Get uploaded certificates count
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as count FROM certificates 
        WHERE certificate_image IS NOT NULL AND certificate_image != ''
    ");
    $uploaded_count = $stmt->fetch()['count'];
} catch (PDOException $e) {
    $uploaded_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Certificate Files | KESA Learning</title>
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .main-content {
            padding: 2rem 0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.875rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-card i {
            font-size: 2rem;
            color: #667eea;
            margin-bottom: 0.5rem;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }

        .stat-card .label {
            color: #666;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .certificates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .cert-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .cert-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .cert-card-header {
            background: #f8f9fa;
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
        }

        .cert-card-header h3 {
            font-size: 0.9rem;
            color: #667eea;
            word-break: break-all;
            margin: 0 0 0.5rem 0;
        }

        .cert-card-header p {
            font-size: 0.9rem;
            color: #666;
            margin: 0;
        }

        .cert-card-body {
            padding: 1rem;
        }

        .cert-info {
            margin-bottom: 1rem;
        }

        .cert-info-label {
            font-size: 0.8rem;
            color: #999;
            font-weight: 600;
            text-transform: uppercase;
        }

        .cert-info-value {
            font-size: 0.95rem;
            color: #333;
            margin-top: 0.25rem;
        }

        .file-input-wrapper {
            position: relative;
            display: block;
            margin-bottom: 1rem;
        }

        .file-input-wrapper input[type="file"] {
            display: none;
        }

        .file-input-label {
            display: block;
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: #667eea;
        }

        .file-input-label:hover {
            background: #e8ebff;
            border-color: #667eea;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 1rem;
        }

        .breadcrumb {
            margin-bottom: 2rem;
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal.show {
            display: block;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
        }

        .modal-header {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }

        .modal-close {
            float: right;
            font-size: 1.5rem;
            cursor: pointer;
            color: #999;
        }

        .modal-close:hover {
            color: #333;
        }

        @media (max-width: 768px) {
            .certificates-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">KESA Learning - Admin</div>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                    <a href="logout.php" class="btn btn-secondary">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

            <div class="page-header">
                <h1>Upload Certificate Files</h1>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-hourglass-half"></i>
                    <div class="number"><?php echo count($pending_certificates); ?></div>
                    <div class="label">Pending Upload</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-check-circle"></i>
                    <div class="number"><?php echo $uploaded_count; ?></div>
                    <div class="label">Uploaded</div>
                </div>
            </div>

            <?php if (empty($pending_certificates)): ?>
                <div class="empty-state">
                    <i class="fas fa-certificate"></i>
                    <h3>No Pending Certificates</h3>
                    <p>All certificates have certificate files uploaded!</p>
                    <a href="bulk-upload.php" class="btn btn-primary" style="margin-top: 1rem;">
                        <i class="fas fa-plus"></i> Bulk Import New Certificates
                    </a>
                </div>
            <?php else: ?>
                <div class="certificates-grid">
                    <?php foreach ($pending_certificates as $cert): ?>
                    <div class="cert-card">
                        <div class="cert-card-header">
                            <h3><i class="fas fa-certificate"></i> <?php echo htmlspecialchars($cert['certificate_number']); ?></h3>
                            <p><?php echo htmlspecialchars($cert['student_name']); ?></p>
                        </div>
                        <div class="cert-card-body">
                            <div class="cert-info">
                                <div class="cert-info-label">Course</div>
                                <div class="cert-info-value"><?php echo htmlspecialchars($cert['course_name']); ?></div>
                            </div>
                            <div class="cert-info">
                                <div class="cert-info-label">Issue Date</div>
                                <div class="cert-info-value"><?php echo date('M j, Y', strtotime($cert['issue_date'])); ?></div>
                            </div>
                            <form method="POST" enctype="multipart/form-data" id="form-<?php echo $cert['id']; ?>">
                                <input type="hidden" name="cert_id" value="<?php echo $cert['id']; ?>">
                                <div class="file-input-wrapper">
                                    <input type="file" id="file-<?php echo $cert['id']; ?>" name="certificate_file" accept=".jpg,.jpeg,.png,.pdf" required>
                                    <label for="file-<?php echo $cert['id']; ?>" class="file-input-label">
                                        <i class="fas fa-cloud-upload-alt"></i> Select Certificate File
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-upload"></i> Upload
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
