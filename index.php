<?php
/**
 * KESA Learn - Landing Page
 */
require_once __DIR__ . '/includes/functions.php';

// Check maintenance mode
checkMaintenanceMode();

$db = getDB();

// Fetch active banners
$banners = $db->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 5")->fetchAll();

// Fetch banner settings
try {
    $bannerSettings = $db->query("SELECT * FROM banner_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bannerSettings = null;
}
$showBannerShadow = $bannerSettings ? $bannerSettings['show_shadow'] : 1;
$carouselSpeed = $bannerSettings ? $bannerSettings['carousel_speed'] : 5000;

// Fetch upcoming events
$upcomingStmt = $db->prepare("SELECT * FROM events WHERE status = 'published' AND start_date > NOW() ORDER BY start_date ASC LIMIT 6");
$upcomingStmt->execute();
$upcomingEvents = $upcomingStmt->fetchAll();

// Fetch past events (recently completed based on start_date)
$pastStmt = $db->prepare("SELECT * FROM events WHERE status = 'published' AND start_date <= NOW() ORDER BY start_date DESC LIMIT 3");
$pastStmt->execute();
$pastEvents = $pastStmt->fetchAll();

// Get stats (base counts from admin settings + auto-increment from database)
$dbUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$dbEvents = $db->query("SELECT COUNT(*) FROM events WHERE status IN ('published','completed')")->fetchColumn();
$dbRegistrations = $db->query("SELECT COUNT(*) FROM registrations WHERE payment_status IN ('paid','verified','pending')")->fetchColumn();

$baseYears = intval(getSetting('base_years_of_impact', '10'));
$baseEvents = intval(getSetting('base_events_conducted', '0'));
$baseLearners = intval(getSetting('base_learners_trained', '0'));
$baseCommunity = intval(getSetting('base_community_members', '0'));

$totalUsers = $baseCommunity + $dbUsers;
$totalEvents = $baseEvents + $dbEvents;
$totalRegistrations = $baseLearners + $dbRegistrations;
$showMaxSeats = getSetting('show_max_seats', '1');

// Fetch approved feedbacks for testimonials section with user profile images and event titles
// Ordered by date (latest first) - no randomization
$feedbackStmt = $db->query("SELECT f.*, u.profile_image, e.title as event_title FROM feedbacks f LEFT JOIN users u ON f.user_id = u.id LEFT JOIN events e ON f.event_id = e.id WHERE f.is_approved = 1 ORDER BY f.created_at DESC LIMIT 12");
$approvedFeedbacks = $feedbackStmt->fetchAll();

// Check if testimonials section should be shown on homepage
$showTestimonials = true;
try {
    $testimonialsSetting = $db->query("SELECT value FROM site_settings WHERE setting_key = 'show_testimonials' LIMIT 1")->fetch();
    $showTestimonials = $testimonialsSetting ? (int)$testimonialsSetting['value'] === 1 : true;
} catch (PDOException $e) {
    // Table might not exist or setting not set, default to showing
    $showTestimonials = true;
}

// Fetch active announcements for marquee
$announcements = [];
try {
    $istTz = new DateTimeZone('Asia/Kolkata');
    $nowIST = (new DateTime('now', $istTz))->format('Y-m-d H:i:s');
    $annStmt = $db->prepare("SELECT * FROM announcements WHERE is_active = 1 AND start_date <= ? AND end_date >= ? ORDER BY sort_order ASC, created_at DESC");
    $annStmt->execute([$nowIST, $nowIST]);
    $announcements = $annStmt->fetchAll();
} catch (PDOException $e) {
    // Table doesn't exist yet
}

// Fetch WhatsApp links from site_content
$whatsappGroupLink = '';
$whatsappChatLink = '';
try {
    $whatsappStmt = $db->prepare("SELECT content_key, content_value FROM site_content WHERE content_key IN ('whatsapp_group_link', 'whatsapp_chat_link')");
    $whatsappStmt->execute();
    $whatsappData = $whatsappStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $whatsappGroupLink = $whatsappData['whatsapp_group_link'] ?? '';
    $whatsappChatLink = $whatsappData['whatsapp_chat_link'] ?? '';
} catch (PDOException $e) {
    // Fields don't exist yet
}

$pageTitle = 'Home';
include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($announcements)): ?>
<!-- Announcements Ticker Bar -->
<div id="announcement-ticker" style="margin-top: 70px; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-bottom: 1px solid rgba(255,255,255,0.1); position: relative; z-index: 40;">
    <div style="display: flex; align-items: stretch; max-width: 100%; overflow: hidden;">
        <!-- Notice Label -->
        <div style="background: linear-gradient(135deg, #e7404a 0%, #dc2626 100%); color: #fff; padding: 12px 20px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; position: relative;">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="animation: bellRing 2s ease-in-out infinite;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            <span>Updates</span>
            <div style="position: absolute; right: -10px; top: 0; bottom: 0; width: 20px; background: linear-gradient(135deg, #e7404a 0%, #dc2626 100%); clip-path: polygon(0 0, 100% 50%, 0 100%);"></div>
        </div>
        
        <!-- Ticker Content Area -->
        <div style="flex: 1; overflow: hidden; position: relative; display: flex; align-items: center; padding: 0 24px;">
            <div id="ticker-track" style="display: flex; gap: 60px; will-change: transform;">
                <?php 
                // Generate items twice for seamless loop
                for ($loop = 0; $loop < 2; $loop++):
                    foreach ($announcements as $ann): 
                        $label = $ann['label'];
                        $hasLink = $ann['link_type'] !== 'none';
                        $linkUrl = '#';
                        $linkTarget = '';
                        $timeAgo = timeAgo($ann['created_at']);
                        
                        // Badge colors
                        $badgeColors = [
                            'new' => 'background: linear-gradient(135deg, #22c55e, #16a34a);',
                            'important' => 'background: linear-gradient(135deg, #ef4444, #dc2626);',
                            'download' => 'background: linear-gradient(135deg, #3b82f6, #2563eb);',
                            'register' => 'background: linear-gradient(135deg, #f59e0b, #d97706);',
                            'free' => 'background: linear-gradient(135deg, #a855f7, #9333ea);',
                            'update' => 'background: linear-gradient(135deg, #6366f1, #4f46e5);',
                            'info' => 'background: linear-gradient(135deg, #64748b, #475569);'
                        ];
                        $badgeStyle = $badgeColors[$label] ?? $badgeColors['info'];
                        
                        if ($ann['link_type'] === 'internal') {
                            $linkUrl = $ann['link_url'];
                        } elseif ($ann['link_type'] === 'external') {
                            $linkUrl = $ann['link_url'];
                            $linkTarget = 'target="_blank" rel="noopener"';
                        } elseif ($ann['link_type'] === 'download' && $ann['file_path']) {
                            $linkUrl = '/download.php?id=' . $ann['id'];
                        }
                ?>
                <div class="ticker-item" style="display: flex; align-items: center; gap: 12px; white-space: nowrap; flex-shrink: 0; padding: 8px 18px; background: rgba(255,255,255,0.06); border-radius: 50px; border: 1px solid rgba(255,255,255,0.1); transition: all 0.3s ease;">
                    <!-- Badge -->
                    <span style="<?php echo $badgeStyle; ?> color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 5px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
                        <?php if ($label === 'new'): ?>
                            <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        <?php elseif ($label === 'important'): ?>
                            <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <?php elseif ($label === 'download'): ?>
                            <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                        <?php elseif ($label === 'register'): ?>
                            <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 002 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
                        <?php elseif ($label === 'free'): ?>
                            <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>
                        <?php elseif ($label === 'update'): ?>
                            <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M21 10.12h-6.78l2.74-2.82c-2.73-2.7-7.15-2.8-9.88-.1-2.73 2.71-2.73 7.08 0 9.79s7.15 2.71 9.88 0C18.32 15.65 19 14.08 19 12.1h2c0 1.98-.88 4.55-2.64 6.29-3.51 3.48-9.21 3.48-12.72 0-3.5-3.47-3.53-9.11-.02-12.58s9.14-3.47 12.65 0L21 3v7.12z"/></svg>
                        <?php else: ?>
                            <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                        <?php endif; ?>
                        <?php echo ucfirst($label); ?>
                    </span>
                    
                    <!-- Content -->
                    <?php if ($hasLink): ?>
                        <a href="<?php echo sanitize($linkUrl); ?>" <?php echo $linkTarget; ?> style="color: #e2e8f0; text-decoration: none; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 6px; transition: color 0.2s;">
                            <?php echo sanitize($ann['content']); ?>
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="opacity: 0.6;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                        </a>
                    <?php else: ?>
                        <span style="color: #e2e8f0; font-size: 0.9rem; font-weight: 500;"><?php echo sanitize($ann['content']); ?></span>
                    <?php endif; ?>
                    
                    <!-- Time -->
                    <span style="font-size: 0.7rem; color: #94a3b8; display: flex; align-items: center; gap: 4px; margin-left: 4px;">
                        <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php echo $timeAgo; ?>
                    </span>
                </div>
                <?php 
                    endforeach;
                endfor; 
                ?>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes bellRing {
    0%, 100% { transform: rotate(0); }
    10%, 30% { transform: rotate(-10deg); }
    20%, 40% { transform: rotate(10deg); }
    50% { transform: rotate(0); }
}
.ticker-item:hover {
    background: rgba(255,255,255,0.12) !important;
    border-color: rgba(255,255,255,0.2) !important;
    transform: scale(1.02);
}
.ticker-item a:hover {
    color: #fbbf24 !important;
}
.ticker-item a:hover svg {
    opacity: 1 !important;
    transform: translateX(3px);
}
</style>

