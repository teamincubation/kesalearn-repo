<?php
/**
 * KESA Learn - Export Assignment Submissions to Excel
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();

// Get filters
$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$statusFilter = $_GET['status'] ?? 'all';

// Build query
$sql = "SELECT s.*, a.title as assignment_title, a.max_score, a.submission_type, 
        e.title as event_title, u.name as user_name, u.email as user_email, u.phone as user_phone
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        JOIN events e ON a.event_id = e.id
        JOIN users u ON s.user_id = u.id
        WHERE 1=1";

$params = [];
if ($assignmentId > 0) {
    $sql .= " AND s.assignment_id = ?";
    $params[] = $assignmentId;
}
if ($statusFilter !== 'all') {
    $sql .= " AND s.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY s.submitted_at DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { 
    $submissions = []; 
}

// Set headers for Excel download
$filename = 'assignment_submissions_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write headers
fputcsv($output, [
    'Submission ID',
    'User Name',
    'User Email',
    'User Phone',
    'Assignment',
    'Course',
    'Submission Type',
    'Status',
    'Score',
    'Max Score',
    'Feedback',
    'Submitted At',
    'Reviewed At',
    'Text Content / URL',
    'File Name'
]);

// Write data rows
foreach ($submissions as $sub) {
    fputcsv($output, [
        $sub['id'],
        $sub['user_name'],
        $sub['user_email'],
        $sub['user_phone'] ?? '',
        $sub['assignment_title'],
        $sub['event_title'],
        ucfirst($sub['submission_type']),
        ucfirst($sub['status']),
        $sub['score'] ?? '',
        $sub['max_score'],
        $sub['feedback'] ?? '',
        $sub['submitted_at'],
        $sub['reviewed_at'] ?? '',
        $sub['text_content'] ?? '',
        $sub['file_name'] ?? ''
    ]);
}

fclose($output);
exit;
