<?php
/**
 * Diagnostic Script - Check Event Slugs in Database
 */

require_once __DIR__ . '/../../includes/functions.php';

// Check admin
session_start();
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die('Access denied.');
}

$db = getDB();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Check Event Slugs</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f4f4f4; }
        .null { color: red; font-weight: bold; }
        .empty { color: orange; }
        .ok { color: green; }
    </style>
</head>
<body>

<h2>Event Slug Diagnostic Report</h2>

<?php
try {
    // Check total events
    $totalEvents = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
    echo "<p><strong>Total Events:</strong> " . $totalEvents . "</p>";
    
    // Check events with NULL slugs
    $nullSlugs = $db->query("SELECT COUNT(*) FROM events WHERE slug IS NULL")->fetchColumn();
    echo "<p><strong>Events with NULL slugs:</strong> <span class='null'>" . $nullSlugs . "</span></p>";
    
    // Check events with empty slugs
    $emptySlugs = $db->query("SELECT COUNT(*) FROM events WHERE slug = ''")->fetchColumn();
    echo "<p><strong>Events with EMPTY slugs:</strong> <span class='empty'>" . $emptySlugs . "</span></p>";
    
    // Check events with valid slugs
    $validSlugs = $db->query("SELECT COUNT(*) FROM events WHERE slug IS NOT NULL AND slug != ''")->fetchColumn();
    echo "<p><strong>Events with VALID slugs:</strong> <span class='ok'>" . $validSlugs . "</span></p>";
    
    echo "<hr>";
    
    // Show sample of events with their slugs
    echo "<h3>Sample Events (First 20)</h3>";
    $events = $db->query("SELECT id, title, slug, status FROM events LIMIT 20")->fetchAll();
    
    echo "<table>";
    echo "<tr><th>ID</th><th>Title</th><th>Slug</th><th>Status</th></tr>";
    
    foreach ($events as $event) {
        $slugClass = is_null($event['slug']) ? 'null' : (empty($event['slug']) ? 'empty' : 'ok');
        $slugDisplay = is_null($event['slug']) ? '[NULL]' : (empty($event['slug']) ? '[EMPTY]' : $event['slug']);
        echo "<tr>";
        echo "<td>" . $event['id'] . "</td>";
        echo "<td>" . sanitize(substr($event['title'], 0, 50)) . "</td>";
        echo "<td class='" . $slugClass . "'>" . sanitize($slugDisplay) . "</td>";
        echo "<td>" . $event['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Show actionable items
    echo "<hr>";
    echo "<h3>Actions</h3>";
    
    if ($nullSlugs > 0 || $emptySlugs > 0) {
        echo "<p style='color: red;'><strong>⚠ Warning:</strong> Found " . ($nullSlugs + $emptySlugs) . " events without valid slugs. Please regenerate them.</p>";
        echo "<p><a href='/admin/tools/fix-event-slugs.php' style='padding: 10px 20px; background: #4facfe; color: white; text-decoration: none; border-radius: 5px;'>Run Slug Fix Script</a></p>";
    } else {
        echo "<p style='color: green;'><strong>✓ All events have valid slugs!</strong></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . sanitize($e->getMessage()) . "</p>";
}
?>

</body>
</html>
