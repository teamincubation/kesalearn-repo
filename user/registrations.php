<?php
/**
 * KESA Learn - User Registrations
 * Mobile-first professional design
 */
require_once __DIR__ . '/../includes/auth_check.php';

$db = getDB();
$userId = $_SESSION['user_id'];

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
$countStmt->execute([$userId]);
$total = $countStmt->fetchColumn();

$stmt = $db->prepare("
    SELECT r.*, e.title, e.slug, e.start_date, e.end_date, e.type, e.is_online, e.venue, e.banner_image, e.status as event_status, e.support_phone, e.support_whatsapp, e.payment_methods
    FROM registrations r 
    JOIN events e ON r.event_id = e.id 
    WHERE r.user_id = ? 
    ORDER BY r.registered_at DESC 
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$userId]);
$registrations = $stmt->fetchAll();

$pageTitle = 'My Registrations';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Registrations Page Mobile-First Styles */
.regs-page {
    padding: 0 0 100px;
    background: var(--bg-secondary);
    min-height: calc(100vh - var(--nav-height));
}

.mobile-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-light);
    position: sticky;
    top: var(--nav-height);
    z-index: 100;
}

@media (min-width: 769px) {
    .mobile-page-header { display: none; }
}

.mobile-page-title {
    font-size: 1.1rem;
    font-weight: 700;
}

.dashboard-link-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--blue);
    color: #fff;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
}

.dashboard-link-btn:hover {
    background: var(--purple);
}

.dashboard-link-btn svg {
    width: 16px;
    height: 16px;
}

.regs-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 16px;
}

/* Desktop Header */
.regs-header {
    display: none;
    margin-bottom: 24px;
}

@media (min-width: 769px) {
    .regs-header {
        display: block;
    }
}

.regs-header h1 {
    font-size: 1.5rem;
    margin-bottom: 4px;
}

.regs-header p {
    color: var(--text-muted);
}

/* Stats Bar */
.stats-bar {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    padding-bottom: 4px;
    margin-bottom: 20px;
    -webkit-overflow-scrolling: touch;
}

.stats-bar::-webkit-scrollbar {
    display: none;
}

.stat-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    white-space: nowrap;
}

.stat-pill-value {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-primary);
}

.stat-pill-label {
    font-size: 0.8rem;
    color: var(--text-muted);
}

/* Registration Cards */
.reg-cards {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.reg-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: all 0.2s ease;
}

.reg-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.reg-card-link {
    text-decoration: none;
    color: inherit;
    display: block;
}

.reg-card-top {
    display: flex;
    gap: 14px;
    padding: 16px;
}

.reg-thumb {
    width: 70px;
    height: 70px;
    border-radius: var(--radius-md);
    background: var(--bg-tertiary);
    overflow: hidden;
    flex-shrink: 0;
}

.reg-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.reg-thumb-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--blue-light);
}

.reg-thumb-placeholder svg {
    width: 28px;
    height: 28px;
    color: var(--blue);
}

.reg-info {
    flex: 1;
    min-width: 0;
}

.reg-title {
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--text-primary);
    margin-bottom: 6px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.reg-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 0.8rem;
    color: var(--text-muted);
}

.reg-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.reg-meta-item svg {
    width: 14px;
    height: 14px;
}

.reg-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-light);
}

.reg-amount {
    font-weight: 700;
    color: var(--text-primary);
}

.reg-status {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.reg-status.paid, .reg-status.verified {
    background: #e6f9f0;
    color: #0d7a42;
}

.reg-status.pending {
    background: var(--yellow-light);
    color: #b8860b;
}

.reg-status.failed, .reg-status.rejected {
    background: #fef2f2;
    color: var(--red);
}

/* Payment Action Styles */
.reg-card-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #fff8e1 0%, #fef9e7 100%);
    border-top: 1px solid #f0d78c;
}

.btn-complete-payment {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--purple) 100%);
    color: #fff;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.btn-complete-payment:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
    color: #fff;
}

.btn-complete-payment svg {
    flex-shrink: 0;
}

.support-buttons {
    display: flex;
    gap: 8px;
}

.btn-support {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    transition: all 0.2s ease;
    text-decoration: none;
}

.btn-support.whatsapp {
    background: #25D366;
    color: #fff;
}

.btn-support.whatsapp:hover {
    background: #128C7E;
    transform: scale(1.1);
}

.btn-support.call {
    background: var(--blue);
    color: #fff;
}

.btn-support.call:hover {
    background: var(--purple);
    transform: scale(1.1);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}

