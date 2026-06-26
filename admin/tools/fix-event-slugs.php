<?php
/**
 * Quick Slug Fix - Run this ONCE to regenerate all event slugs
 * This ensures all events have unique slugs based on their titles
 */

require_once __DIR__ . '/../../includes/admin_check.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Event Slugs</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f4f4f4; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>

<?php
try {
    $db = getDB();
    
    // Step 1: Check if slug column exists
    $columns = $db->query("DESCRIBE events")->fetchAll();
    $slugExists = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'slug') {
            $slugExists = true;
            break;
        }
    }
    
    if (!$slugExists) {
        die('<p class="error"><strong>Error:</strong> slug column does not exist in events table</p>');
    }
    
    // Step 2: Get all events
    $allEvents = $db->query("SELECT id, title, slug FROM events ORDER BY id")->fetchAll();
    
    echo "<h2>Regenerating Event Slugs</h2>";
    echo "<p>Processing " . count($allEvents) . " events...</p>";
    echo "<hr>";
    
    $updateCount = 0;
    
    // Step 3: Regenerate slugs for all events
    foreach ($allEvents as $event) {
        // Generate slug from title
        $newSlug = generateSlug($event['title']);
        
        // Make it unique if needed
        $counter = 1;
        $originalSlug = $newSlug;
        while (true) {
            // Check if this slug already exists for a different event
            $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM events WHERE slug = ? AND id != ?");
            $checkStmt->execute([$newSlug, $event['id']]);
            $result = $checkStmt->fetch();
            $exists = (int)$result['count'];
            
            if ($exists == 0) break;
            $newSlug = $originalSlug . '-' . $counter++;
        }
        
        // Update the event
        $updateStmt = $db->prepare("UPDATE events SET slug = ? WHERE id = ?");
        $updateStmt->execute([$newSlug, $event['id']]);
        $updateCount++;
        
        echo "✓ Event #" . $event['id'] . ": " . sanitize($event['title']) . " → <strong>" . sanitize($newSlug) . "</strong><br>";
    }
    
    echo "<hr>";
    echo "<p class='success'><strong>✓ All " . $updateCount . " slugs regenerated successfully!</strong></p>";
    echo "<p><a href='/events/'>Go to Events</a> | <a href='/admin/events/'>Go to Admin Events</a></p>";
    
} catch (Exception $e) {
    echo "<p class='error'><strong>Error:</strong> " . sanitize($e->getMessage()) . "</p>";
    error_log("Slug regeneration error: " . $e->getMessage());
}
?>

</body>
</html>
