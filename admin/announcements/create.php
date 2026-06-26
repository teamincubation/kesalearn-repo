<?php
/**
 * KESA Learn - Create/Edit Announcement
 */
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();

$db = getDB();
$adminPage = 'announcements';

// Check if table exists
try {
    $db->query("SELECT 1 FROM announcements LIMIT 1");
} catch (PDOException $e) {
    setFlash('error', 'Please run the announcements migration first.');
    redirect('/admin/announcements/');
}

$isEdit = isset($_GET['id']);
$announcement = null;
$pageTitle = $isEdit ? 'Edit Announcement' : 'Create Announcement';

if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $announcement = $stmt->fetch();
    if (!$announcement) {
        setFlash('error', 'Announcement not found.');
        redirect('/admin/announcements/');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Invalid security token.');
        redirect('/admin/announcements/' . ($isEdit ? 'create?id=' . $announcement['id'] : 'create'));
    }
    
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $label = $_POST['label'] ?? 'info';
    $linkType = $_POST['link_type'] ?? 'none';
    $linkUrl = trim($_POST['link_url'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '00:00';
    $endDate = $_POST['end_date'] ?? '';
    $endTime = $_POST['end_time'] ?? '23:59';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    
    $errors = [];
    
    if (empty($title)) $errors[] = 'Title is required.';
    if (empty($content)) $errors[] = 'Content is required.';
    if (empty($startDate)) $errors[] = 'Start date is required.';
    if (empty($endDate)) $errors[] = 'End date is required.';
    
    // Validate dates
    $startDateTime = $startDate . ' ' . $startTime . ':00';
    $endDateTime = $endDate . ' ' . $endTime . ':00';
    
    if (strtotime($endDateTime) <= strtotime($startDateTime)) {
        $errors[] = 'End date must be after start date.';
    }
    
    // Handle file upload for download type
    $filePath = $announcement['file_path'] ?? null;
    if ($linkType === 'download' && !empty($_FILES['download_file']['name'])) {
        $uploadDir = __DIR__ . '/../../uploads/announcements/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $file = $_FILES['download_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt'];
        
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Invalid file type. Allowed: ' . implode(', ', $allowed);
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File size must be less than 10MB.';
        } else {
            $fileName = 'ann_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                // Delete old file
                if ($filePath && file_exists($uploadDir . $filePath)) {
                    unlink($uploadDir . $filePath);
                }
                $filePath = $fileName;
            } else {
                $errors[] = 'Failed to upload file.';
            }
        }
    }
    
    if (empty($errors)) {
        if ($isEdit) {
            $stmt = $db->prepare("UPDATE announcements SET title = ?, content = ?, label = ?, link_type = ?, link_url = ?, file_path = ?, start_date = ?, end_date = ?, is_active = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$title, $content, $label, $linkType, $linkUrl, $filePath, $startDateTime, $endDateTime, $isActive, $sortOrder, $announcement['id']]);
            setFlash('success', 'Announcement updated successfully.');
        } else {
            $stmt = $db->prepare("INSERT INTO announcements (title, content, label, link_type, link_url, file_path, start_date, end_date, is_active, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $content, $label, $linkType, $linkUrl, $filePath, $startDateTime, $endDateTime, $isActive, $sortOrder, $_SESSION['user_id']]);
            setFlash('success', 'Announcement created successfully.');
        }
        redirect('/admin/announcements/');
    } else {
        setFlash('error', implode('<br>', $errors));
    }
}

// Set default values for form
$formData = $announcement ?? [
    'title' => '',
    'content' => '',
    'label' => 'info',
    'link_type' => 'none',
    'link_url' => '',
    'file_path' => '',
    'start_date' => date('Y-m-d\TH:i'),
    'end_date' => date('Y-m-d\TH:i', strtotime('+7 days')),
    'is_active' => 1,
    'sort_order' => 0
];

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.form-card {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    padding: 32px;
    max-width: 700px;
}

.form-header {
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-light);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: var(--text-primary);
}

.form-group input[type="text"],
.form-group input[type="date"],
.form-group input[type="time"],
.form-group input[type="number"],
.form-group input[type="url"],
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 0.95rem;
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.label-preview {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.label-option {
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.2s;
}

.label-option:hover {
    transform: scale(1.05);
}

.label-option.selected {
    border-color: var(--text-primary);
}

.link-section {
    display: none;
    margin-top: 12px;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
}

.link-section.active {
    display: block;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--border-light);
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
}
</style>

