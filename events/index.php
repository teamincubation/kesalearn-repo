<?php
/**
 * KESA Learn - Events Listing Page
 */
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

// Filters
$type = $_GET['type'] ?? 'all';
$search = sanitize($_GET['q'] ?? '');
$loadAll = isset($_GET['all']) && $_GET['all'] === '1';
$initialLimit = 12; // Show 12 initially

// Build query
$where = ["e.status = 'published'"];
$params = [];

if ($type !== 'all' && in_array($type, ['webinar', 'workshop', 'offline', 'course'])) {
    $where[] = "e.type = ?";
    $params[] = $type;
}

if (!empty($search)) {
    $where[] = "(e.title LIKE ? OR e.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = implode(' AND ', $where);

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) FROM events e WHERE $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

// Fetch events - upcoming first (by start_date ASC), then completed (by start_date DESC)
$limitClause = $loadAll ? '' : "LIMIT $initialLimit";
$stmt = $db->prepare("
    SELECT e.*, 
           CASE WHEN e.start_date > NOW() THEN 0 ELSE 1 END as is_past
    FROM events e 
    WHERE $whereClause 
    ORDER BY is_past ASC, 
             CASE WHEN e.start_date > NOW() THEN e.start_date END ASC,
             CASE WHEN e.start_date <= NOW() THEN e.start_date END DESC
    $limitClause
");
$stmt->execute($params);
$events = $stmt->fetchAll();

$pageTitle = 'Events';
include __DIR__ . '/../includes/header.php';
?>

<section style="padding: 100px 0 40px; background: var(--bg-secondary);">
    <div class="container">
        <div class="section-header" style="margin-bottom: 0;">
            <h1>Explore Events</h1>
            <p>Discover workshops, webinars, and learning sessions tailored for you</p>
        </div>
    </div>
</section>

<section class="section" style="padding-top: 32px;">
    <div class="container">
        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group">
                <a href="?type=all" class="filter-btn <?php echo $type === 'all' ? 'active' : ''; ?>">All Types</a>
                <a href="?type=webinar" class="filter-btn <?php echo $type === 'webinar' ? 'active' : ''; ?>">Webinars</a>
                <a href="?type=workshop" class="filter-btn <?php echo $type === 'workshop' ? 'active' : ''; ?>">Workshops</a>
                <a href="?type=offline" class="filter-btn <?php echo $type === 'offline' ? 'active' : ''; ?>">Offline</a>
                <a href="?type=course" class="filter-btn <?php echo $type === 'course' ? 'active' : ''; ?>">Courses</a>
            </div>
            
            <form method="GET" style="margin-left: auto; display: flex; gap: 8px;">
                <input type="hidden" name="type" value="<?php echo sanitize($type); ?>">
                <input type="text" name="q" class="form-control" placeholder="Search events..." value="<?php echo sanitize($search); ?>" style="width: 220px; padding: 8px 16px;">
                <button type="submit" class="btn btn-sm btn-blue">Search</button>
            </form>
        </div>
        
        <!-- Events Grid -->
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <h3>No events found</h3>
                <p>There are no <?php echo $type !== 'all' ? $type : ''; ?> events at the moment. Check back soon!</p>
                <a href="/events/" class="btn btn-primary">Clear Filters</a>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php 
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
                    ['gradient' => 'linear-gradient(135deg, #ecfeff 0%, #cffafe 100%)', 'icon' => '#06b6d4'],
                    ['gradient' => 'linear-gradient(135deg, #fef2f2 0%, #fecaca 100%)', 'icon' => '#ef4444'],
                ];
                
                foreach ($events as $event): 
                    // Get consistent pastel color based on event ID
                    $pastelIndex = $event['id'] % count($pastelColors);
                    $pastel = $pastelColors[$pastelIndex];
                ?>
                    <a href="/events/detail?id=<?php echo $event['id']; ?>" class="card event-card" data-type="<?php echo $event['type']; ?>" style="position: relative;">
                        <?php if (!empty($event['is_new'])): ?>
                            <span class="new-label-badge">NEW</span>
                        <?php endif; ?>
                        <?php if ($event['banner_image']): ?>
                            <img src="/uploads/banners/<?php echo sanitize($event['banner_image']); ?>" alt="<?php echo sanitize($event['title']); ?>" class="card-image">
                        <?php else: ?>
                            <div class="card-image pastel-banner" style="background: <?php echo $pastel['gradient']; ?>; display: flex; align-items: center; justify-content: center;">
                                <svg width="48" height="48" fill="none" stroke="<?php echo $pastel['icon']; ?>" viewBox="0 0 24 24" style="opacity: 0.6;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap;">
                                <?php echo getEventTypeBadge($event['type']); ?>
                                <?php if ($event['is_free']): ?>
                                    <span class="badge badge-success">Free</span>
                                <?php endif; ?>
                                <?php if ($event['is_online']): ?>
                                    <span class="badge badge-info">Online</span>
                                <?php endif; ?>
                            </div>
                            <h3 class="card-title"><?php echo sanitize($event['title']); ?></h3>
                            <p class="card-text"><?php echo sanitize(truncateText($event['short_description'] ?? $event['description'], 120)); ?></p>
                            <div class="card-meta">
                                <span class="event-meta-item">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    <?php echo formatDate($event['start_date']); ?>
                                </span>
                                <?php if (!$event['is_online'] && $event['venue']): ?>
                                    <span class="event-meta-item">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <?php echo sanitize(truncateText($event['venue'], 25)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer">
                            <span class="event-price <?php echo $event['is_free'] ? 'free' : ''; ?>">
                                <?php echo formatPrice($event['price'], $event['currency']); ?>
                            </span>
                            <?php if (isEventRegistrationOpen($event)): ?>
                                <span class="btn btn-sm btn-primary">Register</span>
                            <?php elseif ($event['status'] === 'completed' || strtotime($event['start_date']) < time()): ?>
                                <span class="badge badge-completed">Completed</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Closed</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <?php if (!$loadAll && $total > $initialLimit): ?>
            <div style="text-align: center; margin-top: 40px;">
                <a href="?type=<?php echo urlencode($type); ?><?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>&all=1" class="btn btn-outline-primary" style="padding: 14px 40px; font-size: 1rem; border-radius: 50px; display: inline-flex; align-items: center; gap: 10px;">
                    <span>View More Events</span>
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </a>
                <p style="margin-top: 12px; color: var(--text-muted); font-size: 0.9rem;">Showing <?php echo count($events); ?> of <?php echo $total; ?> events</p>
            </div>
            <?php elseif ($loadAll && $total > $initialLimit): ?>
            <div style="text-align: center; margin-top: 40px;">
                <p style="color: var(--text-muted); font-size: 0.9rem;">Showing all <?php echo $total; ?> events</p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<style>
/* Animated Glassmorphism New Badge */
.new-badge-glass {
    position: absolute;
    top: -8px;
    right: -8px;
    z-index: 10;
    padding: 6px 14px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #fff;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.9), rgba(147, 51, 234, 0.9));
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    box-shadow: 
        0 8px 32px rgba(59, 130, 246, 0.3),
        0 0 0 1px rgba(255, 255, 255, 0.1) inset;
    animation: pulseGlow 2s ease-in-out infinite, floatBadge 3s ease-in-out infinite;
}

.new-badge-glass::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(255,255,255,0.4) 0%, transparent 50%);
    pointer-events: none;
}

@keyframes pulseGlow {
    0%, 100% {
        box-shadow: 
            0 8px 32px rgba(59, 130, 246, 0.3),
            0 0 0 1px rgba(255, 255, 255, 0.1) inset;
    }
    50% {
        box-shadow: 
            0 8px 40px rgba(147, 51, 234, 0.5),
            0 0 20px rgba(59, 130, 246, 0.4),
            0 0 0 1px rgba(255, 255, 255, 0.2) inset;
    }
}

@keyframes floatBadge {
    0%, 100% {
        transform: translateY(0) rotate(-2deg);
    }
    50% {
        transform: translateY(-3px) rotate(2deg);
    }
}

.event-card:hover .new-badge-glass {
    animation: pulseGlow 1s ease-in-out infinite, bounceIn 0.3s ease;
}

@keyframes bounceIn {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
