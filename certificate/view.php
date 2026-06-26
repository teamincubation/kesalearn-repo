<?php
/**
 * KESA Learn - Certificate View (Preview/Display)
 * Shows certificate inline instead of downloading
 */
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$certCode = isset($_GET['code']) ? trim($_GET['code']) : '';

if (empty($certCode)) {
    header('Location: /certificate/verify');
    exit;
}

try {
    // Get certificate details
    $stmt = $db->prepare("
        SELECT c.*, u.name as user_name
        FROM certificates c 
        LEFT JOIN users u ON c.user_id = u.id 
        WHERE c.certificate_code = ? 
           OR c.certificate_number = ? 
           OR c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$certCode, $certCode, intval($certCode)]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$certificate) {
        setFlash('error', 'Certificate not found.');
        redirect('/certificate/verify?code=' . urlencode($certCode));
    }
    
    // Get the certificate file path
    $filePath = null;
    $certFile = $certificate['certificate_file'] ?? '';
    $certNumber = $certificate['certificate_number'] ?? $certificate['certificate_code'] ?? '';
    $certCodeDB = $certificate['certificate_code'] ?? '';
    
    // Define all possible search directories
    $searchDirs = [
        __DIR__ . '/../uploads/certificates/issued/',
        __DIR__ . '/../uploads/certificates/',
        __DIR__ . '/../uploads/',
    ];
    
    // Search for file
    if (!empty($certFile)) {
        foreach ($searchDirs as $dir) {
            $path = $dir . $certFile;
            if (file_exists($path)) {
                $filePath = $path;
                break;
            }
            $path = $dir . basename($certFile);
            if (file_exists($path)) {
                $filePath = $path;
                break;
            }
        }
    }
    
    // Fallback patterns
    if (!$filePath) {
        $identifiers = array_filter([$certCode, $certNumber, $certCodeDB]);
        $extensions = ['pdf', 'jpg', 'jpeg', 'png', 'PDF', 'JPG', 'JPEG', 'PNG'];
        
        foreach ($identifiers as $id) {
            $cleanId = preg_replace('/[^a-zA-Z0-9]/', '_', $id);
            $patterns = [$id, 'cert_' . $cleanId, 'certificate_' . $cleanId, $cleanId];
            
            foreach ($searchDirs as $dir) {
                if (!is_dir($dir)) continue;
                foreach ($patterns as $pattern) {
                    foreach ($extensions as $ext) {
                        $path = $dir . $pattern . '.' . $ext;
                        if (file_exists($path)) {
                            $filePath = $path;
                            break 4;
                        }
                    }
                }
            }
        }
    }
    
    // Glob search
    if (!$filePath) {
        $identifiers = array_filter([$certCode, $certNumber, $certCodeDB]);
        
        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) continue;
            
            foreach ($identifiers as $id) {
                $files = glob($dir . '*' . $id . '*', GLOB_NOSORT);
                if (!empty($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $filePath = $file;
                            break 3;
                        }
                    }
                }
            }
        }
    }
    
    if (!$filePath || !file_exists($filePath)) {
        setFlash('error', 'Certificate file not found on server.');
        redirect('/certificate/verify?code=' . urlencode($certCode));
    }
    
    // Get MIME type and serve inline
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Serve inline (display in browser)
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="certificate_' . $certCode . '.' . $ext . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: public, max-age=3600');
    
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    error_log('Certificate view error: ' . $e->getMessage());
    setFlash('error', 'An error occurred.');
    redirect('/certificate/verify?code=' . urlencode($certCode));
}
