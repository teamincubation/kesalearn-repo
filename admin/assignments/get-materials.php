<?php
/**
 * AJAX endpoint to fetch assignment materials
 */
require_once __DIR__ . '/../../includes/admin_check.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode([]);
    exit;
}

$db = getDB();
try {
    $stmt = $db->prepare("SELECT id, file_name, file_path, file_size FROM assignment_materials WHERE assignment_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    echo json_encode([]);
}
