<?php
/**
 * KESA Learn - Fix Draft Events Slugs
 * This page fixes all draft events by assigning unique slugs
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$updated = 0;
$errors = [];

try {
    // First, update all events with empty or NULL slug to have event_ID format
    $stmt = $db->prepare("SELECT id FROM events WHERE slug IS NULL OR slug = '' ORDER BY id");
    $stmt->execute();
    $events = $stmt->fetchAll();
    
    foreach ($events as $event) {
        $eventId = $event['id'];
        $uniqueSlug = 'event_' . $eventId;
        
        $updateStmt = $db->prepare("UPDATE events SET slug = ? WHERE id = ?");
        if ($updateStmt->execute([$uniqueSlug, $eventId])) {
            $updated++;
        }
    }
    
    // Show results
    echo "
    <div style='padding: 40px; text-align: center;'>
        <h1>✓ Draft Events Fixed!</h1>
        <p style='font-size: 18px; margin: 20px 0;'>Updated <strong>$updated events</strong> with unique slugs.</p>
        <p style='color: #666;'>All events now have proper slug formats (event_ID).</p>
        <p style='color: #666;'>The UNIQUE constraint on the slug column should now allow these events to be saved.</p>
        <br/>
        <a href='/admin/events/' style='display: inline-block; padding: 12px 24px; background: #0066cc; color: white; text-decoration: none; border-radius: 4px;'>Go to Events List</a>
    </div>
    ";
    
} catch (Exception $e) {
    echo "
    <div style='padding: 40px; background: #fee; border: 1px solid #fcc; border-radius: 4px;'>
        <h2 style='color: #c00;'>Error: " . htmlspecialchars($e->getMessage()) . "</h2>
    </div>
    ";
}
?>
