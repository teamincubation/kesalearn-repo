<?php
/**
 * KESA Learn - Certificate Preview Handler
 * Serves certificate file for preview (inline display)
 */
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

// Support both 'code' and 'id' parameters
$certCode = isset($_GET['code']) ? trim($_GET['code']) : '';
$certId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (empty($certCode) && !$certId) {
    http_response_code(400);
    echo 'Invalid request. Certificate code or ID required.';
    exit;
}

try {
    // Get certificate details
    if ($certId) {
        $stmt = $db->prepare("SELECT * FROM certificates WHERE id = ? LIMIT 1");
        $stmt->execute([$certId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM certificates WHERE certificate_code = ? OR certificate_number = ? LIMIT 1");
        $stmt->execute([$certCode, $certCode]);
    }
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$certificate) {
        http_response_code(404);
        echo 'Certificate not found.';
        exit;
    }
    
    // Get the certificate file
    $filePath = null;
    $certFile = $certificate['certificate_file'] ?? '';
    $certCodeValue = $certificate['certificate_code'] ?? $certificate['certificate_number'] ?? '';
    
    // Try certificate_file column first
    if (!empty($certFile)) {
        $possiblePaths = [
            __DIR__ . '/../uploads/certificates/issued/' . $certFile,
            __DIR__ . '/../uploads/certificates/' . $certFile,
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $filePath = $path;
                break;
            }
        }
    }
    
    // Fallback: Try code-based patterns
    if (!$filePath && !empty($certCodeValue)) {
        $patterns = [
            $certCodeValue . '.pdf',
            $certCodeValue . '.jpg',
            $certCodeValue . '.png',
            'cert_' . preg_replace('/[^a-zA-Z0-9]/', '_', $certCodeValue) . '.pdf',
            'cert_' . preg_replace('/[^a-zA-Z0-9]/', '_', $certCodeValue) . '.jpg',
            'cert_' . preg_replace('/[^a-zA-Z0-9]/', '_', $certCodeValue) . '.png',
        ];
        
        $dirs = [
            __DIR__ . '/../uploads/certificates/issued/',
            __DIR__ . '/../uploads/certificates/',
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach ($patterns as $pattern) {
                $path = $dir . $pattern;
                if (file_exists($path)) {
                    $filePath = $path;
                    break 2;
                }
            }
        }
    }
    
    // Fallback: Glob search
    if (!$filePath && !empty($certCodeValue)) {
        $issuedDir = __DIR__ . '/../uploads/certificates/issued/';
        if (is_dir($issuedDir)) {
            $searchPattern = '*' . preg_replace('/[^a-zA-Z0-9]/', '*', $certCodeValue) . '*';
            $files = glob($issuedDir . $searchPattern);
            if (!empty($files)) {
                $filePath = $files[0];
            }
        }
    }
    
    if (!$filePath || !file_exists($filePath)) {
        http_response_code(404);
        echo 'Certificate file not found on server.';
        exit;
    }
    
    // Get MIME type
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    // Set headers for inline display (preview)
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="certificate.' . $ext . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: public, max-age=86400'); // Cache for 1 day
    header('Pragma: public');
    
    // Clear output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo 'An error occurred while loading the certificate.';
    exit;
}
