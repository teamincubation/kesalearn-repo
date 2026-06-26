<?php
/**
 * KESA Learn - Admin: Event Communication / Meeting Details
 * Messages visible only to registered & paid users
 */
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();

$db = getDB();
$adminPage = 'events';
$pageTitle = 'Event Communication';

$eventId = intval($_GET['event_id'] ?? 0);

// Get event details
$event = null;
if ($eventId) {
    $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
}

if (!$event) {
    setFlash('error', 'Event not found.');
    redirect('/admin/events/');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/events/messages?event_id=' . $eventId);
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $messageId = intval($_POST['message_id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $message = $_POST['message'] ?? '';
        $linkType = $_POST['link_type'] ?? 'none';
        $linkUrl = sanitize($_POST['link_url'] ?? '');
        $linkLabel = sanitize($_POST['link_label'] ?? '');
        
        if (empty($title) || empty($message)) {
            setFlash('error', 'Title and message are required.');
            redirect('/admin/events/messages?event_id=' . $eventId);
        }
        
        // Handle file upload
        $filePath = $_POST['existing_file'] ?? '';
        $fileName = $_POST['existing_file_name'] ?? '';
        
        if (!empty($_FILES['attachment']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/event-materials/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'rar', 'txt', 'jpg', 'png'];
            
            if (in_array($ext, $allowed)) {
                $newFileName = 'material_' . $eventId . '_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $newFileName)) {
                    $filePath = $newFileName;
                    $fileName = $_FILES['attachment']['name'];
                }
            }
        }
        
        if ($action === 'create') {
            $stmt = $db->prepare("INSERT INTO event_messages (event_id, title, message, link_type, link_url, link_label, file_path, file_name, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$eventId, $title, $message, $linkType, $linkUrl ?: null, $linkLabel ?: null, $filePath ?: null, $fileName ?: null, $_SESSION['user_id']]);
            logActivity('event_message_created', "Message created for event #$eventId");
            setFlash('success', 'Message created successfully! Registered users can now see this.');
        } else {
            $stmt = $db->prepare("UPDATE event_messages SET title = ?, message = ?, link_type = ?, link_url = ?, link_label = ?, file_path = ?, file_name = ?, updated_at = NOW() WHERE id = ? AND event_id = ?");
            $stmt->execute([$title, $message, $linkType, $linkUrl ?: null, $linkLabel ?: null, $filePath ?: null, $fileName ?: null, $messageId, $eventId]);
            logActivity('event_message_updated', "Message #$messageId updated for event #$eventId");
            setFlash('success', 'Message updated successfully!');
        }
        redirect('/admin/events/messages?event_id=' . $eventId);
    }
    
    if ($action === 'delete') {
        $messageId = intval($_POST['message_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM event_messages WHERE id = ? AND event_id = ?");
        $stmt->execute([$messageId, $eventId]);
        logActivity('event_message_deleted', "Message #$messageId deleted from event #$eventId");
        setFlash('success', 'Message deleted.');
        redirect('/admin/events/messages?event_id=' . $eventId);
    }
    
    if ($action === 'toggle') {
        $messageId = intval($_POST['message_id'] ?? 0);
        $db->prepare("UPDATE event_messages SET is_active = NOT is_active WHERE id = ? AND event_id = ?")->execute([$messageId, $eventId]);
        redirect('/admin/events/messages?event_id=' . $eventId);
    }
}

// Fetch messages for this event
$messages = $db->prepare("SELECT * FROM event_messages WHERE event_id = ? ORDER BY created_at DESC");
$messages->execute([$eventId]);
$messages = $messages->fetchAll();

// Count registered users
$regCount = $db->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND payment_status IN ('paid', 'verified')");
$regCount->execute([$eventId]);
$registeredCount = $regCount->fetchColumn();

// Edit mode
$editMessage = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM event_messages WHERE id = ? AND event_id = ?");
    $stmt->execute([$_GET['edit'], $eventId]);
    $editMessage = $stmt->fetch();
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 0.9rem;
    margin-bottom: 16px;
}
.back-link:hover { color: var(--blue); }
.event-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: var(--radius-md);
    padding: 24px;
    margin-bottom: 24px;
    color: #fff;
}
.event-header h2 { font-size: 1.5rem; margin-bottom: 8px; }
.event-meta { display: flex; gap: 24px; font-size: 0.9rem; opacity: 0.9; flex-wrap: wrap; }
.event-meta span { display: flex; align-items: center; gap: 6px; }
.message-form {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 24px;
    margin-bottom: 24px;
}
.message-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 16px;
    position: relative;
}
.message-card.inactive { opacity: 0.6; }
.message-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.message-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 4px; }
.message-time { font-size: 0.8rem; color: var(--text-muted); }
.message-content {
    color: var(--text-secondary);
    line-height: 1.6;
    margin-bottom: 16px;
    white-space: pre-wrap;
}
.message-attachments {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    padding-top: 12px;
    border-top: 1px solid var(--border-light);
}
.attachment-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    color: var(--blue);
    text-decoration: none;
    transition: all 0.2s;
}
.attachment-link:hover { background: var(--blue); color: #fff; }
.message-actions {
    display: flex;
    gap: 8px;
}
.link-type-options { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.link-type-options label {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
}
.link-type-options input:checked + span { background: var(--blue); color: #fff; }
.link-type-options input { display: none; }
.link-type-options label:has(input:checked) { background: var(--blue); color: #fff; }
.link-fields { display: none; margin-top: 12px; }
.link-fields.active { display: block; }
</style>

<a href="/admin/events/" class="back-link">
    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    Back to Events
</a>

<div class="event-header">
    <h2><?php echo sanitize($event['title']); ?></h2>
    <div class="event-meta">
        <span>
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <?php echo formatDate($event['start_date']); ?>
        </span>
        <span>
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            <?php echo $registeredCount; ?> Registered Users
        </span>
        <span>
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
            <?php echo count($messages); ?> Messages
        </span>
        <span style="background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 4px; font-weight: 600;">
            Event ID: #<?php echo $event['id']; ?>
        </span>
    </div>
</div>

<!-- Create/Edit Message Form -->
<div class="message-form">
    <h3 style="margin-bottom: 20px;"><?php echo $editMessage ? 'Edit Message' : 'Share New Update'; ?></h3>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="<?php echo $editMessage ? 'update' : 'create'; ?>">
        <?php if ($editMessage): ?>
            <input type="hidden" name="message_id" value="<?php echo $editMessage['id']; ?>">
            <input type="hidden" name="existing_file" value="<?php echo sanitize($editMessage['file_path'] ?? ''); ?>">
            <input type="hidden" name="existing_file_name" value="<?php echo sanitize($editMessage['file_name'] ?? ''); ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label>Title <span class="required">*</span></label>
            <input type="text" name="title" class="form-control" placeholder="e.g., Session 1 Joining Link, Study Materials" value="<?php echo sanitize($editMessage['title'] ?? ''); ?>" required>
        </div>
        
        <div class="form-group">
            <label>Message <span class="required">*</span></label>
            <textarea name="message" class="form-control" rows="4" placeholder="Write your message here. This will be visible to all registered users..." required><?php echo sanitize($editMessage['message'] ?? ''); ?></textarea>
        </div>
        
        <div class="form-group">
            <label>Include Link</label>
            <div class="link-type-options">
                <label><input type="radio" name="link_type" value="none" <?php echo ($editMessage['link_type'] ?? 'none') === 'none' ? 'checked' : ''; ?>> <span>No Link</span></label>
                <label><input type="radio" name="link_type" value="meeting" <?php echo ($editMessage['link_type'] ?? '') === 'meeting' ? 'checked' : ''; ?>> <span>Meeting Link</span></label>
                <label><input type="radio" name="link_type" value="external" <?php echo ($editMessage['link_type'] ?? '') === 'external' ? 'checked' : ''; ?>> <span>External URL</span></label>
                <label><input type="radio" name="link_type" value="internal" <?php echo ($editMessage['link_type'] ?? '') === 'internal' ? 'checked' : ''; ?>> <span>Internal Page</span></label>
            </div>
            <div id="linkFields" class="link-fields <?php echo ($editMessage['link_type'] ?? 'none') !== 'none' ? 'active' : ''; ?>">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
                    <input type="url" name="link_url" class="form-control" placeholder="https://..." value="<?php echo sanitize($editMessage['link_url'] ?? ''); ?>">
                    <input type="text" name="link_label" class="form-control" placeholder="Button label (e.g., Join Now)" value="<?php echo sanitize($editMessage['link_label'] ?? ''); ?>">
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Attachment (Optional)</label>
            <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar,.txt,.jpg,.png">
            <p class="form-text">PDF, DOC, PPT, XLS, ZIP, or images. Max 10MB.</p>
            <?php if ($editMessage && $editMessage['file_name']): ?>
                <p style="margin-top: 8px; font-size: 0.85rem;">Current file: <strong><?php echo sanitize($editMessage['file_name']); ?></strong> (leave empty to keep)</p>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary"><?php echo $editMessage ? 'Update Message' : 'Post Update'; ?></button>
            <?php if ($editMessage): ?>
                <a href="/admin/events/messages?event_id=<?php echo $eventId; ?>" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Messages List -->
<h3 style="margin-bottom: 16px;">Posted Updates (<?php echo count($messages); ?>)</h3>

<?php if (empty($messages)): ?>
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 40px; text-align: center; color: var(--text-muted);">
        <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom: 12px; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        <p>No messages posted yet. Share meeting details or materials with registered users.</p>
    </div>
<?php else: ?>
    <?php foreach ($messages as $msg): ?>
        <div class="message-card <?php echo $msg['is_active'] ? '' : 'inactive'; ?>">
            <div class="message-card-header">
                <div>
                    <div class="message-title">
                        <?php echo sanitize($msg['title']); ?>
                        <?php if (!$msg['is_active']): ?>
                            <span style="background: var(--bg-tertiary); color: var(--text-muted); padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; margin-left: 8px;">HIDDEN</span>
                        <?php endif; ?>
                    </div>
                    <div class="message-time">
                        Posted <?php echo timeAgo($msg['created_at']); ?>
                        <?php if ($msg['updated_at'] !== $msg['created_at']): ?>
                            &bull; Updated <?php echo timeAgo($msg['updated_at']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="message-actions">
                    <form method="POST" style="display: inline;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-secondary" title="<?php echo $msg['is_active'] ? 'Hide' : 'Show'; ?>">
                            <?php if ($msg['is_active']): ?>
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                            <?php else: ?>
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <?php endif; ?>
                        </button>
                    </form>
                    <a href="?event_id=<?php echo $eventId; ?>&edit=<?php echo $msg['id']; ?>" class="btn btn-sm btn-secondary">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this message?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </form>
                </div>
            </div>
            <div class="message-content"><?php echo nl2br(sanitize($msg['message'])); ?></div>
            <?php if ($msg['link_url'] || $msg['file_path']): ?>
                <div class="message-attachments">
                    <?php if ($msg['link_url']): ?>
                        <a href="<?php echo sanitize($msg['link_url']); ?>" target="_blank" class="attachment-link">
                            <?php if ($msg['link_type'] === 'meeting'): ?>
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <?php else: ?>
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            <?php endif; ?>
                            <?php echo sanitize($msg['link_label'] ?: 'Open Link'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($msg['file_path']): ?>
                        <a href="/uploads/event-materials/<?php echo sanitize($msg['file_path']); ?>" download class="attachment-link">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <?php echo sanitize($msg['file_name'] ?: 'Download File'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('input[name="link_type"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var fields = document.getElementById('linkFields');
        if (this.value !== 'none') {
            fields.classList.add('active');
        } else {
            fields.classList.remove('active');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
