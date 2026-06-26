<?php
/**
 * Bulk Certificate Upload Helper Functions
 * Uses MySQLi for database operations
 */

// Parse CSV file
function parseCSVFile($filePath) {
    $data = array();
    $errors = array();
    $row_num = 0;
    
    if (($handle = fopen($filePath, 'r')) !== FALSE) {
        $headers = null;
        
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row_num++;
            
            // First row is headers
            if ($row_num === 1) {
                $headers = array_map('trim', $row);
                
                // Validate required columns
                $required = array('Certificate Number', 'Student Name', 'Issue Date', 'Course URL');
                foreach ($required as $col) {
                    if (!in_array($col, $headers)) {
                        $errors[] = "Missing required column: $col";
                    }
                }
                continue;
            }
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Map row to associative array
            $record = array();
            foreach ($headers as $index => $header) {
                $record[trim($header)] = isset($row[$index]) ? trim($row[$index]) : '';
            }
            
            // Validate data
            $validation = validateCertificateRecord($record, $row_num);
            if ($validation['valid']) {
                $data[] = $record;
            } else {
                $errors = array_merge($errors, $validation['errors']);
            }
        }
        fclose($handle);
    }
    
    return array(
        'data' => $data,
        'errors' => $errors,
        'count' => count($data)
    );
}

// Parse XLS file (using simple method for .xls)
function parseXLSFile($filePath) {
    // Convert XLS to CSV first or use basic parsing
    // For simplicity, treat as CSV
    return parseCSVFile($filePath);
}

// Validate certificate record
function validateCertificateRecord($record, $row_num) {
    $errors = array();
    
    if (empty($record['Certificate Number'])) {
        $errors[] = "Row $row_num: Certificate Number is required";
    }
    
    if (empty($record['Student Name'])) {
        $errors[] = "Row $row_num: Student Name is required";
    }
    
    if (empty($record['Issue Date'])) {
        $errors[] = "Row $row_num: Issue Date is required";
    } else if (!isValidDate($record['Issue Date'])) {
        $errors[] = "Row $row_num: Invalid Issue Date format (use YYYY-MM-DD)";
    }
    
    if (empty($record['Course URL'])) {
        $errors[] = "Row $row_num: Course URL is required";
    } else if (!filter_var($record['Course URL'], FILTER_VALIDATE_URL)) {
        $errors[] = "Row $row_num: Invalid Course URL";
    }
    
    return array(
        'valid' => count($errors) === 0,
        'errors' => $errors
    );
}

// Validate date format
function isValidDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Extract course name from URL
function extractCourseName($url) {
    $parts = parse_url($url);
    $path = $parts['path'] ?? '';
    $segments = array_filter(explode('/', $path));
    return !empty($segments) ? ucfirst(end($segments)) : 'Unknown Course';
}

// Check for duplicate certificates
function checkDuplicates($conn, $certificates) {
    $duplicates = array();
    $cert_numbers = array_column($certificates, 'Certificate Number');
    
    if (empty($cert_numbers)) {
        return $duplicates;
    }
    
    $placeholders = implode(',', array_fill(0, count($cert_numbers), '?'));
    $query = "SELECT certificate_number FROM certificates WHERE certificate_number IN ($placeholders)";
    
    // Support both MySQLi and PDO
    if (is_object($conn) && get_class($conn) === 'mysqli') {
        // MySQLi
        $stmt = $conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($cert_numbers)), ...$cert_numbers);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $duplicates[] = $row['certificate_number'];
        }
    } else {
        // PDO
        $stmt = $conn->prepare($query);
        $stmt->execute($cert_numbers);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $duplicates[] = $row['certificate_number'];
        }
    }
    
    return $duplicates;
}

// Save bulk certificates to database
function saveBulkCertificates($conn, $certificates) {
    $saved = 0;
    $failed = 0;
    $errors = array();
    
    $query = "INSERT INTO certificates 
              (certificate_number, student_name, issue_date, course_url, course_name) 
              VALUES (?, ?, ?, ?, ?)";
    
    // Support both MySQLi and PDO
    $is_mysqli = is_object($conn) && get_class($conn) === 'mysqli';
    
    foreach ($certificates as $cert) {
        $course_name = extractCourseName($cert['Course URL']);
        
        try {
            if ($is_mysqli) {
                // MySQLi
                $stmt = $conn->prepare($query);
                $stmt->bind_param(
                    'sssss',
                    $cert['Certificate Number'],
                    $cert['Student Name'],
                    $cert['Issue Date'],
                    $cert['Course URL'],
                    $course_name
                );
                
                if ($stmt->execute()) {
                    $saved++;
                } else {
                    $failed++;
                    $errors[] = "Failed to save certificate " . $cert['Certificate Number'] . ": " . $stmt->error;
                }
            } else {
                // PDO
                $stmt = $conn->prepare($query);
                if ($stmt->execute([
                    $cert['Certificate Number'],
                    $cert['Student Name'],
                    $cert['Issue Date'],
                    $cert['Course URL'],
                    $course_name
                ])) {
                    $saved++;
                } else {
                    $failed++;
                    $errors[] = "Failed to save certificate " . $cert['Certificate Number'];
                }
            }
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Error: " . $e->getMessage();
        }
    }
    
    return array(
        'saved' => $saved,
        'failed' => $failed,
        'errors' => $errors
    );
}