<script>
(function() {
    const track = document.getElementById('ticker-track');
    if (!track) return;
    
    let position = 0;
    let isPaused = false;
    const speed = 0.8; // pixels per frame
    
    // Get half width (since content is duplicated)
    function getResetPoint() {
        return track.scrollWidth / 2;
    }
    
    function animate() {
        if (!isPaused) {
            position -= speed;
            if (Math.abs(position) >= getResetPoint()) {
                position = 0;
            }
            track.style.transform = `translateX(${position}px)`;
        }
        requestAnimationFrame(animate);
    }
    
    // Pause on hover
    track.addEventListener('mouseenter', () => isPaused = true);
    track.addEventListener('mouseleave', () => isPaused = false);
    
    // Start animation
    requestAnimationFrame(animate);
})();
</script>
<?php endif; ?>

<!-- Hero Section -->
<section class="hero">
    <div class="container">
        <div class="hero-content">
            <div class="hero-badge">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                Celebrating <?php echo $baseYears; ?>+ Years of Empowering Learners
            </div>
            <h1>
                <span class="highlight-red">Knowledge</span> Enhancement &amp; <span class="highlight-blue">Skill</span> Acquisition Program
            </h1>
            <p class="hero-text">
                Join KESA Learn for dynamic workshops, expert-led webinars, and hands-on sessions designed to enhance your skills and accelerate your career growth.
            </p>
            <div class="hero-actions">
                <a href="/events/" class="btn btn-primary btn-lg">Explore Events</a>
                <?php if (!empty($whatsappGroupLink)): ?>
                    <a href="<?php echo sanitize($whatsappGroupLink); ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-lg">Join Group</a>
                <?php else: ?>
                    <a href="/auth/signup" class="btn btn-outline btn-lg">Join Free</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-image">
            <?php if (!empty($banners)): ?>
                <div class="banner-carousel<?php echo !$showBannerShadow ? ' no-shadow' : ''; ?>" data-speed="<?php echo $carouselSpeed; ?>">
                    <?php foreach ($banners as $i => $banner): ?>
                        <div class="banner-slide <?php echo $i === 0 ? 'active' : ''; ?>">
                            <img src="/uploads/banners/<?php echo sanitize($banner['image']); ?>" alt="<?php echo sanitize($banner['title'] ?? 'KESA Learn Banner'); ?>">
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($banners) > 1): ?>
                        <div class="banner-dots">
                            <?php foreach ($banners as $i => $banner): ?>
                                <button class="banner-dot <?php echo $i === 0 ? 'active' : ''; ?>" aria-label="Slide <?php echo $i + 1; ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Animated KESA Logo (Lottie) -->
                <div id="kesa-lottie-container" style="width: 100%; max-width: 450px; margin: 0 auto;"></div>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js"></script>
                <script>
                    lottie.loadAnimation({
                        container: document.getElementById('kesa-lottie-container'),
                        renderer: 'svg',
                        loop: true,
                        autoplay: true,
                        path: '/assets/animations/kesa-logo.json'
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Stats Bar -->
<section class="stats-bar">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <h3 data-count="<?php echo $baseYears; ?>" data-suffix="+">0</h3>
                <p>Years of Impact</p>
            </div>
            <div class="stat-item">
                <h3 data-count="<?php echo $totalEvents; ?>" data-suffix="+">0</h3>
                <p>Events Conducted</p>
            </div>
            <div class="stat-item">
                <h3 data-count="<?php echo $totalRegistrations; ?>" data-suffix="+">0</h3>
                <p>Learners Trained</p>
            </div>
            <div class="stat-item">
                <h3 data-count="<?php echo $totalUsers; ?>" data-suffix="+">0</h3>
                <p>Community Members</p>
            </div>
        </div>
    </div>
</section>

<!-- Upcoming Events -->
<?php if (!empty($upcomingEvents)): ?>
<section class="section" id="events">
    <div class="container">
        <div class="section-header">
            <h2>Upcoming Events</h2>
            <p>Register now and reserve your spot in our upcoming learning sessions</p>
        </div>
        
        <div class="events-grid">
            <?php foreach ($upcomingEvents as $event): ?>
                <a href="/events/detail?id=<?php echo $event['id']; ?>" class="card event-card" data-type="<?php echo $event['type']; ?>" style="position: relative;">
                    <?php if (!empty($event['is_new'])): ?>
                        <span class="new-label-badge">NEW</span>
                    <?php endif; ?>
                    <?php if ($event['banner_image']): ?>
                        <img src="/uploads/banners/<?php echo sanitize($event['banner_image']); ?>" alt="<?php echo sanitize($event['title']); ?>" class="card-image">
                    <?php else: ?>
                        <div class="card-image" style="background: linear-gradient(135deg, var(--blue-light), var(--purple-light)); display: flex; align-items: center; justify-content: center;">
                            <span style="font-size: 2rem; font-weight: 800; color: var(--blue); opacity: 0.5;">KESA</span>
                        </div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                            <?php echo getEventTypeBadge($event['type']); ?>
                            <?php if ($event['is_online']): ?>
                                <span class="badge badge-success">Online</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Offline</span>
                            <?php endif; ?>
                        </div>
                        <h3 class="card-title"><?php echo sanitize($event['title']); ?></h3>
                        <p class="card-text"><?php echo sanitize(truncateText($event['short_description'] ?? $event['description'], 100)); ?></p>
                        <div class="card-meta">
                            <span class="event-meta-item">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <?php echo formatDate($event['start_date']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span class="event-price <?php echo $event['is_free'] ? 'free' : ''; ?>">
                            <?php echo formatPrice($event['price'], $event['currency']); ?>
                        </span>
                        <?php if ($event['max_seats'] && $showMaxSeats === '1'): ?>
                            <span style="font-size: 0.85rem; color: var(--text-muted);">
                                <?php echo ($event['max_seats'] - $event['seats_taken']); ?> seats left
                            </span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        
        <div class="text-center mt-4">
            <a href="/events/" class="btn btn-secondary btn-lg">View All Events</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- About Section -->
<section class="section" id="about" style="background: var(--bg-warm);">
    <div class="container">
        <div style="display: flex; gap: 60px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 300px;">
                <div class="hero-badge" style="margin-bottom: 16px;">About KESA</div>
                <h2 style="margin-bottom: 16px;">Empowering Learners Since 2021</h2>
                <p style="color: var(--text-secondary); line-height: 1.8; margin-bottom: 20px;">
                    <?php echo getSiteContent('about_text', 'KESA (Knowledge Enhancement & Skill Acquisition) is a flagship project of Team Incubation, started in 2014. For over 10 years, we have been empowering learners through dynamic workshops, webinars, and expert-led sessions.'); ?>
                </p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div style="padding: 20px; background: var(--bg-primary); border-radius: var(--radius-md); border-left: 4px solid var(--red);">
                        <h4 style="color: var(--red); margin-bottom: 4px;">Workshops</h4>
                        <p style="font-size: 0.9rem; color: var(--text-muted);">Hands-on practical sessions</p>
                    </div>
                    <div style="padding: 20px; background: var(--bg-primary); border-radius: var(--radius-md); border-left: 4px solid var(--purple);">
                        <h4 style="color: var(--purple); margin-bottom: 4px;">Webinars</h4>
                        <p style="font-size: 0.9rem; color: var(--text-muted);">Expert-led online sessions</p>
                    </div>
                    <div style="padding: 20px; background: var(--bg-primary); border-radius: var(--radius-md); border-left: 4px solid var(--blue);">
                        <h4 style="color: var(--blue); margin-bottom: 4px;">Offline Programs</h4>
                        <p style="font-size: 0.9rem; color: var(--text-muted);">In-person learning events</p>
                    </div>
                    <div style="padding: 20px; background: var(--bg-primary); border-radius: var(--radius-md); border-left: 4px solid var(--yellow-dark);">
                        <h4 style="color: var(--yellow-dark); margin-bottom: 4px;">Courses</h4>
                        <p style="font-size: 0.9rem; color: var(--text-muted);">Structured learning programs</p>
                    </div>
                </div>
            </div>
            <div style="flex: 1; min-width: 300px; text-align: center;">
                <img src="https://hebbkx1anhila5yf.public.blob.vercel-storage.com/puzzle%20for%20website%20copy-M9OKyEV5hfsnxEGow1gK81LJFFC7fE.png" alt="KESA Learning" style="max-width: 380px; margin: 0 auto; filter: none;">
            </div>
        </div>
    </div>
</section>

<!-- Past Events -->
<?php if (!empty($pastEvents)): 
    // Pastel color palette for events without banners
    $pastelColors = [
        ['gradient' => 'linear-gradient(135deg, #fef3e2 0%, #fde68a 100%)', 'icon' => '#f59e0b'],
        ['gradient' => 'linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%)', 'icon' => '#0ea5e9'],
        ['gradient' => 'linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%)', 'icon' => '#ec4899'],
        ['gradient' => 'linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%)', 'icon' => '#22c55e'],
        ['gradient' => 'linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%)', 'icon' => '#8b5cf6'],
        ['gradient' => 'linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%)', 'icon' => '#ea580c'],
        ['gradient' => 'linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%)', 'icon' => '#16a34a'],
        ['gradient' => 'linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%)', 'icon' => '#db2777'],
    ];
?>
<section class="section">
    <div class="container">
        <div class="section-header">
            <h2>Past Events</h2>
            <p>Take a look at our recently completed learning sessions</p>
        </div>
        
        <div class="events-grid">
            <?php foreach ($pastEvents as $event): 
                $pastelIndex = $event['id'] % count($pastelColors);
                $pastel = $pastelColors[$pastelIndex];
            ?>
                <a href="/events/detail?id=<?php echo $event['id']; ?>" class="card event-card">
                    <?php if ($event['banner_image']): ?>
                        <img src="/uploads/banners/<?php echo sanitize($event['banner_image']); ?>" alt="<?php echo sanitize($event['title']); ?>" class="card-image">
                    <?php else: ?>
                        <div class="card-image" style="background: <?php echo $pastel['gradient']; ?>; display: flex; align-items: center; justify-content: center;">
                            <svg width="40" height="40" fill="none" stroke="<?php echo $pastel['icon']; ?>" viewBox="0 0 24 24" style="opacity: 0.6;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                    <?php endif; ?>
                    <div class="card-body">
                        <?php echo getEventTypeBadge($event['type']); ?>
                        <h3 class="card-title" style="margin-top: 8px;"><?php echo sanitize($event['title']); ?></h3>
                        <p class="card-text"><?php echo sanitize(truncateText($event['short_description'] ?? $event['description'], 80)); ?></p>
                        <div class="card-meta">
                            <span class="event-meta-item">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <?php echo formatDate($event['start_date']); ?>
                            </span>
                            <span class="badge badge-completed">Completed</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        
        <div style="text-align: center; margin-top: 32px;">
            <a href="/events/?type=all" class="btn btn-outline-primary">View All Events</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Testimonials / Feedbacks -->
<?php if (!empty($approvedFeedbacks) && $showTestimonials): ?>
<section class="section" style="background: var(--bg-secondary);">
    <div class="container">
        <!-- Header with Title and Navigation -->
        <div class="testimonials-header">
            <h2>What Our Learners Say</h2>
            <div class="testimonials-controls">
                <button class="testimonial-nav-btn prev" onclick="prevTestimonial()" aria-label="Previous testimonial">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <button class="testimonial-nav-btn next" onclick="nextTestimonial()" aria-label="Next testimonial">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
        </div>
        
        <!-- Testimonials Carousel -->
        <div class="testimonials-carousel-wrapper">
            <div class="testimonials-carousel" id="testimonialsCarousel">
                <?php foreach ($approvedFeedbacks as $fb): 
                    $postedDate = new DateTime($fb['created_at']);
                    $timeAgo = timeAgo($fb['created_at']);
                ?>
                    <div class="testimonial-card-new" data-card-id="<?php echo $fb['id']; ?>">
                        <!-- Rounded Avatar at Top -->
                        <div class="testimonial-avatar-top">
                            <?php 
                            $profileImagePath = '';
                            if (!empty($fb['profile_image'])) {
                                // Handle different possible formats of profile_image path
                                $imagePath = $fb['profile_image'];
                                
                                // If it's just a filename, prepend the uploads/profiles directory
                                if (!strpos($imagePath, '/')) {
                                    $profileImagePath = '/uploads/profiles/' . basename($imagePath);
                                } 
                                // If it already has a path but not the full one, ensure it has uploads/profiles
                                elseif (!strpos($imagePath, 'uploads/profiles')) {
                                    $profileImagePath = '/uploads/profiles/' . basename($imagePath);
                                }
                                // Otherwise use as-is
                                else {
                                    $profileImagePath = $imagePath;
                                }
                            }
                            ?>
                            <?php if (!empty($profileImagePath)): ?>
                                <img src="<?php echo sanitize($profileImagePath); ?>" alt="<?php echo sanitize($fb['name']); ?>" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <div class="avatar-initial"><?php echo strtoupper(substr($fb['name'], 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Rating Stars -->
                        <div class="testimonial-rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <svg class="star <?php echo $i <= $fb['rating'] ? 'filled' : 'empty'; ?>" width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                </svg>
                            <?php endfor; ?>
                        </div>

                        <!-- Quote Text with Truncation -->
                        <?php 
                        $fullText = $fb['feedback_text'];
                        $maxChars = 150;
                        $truncatedText = strlen($fullText) > $maxChars ? substr($fullText, 0, $maxChars) . '...' : $fullText;
                        $hasReadMore = strlen($fullText) > $maxChars;
                        ?>
                        <p class="testimonial-text" data-full-text="<?php echo htmlspecialchars($fullText); ?>" data-truncated-text="<?php echo htmlspecialchars($truncatedText); ?>">
                            <?php echo sanitize($truncatedText); ?>
                        </p>
                        
                        <?php if ($hasReadMore): ?>
                            <button class="read-more-btn" onclick="toggleReadMore(event)" style="background: none; border: none; color: var(--primary); cursor: pointer; font-weight: 600; padding: 0; font-size: 0.9rem; margin-top: 8px;">
                                Read more
                            </button>
                        <?php endif; ?>

                        <!-- Author Info -->
                        <div class="testimonial-info">
                            <div class="testimonial-name"><?php echo sanitize($fb['name']); ?></div>
                            <?php if (!empty($fb['event_title'])): ?>
                                <div class="testimonial-event"><?php echo sanitize($fb['event_title']); ?></div>
                            <?php endif; ?>
                            <div class="testimonial-time">
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6v6l4 2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span><?php echo $timeAgo; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<script>
let currentTestimonialIndex = 0;
let autoPlayInterval = null;
const cardsPerView = 4;
let testimonialsCarousel = null;
let testimonialCards = null;
let touchStartX = 0;
let touchEndX = 0;

function showTestimonials(index) {
    if (!testimonialsCarousel || !testimonialCards || testimonialCards.length === 0) return;
    
    const maxIndex = Math.max(0, testimonialCards.length - cardsPerView);
    
    // Infinite loop: wrap around
    if (index > maxIndex) {
        currentTestimonialIndex = 0; // Loop back to start
    } else if (index < 0) {
        currentTestimonialIndex = maxIndex; // Loop to end
    } else {
        currentTestimonialIndex = index;
    }
    
    const offset = (currentTestimonialIndex * 100) / cardsPerView;
    testimonialsCarousel.style.transform = `translateX(-${offset}%)`;
}

function clearAutoPlay() {
    if (autoPlayInterval) {
        clearInterval(autoPlayInterval);
        autoPlayInterval = null;
    }
}

function startAutoPlay() {
    clearAutoPlay();
    autoPlayInterval = setInterval(() => {
        if (!testimonialCards || testimonialCards.length === 0) return;
        const maxIndex = Math.max(0, testimonialCards.length - cardsPerView);
        if (currentTestimonialIndex < maxIndex) {
            showTestimonials(currentTestimonialIndex + 1);
        } else {
            showTestimonials(0); // Loop back to start
        }
    }, 5000); // Change slide every 5 seconds
}

// Toggle read more/less functionality
function toggleReadMore(event) {
    const btn = event.target;
    const card = btn.closest('.testimonial-card-new');
    const textEl = card.querySelector('.testimonial-text');
    const isExpanded = btn.classList.contains('expanded');
    
    if (isExpanded) {
        // Collapse - show truncated text
        textEl.textContent = textEl.dataset.truncatedText;
        btn.textContent = 'Read more';
        btn.classList.remove('expanded');
        card.classList.remove('expanded');
    } else {
        // Expand - show full text
        textEl.textContent = textEl.dataset.fullText;
        btn.textContent = 'Read less';
        btn.classList.add('expanded');
        card.classList.add('expanded');
    }
    
    // Cancel auto-play while reading
    clearAutoPlay();
}

// Touch/Swipe support
function handleTouchStart(e) {
    touchStartX = e.changedTouches[0].screenX;
}

function handleTouchEnd(e) {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
}

function handleSwipe() {
    if (!testimonialsCarousel) return;
    
    const swipeThreshold = 50; // Minimum swipe distance
    const diff = touchStartX - touchEndX;
    
    if (Math.abs(diff) > swipeThreshold) {
        clearAutoPlay();
        if (diff > 0) {
            // Swiped left - show next
            showTestimonials(currentTestimonialIndex + 1);
        } else {
            // Swiped right - show previous
            showTestimonials(currentTestimonialIndex - 1);
        }
        startAutoPlay();
    }
}

// Global functions called from button onclick handlers
function prevTestimonial() {
    clearAutoPlay();
    showTestimonials(currentTestimonialIndex - 1);
    startAutoPlay();
}

function nextTestimonial() {
    clearAutoPlay();
    showTestimonials(currentTestimonialIndex + 1);
    startAutoPlay();
}

function initCarousel() {
    testimonialsCarousel = document.getElementById('testimonialsCarousel');
    if (!testimonialsCarousel) return;
    
    testimonialCards = document.querySelectorAll('.testimonial-card-new');
    if (testimonialCards.length === 0) return;
    
    // Add smooth transition CSS
    testimonialsCarousel.style.display = 'flex';
    testimonialsCarousel.style.transition = 'transform 0.6s ease-in-out';
    
    // Add touch/swipe support
    testimonialsCarousel.addEventListener('touchstart', handleTouchStart, false);
    testimonialsCarousel.addEventListener('touchend', handleTouchEnd, false);
    
    // Initialize carousel
    showTestimonials(0);
    startAutoPlay();
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCarousel);
} else {
    initCarousel();
}
</script>

<?php endif; ?>

<!-- Certificate Verification Section -->
<section class="section" id="verify-certificate">
    <div class="container">
        <div class="cert-verify-wrapper">
            <div class="cert-verify-content">
                <div class="cert-verify-icon">
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <h2 class="cert-verify-title">Verify Your Certificate</h2>
                <p class="cert-verify-desc">Enter your certificate number to verify authenticity, view details, and download your KESA certificate.</p>
                
                <form id="certVerifyForm" class="cert-verify-form" onsubmit="verifyCertificate(event)">
                    <div class="cert-input-group">
                        <input 
                            type="text" 
                            id="certNumberInput" 
                            class="cert-input" 
                            placeholder="Enter certificate number (e.g., KESA-2024-001234)" 
                            required
                            autocomplete="off"
                        >
                        <button type="submit" class="cert-verify-btn" id="certVerifyBtn">
                            <span class="btn-text">Verify</span>
                            <span class="btn-loader" style="display: none;">
                                <svg class="spinner" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="32" stroke-dashoffset="32">
                                        <animate attributeName="stroke-dashoffset" values="32;0;32" dur="1.2s" repeatCount="indefinite"/>
                                    </circle>
                                </svg>
                            </span>
                        </button>
                    </div>
                    <p class="cert-hint">Your certificate number is printed on your certificate</p>
                </form>
            </div>
            
            <div class="cert-verify-visual">
                <div class="cert-preview-card">
                    <div class="cert-preview-badge">
                        <svg width="32" height="32" fill="none" stroke="var(--green)" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div class="cert-preview-lines">
                        <div class="line line-1"></div>
                        <div class="line line-2"></div>
                        <div class="line line-3"></div>
                    </div>
                    <div class="cert-preview-seal"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
/* Testimonial Card Expanded State */
.testimonial-card-new {
    transition: all 0.4s ease;
}

.testimonial-card-new.expanded {
    min-height: auto;
}

.testimonial-card-new.expanded .testimonial-text {
    display: block;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    line-height: 1.6;
}

/* Read More Button */
.read-more-btn {
    color: var(--primary, #2563eb);
    font-weight: 600;
    padding: 0;
    font-size: 0.9rem;
    margin-top: 8px;
    background: none;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-block;
}

.read-more-btn:hover {
    color: var(--primary-hover, #1d4ed8);
    text-decoration: underline;
}

/* Certificate Verification Section */
.cert-verify-wrapper {
    display: flex;
    align-items: center;
    gap: 60px;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 50%, #bbf7d0 100%);
    border-radius: 24px;
    padding: 48px;
    position: relative;
    overflow: hidden;
}

.cert-verify-wrapper::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(34, 197, 94, 0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.cert-verify-content {
    flex: 1.2;
    position: relative;
    z-index: 1;
}

.cert-verify-visual {
    flex: 0.8;
    display: flex;
    justify-content: center;
    align-items: center;
}

.cert-verify-icon {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(34, 197, 94, 0.3);
}

.cert-verify-title {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 12px;
}

.cert-verify-desc {
    font-size: 1.05rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin-bottom: 28px;
    max-width: 480px;
}

.cert-verify-form {
    max-width: 500px;
}

.cert-input-group {
    display: flex;
    gap: 12px;
    background: white;
    padding: 8px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.cert-input {
    flex: 1;
    border: none;
    padding: 16px 20px;
    font-size: 1rem;
    background: transparent;
    outline: none;
    color: var(--text-primary);
}

.cert-input::placeholder {
    color: var(--text-muted);
}

.cert-verify-btn {
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    color: white;
    border: none;
    padding: 16px 32px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 120px;
}

.cert-verify-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
}

.cert-verify-btn:active {
    transform: translateY(0);
}

.cert-verify-btn .spinner {
    width: 24px;
    height: 24px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    100% { transform: rotate(360deg); }
}

.cert-hint {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-top: 12px;
    padding-left: 4px;
}

/* Certificate Preview Card */
.cert-preview-card {
    width: 220px;
    height: 280px;
    background: white;
    border-radius: 16px;
    padding: 24px;
    position: relative;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    transform: rotate(3deg);
    transition: transform 0.3s ease;
}

.cert-preview-card:hover {
    transform: rotate(0deg) scale(1.02);
}

.cert-preview-badge {
    width: 56px;
    height: 56px;
    background: #dcfce7;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.cert-preview-lines {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
}

.cert-preview-lines .line {
    height: 10px;
    background: #f1f5f9;
    border-radius: 5px;
}

.cert-preview-lines .line-1 { width: 80%; margin: 0 auto; }
.cert-preview-lines .line-2 { width: 60%; margin: 0 auto; }
.cert-preview-lines .line-3 { width: 90%; margin: 0 auto; }

.cert-preview-seal {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    border-radius: 50%;
    position: absolute;
    bottom: 20px;
    right: 20px;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.cert-preview-seal::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 40px;
    height: 40px;
    border: 2px dashed rgba(255,255,255,0.5);
    border-radius: 50%;
}

/* Responsive */
@media (max-width: 900px) {
    .cert-verify-wrapper {
        flex-direction: column;
        text-align: center;
        padding: 36px 24px;
    }
    
    .cert-verify-content {
        order: 1;
    }
    
    .cert-verify-visual {
        order: 0;
        margin-bottom: 20px;
    }
    
    .cert-verify-desc {
        margin-left: auto;
        margin-right: auto;
    }
    
    .cert-verify-icon {
        margin-left: auto;
        margin-right: auto;
    }
    
    .cert-preview-card {
        width: 180px;
        height: 230px;
        padding: 20px;
    }
    
    .cert-preview-seal {
        width: 48px;
        height: 48px;
        bottom: 16px;
        right: 16px;
    }
    
    .cert-preview-seal::after {
        width: 32px;
        height: 32px;
    }
}

@media (max-width: 600px) {
    .cert-input-group {
        flex-direction: column;
        padding: 12px;
    }
    
    .cert-verify-btn {
        width: 100%;
    }
    
    .cert-verify-visual {
        display: none;
    }
    
    .cert-verify-title {
        font-size: 1.6rem;
    }
}

/* WhatsApp Button Styling */
.btn-whatsapp {
    background: linear-gradient(135deg, #25D366 0%, #128C7E 100%) !important;
    color: #fff !important;
    border: none !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-weight: 600;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3) !important;
}

.btn-whatsapp:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 25px rgba(37, 211, 102, 0.4) !important;
    background: linear-gradient(135deg, #20BA5B 0%, #0D8659 100%) !important;
}

.btn-whatsapp:active {
    transform: translateY(0) !important;
}

/* WhatsApp Chat Widget */
.whatsapp-widget {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 999;
    font-family: inherit;
}

.whatsapp-bubble {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(37, 211, 102, 0.35);
    transition: all 0.3s ease;
    text-decoration: none;
    animation: float-animation 3s ease-in-out infinite;
}

.whatsapp-bubble:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 30px rgba(37, 211, 102, 0.45);
}

.whatsapp-bubble:active {
    transform: scale(0.95);
}

.whatsapp-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.whatsapp-tooltip {
    position: absolute;
    bottom: 80px;
    right: 0;
    background: white;
    color: #333;
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 0.9rem;
    font-weight: 500;
    white-space: nowrap;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    opacity: 0;
    visibility: hidden;
    transform: translateY(10px);
    transition: all 0.3s ease;
    pointer-events: none;
}

.whatsapp-widget:hover .whatsapp-tooltip {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

@keyframes float-animation {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}

@media (max-width: 768px) {
    .whatsapp-widget {
        bottom: 16px;
        right: 16px;
    }
    
    .whatsapp-bubble {
        width: 56px;
        height: 56px;
    }
    
    .whatsapp-bubble svg {
        width: 28px;
        height: 28px;
    }
    
    .whatsapp-tooltip {
        display: none;
    }
}
</style>

<script>
function verifyCertificate(e) {
    e.preventDefault();
    
    var certNumber = document.getElementById('certNumberInput').value.trim();
    if (!certNumber) {
        alert('Please enter a certificate number');
        return;
    }
    
    var btn = document.getElementById('certVerifyBtn');
    var btnText = btn.querySelector('.btn-text');
    var btnLoader = btn.querySelector('.btn-loader');
    
    // Show loader
    btnText.style.display = 'none';
    btnLoader.style.display = 'inline-flex';
    btn.disabled = true;
    
    // Simulate verification delay for smooth UX
    setTimeout(function() {
        window.location.href = '/certificate/verify?code=' + encodeURIComponent(certNumber);
    }, 800);
}
</script>

<!-- CTA Section -->
<section class="section" id="contact" style="background: linear-gradient(135deg, var(--text-primary) 0%, #2d2d4a 100%); color: #fff;">
    <div class="container text-center">
        <h2 style="color: #fff; margin-bottom: 16px;">Ready to Enhance Your Skills?</h2>
        <p style="color: #c7c9d9; font-size: 1.1rem; max-width: 500px; margin: 0 auto 32px;">
            Join thousands of learners who are already growing with KESA. Sign up today and start your learning journey.
        </p>
        <div style="display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;">
            <a href="/auth/signup" class="btn btn-primary btn-lg">Get Started Free</a>
            <a href="/events/" class="btn btn-lg" style="background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);">Browse Events</a>
        </div>
    </div>
</section>

<!-- ISO Certifications Section -->
<section class="section" style="background: #f8fafc; padding: 48px 20px; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
    <div class="container">
        <div style="display: flex; flex-direction: column; align-items: center; gap: 32px;">
            <!-- ISO Logos Row -->
            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 32px; max-width: 1000px; width: 100%;">
                <!-- Quality Certification Badge -->
                <div style="display: flex; align-items: center; justify-content: center; min-width: 90px;">
                    <img src="/images/badges/quality-certification.png" alt="Quality Certification" style="height: 100px; width: auto; object-fit: contain;">
                </div>
                
                <!-- UASL Quality Management Badge -->
                <div style="display: flex; align-items: center; justify-content: center; min-width: 90px;">
                    <img src="/images/badges/uasl-quality-management.png" alt="UASL Quality Management 007" style="height: 100px; width: auto; object-fit: contain;">
                </div>
                
                <!-- Quality Control Certified Badge -->
                <div style="display: flex; align-items: center; justify-content: center; min-width: 90px;">
                    <img src="/images/badges/quality-control-certified.png" alt="Quality Control Certified" style="height: 100px; width: auto; object-fit: contain;">
                </div>
                
                <!-- ISO 9001:2015 Certified Badge -->
                <div style="display: flex; align-items: center; justify-content: center; min-width: 90px;">
                    <img src="/images/badges/iso-9001-2015.png" alt="ISO 9001:2015 Certified" style="height: 100px; width: auto; object-fit: contain;">
                </div>
            </div>
            
            <!-- ISO Certification Text -->
            <div style="text-align: center; max-width: 600px;">
                <p style="font-size: 1rem; color: var(--text-primary); line-height: 1.6; margin: 0;">
                    <strong>Labinc Education Pvt Ltd</strong> | An ISO 9001:2015 Certified Company | 
                    <a href="https://uasl.uk.com/certifiedorganization/" target="_blank" rel="noopener" style="color: var(--blue); text-decoration: none; font-weight: 600; transition: all 0.3s ease;">ISO UASL Cert. No. QMS/C67E/0426</a>
                </p>
            </div>
        </div>
    </div>
    
    <style>
        @media (max-width: 768px) {
            .iso-certifications-row {
                display: flex;
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                gap: 16px;
                padding: 0 8px;
                margin-bottom: 24px;
            }
            
            .iso-badge-item {
                flex: 0 0 auto;
                min-width: 90px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</section>
<?php if (!empty($whatsappChatLink)): ?>
<div class="whatsapp-widget">
    <a href="<?php echo sanitize($whatsappChatLink); ?>" target="_blank" rel="noopener" class="whatsapp-bubble" title="Chat with us on WhatsApp">
        <svg class="whatsapp-icon" xmlns="http://www.w3.org/2000/svg" shape-rendering="geometricPrecision" text-rendering="geometricPrecision" image-rendering="optimizeQuality" fill-rule="evenodd" clip-rule="evenodd" viewBox="0 0 510 512.459"><path fill="white" d="M435.689 74.468C387.754 26.471 324 .025 256.071 0 116.098 0 2.18 113.906 2.131 253.916c-.024 44.758 11.677 88.445 33.898 126.946L0 512.459l134.617-35.311c37.087 20.238 78.85 30.891 121.345 30.903h.109c139.949 0 253.88-113.917 253.928-253.928.024-67.855-26.361-131.645-74.31-179.643v-.012zm-179.618 390.7h-.085c-37.868-.011-75.016-10.192-107.428-29.417l-7.707-4.577-79.886 20.953 21.32-77.889-5.017-7.987c-21.125-33.605-32.29-72.447-32.266-112.322.049-116.366 94.729-211.046 211.155-211.046 56.373.025 109.364 22.003 149.214 61.903 39.853 39.888 61.781 92.927 61.757 149.313-.05 116.377-94.728 211.058-211.057 211.058v.011zm115.768-158.067c-6.344-3.178-37.537-18.52-43.358-20.639-5.82-2.119-10.044-3.177-14.27 3.178-4.225 6.357-16.388 20.651-20.09 24.875-3.702 4.238-7.403 4.762-13.747 1.583-6.343-3.178-26.787-9.874-51.029-31.487-18.86-16.827-31.597-37.598-35.297-43.955-3.702-6.355-.39-9.789 2.775-12.943 2.849-2.848 6.344-7.414 9.522-11.116s4.225-6.355 6.343-10.581c2.12-4.238 1.06-7.937-.522-11.117-1.584-3.177-14.271-34.409-19.568-47.108-5.151-12.37-10.385-10.69-14.269-10.897-3.703-.183-7.927-.219-12.164-.219s-11.105 1.582-16.925 7.939c-5.82 6.354-22.209 21.709-22.209 52.927 0 31.22 22.733 61.405 25.911 65.642 3.177 4.237 44.745 68.318 108.389 95.812 15.135 6.538 26.957 10.446 36.175 13.368 15.196 4.834 29.027 4.153 39.96 2.52 12.19-1.825 37.54-15.353 42.824-30.172 5.283-14.818 5.283-27.529 3.701-30.172-1.582-2.641-5.819-4.237-12.163-7.414l.011-.024z"/></svg>
        <div class="whatsapp-tooltip">Chat with us</div>
    </a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
