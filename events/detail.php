<?php
/**
 * KESA Learn - Event Detail Page
 * Uses Event ID for clean URLs, not database slugs
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tracking.php';

$eventId = intval($_GET['id'] ?? 0);
if (!$eventId) {
    setFlash('error', 'Event not found.');
    redirect('/events/');
}

$db = getDB();

// Fetch event by ID
$stmt = $db->prepare("SELECT * FROM events WHERE id = ? AND status = 'published' LIMIT 1");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('error', 'The event you&apos;re looking for could not be found. It may have been removed or the link may be incorrect.');
    redirect('/events/');
}

// Track event page view (with user ID if logged in, otherwise tracks by IP)
$userId = $_SESSION['user_id'] ?? null;
logEventPageView($eventId, $userId);

// Fetch speakers
$speakersStmt = $db->prepare("SELECT * FROM event_speakers WHERE event_id = ? ORDER BY sort_order ASC");
$speakersStmt->execute([$event['id']]);
$speakers = $speakersStmt->fetchAll();

// Fetch instructors
$instructorsStmt = $db->prepare("SELECT i.* FROM instructors i INNER JOIN event_instructors ei ON i.id = ei.instructor_id WHERE ei.event_id = ? ORDER BY ei.sort_order ASC");
$instructorsStmt->execute([$event['id']]);
$instructors = $instructorsStmt->fetchAll();

// Fetch agenda
$agendaStmt = $db->prepare("SELECT ea.*, es.name as speaker_name FROM event_agenda ea LEFT JOIN event_speakers es ON ea.speaker_id = es.id WHERE ea.event_id = ? ORDER BY ea.sort_order ASC");
$agendaStmt->execute([$event['id']]);
$agenda = $agendaStmt->fetchAll();

// Check user registration
$userRegistered = false;
$userRegistration = null;
if (isLoggedIn()) {
    $regStmt = $db->prepare("SELECT * FROM registrations WHERE user_id = ? AND event_id = ?");
    $regStmt->execute([$_SESSION['user_id'], $event['id']]);
    $userRegistration = $regStmt->fetch();
    $userRegistered = !empty($userRegistration);
}

$registrationOpen = isEventRegistrationOpen($event);
$showMaxSeats = getSetting('show_max_seats', '1');
$seatsAvailable = $event['max_seats'] ? ($event['max_seats'] - $event['seats_taken']) : null;
$seatPercentage = $event['max_seats'] ? ($event['seats_taken'] / $event['max_seats']) * 100 : 0;

// Early bird price check (using IST)
$isEarlyBird = false;
$currentPrice = $event['price'];
if (!empty($event['early_bird_price']) && !empty($event['early_bird_start']) && !empty($event['early_bird_end'])) {
    $istTz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $istTz);
    $ebStart = new DateTime($event['early_bird_start'], $istTz);
    $ebEnd = new DateTime($event['early_bird_end'], $istTz);
    if ($now >= $ebStart && $now <= $ebEnd) {
        $isEarlyBird = true;
        $currentPrice = $event['early_bird_price'];
    }
}

// Communication languages
$commLanguages = $event['communication_languages'] ? explode(',', $event['communication_languages']) : [];

$pageTitle = $event['title'];

// Prepare Open Graph meta tags for social media preview
$baseUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$bannerImage = $event['banner_image'] ?? '';
if (!empty($bannerImage) && strpos($bannerImage, 'http') !== 0) {
    $bannerImage = $baseUrl . (strpos($bannerImage, '/') === 0 ? $bannerImage : '/' . $bannerImage);
} else {
    $bannerImage = $baseUrl . '/assets/images/default-event-banner.png';
}

$ogDescription = !empty($event['short_description']) ? substr(sanitize($event['short_description']), 0, 160) : 'Register for this event on KESA Learn';
$eventUrl = $baseUrl . '/events/detail?id=' . $event['id'];

$extraHead = '
<!-- Open Graph Tags for Social Media Preview -->
<meta property="og:title" content="' . htmlspecialchars($event['title']) . '">
<meta property="og:description" content="' . htmlspecialchars($ogDescription) . '">
<meta property="og:image" content="' . htmlspecialchars($bannerImage) . '">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:url" content="' . htmlspecialchars($eventUrl) . '">
<meta property="og:type" content="website">
<meta property="og:site_name" content="KESA Learn">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="' . htmlspecialchars($event['title']) . '">
<meta name="twitter:description" content="' . htmlspecialchars($ogDescription) . '">
<meta name="twitter:image" content="' . htmlspecialchars($bannerImage) . '">
';

include __DIR__ . '/../includes/header.php';
?>

<!-- Event Hero -->
<section class="event-hero">
    <div class="container">
        <div class="event-hero-inner">
            <div class="event-hero-content">
                <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
                    <?php echo getEventTypeBadge($event['type']); ?>
                    <?php echo getEventStatusBadge($event['status']); ?>
                    <?php if ($event['is_online']): ?>
                        <span class="badge badge-info">Online</span>
                    <?php else: ?>
                        <span class="badge badge-warning">In-Person</span>
                    <?php endif; ?>
                    <?php if ($isEarlyBird): ?>
                        <span class="badge" style="background: #fef9e7; color: #d4ad1e;">Early Bird</span>
                    <?php endif; ?>
                </div>
                
                <h1 style="font-size: 2.2rem; margin-bottom: 16px;"><?php echo sanitize($event['title']); ?></h1>
                
                <div style="display: flex; flex-wrap: wrap; gap: 24px; color: var(--text-secondary); font-size: 0.95rem;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <?php echo formatDateTime($event['start_date'], 'M d, Y'); ?>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php echo formatDateTime($event['start_date'], 'h:i A'); ?> - <?php echo formatDateTime($event['end_date'], 'h:i A'); ?>
                    </div>
                    <?php if ($event['venue']): ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                            <?php echo sanitize($event['venue']); ?>
                        </div>
                    <?php endif; ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                        <?php echo sanitize($event['timezone']); ?>
                    </div>
                </div>
                
                <!-- Communication Languages -->
                <?php if (!empty($commLanguages)): ?>
                    <div style="margin-top: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Languages:</span>
                        <?php foreach ($commLanguages as $lang): ?>
                            <span style="font-size: 0.8rem; padding: 4px 10px; background: var(--bg-tertiary); border-radius: 12px;"><?php echo sanitize(trim($lang)); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="event-hero-image">
                <?php if ($event['banner_image']): ?>
                    <img src="/uploads/banners/<?php echo sanitize($event['banner_image']); ?>" alt="<?php echo sanitize($event['title']); ?>">
                <?php else: ?>
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
                    ];
                    $pastelIndex = $event['id'] % count($pastelColors);
                    $pastel = $pastelColors[$pastelIndex];
                    ?>
                    <div style="background: <?php echo $pastel['gradient']; ?>; border-radius: var(--radius-lg); height: 250px; display: flex; align-items: center; justify-content: center;">
                        <svg width="64" height="64" fill="none" stroke="<?php echo $pastel['icon']; ?>" viewBox="0 0 24 24" style="opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Event Content -->
<section style="background: var(--bg-primary);">
    <div class="container">
        <div class="event-detail-grid">
            <!-- Main Content -->
            <div>
                <!-- Description -->
                <div style="margin-bottom: 48px;">
                    <h2 style="margin-bottom: 16px;">About This Event</h2>
                    <div style="color: var(--text-secondary); line-height: 1.8; font-size: 1rem;">
                        <style scoped>
                            .event-description p { margin: 12px 0; }
                            .event-description h1 { font-size: 1.8rem; margin: 20px 0 12px; font-weight: 700; }
                            .event-description h2 { font-size: 1.5rem; margin: 18px 0 10px; font-weight: 700; }
                            .event-description h3 { font-size: 1.2rem; margin: 16px 0 8px; font-weight: 600; }
                            .event-description h4, .event-description h5, .event-description h6 { margin: 12px 0 6px; font-weight: 600; }
                            .event-description ul, .event-description ol { margin: 12px 0; padding-left: 24px; }
                            .event-description li { margin: 6px 0; }
                            .event-description blockquote { margin: 12px 0; padding-left: 16px; border-left: 4px solid var(--blue); background: var(--bg-secondary); padding: 12px 16px; border-radius: 4px; }
                            .event-description code { background: var(--bg-secondary); padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; }
                            .event-description pre { background: var(--bg-secondary); padding: 12px; border-radius: 4px; overflow-x: auto; margin: 12px 0; }
                            .event-description a { color: var(--blue); text-decoration: none; }
                            .event-description a:hover { text-decoration: underline; }
                            .event-description img { max-width: 100%; height: auto; border-radius: 6px; margin: 12px 0; }
                            .event-description table { width: 100%; border-collapse: collapse; margin: 12px 0; }
                            .event-description th, .event-description td { border: 1px solid var(--border-color); padding: 10px; text-align: left; }
                            .event-description th { background: var(--bg-secondary); font-weight: 600; }
                            .event-description mark { background: #fff3cd; padding: 2px 4px; border-radius: 2px; }
                            .event-description hr { margin: 24px 0; border: none; border-top: 1px solid var(--border-color); }
                        </style>
                        <div class="event-description" id="eventDescription">
                            <?php 
                            $description = sanitizeHTML($event['description']);
                            
                            // Extract first paragraph
                            $paragraphs = array_filter(explode('</p>', $description));
                            if (!empty($paragraphs)) {
                                $firstParagraph = $paragraphs[0];
                                // Clean up the paragraph
                                if (strpos($firstParagraph, '<p>') === false) {
                                    $firstParagraph = '<p>' . $firstParagraph;
                                }
                                $firstParagraph .= '</p>';
                            } else {
                                $firstParagraph = $description;
                            }
                            
                            // Check if there are more paragraphs beyond the first
                            $hasMoreContent = count($paragraphs) > 1;
                            
                            echo '<div id="descriptionPreview">' . $firstParagraph . '</div>';
                            
                            if ($hasMoreContent) {
                                echo '<button class="view-full-btn" onclick="expandDescription()" style="margin-top: 16px; padding: 12px 20px; background: var(--blue); color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px;">View Full Details <span style="font-size: 1.1rem;">→</span></button>';
                                echo '<div id="fullDescription" style="display: none; margin-top: 16px;">' . $description . '</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Instructors / Faculty -->
                <?php if (!empty($instructors)): ?>
                    <div style="margin-bottom: 48px;">
                        <h2 style="margin-bottom: 24px;">Faculty / Instructors</h2>
                        <div class="speakers-grid">
                            <?php foreach ($instructors as $ins): ?>
                                <div class="speaker-card">
                                    <?php if (!empty($ins['photo'])): ?>
                                        <img src="/uploads/instructors/<?php echo sanitize($ins['photo']); ?>" alt="<?php echo sanitize($ins['name']); ?>" class="speaker-avatar">
                                    <?php else: ?>
                                        <div class="speaker-avatar" style="font-weight: 700; font-size: 1.2rem; color: var(--blue);">
                                            <?php echo strtoupper(substr($ins['name'], 0, 2)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="speaker-name"><?php echo sanitize($ins['name']); ?></div>
                                    <?php if ($ins['designation']): ?>
                                        <div class="speaker-title"><?php echo sanitize($ins['designation']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($ins['qualification']): ?>
                                        <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px;"><?php echo sanitize($ins['qualification']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($ins['experience']): ?>
                                        <p style="font-size: 0.8rem; color: var(--blue); margin-top: 4px;"><?php echo sanitize($ins['experience']); ?> experience</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Speakers -->
                <?php if (!empty($speakers)): ?>
                    <div style="margin-bottom: 48px;">
                        <h2 style="margin-bottom: 24px;">Speakers</h2>
                        <div class="speakers-grid">
                            <?php foreach ($speakers as $speaker): ?>
                                <div class="speaker-card">
                                    <?php if ($speaker['image']): ?>
                                        <img src="/uploads/speakers/<?php echo sanitize($speaker['image']); ?>" alt="<?php echo sanitize($speaker['name']); ?>" class="speaker-avatar">
                                    <?php else: ?>
                                        <div class="speaker-avatar" style="font-weight: 700; font-size: 1.2rem; color: var(--blue);">
                                            <?php echo strtoupper(substr($speaker['name'], 0, 2)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="speaker-name"><?php echo sanitize($speaker['name']); ?></div>
                                    <?php if ($speaker['title']): ?>
                                        <div class="speaker-title"><?php echo sanitize($speaker['title']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($speaker['bio']): ?>
                                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 8px;"><?php echo sanitize(truncateText($speaker['bio'], 100)); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Agenda -->
                <?php if (!empty($agenda)): ?>
                    <div style="margin-bottom: 48px;">
                        <h2 style="margin-bottom: 24px;">Event Agenda</h2>
                        <div class="agenda-timeline">
                            <?php foreach ($agenda as $item): ?>
                                <div class="agenda-item">
                                    <div class="agenda-time"><?php echo sanitize($item['time']); ?></div>
                                    <div class="agenda-title"><?php echo sanitize($item['title']); ?></div>
                                    <?php if ($item['description']): ?>
                                        <div class="agenda-desc"><?php echo sanitize($item['description']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($item['speaker_name']): ?>
                                        <div style="font-size: 0.85rem; color: var(--purple); margin-top: 4px;">
                                            By <?php echo sanitize($item['speaker_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <div class="event-sidebar">
<!-- Countdown Timer (IST-aware) -->
  <?php 
  $istTzCountdown = new DateTimeZone('Asia/Kolkata');
  $nowIstCountdown = new DateTime('now', $istTzCountdown);
  $eventStartIst = new DateTime($event['start_date'], $istTzCountdown);
  if ($eventStartIst > $nowIstCountdown): 
  // Format as ISO 8601 with IST offset for JavaScript
  $isoStartDate = $eventStartIst->format('Y-m-d\TH:i:s+05:30');
  ?>
  <div class="sidebar-card">
  <h4 style="margin-bottom: 8px; text-align: center;">Event Starts In</h4>
  <div class="countdown-timer" data-countdown="<?php echo $isoStartDate; ?>">
                            <div class="countdown-item days">
                                <div class="number">00</div>
                                <div class="label">Days</div>
                            </div>
                            <div class="countdown-item hours">
                                <div class="number">00</div>
                                <div class="label">Hours</div>
                            </div>
                            <div class="countdown-item minutes">
                                <div class="number">00</div>
                                <div class="label">Mins</div>
                            </div>
                            <div class="countdown-item seconds">
                                <div class="number">00</div>
                                <div class="label">Secs</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Registration Card -->
                <div class="sidebar-card">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <?php if ($isEarlyBird): ?>
                            <div style="font-size: 0.9rem; text-decoration: line-through; color: var(--text-muted);">
                                <?php echo formatPrice($event['price'], $event['currency']); ?>
                            </div>
                            <div style="font-size: 2rem; font-weight: 800; color: #0d7a42;">
                                <?php echo formatPrice($currentPrice, $event['currency']); ?>
                            </div>
                            <p style="background: #fef9e7; color: #d4ad1e; font-weight: 600; font-size: 0.85rem; padding: 6px 12px; border-radius: 20px; display: inline-block; margin-top: 8px;">
                                Early Bird Offer!
                            </p>
                            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px;">
                                Ends: <?php echo formatDateTime($event['early_bird_end'], 'M d, h:i A'); ?>
                            </p>
                        <?php else: ?>
                            <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary);">
                                <?php echo formatPrice($currentPrice, $event['currency']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($event['is_free']): ?>
                            <p style="color: #0d7a42; font-weight: 600; font-size: 0.9rem;">No payment required</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Seats -->
                    <?php if ($event['max_seats'] && $showMaxSeats === '1'): ?>
                        <div data-check-seats="<?php echo $event['id']; ?>" style="margin-bottom: 20px;">
                            <div class="seats-bar">
                                <div class="seats-bar-fill <?php echo $seatPercentage > 80 ? 'almost-full' : ($seatPercentage > 50 ? 'filling' : ''); ?>" style="width: <?php echo $seatPercentage; ?>%"></div>
                            </div>
                            <p class="seats-text">
                                <strong><?php echo $seatsAvailable; ?></strong> seats remaining out of <?php echo $event['max_seats']; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Action Button -->
                    <?php if ($userRegistered): ?>
                        <div style="text-align: center; padding: 16px; background: #e6f9f0; border-radius: var(--radius-sm); margin-bottom: 12px;">
                            <svg width="24" height="24" fill="none" stroke="#0d7a42" viewBox="0 0 24 24" style="margin: 0 auto 8px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p style="color: #0d7a42; font-weight: 600;">You're registered!</p>
                            <p style="color: #6c757d; font-size: 0.85rem; margin-top: 4px;">Status: <?php echo ucfirst($userRegistration['payment_status']); ?></p>
                        </div>
                        <a href="/user/registrations" class="btn btn-secondary btn-full">View Registration</a>
                    <?php elseif ($registrationOpen): ?>
                        <a href="/events/register?id=<?php echo $event['id']; ?>" class="btn btn-primary btn-full btn-lg">Register Now</a>
                        <?php if ($event['registration_deadline']): ?>
                            <p style="text-align: center; font-size: 0.8rem; color: var(--text-muted); margin-top: 8px;">
                                Registration closes <?php echo formatDateTime($event['registration_deadline']); ?>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-full" disabled>Registration Closed</button>
                    <?php endif; ?>
                </div>
                
                <!-- Support Contact Card -->
                <?php if (!empty($event['support_phone']) || !empty($event['support_whatsapp'])): ?>
                    <div class="sidebar-card">
                        <h4 style="margin-bottom: 16px;">Need Help?</h4>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php if (!empty($event['support_phone'])): ?>
                                <a href="tel:<?php echo sanitize($event['support_phone']); ?>" style="display: flex; align-items: center; gap: 10px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-sm); color: var(--text-primary); font-weight: 500;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    Call Support
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($event['support_whatsapp'])): ?>
                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $event['support_whatsapp']); ?>?text=Hi,%20I%20have%20a%20query%20about%20<?php echo urlencode($event['title']); ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #e6f9f0; border-radius: var(--radius-sm); color: #0d7a42; font-weight: 500;">
                                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    WhatsApp Support
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Event Details Card -->
                <div class="sidebar-card">
                    <h4 style="margin-bottom: 16px;">Event Details</h4>
                    <ul style="list-style: none;">
                        <li style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                            <span style="color: var(--text-muted);">Date</span>
                            <strong><?php echo formatDate($event['start_date']); ?></strong>
                        </li>
                        <li style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                            <span style="color: var(--text-muted);">Time</span>
                            <strong><?php echo formatDateTime($event['start_date'], 'h:i A'); ?></strong>
                        </li>
                        <li style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                            <span style="color: var(--text-muted);">Type</span>
                            <strong><?php echo ucfirst($event['type']); ?></strong>
                        </li>
                        <li style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                            <span style="color: var(--text-muted);">Mode</span>
                            <strong><?php echo $event['is_online'] ? 'Online' : 'Offline'; ?></strong>
                        </li>
                        <?php if ($event['venue']): ?>
                            <li style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-muted);">Venue</span>
                                <strong style="text-align: right; max-width: 60%;"><?php echo sanitize($event['venue']); ?></strong>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($commLanguages)): ?>
                            <li style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                                <span style="color: var(--text-muted);">Languages</span>
                                <strong><?php echo sanitize(implode(', ', $commLanguages)); ?></strong>
                            </li>
                        <?php endif; ?>
                        <li style="display: flex; justify-content: space-between; padding: 10px 0;">
                            <span style="color: var(--text-muted);">Timezone</span>
                            <strong><?php echo sanitize($event['timezone']); ?></strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
function expandDescription() {
    const preview = document.getElementById('descriptionPreview');
    const fullDesc = document.getElementById('fullDescription');
    const btn = document.querySelector('.view-full-btn');
    
    if (fullDesc.style.display === 'none') {
        // Expand
        preview.style.display = 'none';
        fullDesc.style.display = 'block';
        btn.textContent = 'Show Less ↑';
    } else {
        // Collapse
        preview.style.display = 'block';
        fullDesc.style.display = 'none';
        btn.textContent = 'View Full Details →';
        // Smooth scroll to button
        btn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
