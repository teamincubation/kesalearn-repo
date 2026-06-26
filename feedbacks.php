<?php
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$pageTitle = 'Testimonials';
$page = intval($_GET['page'] ?? 1);
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Get total count
$totalStmt = $db->query("SELECT COUNT(*) FROM feedbacks WHERE is_approved = 1");
$totalCount = $totalStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Fetch testimonials
$feedbackStmt = $db->prepare("
    SELECT f.*, u.profile_image, e.title as event_title 
    FROM feedbacks f 
    LEFT JOIN users u ON f.user_id = u.id 
    LEFT JOIN events e ON f.event_id = e.id 
    WHERE f.is_approved = 1 
    ORDER BY f.created_at DESC 
    LIMIT ? OFFSET ?
");
$feedbackStmt->execute([$perPage, $offset]);
$feedbacks = $feedbackStmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);">
    <div class="container">
        <!-- Page Header -->
        <div class="section-header">
            <h1>What Our Learners Say</h1>
            <p>Join thousands of students and professionals who have benefited from KESA Learn events</p>
        </div>

        <!-- Testimonials Premium Grid -->
        <div class="testimonials-premium-grid">
            <?php if (!empty($feedbacks)): ?>
                <?php foreach ($feedbacks as $fb): 
                    $postedDate = new DateTime($fb['created_at']);
                    $formattedDate = $postedDate->format('M d, Y');
                    $formattedTime = $postedDate->format('h:i A');
                ?>
                    <div class="testimonial-premium-card">
                        <!-- Stars -->
                        <div class="testimonial-premium-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <svg class="star-icon <?php echo $i <= $fb['rating'] ? 'filled' : 'empty'; ?>" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" fill="currentColor"/>
                                </svg>
                            <?php endfor; ?>
                        </div>

                        <!-- Quote Text -->
                        <p class="testimonial-premium-quote">"<?php echo sanitize($fb['feedback_text']); ?>"</p>

                        <!-- Author Section -->
                        <div class="testimonial-premium-author">
                            <div class="testimonial-premium-avatar">
                                <?php if (!empty($fb['profile_image'])): ?>
                                    <?php 
                                    $imgPath = $fb['profile_image'];
                                    if (!filter_var($imgPath, FILTER_VALIDATE_URL) && !preg_match('/^\//', $imgPath)) {
                                        $imgPath = '/uploads/' . ltrim($imgPath, '/');
                                    }
                                    ?>
                                    <img src="<?php echo sanitize($imgPath); ?>" alt="<?php echo sanitize($fb['name']); ?>">
                                <?php else: ?>
                                    <div class="avatar-placeholder"><?php echo strtoupper(substr($fb['name'], 0, 1)); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="testimonial-premium-info">
                                <div class="testimonial-premium-name"><?php echo sanitize($fb['name']); ?></div>
                                <?php if (!empty($fb['role_title'])): ?>
                                    <div class="testimonial-premium-role"><?php echo sanitize($fb['role_title']); ?></div>
                                <?php endif; ?>
                                <div class="testimonial-premium-date">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    <?php echo $formattedDate; ?> at <?php echo $formattedTime; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Event Badge -->
                        <?php if (!empty($fb['event_title'])): ?>
                            <div class="testimonial-premium-event">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M13 10V3L4 14h7v7l9-11h-7z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <?php echo sanitize($fb['event_title']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 80px 20px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="rgba(79, 172, 254, 0.3)" style="margin: 0 auto 20px;">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 20px;">No testimonials available yet.</p>
                    <a href="/events/" class="btn btn-primary">Explore Events</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="testimonials-pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1" class="btn btn-secondary btn-sm" title="First page">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="15 18l-6-6 6-6"></polyline>
                            <polyline points="9 18l-6-6 6-6"></polyline>
                        </svg>
                    </a>
                    <a href="?page=<?php echo $page - 1; ?>" class="btn btn-secondary btn-sm" title="Previous page">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="15 18l-6-6 6-6"></polyline>
                        </svg>
                    </a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <button class="btn btn-primary btn-sm" disabled><?php echo $i; ?></button>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>" class="btn btn-secondary btn-sm"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="btn btn-secondary btn-sm" title="Next page">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="9 18l6-6-6-6"></polyline>
                        </svg>
                    </a>
                    <a href="?page=<?php echo $totalPages; ?>" class="btn btn-secondary btn-sm" title="Last page">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <polyline points="9 18l6-6-6-6"></polyline>
                            <polyline points="15 18l6-6-6-6"></polyline>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
