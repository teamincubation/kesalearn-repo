<?php
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Event Registration Test</title>
    <style>
        body { font-family: Arial; padding: 20px; max-width: 1200px; margin: 0 auto; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        tr:nth-child(even) { background: #f9f9f9; }
        .test-section { margin: 30px 0; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        a { color: #0066cc; margin-right: 10px; }
    </style>
</head>
<body>

<h1>Event Registration System Test</h1>

<?php

// Test 1: Check events table
echo '<div class="test-section"><h2>Test 1: Events Table Status</h2>';
try {
    $count = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
    echo "<p class='success'>✓ Events table exists with " . $count . " events</p>";
} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}
echo '</div>';

// Test 2: Check slug column
echo '<div class="test-section"><h2>Test 2: Slug Column Status</h2>';
try {
    $stmt = $db->query("DESCRIBE events");
    $columns = $stmt->fetchAll();
    $slugExists = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'slug') {
            $slugExists = true;
            break;
        }
    }
    if ($slugExists) {
        $nullCount = $db->query("SELECT COUNT(*) FROM events WHERE slug IS NULL OR slug = ''")->fetchColumn();
        if ($nullCount == 0) {
            echo "<p class='success'>✓ Slug column exists and all events have valid slugs</p>";
        } else {
            echo "<p class='warning'>⚠ Slug column exists but " . $nullCount . " events have NULL/empty slugs</p>";
        }
    } else {
        echo "<p class='error'>✗ Slug column does not exist</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}
echo '</div>';

// Test 3: Sample events with slugs
echo '<div class="test-section"><h2>Test 3: Sample Events</h2>';
$events = $db->query("SELECT id, title, slug, status FROM events LIMIT 10")->fetchAll();
echo '<table>';
echo '<tr><th>ID</th><th>Title</th><th>Slug</th><th>Status</th><th>Detail Link</th><th>Register</th></tr>';
foreach ($events as $event) {
    $detailLink = "https://kesalearn.com/events/detail?slug=" . urlencode($event['slug']);
    $registerLink = "https://kesalearn.com/events/register?id=" . $event['id'];
    echo '<tr>';
    echo '<td>' . $event['id'] . '</td>';
    echo '<td>' . htmlspecialchars(substr($event['title'], 0, 30)) . '...</td>';
    echo '<td><code>' . htmlspecialchars($event['slug']) . '</code></td>';
    echo '<td>' . $event['status'] . '</td>';
    echo '<td><a href="' . $detailLink . '" target="_blank">View</a></td>';
    echo '<td><a href="' . $registerLink . '" target="_blank">Register</a></td>';
    echo '</tr>';
}
echo '</table>';
echo '</div>';

// Test 4: Test slug lookup
echo '<div class="test-section"><h2>Test 4: Slug Lookup Test</h2>';
$testEvent = $db->query("SELECT slug FROM events LIMIT 1")->fetch();
if ($testEvent) {
    $slug = $testEvent['slug'];
    $stmt = $db->prepare("SELECT id, title, slug FROM events WHERE slug = ?");
    $stmt->execute([$slug]);
    $found = $stmt->fetch();
    if ($found) {
        echo "<p class='success'>✓ Slug lookup works! Found: " . htmlspecialchars($found['title']) . "</p>";
    } else {
        echo "<p class='error'>✗ Slug lookup failed for slug: " . htmlspecialchars($slug) . "</p>";
    }
}
echo '</div>';

// Test 5: Registration flow check
echo '<div class="test-section"><h2>Test 5: Registrations Table</h2>';
try {
    $regCount = $db->query("SELECT COUNT(*) FROM registrations")->fetchColumn();
    echo "<p class='success'>✓ Registrations table exists with " . $regCount . " registrations</p>";
} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}
echo '</div>';

// Test 6: Published events count
echo '<div class="test-section"><h2>Test 6: Published Events</h2>';
$published = $db->query("SELECT COUNT(*) FROM events WHERE status = 'published'")->fetchColumn();
$upcoming = $db->query("SELECT COUNT(*) FROM events WHERE status = 'published' AND start_date > NOW()")->fetchColumn();
echo "<p>Total published events: <strong>" . $published . "</strong></p>";
echo "<p>Upcoming events: <strong>" . $upcoming . "</strong></p>";
if ($upcoming > 0) {
    echo "<p class='success'>✓ There are upcoming events available</p>";
    $nextEvent = $db->query("SELECT id, title, slug FROM events WHERE status = 'published' AND start_date > NOW() ORDER BY start_date ASC LIMIT 1")->fetch();
    if ($nextEvent) {
        $link = "https://kesalearn.com/events/detail?slug=" . urlencode($nextEvent['slug']);
        echo "<p>Next event: <a href='" . $link . "' target='_blank'>" . htmlspecialchars($nextEvent['title']) . "</a></p>";
    }
} else {
    echo "<p class='warning'>⚠ No upcoming events found</p>";
}
echo '</div>';

?>

</body>
</html>
