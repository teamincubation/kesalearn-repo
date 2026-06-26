<?php
/**
 * KESA Learn - Super Admin: Admin Management
 * Only accessible by super admin (admin@kesalearn.com)
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'admin_management';
$pageTitle = 'Admin Management';

// Only super admin can access this
if (!isSuperAdmin()) {
    include __DIR__ . '/../includes/sidebar.php';
    echo renderRestrictedOverlay();
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/admin-management/');
    }
    
    $action = $_POST['action'] ?? '';
    
    // Grant admin access to a user
    if ($action === 'grant_admin') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
            $stmt->execute([$userId]);
            
            // Grant default permissions (dashboard only)
            $db->prepare("INSERT IGNORE INTO admin_permissions (user_id, section, can_access) VALUES (?, 'dashboard', 1)")->execute([$userId]);
            
            logActivity('admin_granted', "Admin access granted to user ID: $userId");
            setFlash('success', 'Admin access granted successfully!');
        }
        redirect('/admin/admin-management/');
    }
    
    // Revoke admin access
    if ($action === 'revoke_admin') {
        $userId = intval($_POST['user_id'] ?? 0);
        // Cannot revoke super admin
        $checkStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
        $checkStmt->execute([$userId]);
        $userEmail = $checkStmt->fetchColumn();
        
        if (strtolower($userEmail) !== strtolower(SUPER_ADMIN_EMAIL)) {
            $stmt = $db->prepare("UPDATE users SET role = 'user' WHERE id = ?");
            $stmt->execute([$userId]);
            
            // Remove all permissions
            $db->prepare("DELETE FROM admin_permissions WHERE user_id = ?")->execute([$userId]);
            
            logActivity('admin_revoked', "Admin access revoked from user ID: $userId");
            setFlash('success', 'Admin access revoked.');
        } else {
            setFlash('error', 'Cannot revoke super admin access.');
        }
        redirect('/admin/admin-management/');
    }
    
    // Update permissions
    if ($action === 'update_permissions') {
        $userId = intval($_POST['user_id'] ?? 0);
        $permissions = $_POST['permissions'] ?? [];
        
        // Check not updating super admin
        $checkStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
        $checkStmt->execute([$userId]);
        $userEmail = $checkStmt->fetchColumn();
        
        if (strtolower($userEmail) !== strtolower(SUPER_ADMIN_EMAIL)) {
            // Clear existing permissions
            $db->prepare("DELETE FROM admin_permissions WHERE user_id = ?")->execute([$userId]);
            
            // Insert new permissions
            foreach (ADMIN_SECTIONS as $section => $label) {
                $canAccess = in_array($section, $permissions) ? 1 : 0;
                $db->prepare("INSERT INTO admin_permissions (user_id, section, can_access) VALUES (?, ?, ?)")->execute([$userId, $section, $canAccess]);
            }
            
            logActivity('permissions_updated', "Permissions updated for user ID: $userId");
            setFlash('success', 'Permissions updated successfully!');
        }
        redirect('/admin/admin-management/');
    }
}

// Fetch all admins
$admins = $db->query("SELECT id, name, email, profile_image, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC")->fetchAll();

// Fetch users who can be made admin (non-admins)
$users = $db->query("SELECT id, name, email FROM users WHERE role = 'user' ORDER BY name ASC LIMIT 100")->fetchAll();

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.admin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.admin-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
}
.admin-card-header {
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    border-bottom: 1px solid var(--border-color);
}
.admin-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--blue) 0%, #8b5cf6 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.2rem;
    color: #fff;
    flex-shrink: 0;
}
.admin-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}
.admin-info h3 {
    margin: 0 0 4px 0;
    font-size: 1.1rem;
    color: var(--text-primary);
}
.admin-info p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-muted);
}
.super-admin-badge {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    color: #1e293b;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 4px;
    margin-left: 8px;
}
.admin-card-body {
    padding: 20px;
}
.permissions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.permission-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--bg-secondary);
    border-radius: 6px;
    font-size: 0.85rem;
}
.permission-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--blue);
}
.permission-item.granted {
    background: #d1fae5;
    color: #065f46;
}
.permission-item.denied {
    background: #fee2e2;
    color: #991b1b;
}
.admin-card-footer {
    padding: 16px 20px;
    background: var(--bg-secondary);
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
</style>

<div class="section-header">
    <div>
        <h2 style="margin: 0;">Admin Management</h2>
        <p style="margin: 8px 0 0 0; color: var(--text-muted);">Manage administrator access and permissions. Only you (Super Admin) can access this page.</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="showModal('addAdminModal')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
        Add New Admin
    </button>
</div>

<!-- Current Admins -->
<div class="admin-grid">
    <?php foreach ($admins as $admin): 
        $isSuperAdminUser = strtolower($admin['email']) === strtolower(SUPER_ADMIN_EMAIL);
        $permissions = getUserPermissions($admin['id']);
    ?>
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-avatar">
                <?php if (!empty($admin['profile_image'])): ?>
                    <img src="/uploads/profiles/<?php echo sanitize($admin['profile_image']); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr($admin['name'], 0, 2)); ?>
                <?php endif; ?>
            </div>
            <div class="admin-info">
                <h3>
                    <?php echo sanitize($admin['name']); ?>
                    <?php if ($isSuperAdminUser): ?>
                        <span class="super-admin-badge">SUPER ADMIN</span>
                    <?php endif; ?>
                </h3>
                <p><?php echo sanitize($admin['email']); ?></p>
                <p style="font-size: 0.75rem;">Joined: <?php echo formatDate($admin['created_at']); ?></p>
            </div>
        </div>
        
        <?php if (!$isSuperAdminUser): ?>
        <form method="POST" id="permForm<?php echo $admin['id']; ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="user_id" value="<?php echo $admin['id']; ?>">
            
            <div class="admin-card-body">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0 0 12px 0;">Section Access:</p>
                <div class="permissions-grid">
                    <?php foreach (ADMIN_SECTIONS as $section => $label): 
                        $hasAccess = isset($permissions[$section]) ? $permissions[$section] : ($section === 'dashboard');
                    ?>
                    <label class="permission-item <?php echo $hasAccess ? 'granted' : 'denied'; ?>">
                        <input type="checkbox" name="permissions[]" value="<?php echo $section; ?>" <?php echo $hasAccess ? 'checked' : ''; ?>>
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="admin-card-footer">
                <button type="submit" class="btn btn-sm btn-primary">Save Permissions</button>
                <button type="button" class="btn btn-sm btn-danger" onclick="confirmRevoke(<?php echo $admin['id']; ?>, '<?php echo sanitize($admin['name']); ?>')">Revoke Admin</button>
            </div>
        </form>
        <?php else: ?>
        <div class="admin-card-body">
            <div style="padding: 20px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 8px; text-align: center;">
                <svg width="32" height="32" fill="none" stroke="#92400e" viewBox="0 0 24 24" style="margin-bottom: 8px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <p style="margin: 0; color: #92400e; font-weight: 600;">Full Access to All Sections</p>
                <p style="margin: 4px 0 0 0; color: #a16207; font-size: 0.8rem;">Super Admin cannot be modified</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add Admin Modal -->
<div id="addAdminModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Add New Administrator</h3>
            <button type="button" class="modal-close" onclick="hideModal('addAdminModal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="grant_admin">
            
            <div class="form-group">
                <label>Select User</label>
                <select name="user_id" class="form-control" required>
                    <option value="">-- Select a user --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo sanitize($u['name']); ?> (<?php echo sanitize($u['email']); ?>)</option>
                    <?php endforeach; ?>
                </select>
                <p class="form-text">Only registered users can be made administrators.</p>
            </div>
            
            <div style="padding: 16px; background: #eff6ff; border-radius: 8px; margin-bottom: 20px;">
                <p style="margin: 0; color: #1e40af; font-size: 0.9rem;">
                    <strong>Note:</strong> New admins will only have Dashboard access by default. You can grant additional permissions after adding them.
                </p>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('addAdminModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Grant Admin Access</button>
            </div>
        </form>
    </div>
</div>

<!-- Revoke Admin Form (hidden) -->
<form id="revokeForm" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="revoke_admin">
    <input type="hidden" name="user_id" id="revokeUserId">
</form>

<script>
function showModal(id) {
    document.getElementById(id).style.display = 'flex';
}
function hideModal(id) {
    document.getElementById(id).style.display = 'none';
}
function confirmRevoke(userId, name) {
    if (confirm('Are you sure you want to revoke admin access for "' + name + '"?\n\nThey will be converted to a regular user and lose all admin permissions.')) {
        document.getElementById('revokeUserId').value = userId;
        document.getElementById('revokeForm').submit();
    }
}

// Close modal on outside click
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === m) hideModal(m.id);
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
