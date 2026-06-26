<?php
/**
 * KESA Learn - Slug Migration/Regeneration
 * This script regenerates slugs for all events that don't have them
 * Run this from the admin panel or directly via browser
 */

require_once __DIR__ . '/../../includes/functions.php';

// Security check - verify user is admin
session_start();
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die('Unauthorized access');
}

$db = getDB();
$regenerated = 0;
$skipped = 0;

// Get all events without slugs
$stmt = $db->query("SELECT id, title FROM events WHERE slug IS NULL OR slug = ''");
$events = $stmt->fetchAll();

foreach ($events as $event) {
    try {
        // Generate unique slug from title
        $slug = uniqueSlug($event['title']);
        
        // Update event with new slug
        $updateStmt = $db->prepare("UPDATE events SET slug = ? WHERE id = ?");
        $updateStmt->execute([$slug, $event['id']]);
        
        echo "✓ Event #{$event['id']} ({$event['title']}) → {$slug}<br>";
        $regenerated++;
    } catch (Exception $e) {
        echo "✗ Error updating event #{$event['id']}: " . $e->getMessage() . "<br>";
        $skipped++;
    }
}

echo "<hr>";
echo "<strong>Migration Complete:</strong><br>";
echo "Regenerated: <strong>$regenerated</strong> slugs<br>";
echo "Skipped/Failed: <strong>$skipped</strong><br>";

// Also fix any duplicate slugs
echo "<hr>";
echo "<h3>Fixing duplicate slugs...</h3>";

$dupStmt = $db->query("
    SELECT slug, COUNT(*) as cnt FROM events 
    WHERE slug IS NOT NULL AND slug != '' 
    GROUP BY slug HAVING COUNT(*) > 1
");
$duplicates = $dupStmt->fetchAll();

$fixed = 0;
foreach ($duplicates as $dup) {
    // Get all events with this duplicate slug
    $allStmt = $db->prepare("SELECT id, title FROM events WHERE slug = ? ORDER BY id");
    $allStmt->execute([$dup['slug']]);
    $allEvents = $allStmt->fetchAll();
    
    // Keep first one, update others
    for ($i = 1; $i < count($allEvents); $i++) {
        $counter = 1;
        $newSlug = $allEvents[$i]['slug'] . '-' . $counter;
        
        // Make sure new slug is unique
        while (true) {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM events WHERE slug = ?");
            $checkStmt->execute([$newSlug]);
            if ($checkStmt->fetchColumn() == 0) break;
            $counter++;
            $newSlug = $allEvents[$i]['slug'] . '-' . $counter;
        }
        
        $fixStmt = $db->prepare("UPDATE events SET slug = ? WHERE id = ?");
        $fixStmt->execute([$newSlug, $allEvents[$i]['id']]);
        
        echo "✓ Fixed duplicate: Event #{$allEvents[$i]['id']} → {$newSlug}<br>";
        $fixed++;
    }
}

if ($fixed == 0) {
    echo "No duplicate slugs found.<br>";
}

echo "<hr>";
echo "<p><strong>All slugs have been regenerated successfully!</strong></p>";
echo "<p><a href='/events/' class='btn btn-primary'>View Events</a></p>";
