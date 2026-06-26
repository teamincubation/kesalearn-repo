<?php
/**
 * KESA Learn - Certificate Download Handler
 * Downloads certificate file for given certificate code
 */

// Include functions first (it handles session)
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tracking.php';

// Clear any output buffering
while (ob_get_level()) {
    ob_end_clean();
}

$db = getDB();
$certCode = isset($_GET['code']) ? trim($_GET['code']) : '';

// Debug mode - add ?debug=1 to URL to see debug info
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1' && isAdmin();

if (empty($certCode)) {
    header('Location: /certificate/verify');
    exit;
}

try {
    // Get certificate details - search by certificate_code, certificate_number, or id
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
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Certificate not found in database.'];
        header('Location: /certificate/verify?code=' . urlencode($certCode));
        exit;
    }
    
    // Get the certificate file path
    $filePath = null;
    $fileName = null;
    $certFile = $certificate['certificate_file'] ?? '';
    $certNumber = $certificate['certificate_number'] ?? $certificate['certificate_code'] ?? '';
    $certCodeDB = $certificate['certificate_code'] ?? '';
    
    // Define all possible search directories
    $searchDirs = [
        __DIR__ . '/../uploads/certificates/issued/',
        __DIR__ . '/../uploads/certificates/',
        __DIR__ . '/../uploads/',
    ];
    
    // Primary: Check the certificate_file column (exact filename stored in DB)
    if (!empty($certFile)) {
        foreach ($searchDirs as $dir) {
            $path = $dir . $certFile;
            if (file_exists($path)) {
                $filePath = $path;
                $fileName = $certFile;
                break;
            }
            // Also try with subdirectories
            $path = $dir . basename($certFile);
            if (file_exists($path)) {
                $filePath = $path;
                $fileName = basename($certFile);
                break;
            }
        }
    }
    
    // Fallback 1: Try various naming patterns with certificate_number and certificate_code
    if (!$filePath) {
        $identifiers = array_filter([$certCode, $certNumber, $certCodeDB]);
        $extensions = ['pdf', 'jpg', 'jpeg', 'png', 'PDF', 'JPG', 'JPEG', 'PNG'];
        
        foreach ($identifiers as $id) {
            $cleanId = preg_replace('/[^a-zA-Z0-9]/', '_', $id);
            $patterns = [
                $id,
                'cert_' . $cleanId,
                'certificate_' . $cleanId,
                $cleanId,
            ];
            
            foreach ($searchDirs as $dir) {
                if (!is_dir($dir)) continue;
                foreach ($patterns as $pattern) {
                    foreach ($extensions as $ext) {
                        $path = $dir . $pattern . '.' . $ext;
                        if (file_exists($path)) {
                            $filePath = $path;
                            $fileName = $pattern . '.' . $ext;
                            break 4;
                        }
                    }
                }
            }
        }
    }
    
    // Fallback 2: Glob search for any matching file
    if (!$filePath) {
        $identifiers = array_filter([$certCode, $certNumber, $certCodeDB]);
        
        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) continue;
            
            foreach ($identifiers as $id) {
                $searchPatterns = [
                    '*' . $id . '*',
                    '*' . preg_replace('/[^a-zA-Z0-9]/', '*', $id) . '*',
                    'cert*' . $id . '*',
                ];
                
                foreach ($searchPatterns as $pattern) {
                    $files = glob($dir . $pattern, GLOB_NOSORT);
                    if (!empty($files)) {
                        // Get the first file that's actually a file (not directory)
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                $filePath = $file;
                                $fileName = basename($file);
                                break 4;
                            }
                        }
                    }
                }
            }
        }
    }
    
    if (!$filePath || !file_exists($filePath)) {
        // Debug mode - show detailed info for admins
        if ($debugMode) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<h2>Certificate Download Debug</h2>';
            echo '<h3>Certificate Data from DB:</h3>';
            echo '<pre>' . print_r($certificate, true) . '</pre>';
            echo '<h3>Search Parameters:</h3>';
            echo '<ul>';
            echo '<li>certificate_file column: <strong>' . ($certFile ?: 'EMPTY') . '</strong></li>';
            echo '<li>certificate_number: <strong>' . ($certNumber ?: 'EMPTY') . '</strong></li>';
            echo '<li>certificate_code: <strong>' . ($certCodeDB ?: 'EMPTY') . '</strong></li>';
            echo '</ul>';
            echo '<h3>Directories Searched:</h3>';
            echo '<ul>';
            foreach ($searchDirs as $dir) {
                $exists = is_dir($dir);
                $files = $exists ? scandir($dir) : [];
                echo '<li>' . $dir . ' - <strong>' . ($exists ? 'EXISTS' : 'NOT FOUND') . '</strong>';
                if ($exists && count($files) > 2) {
                    echo '<br>Files: ' . implode(', ', array_slice(array_diff($files, ['.', '..']), 0, 20));
                }
                echo '</li>';
            }
            echo '</ul>';
            exit;
        }
        
        // Store debug info for admin
        $debugInfo = "Certificate file not found. DB file: " . ($certFile ?: 'empty') . 
                     ", Code: $certCode, Number: $certNumber";
        error_log($debugInfo);
        
        // Show more helpful error message
        $errorMsg = 'Certificate file not found on server. ';
        if (!empty($certFile)) {
            $errorMsg .= "DB shows: $certFile. ";
        } else {
            $errorMsg .= "No file path in database. ";
        }
        $errorMsg .= 'Contact support with code: ' . $certCode;
        
        $_SESSION['flash'] = ['type' => 'error', 'message' => $errorMsg];
        header('Location: /certificate/verify?code=' . urlencode($certCode));
        exit;
    }
    
    // Update download count
    try {
        $db->prepare("UPDATE certificates SET download_count = COALESCE(download_count, 0) + 1 WHERE id = ?")->execute([$certificate['id']]);
    } catch (Exception $e) {
        // Non-critical, continue with download
    }
    
    // Get MIME type
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    // Generate download filename
    $recipientName = $certificate['recipient_name'] ?? $certificate['user_name'] ?? 'Certificate';
    $safeName = preg_replace('/[^a-zA-Z0-9\s]/', '', $recipientName);
    $safeName = str_replace(' ', '_', trim($safeName));
    if (empty($safeName)) $safeName = 'Certificate';
    $downloadName = 'KESA_Certificate_' . $safeName . '_' . $certCode . '.' . $ext;
    
    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Get file size
    $fileSize = filesize($filePath);
    
    // Log certificate download
    $userId = $_SESSION['user_id'] ?? null;
    $eventId = $certificate['event_id'] ?? null;
    logCertificateDownload($certificate['certificate_code'], $downloadName, $eventId, $userId);
    
    // Send headers for file download
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $fileSize);
    
    // Flush headers
    flush();
    
    // Read and output file
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Certificate download error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'An error occurred while downloading the certificate. Please try again.'];
    header('Location: /certificate/verify?code=' . urlencode($certCode));
    exit;
}
