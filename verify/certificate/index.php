<?php
require_once '../config/database.php';

$certificate = null;
$error = null;

if (isset($_GET['cert']) && !empty($_GET['cert'])) {
    $cert_number = trim($_GET['cert']);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE certificate_number = ?");
        $stmt->execute([$cert_number]);
        $certificate = $stmt->fetch();
        
        if (!$certificate) {
            $error = "Certificate not found. Please check the certificate number.";
        }
    } catch(PDOException $e) {
        $error = "Database error occurred. Please try again later.";
        error_log("Database error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $certificate ? 'Certificate - ' . htmlspecialchars($certificate['student_name']) : 'Certificate Verification'; ?> | KESA Learning</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <?php if ($certificate && $certificate['certificate_image']): ?>
    
    <meta property="og:title" content="Certificate of Completion - <?php echo htmlspecialchars($certificate['course_name']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($certificate['student_name']); ?> has successfully completed <?php echo htmlspecialchars($certificate['course_name']); ?> from KESA Learning.">
    <meta property="og:image" content="https://kesalearn.com/uploads/certificates/<?php echo htmlspecialchars($certificate['certificate_image']); ?>">
    <meta property="og:url" content="https://kesalearn.com/certificate/?cert=<?php echo htmlspecialchars($certificate['certificate_number']); ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Certificate of Completion - <?php echo htmlspecialchars($certificate['course_name']); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($certificate['student_name']); ?> has successfully completed <?php echo htmlspecialchars($certificate['course_name']); ?> from KESA Learning.">
    <meta name="twitter:image" content="https://kesalearn.com/uploads/certificates/<?php echo htmlspecialchars($certificate['certificate_image']); ?>">
    <?php endif; ?>
    
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
            color: #333;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }

        .nav-links a {
            color: #666;
            text-decoration: none;
            margin-left: 2rem;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .nav-links a:hover {
            color: #667eea;
        }

        .main-content {
            padding: 3rem 0;
        }

        .certificate-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            margin: 2rem auto;
            max-width: 1000px;
        }

        .certificate-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .certificate-header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .certificate-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .certificate-body {
            padding: 2rem;
        }

        .student-info {
            text-align: center;
            margin-bottom: 2rem;
        }

        .student-name {
            font-size: 2rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .certificate-number {
            font-size: 1rem;
            color: #666;
            background: #f8f9fa;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            display: inline-block;
        }

        .certificate-display-section {
            margin: 2rem 0;
            text-align: center;
        }

        .certificate-image-container {
            position: relative;
            display: inline-block;
            max-width: 100%;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background: #f8f9fa;
        }

        .certificate-image-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .certificate-image {
            width: 100%;
            max-width: 800px;
            height: auto;
            display: block;
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .certificate-image-container:hover .image-overlay {
            opacity: 1;
        }

        .image-overlay i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .image-overlay span {
            font-size: 1rem;
            font-weight: 500;
        }

        .course-info {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin: 2rem 0;
            text-align: center;
        }

        .course-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 1rem;
        }

        .issue-date {
            color: #666;
            font-size: 0.9rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
            padding: 2rem 0;
            border-top: 1px solid #e9ecef;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: #28a745;
            color: white;
        }

        .btn-linkedin {
            background: #0077b5;
            color: white;
        }

        .btn-view {
            background: #17a2b8;
            color: white;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .certificate-status {
            text-align: center;
            margin: 1rem 0;
            padding: 1rem;
            border-radius: 10px;
        }

        .certificate-available {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .certificate-unavailable {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            margin: 2rem 0;
        }

        .search-form {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
            margin: 2rem auto;
        }

        .search-form h2 {
            margin-bottom: 1rem;
            color: #333;
        }

        .search-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 1rem;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            margin: auto;
            display: block;
            width: 95%;
            max-width: 1200px;
            max-height: 95%;
            object-fit: contain;
            border-radius: 10px;
            animation: zoomIn 0.3s ease;
        }

        .close {
            position: absolute;
            top: 20px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
            transition: color 0.3s ease;
        }

        .close:hover {
            color: #bbb;
        }

        .modal-info {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            color: white;
            text-align: center;
            background: rgba(0,0,0,0.7);
            padding: 10px 20px;
            border-radius: 20px;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes zoomIn {
            from { transform: scale(0.8); }
            to { transform: scale(1); }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }

            .nav-links a {
                margin: 0 1rem;
            }

            .certificate-header h1 {
                font-size: 2rem;
            }

            .student-name {
                font-size: 1.5rem;
            }

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                max-width: 280px;
                justify-content: center;
            }

            .certificate-image {
                max-width: 100%;
            }

            .modal-content {
                width: 98%;
                max-height: 90%;
            }

            .close {
                top: 10px;
                right: 20px;
                font-size: 30px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">KESA Learning</div>
                <nav class="nav-links">
                    <a href="https://kesalearn.com">Home</a>
                    <a href="https://courses.pepplearning.com/learn/categories/KESA-Courses">Courses</a>
                    <a href="#">About</a>
                    </nav>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <?php if ($certificate): ?>
                <div class="certificate-card">
                    <div class="certificate-header">
                        <h1>Certificate of Completion</h1>
                        <p>This is to certify that</p>
                    </div>
                    
                    <div class="certificate-body">
                        <div class="student-info">
                            <div class="student-name"><?php echo htmlspecialchars($certificate['student_name']); ?></div>
                            <div class="certificate-number">
                                Certificate No: <?php echo htmlspecialchars($certificate['certificate_number']); ?>
                            </div>
                        </div>

                        <?php if (!empty($certificate['certificate_image']) && file_exists('../uploads/certificates/' . $certificate['certificate_image'])): ?>
                            <div class="certificate-status certificate-available">
                                <i class="fas fa-certificate"></i>
                                <strong>Certificate Available!</strong><br>
                                Your certificate has been uploaded and is ready to view and download.
                            </div>
                            
                            <div class="certificate-display-section">
                                <div class="certificate-image-container">
                                    <img src="../uploads/certificates/<?php echo htmlspecialchars($certificate['certificate_image']); ?>" 
                                         alt="Certificate of Completion - <?php echo htmlspecialchars($certificate['student_name']); ?>" 
                                         class="certificate-image" 
                                         onclick="openModal('<?php echo htmlspecialchars($certificate['certificate_image']); ?>')">
                                    <div class="image-overlay">
                                        <i class="fas fa-search-plus"></i>
                                        <span>Click to view full size</span>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="certificate-status certificate-unavailable">
                                <i class="fas fa-clock"></i>
                                <strong>Certificate Pending</strong><br>
                                Your certificate is being processed and will be uploaded by the admin soon.
                            </div>
                        <?php endif; ?>

                        <div class="course-info">
                            <div class="course-title"><?php echo htmlspecialchars($certificate['course_name']); ?></div>
                            <div class="issue-date">
                                Issued on: <?php echo date('F j, Y', strtotime($certificate['issue_date'])); ?>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <?php if (!empty($certificate['certificate_image']) && file_exists('../uploads/certificates/' . $certificate['certificate_image'])): ?>
                                <a href="../uploads/certificates/<?php echo htmlspecialchars($certificate['certificate_image']); ?>" 
                                   class="btn btn-view" target="_blank">
                                    <i class="fas fa-eye"></i> View Certificate
                                </a>
                                
                                <a href="../uploads/certificates/<?php echo htmlspecialchars($certificate['certificate_image']); ?>" 
                                   class="btn btn-primary" download="<?php echo htmlspecialchars($certificate['student_name'] . '_Certificate_' . $certificate['certificate_number']); ?>">
                                    <i class="fas fa-download"></i> Download Certificate
                                </a>
                                
                                <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode('https://kesalearn.com/certificate/?cert=' . $certificate['certificate_number']); ?>" 
                                   class="btn btn-linkedin" target="_blank">
                                    <i class="fab fa-linkedin"></i> Share on LinkedIn
                                </a>
                            <?php else: ?>
                                <button class="btn btn-view" disabled>
                                    <i class="fas fa-eye"></i> Certificate Not Available
                                </button>
                                
                                <button class="btn btn-primary" disabled>
                                    <i class="fas fa-download"></i> Certificate Not Available
                                </button>
                                
                                <button class="btn btn-linkedin" disabled>
                                    <i class="fab fa-linkedin"></i> Certificate Not Available
                                </button>
                            <?php endif; ?>
                            
                            <?php if (!empty($certificate['course_url'])): ?>
                                <a href="<?php echo htmlspecialchars($certificate['course_url']); ?>" 
                                   class="btn btn-secondary" target="_blank">
                                    <i class="fas fa-external-link-alt"></i> View Course
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($certificate['certificate_image']) && file_exists('../uploads/certificates/' . $certificate['certificate_image'])): ?>
                <div id="certificateModal" class="modal">
                    <span class="close" onclick="closeModal()">&times;</span>
                    <img class="modal-content" id="modalImage">
                    <div class="modal-info">
                        <i class="fas fa-info-circle"></i> Press ESC to close or click outside the image
                    </div>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="search-form">
                    <h2>Verify Certificate</h2>
                    <p>Enter your certificate number to verify and view your certificate</p>
                    
                    <?php if ($error): ?>
                        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="GET">
                        <input type="text" name="cert" class="search-input" 
                               placeholder="Enter Certificate Number" 
                               value="<?php echo isset($_GET['cert']) ? htmlspecialchars($_GET['cert']) : ''; ?>" required>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Verify Certificate
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function openModal(imageSrc) {
            const modal = document.getElementById('certificateModal');
            const modalImg = document.getElementById('modalImage');
            if (modal && modalImg) {
                modal.style.display = 'block';
                modalImg.src = '../uploads/certificates/' + imageSrc;
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal() {
            const modal = document.getElementById('certificateModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('certificateModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>