<div class="admin-content">
    <div class="form-card">
        <div class="form-header">
            <h1 style="margin: 0;"><?php echo $pageTitle; ?></h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">This will appear on the homepage marquee</p>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="form-group">
                <label for="title">Title *</label>
                <input type="text" id="title" name="title" value="<?php echo sanitize($formData['title']); ?>" required placeholder="Short title for the announcement">
            </div>
            
            <div class="form-group">
                <label for="content">Content *</label>
                <textarea id="content" name="content" required placeholder="The scrolling text that will be displayed..."><?php echo sanitize($formData['content']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Label / Badge</label>
                <input type="hidden" name="label" id="labelInput" value="<?php echo $formData['label']; ?>">
                <div class="label-preview">
                    <span class="label-option label-badge label-new <?php echo $formData['label'] === 'new' ? 'selected' : ''; ?>" data-value="new">New</span>
                    <span class="label-option label-badge label-important <?php echo $formData['label'] === 'important' ? 'selected' : ''; ?>" data-value="important">Important</span>
                    <span class="label-option label-badge label-download <?php echo $formData['label'] === 'download' ? 'selected' : ''; ?>" data-value="download">Download</span>
                    <span class="label-option label-badge label-register <?php echo $formData['label'] === 'register' ? 'selected' : ''; ?>" data-value="register">Register</span>
                    <span class="label-option label-badge label-free <?php echo $formData['label'] === 'free' ? 'selected' : ''; ?>" data-value="free">Free</span>
                    <span class="label-option label-badge label-update <?php echo $formData['label'] === 'update' ? 'selected' : ''; ?>" data-value="update">Update</span>
                    <span class="label-option label-badge label-info <?php echo $formData['label'] === 'info' ? 'selected' : ''; ?>" data-value="info">Info</span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="link_type">Link Type</label>
                <select id="link_type" name="link_type">
                    <option value="none" <?php echo $formData['link_type'] === 'none' ? 'selected' : ''; ?>>No Link</option>
                    <option value="internal" <?php echo $formData['link_type'] === 'internal' ? 'selected' : ''; ?>>Internal Link (within site)</option>
                    <option value="external" <?php echo $formData['link_type'] === 'external' ? 'selected' : ''; ?>>External Link</option>
                    <option value="download" <?php echo $formData['link_type'] === 'download' ? 'selected' : ''; ?>>Downloadable File</option>
                </select>
                
                <div class="link-section" id="urlSection">
                    <label for="link_url">URL</label>
                    <input type="text" id="link_url" name="link_url" value="<?php echo sanitize($formData['link_url']); ?>" placeholder="/events/ or https://example.com">
                </div>
                
                <div class="link-section" id="fileSection">
                    <label for="download_file">Upload File (max 10MB)</label>
                    <input type="file" id="download_file" name="download_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.txt">
                    <?php if (!empty($formData['file_path'])): ?>
                    <p style="margin-top: 8px; font-size: 0.85rem; color: var(--text-muted);">
                        Current file: <a href="/uploads/announcements/<?php echo sanitize($formData['file_path']); ?>" target="_blank"><?php echo sanitize($formData['file_path']); ?></a>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="start_date">Start Date *</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d', strtotime($formData['start_date'])); ?>" required>
                </div>
                <div class="form-group">
                    <label for="start_time">Start Time</label>
                    <input type="time" id="start_time" name="start_time" value="<?php echo date('H:i', strtotime($formData['start_date'])); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="end_date">End Date *</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d', strtotime($formData['end_date'])); ?>" required>
                </div>
                <div class="form-group">
                    <label for="end_time">End Time</label>
                    <input type="time" id="end_time" name="end_time" value="<?php echo date('H:i', strtotime($formData['end_date'])); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="sort_order">Sort Order</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?php echo intval($formData['sort_order']); ?>" min="0">
                    <small style="color: var(--text-muted);">Lower numbers appear first</small>
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo $formData['is_active'] ? 'checked' : ''; ?>>
                        <label for="is_active" style="margin: 0;">Active</label>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update' : 'Create'; ?> Announcement</button>
                <a href="/admin/announcements/" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
// Label selection
document.querySelectorAll('.label-option').forEach(el => {
    el.addEventListener('click', function() {
        document.querySelectorAll('.label-option').forEach(l => l.classList.remove('selected'));
        this.classList.add('selected');
        document.getElementById('labelInput').value = this.dataset.value;
    });
});

// Link type toggle
const linkType = document.getElementById('link_type');
const urlSection = document.getElementById('urlSection');
const fileSection = document.getElementById('fileSection');

function toggleLinkSections() {
    urlSection.classList.remove('active');
    fileSection.classList.remove('active');
    
    if (linkType.value === 'internal' || linkType.value === 'external') {
        urlSection.classList.add('active');
    } else if (linkType.value === 'download') {
        fileSection.classList.add('active');
    }
}

linkType.addEventListener('change', toggleLinkSections);
toggleLinkSections();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
