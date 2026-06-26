<?php
/**
 * KESA Learn - Admin: WhatsApp Broadcasting
 * Manage and send WhatsApp messages to users with event-based or manual selection
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'whatsapp-broadcasts';
$pageTitle = 'WhatsApp Broadcasting';

// Only super admin can access this
if (!isSuperAdmin()) {
    setFlash('error', 'Only super admin can access WhatsApp broadcasting.');
    redirect('/admin/');
}

// Handle create broadcast
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_broadcast'])) {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $title = sanitize($_POST['title'] ?? '');
        $message = $_POST['message'] ?? '';
        $messageType = $_POST['message_type'] ?? 'manual';
        $eventId = intval($_POST['event_id'] ?? 0);
        $timeGap = intval($_POST['time_gap'] ?? 2);
        
        // Validate inputs
        $errors = [];
        if (empty($title)) $errors[] = 'Broadcast title is required.';
        if (empty($message)) $errors[] = 'Message is required.';
        if ($messageType === 'event' && $eventId <= 0) $errors[] = 'Please select an event.';
        if ($timeGap < 1 || $timeGap > 60) $errors[] = 'Time gap must be between 1-60 seconds.';
        
        if (empty($errors)) {
            try {
                // Create broadcast record
                $broadcastStmt = $db->prepare("
                    INSERT INTO whatsapp_broadcasts (admin_id, title, message, message_type, event_id, time_gap_seconds)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $broadcastStmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $message,
                    $messageType,
                    $eventId > 0 ? $eventId : null,
                    $timeGap
                ]);
                
                $broadcastId = $db->lastInsertId();
                
                // Add recipients based on message type
                if ($messageType === 'event' && $eventId > 0) {
                    // Get all users registered for this event
                    $recipientsStmt = $db->prepare("
                        SELECT DISTINCT u.id, u.name, u.phone, e.title as event_name 
                        FROM registrations r
                        JOIN users u ON r.user_id = u.id
                        JOIN events e ON r.event_id = e.id
                        WHERE r.event_id = ? AND u.phone IS NOT NULL AND u.phone != ''
                    ");
                    $recipientsStmt->execute([$eventId]);
                    $recipients = $recipientsStmt->fetchAll();
                } elseif ($messageType === 'all') {
                    // Get all users with WhatsApp numbers
                    $recipientsStmt = $db->prepare("
                        SELECT id, name, phone, NULL as event_name
                        FROM users 
                        WHERE phone IS NOT NULL AND phone != '' AND role = 'user'
                        ORDER BY name ASC
                    ");
                    $recipientsStmt->execute();
                    $recipients = $recipientsStmt->fetchAll();
                } else {
                    $recipients = [];
                }
                
                // Add recipients to queue
                foreach ($recipients as $recipient) {
                    $personalizedMsg = str_replace(['<<Name>>', '<<event name>>'], [$recipient['name'], $recipient['event_name'] ?? 'the event'], $message);
                    
                    $queueStmt = $db->prepare("
                        INSERT INTO whatsapp_queue (broadcast_id, user_id, phone, recipient_name, event_name, personalized_message)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $queueStmt->execute([
                        $broadcastId,
                        $recipient['id'],
                        $recipient['phone'],
                        $recipient['name'],
                        $recipient['event_name'],
                        $personalizedMsg
                    ]);
                }
                
                $recipientCount = count($recipients);
                $db->prepare("UPDATE whatsapp_broadcasts SET recipient_count = ? WHERE id = ?")->execute([$recipientCount, $broadcastId]);
                
                logActivity('whatsapp_broadcast_created', "Created WhatsApp broadcast: $title with $recipientCount recipients");
                setFlash('success', "Broadcast created with $recipientCount recipients. Ready to send!");
                redirect('/admin/whatsapp-broadcasts/');
            } catch (Exception $e) {
                setFlash('error', 'Error creating broadcast: ' . $e->getMessage());
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }
    }
}

// Get all broadcasts
try {
    $broadcastsStmt = $db->prepare("
        SELECT wb.*, u.name as admin_name, e.title as event_name,
               COUNT(wq.id) as queue_count,
               SUM(CASE WHEN wq.status = 'sent' THEN 1 ELSE 0 END) as sent_count
        FROM whatsapp_broadcasts wb
        LEFT JOIN users u ON wb.admin_id = u.id
        LEFT JOIN events e ON wb.event_id = e.id
        LEFT JOIN whatsapp_queue wq ON wb.id = wq.broadcast_id
        GROUP BY wb.id
        ORDER BY wb.created_at DESC
    ");
    $broadcastsStmt->execute();
    $broadcasts = $broadcastsStmt->fetchAll();
} catch (Exception $e) {
    // Tables don't exist yet - show setup message
    $broadcasts = [];
    $setupNeeded = true;
}

// Get all events
$eventsStmt = $db->prepare("SELECT id, title FROM events WHERE is_active = 1 ORDER BY start_date DESC");
$eventsStmt->execute();
$events = $eventsStmt->fetchAll();

include __DIR__ . '/../includes/sidebar.php';

// Show setup message if tables don't exist
if (!empty($setupNeeded)):
?>
<div style="padding: 24px; max-width: 1000px; margin: 0 auto;">
    <div style="background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; padding: 20px;">
        <h2 style="margin: 0 0 8px 0; color: #92400e;">Database Setup Required</h2>
        <p style="margin: 0 0 12px 0; color: #78350f;">The WhatsApp Broadcasting module requires database tables to be created. Please run the migration:</p>
        <code style="background: #fef08a; padding: 12px; border-radius: 4px; display: block; margin-bottom: 12px; word-break: break-all;">mysql -u [user] -p [database] < kesa-learn/sql/migration_whatsapp_broadcasts.sql</code>
        <p style="color: #78350f; font-size: 0.9rem; margin: 0;">After running the migration, refresh this page.</p>
    </div>
</div>
<?php
    exit;
endif;


<div style="padding: 24px; max-width: 1200px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="margin: 0 0 8px 0; font-size: 2rem;">WhatsApp Broadcasting</h1>
            <p style="margin: 0; color: var(--text-muted);">Create and send WhatsApp messages to users</p>
        </div>
        <button onclick="openCreateModal()" class="btn btn-primary" style="background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); border: none;">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 8px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Broadcast
        </button>
    </div>

    <!-- Safety Guidelines -->
    <div style="background: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <div style="display: flex; gap: 12px;">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24" style="color: #0284c7; flex-shrink: 0; margin-top: 2px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            <div style="flex: 1;">
                <strong style="color: #0c4a6e;">WhatsApp Broadcasting Safety Guidelines</strong>
                <ul style="margin: 8px 0 0 0; padding-left: 20px; color: #075985; font-size: 0.9rem;">
                    <li>Set appropriate time gaps (2-5 seconds) to avoid WhatsApp rate limiting and blocks</li>
                    <li>Do not send duplicate messages to the same user within 24 hours</li>
                    <li>Personalize messages with user names and event details when possible</li>
                    <li>Ensure all recipients have opted in to receive WhatsApp messages</li>
                    <li>Messages are sent from your connected WhatsApp Business account</li>
                    <li>Keep message content professional and relevant to the user</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Broadcasts List -->
    <div style="background: white; border-radius: 8px; border: 1px solid var(--border-color); overflow: hidden;">
        <table class="table" style="margin: 0;">
            <thead>
                <tr style="background: var(--bg-secondary);">
                    <th style="padding: 16px; text-align: left; font-weight: 600;">Title</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600;">Type</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600;">Recipients</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600;">Status</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600;">Progress</th>
                    <th style="padding: 16px; text-align: center; font-weight: 600;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($broadcasts)): ?>
                <tr>
                    <td colspan="6" style="padding: 40px; text-align: center; color: var(--text-muted);">
                        <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin: 0 auto 12px; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg><br>
                        No broadcasts yet. Create your first one!
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($broadcasts as $b): ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 16px;">
                        <div style="font-weight: 600;"><?php echo sanitize($b['title']); ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">By <?php echo sanitize($b['admin_name']); ?> on <?php echo formatDate($b['created_at']); ?></div>
                    </td>
                    <td style="padding: 16px;">
                        <span class="badge <?php echo $b['message_type'] === 'event' ? 'badge-blue' : ($b['message_type'] === 'all' ? 'badge-purple' : 'badge-gray'); ?>">
                            <?php echo ucfirst($b['message_type']); ?>
                            <?php if ($b['message_type'] === 'event' && $b['event_name']): ?>
                                - <?php echo sanitize($b['event_name']); ?>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td style="padding: 16px; font-weight: 600;"><?php echo $b['recipient_count']; ?></td>
                    <td style="padding: 16px;">
                        <span class="badge <?php 
                            echo match($b['status']) {
                                'draft' => 'badge-gray',
                                'scheduled' => 'badge-yellow',
                                'sending' => 'badge-blue',
                                'completed' => 'badge-success',
                                'failed' => 'badge-red',
                                'paused' => 'badge-orange',
                                default => 'badge-gray'
                            };
                        ?>">
                            <?php echo ucfirst($b['status']); ?>
                        </span>
                    </td>
                    <td style="padding: 16px;">
                        <div style="background: var(--bg-secondary); border-radius: 4px; overflow: hidden; height: 24px; display: flex; align-items: center;">
                            <?php 
                            $percentage = $b['recipient_count'] > 0 ? ($b['sent_count'] / $b['recipient_count']) * 100 : 0;
                            ?>
                            <div style="background: linear-gradient(90deg, #25D366 0%, #128C7E 100%); height: 100%; width: <?php echo $percentage; ?>%; transition: width 0.3s;"></div>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;"><?php echo $b['sent_count']; ?> / <?php echo $b['recipient_count']; ?></div>
                    </td>
                    <td style="padding: 16px; text-align: center;">
                        <a href="/admin/whatsapp-broadcasts/view?id=<?php echo $b['id']; ?>" class="btn-icon" title="View">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Broadcast Modal -->
<div id="createModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; overflow-y: auto;">
    <div style="background: white; border-radius: 12px; margin: 60px auto; padding: 32px; max-width: 600px; width: 90%;">
        <h2 style="margin: 0 0 24px 0; font-size: 1.5rem;">Create New Broadcast</h2>
        
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="create_broadcast" value="1">
            
            <div style="margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Broadcast Title *</label>
                <input type="text" name="title" placeholder="e.g., Event Reminder - XYZ Workshop" required style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 1rem;">
            </div>

            <div style="margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Message Type *</label>
                <select name="message_type" onchange="updateMessageTypeUI()" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 1rem;">
                    <option value="manual">Manual Selection (Select Users A-Z)</option>
                    <option value="event">Event-Based (All Registered Users)</option>
                    <option value="all">All Users</option>
                </select>
            </div>

            <div id="eventSelectionDiv" style="display: none; margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Select Event *</label>
                <select name="event_id" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 1rem;">
                    <option value="">-- Select Event --</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?php echo $event['id']; ?>"><?php echo sanitize($event['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Message *</label>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0 0 8px 0;">
                    Supports line breaks, emojis, and variables: &lt;&lt;Name&gt;&gt;, &lt;&lt;event name&gt;&gt;
                </p>
                <textarea name="message" placeholder="Enter your message here..." required style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 1rem; min-height: 120px; font-family: monospace; resize: vertical;"></textarea>
            </div>

            <div style="margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Time Gap Between Messages (seconds) *</label>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <input type="range" name="time_gap" value="2" min="1" max="60" step="1" oninput="updateTimeGapDisplay()" style="flex: 1; cursor: pointer;">
                    <span id="timeGapDisplay" style="min-width: 50px; font-weight: 600; color: var(--blue);">2 sec</span>
                </div>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 8px 0 0 0;">
                    Recommended: 2-5 seconds to avoid WhatsApp blocking
                </p>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="closeCreateModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); border: none;">Create & Review</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').style.display = 'flex';
    document.getElementById('createModal').style.alignItems = 'flex-start';
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}

function updateMessageTypeUI() {
    const type = document.querySelector('select[name="message_type"]').value;
    document.getElementById('eventSelectionDiv').style.display = type === 'event' ? 'block' : 'none';
}

function updateTimeGapDisplay() {
    const gap = document.querySelector('input[name="time_gap"]').value;
    document.getElementById('timeGapDisplay').textContent = gap + ' sec';
}

// Close modal when clicking outside
document.getElementById('createModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});
</script>
