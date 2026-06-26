<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'Page Not Found';
include __DIR__ . '/../includes/header.php';
?>
<section style="min-height: 60vh; display: flex; align-items: center; justify-content: center; padding: 100px 20px;">
    <div class="text-center">
        <h1 style="font-size: 6rem; font-weight: 900; color: var(--border-color); line-height: 1;">404</h1>
        <h2 style="margin-bottom: 12px;">Page Not Found</h2>
        <p style="color: var(--text-muted); margin-bottom: 32px; max-width: 400px;">The page you're looking for doesn't exist or has been moved.</p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <a href="/" class="btn btn-primary">Go Home</a>
            <a href="/events/" class="btn btn-secondary">Browse Events</a>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
