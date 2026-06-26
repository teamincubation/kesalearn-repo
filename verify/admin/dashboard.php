<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Handle certificate deletion
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $cert_id = $_GET['delete'];
    
    try {
        // Get certificate info to delete files
        $stmt = $pdo->prepare("SELECT certificate_image FROM certificates WHERE id = ?");
        $stmt->execute([$cert_id]);
        $cert = $stmt->fetch();
        
        if ($cert) {
            // Delete certificate image file
            if ($cert['certificate_image'] && file_exists('../uploads/certificates/' . $cert['certificate_image'])) {
                unlink('../uploads/certificates/' . $cert['certificate_image']);
            }
            
            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
            $stmt->execute([$cert_id]);
            
            $success_message = "Certificate deleted successfully!";
        }
    } catch(PDOException $e) {
        $error_message = "Error deleting certificate.";
        error_log("Delete error: " . $e->getMessage());
    }
}

// Get all certificates
try {
    $stmt = $pdo->query("SELECT * FROM certificates ORDER BY created_at DESC");
    $certificates = $stmt->fetchAll();
} catch(PDOException $e) {
    $certificates = [];
    $error_message = "Error fetching certificates.";
    error_log("Fetch error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | KESA Learning</title>
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

        .dashboard-header {
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

        .btn-danger {
            background: #dc3545;
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

        .certificates-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .table tr:hover {
            background: #f8f9fa;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.875rem;
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

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .cert-preview {
            width: 60px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #ddd;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-available {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .table-responsive {
                overflow-x: auto;
            }

            .actions {
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
            <div class="dashboard-header">
                <h1>Certificate Management</h1>
                <a href="add-certificate.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Certificate
                </a>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="certificates-table">
                <?php if (empty($certificates)): ?>
                    <div class="empty-state">
                        <i class="fas fa-certificate"></i>
                        <h3>No certificates found</h3>
                        <p>Start by adding your first certificate</p>
                        <a href="add-certificate.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Certificate
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Preview</th>
                                    <th>Certificate #</th>
                                    <th>Student Name</th>
                                    <th>Course</th>
                                    <th>Status</th>
                                    <th>Issue Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($certificates as $cert): ?>
                                    <tr>
                                        <td>
                                            <?php if ($cert['certificate_image'] && file_exists('../uploads/certificates/' . $cert['certificate_image'])): ?>
                                                <img src="../uploads/certificates/<?php echo htmlspecialchars($cert['certificate_image']); ?>" 
                                                     alt="Certificate Preview" class="cert-preview">
                                            <?php else: ?>
                                                <i class="fas fa-image" style="color: #ccc;"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($cert['certificate_number']); ?></td>
                                        <td><?php echo htmlspecialchars($cert['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($cert['course_name']); ?></td>
                                        <td>
                                            <?php if ($cert['certificate_image'] && file_exists('../uploads/certificates/' . $cert['certificate_image'])): ?>
                                                <span class="status-badge status-available">Available</span>
                                            <?php else: ?>
                                                <span class="status-badge status-pending">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($cert['issue_date'])); ?></td>
                                        <td>
                                            <div class="actions">
                                                <a href="../certificate/?cert=<?php echo urlencode($cert['certificate_number']); ?>" 
                                                   class="btn btn-secondary btn-sm" target="_blank">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="edit-certificate.php?id=<?php echo $cert['id']; ?>" 
                                                   class="btn btn-primary btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="?delete=<?php echo $cert['id']; ?>" 
                                                   class="btn btn-danger btn-sm"
                                                   onclick="return confirm('Are you sure you want to delete this certificate?')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
