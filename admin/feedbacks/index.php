<?php
/**
 * KESA Learn - Admin: Feedback Management System
 * Modern professional feedback dashboard with filtering, approval workflow, and testimonial publishing
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$pageTitle = 'Feedback Management';
$adminPage = 'feedbacks';

// Ensure is_read column exists
try {
    $db->exec("ALTER TABLE feedbacks ADD COLUMN IF NOT EXISTS is_read TINYINT DEFAULT 0");
} catch (Exception $e) {
    // Column might already exist
}

// Get filter parameters
$statusFilter = sanitize($_GET['status'] ?? 'all');
$eventFilter = intval($_GET['event'] ?? 0);
$searchQuery = sanitize($_GET['search'] ?? '');

// Build where clause
$where = ['1=1'];
$params = [];

if ($statusFilter === 'pending') {
    $where[] = "f.is_approved = 0 AND COALESCE(f.is_read, 0) = 0";
} elseif ($statusFilter === 'approved') {
    $where[] = "f.is_approved = 1";
} elseif ($statusFilter === 'read') {
    $where[] = "f.is_read = 1 AND f.is_approved = 0";
}

if ($eventFilter > 0) {
    $where[] = "f.event_id = ?";
    $params[] = $eventFilter;
}

if (!empty($searchQuery)) {
    $where[] = "(f.name LIKE ? OR f.feedback_text LIKE ? OR f.role_title LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$whereClause = implode(' AND ', $where);

// Get statistics
try {
    $totalStmt = $db->prepare("SELECT COUNT(*) FROM feedbacks");
    $totalStmt->execute();
    $total = $totalStmt->fetchColumn();
    
    $publishedStmt = $db->prepare("SELECT COUNT(*) FROM feedbacks WHERE is_approved = 1");
    $publishedStmt->execute();
    $published = $publishedStmt->fetchColumn();
    
    $pendingStmt = $db->prepare("SELECT COUNT(*) FROM feedbacks WHERE is_approved = 0 AND COALESCE(is_read, 0) = 0");
    $pendingStmt->execute();
    $pending = $pendingStmt->fetchColumn();
    
    $newStmt = $db->prepare("SELECT COUNT(*) FROM feedbacks WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_approved = 0");
    $newStmt->execute();
    $new = $newStmt->fetchColumn();
} catch (Exception $e) {
    $total = $published = $pending = $new = 0;
}

// Get events for filter
try {
    $eventsStmt = $db->prepare("SELECT id, title FROM events ORDER BY title ASC LIMIT 50");
    $eventsStmt->execute();
    $events = $eventsStmt->fetchAll();
} catch (Exception $e) {
    $events = [];
}

// Get feedbacks
try {
    $feedbacksStmt = $db->prepare("
        SELECT 
            f.id, 
            f.name, 
            f.role_title, 
            f.feedback_text, 
            f.rating,
            f.is_approved,
            COALESCE(f.is_read, 0) as is_read,
            f.event_id,
            f.created_at,
            e.title as event_title
        FROM feedbacks f
        LEFT JOIN events e ON f.event_id = e.id
        WHERE $whereClause
        ORDER BY f.created_at DESC
    ");
    $feedbacksStmt->execute($params);
    $feedbacks = $feedbacksStmt->fetchAll();
} catch (Exception $e) {
    $feedbacks = [];
}

// Handle testimonials visibility toggle
$showTestimonials = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_testimonials'])) {
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Invalid security token.');
    } else {
        try {
            $newValue = $showTestimonials ? 0 : 1;
            
            // Check if setting exists
            $exists = $db->query("SELECT 1 FROM site_settings WHERE setting_key = 'show_testimonials' LIMIT 1")->fetch();
            
            if ($exists) {
                $stmt = $db->prepare("UPDATE site_settings SET value = ?, updated_at = NOW() WHERE setting_key = 'show_testimonials'");
            } else {
                $stmt = $db->prepare("INSERT INTO site_settings (setting_key, value, created_at, updated_at) VALUES ('show_testimonials', ?, NOW(), NOW())");
            }
            
            $stmt->execute([$newValue]);
            $showTestimonials = $newValue == '0' ? false : true;
            
            setFlash('success', $newValue ? 'Testimonials section is now visible on homepage.' : 'Testimonials section is now hidden from homepage.');
        } catch (PDOException $e) {
            setFlash('error', 'Failed to update testimonials setting.');
        }
    }
}

// Get current testimonials visibility setting
try {
    $setting = $db->query("SELECT value FROM site_settings WHERE setting_key = 'show_testimonials' LIMIT 1")->fetch();
    $showTestimonials = $setting ? $setting['value'] == '1' : true;
} catch (PDOException $e) {
    $showTestimonials = true;
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
:root {
    --primary-color: #667eea;
    --success-color: #11998e;
    --danger-color: #f5576c;
    --info-color: #4facfe;
    --warning-color: #f5af19;
}

.feedback-management-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
}

.feedback-header {
    margin-bottom: 32px;
}

.feedback-header h1 {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.feedback-header p {
    color: #666;
    margin: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 4px solid var(--primary-color);
}

.stat-card.success { border-left-color: var(--success-color); }
.stat-card.danger { border-left-color: var(--danger-color); }
.stat-card.info { border-left-color: var(--info-color); }

.stat-card-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.stat-card-label {
    color: #666;
    font-size: 0.9rem;
}

.filters-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.filter-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    font-size: 0.9rem;
}

.filter-group select,
.filter-group input {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 0.9rem;
}

.filter-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.btn-filter {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-filter-apply {
    background: var(--primary-color);
    color: white;
}

.btn-filter-apply:hover {
    opacity: 0.9;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.btn-filter-reset {
    background: #f0f0f0;
    color: #333;
}

.btn-filter-reset:hover {
    background: #e0e0e0;
}

.feedbacks-list {
    display: grid;
    gap: 16px;
}

.feedback-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #eee;
    transition: all 0.3s ease;
}

.feedback-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    border-color: var(--primary-color);
}

.feedback-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
    gap: 16px;
}

.feedback-user {
    display: flex;
    gap: 12px;
    flex: 1;
}

.feedback-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-color), #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}

.feedback-user-info h4 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    font-weight: 600;
}

.feedback-user-info p {
    margin: 0;
    color: #666;
    font-size: 0.85rem;
}

.feedback-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.badge-approved {
    background: rgba(17, 153, 142, 0.15);
    color: #11998e;
}

.badge-pending {
    background: rgba(245, 87, 108, 0.15);
    color: #f5576c;
}

.badge-read {
    background: rgba(79, 172, 254, 0.15);
    color: #4facfe;
}

.badge-rating {
    background: rgba(245, 175, 25, 0.15);
    color: #f5af19;
}

.feedback-meta {
    display: flex;
    gap: 16px;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid #eee;
    font-size: 0.85rem;
    color: #666;
}

.feedback-text {
    color: #333;
    line-height: 1.6;
    margin-bottom: 16px;
    padding: 16px;
    background: #f9f9f9;
    border-left: 3px solid var(--primary-color);
    border-radius: 4px;
}

.feedback-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.btn-action {
    padding: 10px 16px;
    border: none;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-approve {
    background: var(--success-color);
    color: white;
}

.btn-approve:hover {
    opacity: 0.9;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(17, 153, 142, 0.3);
}

.btn-mark-read {
    background: var(--info-color);
    color: white;
}

.btn-mark-read:hover {
    opacity: 0.9;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(79, 172, 254, 0.3);
}

.btn-delete {
    background: rgba(245, 87, 108, 0.15);
    color: #f5576c;
    border: 1px solid #f5576c;
}

.btn-delete:hover {
    background: #f5576c;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245, 87, 108, 0.3);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
}

.empty-state h3 {
    font-size: 1.2rem;
    margin-bottom: 8px;
}

.empty-state p {
    color: #666;
    margin: 0;
}

@media (max-width: 768px) {
    .feedback-card-header {
        flex-direction: column;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-actions {
        flex-direction: column;
    }
    
    .btn-filter {
        width: 100%;
    }
    
    .feedback-actions {
        gap: 8px;
    }
    
    .btn-action {
        padding: 8px 12px;
        font-size: 0.85rem;
    }
}
</style>

<div class="feedback-management-container">
    <div class="feedback-header">
        <h1>Feedback Management</h1>
        <p>Manage user feedbacks and publish them as testimonials on your website</p>
    </div>

    <!-- Testimonials Visibility Toggle -->
    <div style="background: linear-gradient(135deg, rgba(79, 172, 254, 0.1), rgba(147, 51, 234, 0.1)); border: 2px solid rgba(79, 172, 254, 0.2); border-radius: 12px; padding: 24px; margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; gap: 24px;">
        <div>
            <h3 style="margin: 0 0 8px 0; font-size: 1.1rem; color: #333;">Testimonials Section on Homepage</h3>
            <p style="margin: 0; color: #666; font-size: 0.95rem;">Control whether the "What Our Learners Say" section appears on your website homepage.</p>
        </div>
        <div style="display: flex; align-items: center; gap: 16px; white-space: nowrap;">
            <span style="display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 0.9rem; text-transform: uppercase; <?php echo $showTestimonials ? 'background: rgba(74, 222, 128, 0.15); color: #15803d;' : 'background: rgba(239, 68, 68, 0.15); color: #dc2626;'; ?>">
                <?php echo $showTestimonials ? '✓ Visible' : '✗ Hidden'; ?>
            </span>
            <form method="POST" style="margin: 0; display: inline;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="toggle_testimonials" value="1">
                <button type="submit" style="background: #4facfe; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.3s; font-size: 0.95rem;">
                    <?php echo $showTestimonials ? 'Hide from Homepage' : 'Show on Homepage'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-value"><?php echo $total; ?></div>
            <div class="stat-card-label">Total Feedbacks</div>
        </div>
        <div class="stat-card success">
            <div class="stat-card-value"><?php echo $published; ?></div>
            <div class="stat-card-label">Published as Testimonials</div>
        </div>
        <div class="stat-card danger">
            <div class="stat-card-value"><?php echo $pending; ?></div>
            <div class="stat-card-label">Pending Review</div>
        </div>
        <div class="stat-card info">
            <div class="stat-card-value"><?php echo $new; ?></div>
            <div class="stat-card-label">New This Week</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters-section">
        <h3 style="margin-top: 0;">Filters</h3>
        <div class="filters-grid">
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Feedbacks</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                    <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Published</option>
                    <option value="read" <?php echo $statusFilter === 'read' ? 'selected' : ''; ?>>Marked as Read (Draft)</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Event</label>
                <select name="event">
                    <option value="0">All Events</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?php echo $event['id']; ?>" <?php echo $eventFilter === $event['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($event['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Name, role, or feedback..." value="<?php echo sanitize($searchQuery); ?>">
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-filter btn-filter-apply">Apply Filters</button>
            <a href="?status=all" class="btn-filter btn-filter-reset">Reset</a>
        </div>
    </form>

    <!-- Feedbacks List -->
    <div class="feedbacks-list">
        <?php if (empty($feedbacks)): ?>
            <div class="empty-state">
                <h3>No feedbacks found</h3>
                <p>Try adjusting your filters or check back later when new feedbacks arrive</p>
            </div>
        <?php else: ?>
            <?php foreach ($feedbacks as $feedback): ?>
                <div class="feedback-card" data-feedback-id="<?php echo $feedback['id']; ?>">
                    <div class="feedback-card-header">
                        <div class="feedback-user">
                            <div class="feedback-avatar">
                                <?php echo strtoupper(substr($feedback['name'], 0, 1)); ?>
                            </div>
                            <div class="feedback-user-info">
                                <h4><?php echo sanitize($feedback['name']); ?></h4>
                                <p><?php echo !empty($feedback['role_title']) ? sanitize($feedback['role_title']) : 'User'; ?></p>
                            </div>
                        </div>
                        <div class="feedback-badges">
                            <?php if ($feedback['is_approved']): ?>
                                <span class="badge badge-approved">Published</span>
                            <?php elseif ($feedback['is_read']): ?>
                                <span class="badge badge-read">Read (Draft)</span>
                            <?php else: ?>
                                <span class="badge badge-pending">Pending</span>
                            <?php endif; ?>
                            <?php if ($feedback['rating']): ?>
                                <span class="badge badge-rating">★ <?php echo $feedback['rating']; ?>/5</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="feedback-meta">
                        <span><?php echo formatDate($feedback['created_at']); ?></span>
                        <?php if ($feedback['event_title']): ?>
                            <span><?php echo sanitize($feedback['event_title']); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="feedback-text">
                        <?php echo nl2br(sanitize($feedback['feedback_text'])); ?>
                    </div>

                    <div class="feedback-actions">
                        <?php if (!$feedback['is_approved'] && !$feedback['is_read']): ?>
                            <button type="button" class="btn-action btn-approve" onclick="feedbackAction(<?php echo $feedback['id']; ?>, 'approve')">
                                ✓ Approve & Publish
                            </button>
                            <button type="button" class="btn-action btn-mark-read" onclick="feedbackAction(<?php echo $feedback['id']; ?>, 'mark_read')">
                                → Mark as Read
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn-action btn-delete" onclick="feedbackAction(<?php echo $feedback['id']; ?>, 'delete')">
                            🗑 Delete
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
async function feedbackAction(feedbackId, action) {
    if (action === 'delete' && !confirm('Are you sure you want to permanently delete this feedback?')) {
        return;
    }

    const card = document.querySelector(`[data-feedback-id="${feedbackId}"]`);
    if (card) {
        card.style.opacity = '0.6';
        card.style.pointerEvents = 'none';
    }

    try {
        const response = await fetch('./action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: action,
                feedback_id: feedbackId,
                csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
            })
        });

        const data = await response.json();

        if (data.success) {
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            alert(data.message || 'An error occurred');
            if (card) {
                card.style.opacity = '1';
                card.style.pointerEvents = 'auto';
            }
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to process request');
        if (card) {
            card.style.opacity = '1';
            card.style.pointerEvents = 'auto';
        }
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
