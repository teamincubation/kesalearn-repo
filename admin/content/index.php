<?php
/**
 * KESA Learn - Admin: Manage Site Content
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'content';
$pageTitle = 'Site Content';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/content/');
    }
    
    foreach ($_POST['content'] as $key => $value) {
        $stmt = $db->prepare("UPDATE site_content SET content_value = ? WHERE content_key = ?");
        $stmt->execute([$value, $key]);
    }
    
    logActivity('content_updated', 'Site content updated');
    setFlash('success', 'Site content updated successfully!');
    redirect('/admin/content/');
}

$contents = $db->query("SELECT * FROM site_content ORDER BY id")->fetchAll();

include __DIR__ . '/../includes/sidebar.php';
?>

<form method="POST" class="admin-form">
    <?php echo csrfField(); ?>
    
    <?php foreach ($contents as $c): ?>
        <div class="form-group">
            <label for="content_<?php echo $c['content_key']; ?>"><?php echo ucwords(str_replace('_', ' ', $c['content_key'])); ?></label>
            
            <?php if (in_array($c['content_key'], ['whatsapp_group_link', 'whatsapp_chat_link'])): ?>
                <!-- WhatsApp URL Fields -->
                <input type="url" id="content_<?php echo $c['content_key']; ?>" name="content[<?php echo $c['content_key']; ?>]" class="form-control" value="<?php echo sanitize($c['content_value'] ?? ''); ?>" placeholder="https://...">
                <?php if ($c['content_key'] === 'whatsapp_group_link'): ?>
                    <p class="form-text">WhatsApp group invitation link (e.g., https://chat.whatsapp.com/xxxxx)</p>
                <?php elseif ($c['content_key'] === 'whatsapp_chat_link'): ?>
                    <p class="form-text">WhatsApp direct chat link (e.g., https://wa.me/1234567890)</p>
                <?php endif; ?>
            <?php elseif (in_array($c['content_key'], ['terms_conditions_content', 'privacy_policy_content', 'refund_policy_content'])): ?>
                <!-- Quill Rich Text Editor for Policy Content -->
                <div id="editor_<?php echo $c['content_key']; ?>" class="quill-editor"></div>
                <textarea id="content_<?php echo $c['content_key']; ?>" name="content[<?php echo $c['content_key']; ?>]" class="quill-textarea" style="display:none;"><?php echo $c['content_value'] ?? ''; ?></textarea>
                <?php if ($c['content_key'] === 'terms_conditions_content'): ?>
                    <p class="form-text">Terms & Conditions - Will be displayed on /terms page</p>
                <?php elseif ($c['content_key'] === 'privacy_policy_content'): ?>
                    <p class="form-text">Privacy Policy - Will be displayed on /privacy page</p>
                <?php elseif ($c['content_key'] === 'refund_policy_content'): ?>
                    <p class="form-text">Refund Policy - Will be displayed on /refund page</p>
                <?php endif; ?>
            <?php elseif (strlen($c['content_value'] ?? '') > 100 || in_array($c['content_key'], ['about_text', 'footer_text'])): ?>
                <textarea id="content_<?php echo $c['content_key']; ?>" name="content[<?php echo $c['content_key']; ?>]" class="form-control" rows="4"><?php echo sanitize($c['content_value'] ?? ''); ?></textarea>
            <?php else: ?>
                <input type="text" id="content_<?php echo $c['content_key']; ?>" name="content[<?php echo $c['content_key']; ?>]" class="form-control" value="<?php echo sanitize($c['content_value'] ?? ''); ?>">
            <?php endif; ?>
            <p class="form-text">Last updated: <?php echo formatDateTime($c['updated_at']); ?></p>
        </div>
    <?php endforeach; ?>
    
    <div class="admin-form-actions">
        <button type="submit" class="btn btn-primary btn-lg">Save All Changes</button>
    </div>
</form>

<!-- Quill Rich Text Editor (Free & Open Source) -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

<style>
.quill-editor {
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-height: 400px;
    margin-bottom: 10px;
}

.ql-toolbar {
    border-top-left-radius: 4px;
    border-top-right-radius: 4px;
    background-color: #f8f9fa;
    border-color: #ddd;
}

.ql-container {
    border-bottom-left-radius: 4px;
    border-bottom-right-radius: 4px;
    font-size: 14px;
    border-color: #ddd;
}

.ql-editor {
    min-height: 350px;
    font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #333;
}

.ql-editor.ql-blank::before {
    color: #999;
    font-style: italic;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Quill editors for policy content
    const editorConfigs = [
        { elementId: 'editor_terms_conditions_content', textareaId: 'content_terms_conditions_content' },
        { elementId: 'editor_privacy_policy_content', textareaId: 'content_privacy_policy_content' },
        { elementId: 'editor_refund_policy_content', textareaId: 'content_refund_policy_content' }
    ];
    
    const editors = {};
    
    editorConfigs.forEach(config => {
        const element = document.getElementById(config.elementId);
        if (element) {
            const textarea = document.getElementById(config.textareaId);
            const initialContent = textarea.value;
            
            const quill = new Quill('#' + config.elementId, {
                theme: 'snow',
                placeholder: 'Start typing your content here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline', 'strike'],
                        ['blockquote', 'code-block'],
                        [{ 'header': 1 }, { 'header': 2 }],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });
            
            // Load initial content
            if (initialContent) {
                try {
                    quill.root.innerHTML = initialContent;
                } catch (e) {
                    console.log('[v0] Error loading content:', e);
                }
            }
            
            editors[config.textareaId] = quill;
        }
    });
    
    // Update textareas before form submission
    const form = document.querySelector('.admin-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            Object.keys(editors).forEach(textareaId => {
                const textarea = document.getElementById(textareaId);
                const quill = editors[textareaId];
                textarea.value = quill.root.innerHTML;
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
