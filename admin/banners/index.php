<?php
/**
 * KESA Learn - Admin: Manage Banners
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'banners';
$pageTitle = 'Manage Banners';

// Ensure banner_settings table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS banner_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        show_shadow TINYINT(1) NOT NULL DEFAULT 1,
        carousel_speed INT NOT NULL DEFAULT 5000,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $settingsExists = $db->query("SELECT COUNT(*) FROM banner_settings WHERE id = 1")->fetchColumn();
    if (!$settingsExists) {
        $db->exec("INSERT INTO banner_settings (id, show_shadow, carousel_speed) VALUES (1, 1, 5000)");
    }
} catch (PDOException $e) {}

// Fetch banner settings
$bannerSettings = $db->query("SELECT * FROM banner_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$bannerSettings) {
    $bannerSettings = ['show_shadow' => 1, 'carousel_speed' => 5000];
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/banners/');
    }
    
    $showShadow = isset($_POST['show_shadow']) ? 1 : 0;
    $carouselSpeed = intval($_POST['carousel_speed'] ?? 5000);
    if ($carouselSpeed < 1000) $carouselSpeed = 1000;
    if ($carouselSpeed > 30000) $carouselSpeed = 30000;
    
    $db->prepare("UPDATE banner_settings SET show_shadow = ?, carousel_speed = ? WHERE id = 1")
       ->execute([$showShadow, $carouselSpeed]);
    
    logActivity('banner_settings_updated', "Banner settings updated");
    setFlash('success', 'Banner settings updated!');
    redirect('/admin/banners/');
}

// Handle delete
if (isset($_GET['delete']) && verifyCSRFToken($_GET['token'] ?? '')) {
    $db->prepare("DELETE FROM banners WHERE id = ?")->execute([$_GET['delete']]);
    logActivity('banner_deleted', "Banner #" . $_GET['delete'] . " deleted");
    setFlash('success', 'Banner deleted.');
    redirect('/admin/banners/');
}

// Handle toggle
if (isset($_GET['toggle']) && verifyCSRFToken($_GET['token'] ?? '')) {
    $db->prepare("UPDATE banners SET is_active = NOT is_active WHERE id = ?")->execute([$_GET['toggle']]);
    setFlash('success', 'Banner status updated.');
    redirect('/admin/banners/');
}

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/banners/');
    }
    
    $title = sanitize($_POST['title'] ?? '');
    $link = sanitize($_POST['link'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    
    if (!empty($_FILES['banner_image']['name'])) {
        $upload = uploadFile($_FILES['banner_image'], 'banners');
        if ($upload['success']) {
            $stmt = $db->prepare("INSERT INTO banners (title, image, link, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $upload['filename'], $link, $sortOrder]);
            logActivity('banner_uploaded', "Banner uploaded: $title");
            setFlash('success', 'Banner uploaded successfully!');
        } else {
            setFlash('error', $upload['error']);
        }
    }
    redirect('/admin/banners/');
}

$banners = $db->query("SELECT * FROM banners ORDER BY sort_order ASC, created_at DESC")->fetchAll();

include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Banner Settings -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 24px; margin-bottom: 24px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
        <h3 style="font-size: 1.1rem; margin: 0; display: flex; align-items: center; gap: 8px;">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Banner Settings
        </h3>
    </div>
    <form method="POST" style="display: flex; gap: 24px; align-items: flex-end; flex-wrap: wrap;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="update_settings" value="1">
        
        <div class="form-group" style="margin-bottom: 0;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                <input type="checkbox" name="show_shadow" value="1" <?php echo $bannerSettings['show_shadow'] ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: var(--blue);">
                <span>Show Banner Shadow</span>
            </label>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">Enable/disable the drop shadow effect on banner carousel</p>
        </div>
        
        <div class="form-group" style="margin-bottom: 0; width: 180px;">
            <label>Carousel Speed (ms)</label>
            <input type="number" name="carousel_speed" class="form-control" value="<?php echo $bannerSettings['carousel_speed']; ?>" min="1000" max="30000" step="500">
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">Time between slides (1000-30000)</p>
        </div>
        
        <button type="submit" class="btn btn-primary" style="margin-bottom: 20px;">Save Settings</button>
    </form>
</div>

<!-- Upload Form -->
<div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 24px; margin-bottom: 24px;">
    <h3 style="font-size: 1.1rem; margin-bottom: 16px;">Upload New Banner</h3>
    <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
        <?php echo csrfField(); ?>
        <div class="form-group" style="flex: 1; min-width: 200px;">
            <label>Title</label>
            <input type="text" name="title" class="form-control" placeholder="Banner title">
        </div>
        <div class="form-group" style="flex: 1; min-width: 200px;">
            <label>Link (optional)</label>
            <input type="url" name="link" class="form-control" placeholder="https://...">
        </div>
        <div class="form-group" style="width: 80px;">
            <label>Order</label>
            <input type="number" name="sort_order" class="form-control" value="0" min="0">
        </div>
        <div class="form-group" style="flex: 1; min-width: 200px;">
            <label>Image <span class="required">*</span></label>
            <input type="file" name="banner_image" class="form-control" accept="image/*" required>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-bottom: 20px;">Upload</button>
    </form>
</div>

<!-- Banners List -->
<?php if (empty($banners)): ?>
    <div class="empty-state" style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
        <h3>No banners yet</h3>
        <p>Upload your first banner to display on the landing page.</p>
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
        <?php foreach ($banners as $banner): ?>
            <div class="card" style="cursor: default;">
                <img src="/uploads/banners/<?php echo sanitize($banner['image']); ?>" alt="<?php echo sanitize($banner['title'] ?? 'Banner'); ?>" class="card-image">
                <div class="card-body">
                    <h4 style="font-size: 1rem;"><?php echo sanitize($banner['title'] ?? 'Untitled'); ?></h4>
                    <div style="display: flex; gap: 8px; margin-top: 4px;">
                        <?php echo $banner['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-draft">Inactive</span>'; ?>
                        <span class="badge" style="background: var(--bg-tertiary); color: var(--text-muted);">Order: <?php echo $banner['sort_order']; ?></span>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="?toggle=<?php echo $banner['id']; ?>&token=<?php echo generateCSRFToken(); ?>" class="btn btn-sm btn-secondary">
                        <?php echo $banner['is_active'] ? 'Deactivate' : 'Activate'; ?>
                    </a>
                    <a href="?delete=<?php echo $banner['id']; ?>&token=<?php echo generateCSRFToken(); ?>" class="btn btn-sm" style="color: var(--red);" data-confirm="Delete this banner?">Delete</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
