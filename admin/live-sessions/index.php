<?php
/**
 * KESA Learn - Admin: Live Sessions Management
 * Schedule and manage live sessions for events
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'live-sessions';
$pageTitle = 'Live Sessions';

// Check if live_sessions table exists, create if not
$tableExists = false;
try {
    $db->query("SELECT 1 FROM live_sessions LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {
    // Table doesn't exist, create it
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS live_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NOT NULL,
                instructor_id INT DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                start_datetime DATETIME NOT NULL,
                end_datetime DATETIME NOT NULL,
                platform ENUM('zoom', 'google_meet', 'teams', 'youtube_live', 'custom') DEFAULT 'google_meet',
                meeting_link VARCHAR(500),
                recording_url VARCHAR(500),
                recording_expiry_date DATE DEFAULT NULL,
                status ENUM('scheduled', 'live', 'completed', 'cancelled') DEFAULT 'scheduled',
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE SET NULL,
                INDEX idx_event (event_id),
                INDEX idx_status (status),
                INDEX idx_start (start_datetime)
            )
        ");
        $tableExists = true;
    } catch (PDOException $createError) {
        setFlash('error', 'Could not create live_sessions table. Please run the migration script first.');
    }
}

// Fetch events for dropdown
$eventsStmt = $db->query("SELECT id, title FROM events WHERE status IN ('published', 'draft') ORDER BY start_date DESC");
$events = $eventsStmt->fetchAll();

// Fetch instructors for dropdown
$instructorsStmt = $db->query("SELECT id, name FROM instructors ORDER BY name");
$instructors = $instructorsStmt->fetchAll();

// Handle bulk delete
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM live_sessions WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        logActivity('live_sessions_bulk_deleted', "Deleted $deleted live sessions");
        setFlash('success', "$deleted session(s) deleted successfully.");
    }
    redirect('/admin/live-sessions/');
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/live-sessions/');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $eventId = intval($_POST['event_id'] ?? 0);
        $instructorId = intval($_POST['instructor_id'] ?? 0) ?: null;
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $startDatetime = $_POST['start_datetime'] ?? '';
        $endDatetime = $_POST['end_datetime'] ?? '';
        $platform = $_POST['platform'] ?? 'google_meet';
        $meetingLink = sanitize($_POST['meeting_link'] ?? '');
        $recordingUrl = sanitize($_POST['recording_url'] ?? '');
        $recordingExpiry = $_POST['recording_expiry'] ?? null;
        $status = $_POST['status'] ?? 'scheduled';
        $reminderSendMinutes = intval($_POST['reminder_send_minutes'] ?? 5);
        
        if (empty($eventId) || empty($title) || empty($startDatetime) || empty($endDatetime)) {
            setFlash('error', 'Event, Title, Start and End time are required.');
            redirect('/admin/live-sessions/');
        }
        
        $recordingExpiry = !empty($recordingExpiry) ? $recordingExpiry : null;
        
        if ($action === 'create') {
            $stmt = $db->prepare("
                INSERT INTO live_sessions (event_id, instructor_id, title, description, start_datetime, end_datetime, platform, meeting_link, recording_url, recording_expiry_date, status, reminder_send_minutes, auto_notification_enabled, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$eventId, $instructorId, $title, $description, $startDatetime, $endDatetime, $platform, $meetingLink, $recordingUrl, $recordingExpiry, $status, $reminderSendMinutes, $reminderSendMinutes > 0, $_SESSION['user_id']]);
            $sessionId = $db->lastInsertId();
            
            logActivity('live_session_created', "Created live session: $title (ID: $sessionId) with $reminderSendMinutes min reminder");
            
            setFlash('success', 'Live session created successfully!');
        } else {
            $stmt = $db->prepare("
                UPDATE live_sessions SET 
                    event_id = ?, instructor_id = ?, title = ?, description = ?, 
                    start_datetime = ?, end_datetime = ?, platform = ?, meeting_link = ?,
                    recording_url = ?, recording_expiry_date = ?, status = ?, reminder_send_minutes = ?, auto_notification_enabled = ?
                WHERE id = ?
            ");
            $stmt->execute([$eventId, $instructorId, $title, $description, $startDatetime, $endDatetime, $platform, $meetingLink, $recordingUrl, $recordingExpiry, $status, $reminderSendMinutes, $reminderSendMinutes > 0, $id]);
            
            logActivity('live_session_updated', "Updated live session: $title (ID: $id)");
            
            setFlash('success', 'Live session updated successfully!');
        }
        redirect('/admin/live-sessions/');
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM live_sessions WHERE id = ?")->execute([$id]);
            logActivity('live_session_deleted', "Deleted live session ID: $id");
            setFlash('success', 'Live session deleted.');
        }
        redirect('/admin/live-sessions/');
    }
    
    if ($action === 'mark_live') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            // Mark as manually started so auto-notification won't be sent
            $db->prepare("UPDATE live_sessions SET status = 'live', is_manually_started = TRUE WHERE id = ?")->execute([$id]);
            logActivity('session_manually_started', "Admin manually started session ID: $id");
            setFlash('success', 'Session marked as LIVE. (No automatic notification sent for manual start)');
        }
        redirect('/admin/live-sessions/');
    }
    
    if ($action === 'mark_completed') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE live_sessions SET status = 'completed' WHERE id = ?")->execute([$id]);
            setFlash('success', 'Session marked as completed.');
        }
        redirect('/admin/live-sessions/');
    }
}

// Helper function to send session notifications
function sendSessionNotifications($sessionId, $type) {
    $db = getDB();
    
    // Get session details
    $sessionStmt = $db->prepare("
        SELECT ls.*, e.title as event_title, i.name as instructor_name
        FROM live_sessions ls
        JOIN events e ON ls.event_id = e.id
        LEFT JOIN instructors i ON ls.instructor_id = i.id
        WHERE ls.id = ?
    ");
    $sessionStmt->execute([$sessionId]);
    $sessionData = $sessionStmt->fetch();
    
    if (!$sessionData) return;
    
    // Get all registered users for this event
    $usersStmt = $db->prepare("
        SELECT u.id, u.email, u.name
        FROM registrations r
        JOIN users u ON r.user_id = u.id
        WHERE r.event_id = ? AND r.payment_status IN ('paid', 'verified')
    ");
    $usersStmt->execute([$sessionData['event_id']]);
    
    // Queue emails using mailer (if available)
    require_once __DIR__ . '/../../includes/mailer.php';
    
    while ($user = $usersStmt->fetch()) {
        // Log notification
        try {
            $notifStmt = $db->prepare("INSERT INTO session_notifications (session_id, user_id, notification_type) VALUES (?, ?, ?)");
            $notifStmt->execute([$sessionId, $user['id'], $type]);
        } catch (Exception $e) {
            // Table might not exist yet
        }
        
        // Send email
        sendSessionEmail($user, $sessionData, $type);
    }
}

function sendSessionEmail($user, $session, $type) {
    $subject = '';
    $message = '';
    
    switch ($type) {
        case 'scheduled':
            $subject = 'New Live Session Scheduled: ' . $session['title'];
            break;
        case 'live':
            $subject = 'Live Now: ' . $session['title'];
            break;
        case 'recording_available':
            $subject = 'Recording Available: ' . $session['title'];
            break;
        default:
            $subject = 'Update: ' . $session['title'];
    }
    
    // Use the mailer system if available
    if (function_exists('sendLiveSessionEmail')) {
        sendLiveSessionEmail($user['email'], $user['name'], $session, $type);
    }
}

// Fetch sessions with filters
$statusFilter = $_GET['status'] ?? '';
$eventFilter = intval($_GET['event'] ?? 0);
$sessions = [];

if ($tableExists) {
    $where = ['1=1'];
    $params = [];

    if (!empty($statusFilter)) {
        $where[] = "ls.status = ?";
        $params[] = $statusFilter;
    }
    if ($eventFilter > 0) {
        $where[] = "ls.event_id = ?";
        $params[] = $eventFilter;
    }

    $whereClause = implode(' AND ', $where);

    try {
        $sessionsStmt = $db->prepare("
            SELECT ls.*, e.title as event_title, i.name as instructor_name
            FROM live_sessions ls
            JOIN events e ON ls.event_id = e.id
            LEFT JOIN instructors i ON ls.instructor_id = i.id
            WHERE $whereClause
            ORDER BY ls.start_datetime DESC
        ");
        $sessionsStmt->execute($params);
        $sessions = $sessionsStmt->fetchAll();
    } catch (PDOException $e) {
        $sessions = [];
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>

<div class="table-header">
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <div class="filter-group">
            <a href="?status=" class="filter-btn <?php echo empty($statusFilter) ? 'active' : ''; ?>">All</a>
            <a href="?status=scheduled" class="filter-btn <?php echo $statusFilter === 'scheduled' ? 'active' : ''; ?>">Scheduled</a>
            <a href="?status=live" class="filter-btn <?php echo $statusFilter === 'live' ? 'active' : ''; ?>">Live</a>
            <a href="?status=completed" class="filter-btn <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>">Completed</a>
        </div>
        <select onchange="window.location.href='?event='+this.value" style="padding: 8px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
            <option value="">All Events</option>
            <?php foreach ($events as $ev): ?>
            <option value="<?php echo $ev['id']; ?>" <?php echo $eventFilter === $ev['id'] ? 'selected' : ''; ?>><?php echo sanitize(truncateText($ev['title'], 40)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Schedule Session
    </button>
</div>

<?php if (empty($sessions)): ?>
<div class="empty-state" style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
    <h3>No live sessions scheduled</h3>
    <p>Create your first live session for an event.</p>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">Schedule Session</button>
</div>
<?php else: ?>
<div class="table-wrapper">
    <table class="table">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th>
                <th>ID</th>
                <th>Session</th>
                <th>Event</th>
                <th>Instructor</th>
                <th>Date & Time</th>
                <th>Platform</th>
                <th>Status</th>
                <th>Reminder</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $session): ?>
            <tr>
                <td><input type="checkbox" class="row-checkbox" value="<?php echo $session['id']; ?>"></td>
                <td><?php echo $session['id']; ?></td>
                <td>
                    <strong><?php echo sanitize($session['title']); ?></strong>
                    <?php if (!empty($session['recording_url'])): ?>
                    <span class="badge badge-success" style="margin-left: 4px;">Recording</span>
                    <?php endif; ?>
                </td>
                <td><?php echo sanitize(truncateText($session['event_title'], 30)); ?></td>
                <td><?php echo $session['instructor_name'] ? sanitize($session['instructor_name']) : '<span style="color: var(--text-muted);">TBA</span>'; ?></td>
                <td>
                    <div style="font-size: 0.9rem;"><?php echo date('M d, Y', strtotime($session['start_datetime'])); ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('h:i A', strtotime($session['start_datetime'])); ?> - <?php echo date('h:i A', strtotime($session['end_datetime'])); ?></div>
                    <div id="countdown-<?php echo $session['id']; ?>" style="font-size: 0.75rem; color: #2563eb; font-weight: 600; margin-top: 4px;">
                        <?php
                        $startTime = new DateTime($session['start_datetime']);
                        $endTime = new DateTime($session['end_datetime']);
                        $now = new DateTime();
                        
                        if ($session['status'] === 'scheduled') {
                            $diff = $startTime->diff($now);
                            if ($diff->invert) {
                                $hours = $diff->h + ($diff->days * 24);
                                $mins = $diff->i;
                                if ($hours > 0) {
                                    echo "<span id='start-{$session['id']}'>Starts in: {$hours}h {$mins}m</span>";
                                } elseif ($mins > 0) {
                                    echo "<span id='start-{$session['id']}'>Starts in: {$mins}m</span>";
                                } else {
                                    echo "<span id='start-{$session['id']}'>Starts in: {$diff->s}s</span>";
                                }
                            } else {
                                echo '<span style="color: #ef4444;">Starting soon...</span>';
                            }
                        } elseif ($session['status'] === 'live') {
                            $diff = $endTime->diff($now);
                            if ($diff->invert) {
                                $hours = $diff->h;
                                $mins = $diff->i;
                                echo "<span id='end-{$session['id']}' style='color: #dc2626; font-weight: 700;'>";
                                if ($hours > 0) {
                                    echo "Ends in: {$hours}h {$mins}m";
                                } else {
                                    echo "Ends in: {$mins}m";
                                }
                                echo "</span>";
                            }
                        }
                        ?>
                    </div>
                </td>
                <td>
                    <?php 
                    $platformIcons = [
                        'google_meet' => '<span style="color: #00897b;">Google Meet</span>',
                        'zoom' => '<span style="color: #2d8cff;">Zoom</span>',
                        'youtube_live' => '<span style="color: #ff0000;">YouTube</span>',
                        'other' => 'Other'
                    ];
                    echo $platformIcons[$session['platform']] ?? $session['platform'];
                    ?>
                </td>
                <td>
                    <?php
                    $statusBadges = [
                        'scheduled' => '<span class="badge badge-info">Scheduled</span>',
                        'live' => '<span class="badge badge-success" style="background: #dc2626; animation: pulse 1s infinite;">LIVE</span>',
                        'completed' => '<span class="badge">Completed</span>',
                        'cancelled' => '<span class="badge badge-danger">Cancelled</span>'
                    ];
                    echo $statusBadges[$session['status']] ?? $session['status'];
                    ?>
                </td>
                <td>
                    <?php
                    // Check if reminder notification was sent
                    $reminderStmt = $db->prepare("
                        SELECT COUNT(*) as count FROM session_notifications 
                        WHERE session_id = ? AND notification_type = 'reminder'
                    ");
                    $reminderStmt->execute([$session['id']]);
                    $reminderResult = $reminderStmt->fetch();
                    $reminderSent = $reminderResult['count'] > 0;
                    
                    // Calculate time until session starts
                    $startTime = new DateTime($session['start_datetime']);
                    $now = new DateTime();
                    $interval = $now->diff($startTime);
                    $minutesUntilStart = (int)($interval->total_seconds / 60);
                    
                    if ($reminderSent) {
                        echo '<span class="badge" style="background: #10b981; color: white;">✓ Sent</span>';
                    } elseif ($session['status'] === 'scheduled' && $minutesUntilStart <= 10 && $minutesUntilStart >= 0) {
                        echo '<span class="badge" style="background: #f59e0b; color: white;">⏱ Pending</span>';
                    } elseif ($session['status'] === 'scheduled' && $minutesUntilStart < 0) {
                        echo '<span class="badge" style="background: #ef4444; color: white;">⏰ Missed</span>';
                    } else {
                        echo '<span class="badge" style="background: #6b7280; color: white;">— Not Yet</span>';
                    }
                    ?>
                </td>
                <td>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick='editSession(<?php echo json_encode($session); ?>)' title="Edit">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                        <?php if ($session['status'] === 'scheduled'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="mark_live">
                            <input type="hidden" name="id" value="<?php echo $session['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Go Live" onclick="return confirm('Mark this session as LIVE? Notifications will be sent.')">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </button>
                        </form>
                        <?php elseif ($session['status'] === 'live'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="mark_completed">
                            <input type="hidden" name="id" value="<?php echo $session['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-secondary" title="End Session">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if (!empty($session['meeting_link'])): ?>
                        <a href="<?php echo sanitize($session['meeting_link']); ?>" target="_blank" class="btn btn-sm btn-info" title="Open Meeting">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        </a>
                        <?php endif; ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this session?')">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $session['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Create/Edit Session Modal -->
<div id="sessionModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3 id="modalTitle">Schedule Live Session</h3>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" id="sessionForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="sessionId" value="">
            
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="event_id">Event *</label>
                        <select name="event_id" id="event_id" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="">-- Select Event --</option>
                            <?php foreach ($events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>"><?php echo sanitize($ev['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="title">Session Title *</label>
                        <input type="text" name="title" id="title" required placeholder="e.g., Introduction to Research Methods" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                    
                    <div class="form-group">
                        <label for="instructor_id">Instructor</label>
                        <select name="instructor_id" id="instructor_id" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="">-- Select Instructor --</option>
                            <?php foreach ($instructors as $inst): ?>
                            <option value="<?php echo $inst['id']; ?>"><?php echo sanitize($inst['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="platform">Platform *</label>
                        <select name="platform" id="platform" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="google_meet">Google Meet</option>
                            <option value="zoom">Zoom</option>
                            <option value="youtube_live">YouTube Live</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="start_datetime">Start Date & Time *</label>
                        <input type="datetime-local" name="start_datetime" id="start_datetime" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_datetime">End Date & Time *</label>
                        <input type="datetime-local" name="end_datetime" id="end_datetime" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="meeting_link">Meeting Link</label>
                        <input type="url" name="meeting_link" id="meeting_link" placeholder="https://meet.google.com/xxx-xxxx-xxx" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" rows="3" placeholder="Brief description of what will be covered..." style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="scheduled">Scheduled</option>
                            <option value="live">Live</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="reminder_send_minutes">Reminder Email (minutes before start)</label>
                        <select name="reminder_send_minutes" id="reminder_send_minutes" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="5" selected>5 minutes before</option>
                            <option value="10">10 minutes before</option>
                            <option value="15">15 minutes before</option>
                            <option value="30">30 minutes before</option>
                            <option value="1">1 hour before</option>
                            <option value="0">Don't send reminder</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                    <h4 style="margin-bottom: 12px; font-size: 0.95rem;">Recording (After Session)</h4>
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label for="recording_url">Recording URL</label>
                            <input type="url" name="recording_url" id="recording_url" placeholder="https://youtube.com/watch?v=..." style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                        </div>
                        <div class="form-group">
                            <label for="recording_expiry">Access Until</label>
                            <input type="date" name="recording_expiry" id="recording_expiry" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Schedule Session</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}
.modal-content {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 50px rgba(0,0,0,0.3);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
}
.modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
}
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-muted);
}
.modal-body {
    padding: 24px;
}
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}
.form-group {
    margin-bottom: 0;
}
.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 0.85rem;
}
</style>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Schedule Live Session';
    document.getElementById('formAction').value = 'create';
    document.getElementById('sessionId').value = '';
    document.getElementById('submitBtn').textContent = 'Schedule Session';
    document.getElementById('sessionForm').reset();
    document.getElementById('sessionModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function editSession(session) {
    document.getElementById('modalTitle').textContent = 'Edit Live Session';
    document.getElementById('formAction').value = 'update';
    document.getElementById('sessionId').value = session.id;
    document.getElementById('submitBtn').textContent = 'Update Session';
    
    document.getElementById('event_id').value = session.event_id;
    document.getElementById('title').value = session.title;
    document.getElementById('instructor_id').value = session.instructor_id || '';
    document.getElementById('platform').value = session.platform;
    document.getElementById('start_datetime').value = session.start_datetime.replace(' ', 'T').slice(0, 16);
    document.getElementById('end_datetime').value = session.end_datetime.replace(' ', 'T').slice(0, 16);
    document.getElementById('meeting_link').value = session.meeting_link || '';
    document.getElementById('description').value = session.description || '';
    document.getElementById('status').value = session.status;
    document.getElementById('recording_url').value = session.recording_url || '';
    document.getElementById('recording_expiry').value = session.recording_expiry_date || '';
    
    document.getElementById('sessionModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('sessionModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('sessionModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>session(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="sessions">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            Delete Selected
        </button>
    </div>
</div>

<form id="bulkDeleteForm" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="bulk_delete" value="1">
    <input type="hidden" name="bulk_ids" id="bulkDeleteIds" value="">
</form>

<script>
// Auto-check live sessions every 30 seconds
function checkLiveSessions() {
    fetch('/api/check_live_sessions.php')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.sessions) {
                updateCountdowns(data.sessions);
            }
        })
        .catch(err => console.log('[v0] Auto-check error:', err));
}

// Update countdown displays
function updateCountdowns(sessions) {
    sessions.forEach(session => {
        const countdownEl = document.getElementById('countdown-' + session.id);
        if (countdownEl) {
            const startCountdown = document.getElementById('start-' + session.id);
            const endCountdown = document.getElementById('end-' + session.id);
            const statusEl = document.getElementById('status-' + session.id);
            
            if (startCountdown) {
                startCountdown.textContent = session.startsIn;
                if (session.status === 'live') {
                    startCountdown.parentElement.style.display = 'none';
                }
            }
            if (endCountdown && session.endsIn) {
                endCountdown.textContent = session.endsIn;
                if (session.status === 'completed') {
                    endCountdown.parentElement.style.display = 'none';
                }
            }
            if (statusEl) {
                statusEl.textContent = session.status.toUpperCase();
                statusEl.className = 'badge ' + (session.status === 'live' ? 'badge-success' : '');
            }
        }
    });
}

// Check on page load and every 30 seconds
document.addEventListener('DOMContentLoaded', () => {
    checkLiveSessions();
    setInterval(checkLiveSessions, 30000);
});

// Modal functions
function openModal(sessionId = null) {
    const modal = document.getElementById('sessionModal');
    const form = document.getElementById('sessionForm');
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const submitBtn = document.getElementById('submitBtn');
    
    if (sessionId) {
        modalTitle.textContent = 'Edit Session';
        formAction.value = 'update';
        submitBtn.textContent = 'Update Session';
        document.getElementById('sessionId').value = sessionId;
        // Load session data via API or fetch from table
    } else {
        modalTitle.textContent = 'Schedule Live Session';
        formAction.value = 'create';
        submitBtn.textContent = 'Schedule Session';
        form.reset();
    }
    
    modal.style.display = 'flex';
}

function closeModal() {
    document.getElementById('sessionModal').style.display = 'none';
}

// Close modal on overlay click
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('sessionModal');
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
