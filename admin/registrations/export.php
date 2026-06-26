<?php
/**
 * KESA Learn - Admin: Export Registrations as CSV
 * Respects all active filters from the registrations list
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();

// Get all filter parameters from GET/POST (matches the filters from index.php)
$search = sanitize($_REQUEST['q'] ?? '');
$paymentStatus = $_REQUEST['payment_status'] ?? '';
$eventId = intval($_REQUEST['event_id'] ?? 0);
$verificationStatus = $_REQUEST['verification_status'] ?? '';

// Build WHERE clause based on active filters
$whereConditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(u.name LIKE ? OR u.email LIKE ? OR r.registration_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($paymentStatus)) {
    $whereConditions[] = "r.payment_status = ?";
    $params[] = $paymentStatus;
}

if ($eventId > 0) {
    $whereConditions[] = "r.event_id = ?";
    $params[] = $eventId;
}

if (!empty($verificationStatus)) {
    $whereConditions[] = "r.verification_status = ?";
    $params[] = $verificationStatus;
}

$whereClause = implode(' AND ', $whereConditions);

// Build query with all filters
$query = "
    SELECT 
        r.*,
        u.name,
        u.email,
        u.phone,
        u.college,
        u.city,
        e.title as event_title,
        e.start_date
    FROM registrations r
    JOIN users u ON r.user_id = u.id
    JOIN events e ON r.event_id = e.id
    WHERE $whereClause
    ORDER BY r.registered_at DESC
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll();

if (empty($registrations)) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'No registrations found with selected filters']);
    exit;
}

// Collect all unique form data field names
$allFormFields = [];
foreach ($registrations as $r) {
    if (!empty($r['form_data'])) {
        $formData = json_decode($r['form_data'], true);
        if (is_array($formData)) {
            $allFormFields = array_unique(array_merge($allFormFields, array_keys($formData)));
        }
    }
}
sort($allFormFields);

// Set headers for CSV download
$filename = 'registrations_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

// Write CSV headers
$headers = [
    'Registration ID',
    'Reg. Number',
    'Name',
    'Email',
    'Phone',
    'College',
    'City',
    'Event',
    'Event Date',
    'Amount',
    'Payment Method',
    'Payment Status',
    'Verification Status',
    'Registered At'
];
$headers = array_merge($headers, $allFormFields);
fputcsv($output, $headers);

// Write data rows
foreach ($registrations as $r) {
    $formData = [];
    if (!empty($r['form_data'])) {
        $parsed = json_decode($r['form_data'], true);
        if (is_array($parsed)) {
            foreach ($allFormFields as $field) {
                $value = isset($parsed[$field]) ? $parsed[$field] : '';
                // Handle array values
                if (is_array($value)) {
                    $value = implode('; ', $value);
                }
                $formData[] = $value;
            }
        }
    } else {
        $formData = array_fill(0, count($allFormFields), '');
    }
    
    $row = [
        $r['id'],
        $r['registration_number'] ?? '',
        $r['name'],
        $r['email'],
        $r['phone'] ?? '',
        $r['college'] ?? '',
        $r['city'] ?? '',
        $r['event_title'],
        $r['start_date'] ?? '',
        $r['amount'] ?? '',
        $r['payment_method'] ?? '',
        ucfirst(str_replace('_', ' ', $r['payment_status'])),
        ucfirst(str_replace('_', ' ', $r['verification_status'])),
        $r['registered_at']
    ];
    $row = array_merge($row, $formData);
    fputcsv($output, $row);
}

fclose($output);

// Log the export action with filter details
$filterInfo = "search='$search', payment='$paymentStatus', event_id=$eventId, verification='$verificationStatus'";
logActivity('registrations_exported', "Exported " . count($registrations) . " registrations as CSV with filters: $filterInfo");

exit;

