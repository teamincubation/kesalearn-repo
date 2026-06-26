<?php
/**
 * Slug Lookup Test - Debug slug matching issues
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();

// Get a random published event
$randomEvent = $db->query("SELECT id, title, slug, status FROM events WHERE status = 'published' LIMIT 1")->fetch();

if (!$randomEvent) {
    die("No published events found in database");
}

$testSlug = $randomEvent['slug'];
$testId = $randomEvent['id'];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Slug Lookup Test</title>
    <style>
        body { font-family: Arial; padding: 20px; max-width: 800px; margin: 0 auto; }
        .success { color: green; background: #f0f9f0; padding: 10px; margin: 10px 0; border-radius: 4px; }
        .error { color: red; background: #f9f0f0; padding: 10px; margin: 10px 0; border-radius: 4px; }
        pre { background: #f4f4f4; padding: 10px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        td, th { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f4f4f4; }
    </style>
</head>
<body>

<h2>Slug Lookup Debug Test</h2>

<h3>Random Test Event</h3>
<table>
    <tr>
        <th>Field</th>
        <th>Value</th>
    </tr>
    <tr>
        <td>ID</td>
        <td><?php echo $testId; ?></td>
    </tr>
    <tr>
        <td>Title</td>
        <td><?php echo htmlspecialchars($randomEvent['title']); ?></td>
    </tr>
    <tr>
        <td>Slug</td>
        <td><?php echo htmlspecialchars($testSlug); ?></td>
    </tr>
    <tr>
        <td>Status</td>
        <td><?php echo $randomEvent['status']; ?></td>
    </tr>
</table>

<h3>Test 1: Direct Slug Query</h3>
<?php
$stmt = $db->prepare("SELECT id, title, slug FROM events WHERE slug = ? LIMIT 1");
$stmt->execute([$testSlug]);
$result = $stmt->fetch();

if ($result) {
    echo '<div class="success">✓ Found! Event ID: ' . $result['id'] . '</div>';
} else {
    echo '<div class="error">✗ Not found with direct query</div>';
}
?>

<h3>Test 2: Case-Insensitive Slug Query</h3>
<?php
$stmt = $db->prepare("SELECT id, title, slug FROM events WHERE LOWER(slug) = LOWER(?) LIMIT 1");
$stmt->execute([$testSlug]);
$result = $stmt->fetch();

if ($result) {
    echo '<div class="success">✓ Found! Event ID: ' . $result['id'] . '</div>';
} else {
    echo '<div class="error">✗ Not found with case-insensitive query</div>';
}
?>

<h3>Test 3: Event ID Direct Query</h3>
<?php
$stmt = $db->prepare("SELECT id, title, slug FROM events WHERE id = ? LIMIT 1");
$stmt->execute([$testId]);
$result = $stmt->fetch();

if ($result) {
    echo '<div class="success">✓ Found! Slug: ' . htmlspecialchars($result['slug']) . '</div>';
} else {
    echo '<div class="error">✗ Not found with ID query</div>';
}
?>

<h3>Test Event Links</h3>
<ul>
    <li><a href="/events/detail?slug=<?php echo urlencode($testSlug); ?>" target="_blank">Detail Page (by slug)</a></li>
    <li><a href="/events/register?id=<?php echo $testId; ?>" target="_blank">Register Page (by ID)</a></li>
</ul>

<h3>Debug Info</h3>
<pre>
Test Slug: <?php echo htmlspecialchars($testSlug); ?>
Test ID: <?php echo $testId; ?>

URL Parameters:
- slug parameter: <?php echo urlencode($testSlug); ?>
- id parameter: <?php echo $testId; ?>
</pre>

</body>
</html>