// Get pending certificates
function getPendingCertificates($conn) {
    $is_mysqli = is_object($conn) && get_class($conn) === 'mysqli';
    $certificates = array();
    
    // Select certificates where certificate_image is NULL or empty
    $query = "SELECT * FROM certificates WHERE certificate_image IS NULL OR certificate_image = '' ORDER BY created_at DESC";
    
    try {
        if ($is_mysqli) {
            $result = $conn->query($query);
            while ($row = $result->fetch_assoc()) {
                $certificates[] = $row;
            }
        } else {
            $stmt = $conn->query($query);
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Error fetching pending certificates: " . $e->getMessage());
    }
    
    return $certificates;
}

// Upload certificate file
function uploadCertificateFile($conn, $certificate_id, $file) {
    $upload_dir = '../uploads/certificates/';
    
    // Create directory if not exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Validate file
    $allowed = array('pdf', 'jpg', 'jpeg', 'png', 'gif');
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, $allowed)) {
        return array('success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowed));
    }
    
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        return array('success' => false, 'error' => 'File size exceeds 5MB limit');
    }
    
    // Get certificate to retrieve certificate_number for filename
    $is_mysqli = is_object($conn) && get_class($conn) === 'mysqli';
    
    try {
        if ($is_mysqli) {
            $stmt = $conn->prepare("SELECT * FROM certificates WHERE id = ?");
            $stmt->bind_param('i', $certificate_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $cert = $result->fetch_assoc();
        } else {
            $stmt = $conn->prepare("SELECT * FROM certificates WHERE id = ?");
            $stmt->execute([$certificate_id]);
            $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$cert) {
            return array('success' => false, 'error' => 'Certificate not found');
        }
        
        // Delete old file if exists
        if ($cert['certificate_image'] && file_exists($upload_dir . $cert['certificate_image'])) {
            unlink($upload_dir . $cert['certificate_image']);
        }
        
        // Generate unique filename
        $filename = $cert['certificate_number'] . '_' . time() . '.' . $file_ext;
        $filepath = $upload_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Update database - use certificate_image field
            $update_query = "UPDATE certificates SET certificate_image = ? WHERE id = ?";
            
            if ($is_mysqli) {
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param('si', $filename, $certificate_id);
                $success = $stmt->execute();
            } else {
                $stmt = $conn->prepare($update_query);
                $success = $stmt->execute([$filename, $certificate_id]);
            }
            
            if ($success) {
                return array('success' => true, 'filename' => $filename, 'path' => $filepath);
            } else {
                unlink($filepath);
                return array('success' => false, 'error' => 'Database update failed');
            }
        } else {
            return array('success' => false, 'error' => 'Failed to upload file');
        }
    } catch (Exception $e) {
        return array('success' => false, 'error' => 'Error: ' . $e->getMessage());
    }
}

// Get dashboard statistics
function getDashboardStats($conn) {
    $is_mysqli = is_object($conn) && get_class($conn) === 'mysqli';
    
    try {
        if ($is_mysqli) {
            $total = $conn->query("SELECT COUNT(*) as count FROM certificates")->fetch_assoc()['count'];
            $pending = $conn->query("SELECT COUNT(*) as count FROM certificates WHERE certificate_image IS NULL OR certificate_image = ''")->fetch_assoc()['count'];
            $uploaded = $conn->query("SELECT COUNT(*) as count FROM certificates WHERE certificate_image IS NOT NULL AND certificate_image != ''")->fetch_assoc()['count'];
        } else {
            $stmt = $conn->query("SELECT COUNT(*) as count FROM certificates");
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $stmt = $conn->query("SELECT COUNT(*) as count FROM certificates WHERE certificate_image IS NULL OR certificate_image = ''");
            $pending = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $stmt = $conn->query("SELECT COUNT(*) as count FROM certificates WHERE certificate_image IS NOT NULL AND certificate_image != ''");
            $uploaded = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }
    } catch (Exception $e) {
        $total = $pending = $uploaded = 0;
        error_log("Error fetching dashboard stats: " . $e->getMessage());
    }
    
    return array(
        'total' => $total,
        'pending' => $pending,
        'uploaded' => $uploaded
    );
}
?>
