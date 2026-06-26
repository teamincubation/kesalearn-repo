<?php
require_once __DIR__ . '/../../includes/admin_check.php';
$db = getDB();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Event Status Check</title>
    <style>
        body { font-family: Arial; padding: 20px; max-width: 1000px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        td, th { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        tr:nth-child(even) { background: #f9f9f9; }
        .published { color: green; font-weight: bold; }
        .draft { color: orange; font-weight: bold; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0; }
        .stat-box { background: #f4f4f4; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; }
    </style>
</head>
<body>

<h2>Event Status Overview</h2>

<?php
// Get status statistics
$stats = $db->query("SELECT status, COUNT(*) as count FROM events GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$total = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
?>

<div class="stats">
    <div class="stat-box">
        <div>Total Events</div>
        <div class="stat-number"><?php echo number_format($total); ?></div>
    </div>
    <?php foreach ($stats as $status => $count): ?>
    <div class="stat-box">
        <div><?php echo ucfirst($status); ?></div>
        <div class="stat-number"><?php echo number_format($count); ?></div>
    </div>
    <?php endforeach; ?>
</div>

<h3>Sample Events with Status</h3>
<table>
    <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Slug</th>
        <th>Status</th>
        <th>Start Date</th>
    </tr>
    <?php
    $events = $db->query("SELECT id, title, slug, status, start_date FROM events LIMIT 20")->fetchAll();
    foreach ($events as $evt):
    ?>
    <tr>
        <td><?php echo $evt['id']; ?></td>
        <td><?php echo htmlspecialchars(substr($evt['title'], 0, 50)); ?></td>
        <td><?php echo htmlspecialchars($evt['slug']); ?></td>
        <td class="<?php echo strtolower($evt['status']); ?>"><?php echo $evt['status']; ?></td>
        <td><?php echo date('M d, Y', strtotime($evt['start_date'])); ?></td>
    </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