.empty-state-icon {
    width: 80px;
    height: 80px;
    background: var(--bg-tertiary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.empty-state-icon svg {
    width: 40px;
    height: 40px;
    color: var(--text-muted);
}

.empty-state-modern h3 {
    font-size: 1.2rem;
    margin-bottom: 8px;
}

.empty-state-modern p {
    color: var(--text-muted);
    font-size: 0.95rem;
    margin-bottom: 20px;
}

/* Pagination */
.pagination-modern {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 24px;
}

.pagination-modern a, .pagination-modern span {
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    font-size: 0.9rem;
    font-weight: 500;
    text-decoration: none;
}

.pagination-modern a {
    background: var(--bg-primary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.pagination-modern a:hover {
    background: var(--blue);
    color: #fff;
    border-color: var(--blue);
}

.pagination-modern .current {
    background: var(--blue);
    color: #fff;
}
</style>

<div class="regs-page">
    <!-- Mobile Page Header -->
    <div class="mobile-page-header">
        <span class="mobile-page-title">My Registrations</span>
        <a href="/user/dashboard" class="dashboard-link-btn">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
    </div>
    
    <div class="regs-container">
        <!-- Desktop Header -->
        <div class="regs-header">
            <h1>My Registrations</h1>
            <p>Track all your event registrations and payment status</p>
        </div>
        
        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stat-pill">
                <span class="stat-pill-value"><?php echo $total; ?></span>
                <span class="stat-pill-label">Total</span>
            </div>
            <?php
            $paidCount = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND payment_status = 'paid'");
            $paidCount->execute([$userId]);
            ?>
            <div class="stat-pill">
                <span class="stat-pill-value"><?php echo $paidCount->fetchColumn(); ?></span>
                <span class="stat-pill-label">Confirmed</span>
            </div>
            <?php
            $pendingCount = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND payment_status = 'pending'");
            $pendingCount->execute([$userId]);
            ?>
            <div class="stat-pill">
                <span class="stat-pill-value"><?php echo $pendingCount->fetchColumn(); ?></span>
                <span class="stat-pill-label">Pending</span>
            </div>
        </div>
        
        <?php if (empty($registrations)): ?>
            <div class="empty-state-modern">
                <div class="empty-state-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <h3>No registrations yet</h3>
                <p>You haven't registered for any events yet. Browse our upcoming events and get started!</p>
                <a href="/events/" class="btn btn-primary">Explore Events</a>
            </div>
        <?php else: ?>
            <div class="reg-cards">
                <?php foreach ($registrations as $reg): 
                    $eventDate = new DateTime($reg['start_date']);
                ?>
                <div class="reg-card">
                    <a href="/events/detail?id=<?php echo $reg['event_id']; ?>" class="reg-card-link">
                        <div class="reg-card-top">
                            <div class="reg-thumb">
                                <?php if (!empty($reg['banner_image'])): ?>
                                    <img src="/uploads/banners/<?php echo sanitize($reg['banner_image']); ?>" alt="">
                                <?php else: ?>
                                    <div class="reg-thumb-placeholder">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="reg-info">
                                <div class="reg-title"><?php echo sanitize($reg['title']); ?></div>
                                <div class="reg-meta">
                                    <span class="reg-meta-item">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        <?php echo $eventDate->format('M d, Y'); ?>
                                    </span>
                                    <span class="reg-meta-item">
                                        <?php if ($reg['is_online']): ?>
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                            Online
                                        <?php else: ?>
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                                            In-Person
                                        <?php endif; ?>
                                    </span>
                                    <span class="reg-meta-item" style="text-transform: capitalize;">
                                        <?php echo $reg['type']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                    <div class="reg-card-bottom">
                        <span class="reg-amount"><?php echo formatPrice($reg['amount']); ?></span>
                        <span class="reg-status <?php echo $reg['payment_status']; ?>"><?php echo ucfirst($reg['payment_status']); ?></span>
                    </div>
                    
                    <?php if (in_array($reg['payment_status'], ['paid', 'verified'])): ?>
                    <!-- View Updates for Confirmed Registrations -->
                    <div class="reg-card-actions" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-top-color: #a7f3d0;">
                        <a href="/user/event-details?event_id=<?php echo $reg['event_id']; ?>" class="btn-complete-payment" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            View Updates & Meeting Details
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($reg['payment_status'] === 'pending' && $reg['amount'] > 0): ?>
                    <!-- Payment Action for Pending -->
                    <div class="reg-card-actions">
                        <a href="/events/payment?registration_id=<?php echo $reg['id']; ?>" class="btn-complete-payment">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            Complete Payment
                        </a>
                        
                        <!-- Support Contact -->
                        <?php if (!empty($reg['support_phone']) || !empty($reg['support_whatsapp'])): ?>
                        <div class="support-buttons">
                            <?php if (!empty($reg['support_whatsapp'])): ?>
                            <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $reg['support_whatsapp']); ?>?text=Hi,%20I%20need%20help%20with%20payment%20for%20<?php echo urlencode($reg['title']); ?>" target="_blank" class="btn-support whatsapp" title="WhatsApp Support">
                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($reg['support_phone'])): ?>
                            <a href="tel:<?php echo sanitize($reg['support_phone']); ?>" class="btn-support call" title="Call Support">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($total > $perPage): ?>
            <div class="pagination-modern">
                <?php
                $totalPages = ceil($total / $perPage);
                if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>">Prev</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- User Bottom Navigation -->
<?php include __DIR__ . '/../includes/user_nav.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
