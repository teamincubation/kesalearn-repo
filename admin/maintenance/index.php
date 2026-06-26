<?php
/**
 * KESA Learn - Admin Maintenance Mode Management
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'maintenance';
$pageTitle = 'Maintenance Mode';

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS maintenance_mode (
        id INT PRIMARY KEY AUTO_INCREMENT,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        message TEXT,
        scheduled_start DATETIME DEFAULT NULL,
        scheduled_end DATETIME DEFAULT NULL,
        allow_admin_access TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Check if default row exists
    $rowExists = $db->query("SELECT COUNT(*) FROM maintenance_mode WHERE id = 1")->fetchColumn();
    if (!$rowExists) {
        $db->exec("INSERT INTO maintenance_mode (id, is_active, message, allow_admin_access) 
                   VALUES (1, 0, 'We are currently performing scheduled maintenance to improve your experience. We will be back shortly!', 1)");
    }
} catch (PDOException $e) {
    // Log error for debugging but continue
    error_log("Maintenance table error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $message = sanitize($_POST['message'] ?? '');
        $scheduledStart = !empty($_POST['scheduled_start']) ? $_POST['scheduled_start'] : null;
        $scheduledEnd = !empty($_POST['scheduled_end']) ? $_POST['scheduled_end'] : null;
        $allowAdminAccess = isset($_POST['allow_admin_access']) ? 1 : 0;
        
        $stmt = $db->prepare("UPDATE maintenance_mode SET 
            is_active = ?, 
            message = ?, 
            scheduled_start = ?, 
            scheduled_end = ?,
            allow_admin_access = ?
            WHERE id = 1");
        $stmt->execute([$isActive, $message, $scheduledStart, $scheduledEnd, $allowAdminAccess]);
        
        logActivity('maintenance_updated', $isActive ? 'Enabled maintenance mode' : 'Disabled maintenance mode');
        setFlash('success', 'Maintenance settings updated successfully!');
        redirect('/admin/maintenance/');
    }
    
    if ($action === 'toggle_quick') {
        $currentStatus = $db->query("SELECT is_active FROM maintenance_mode WHERE id = 1")->fetchColumn();
        $newStatus = $currentStatus ? 0 : 1;
        $db->prepare("UPDATE maintenance_mode SET is_active = ? WHERE id = 1")->execute([$newStatus]);
        
        logActivity('maintenance_toggled', $newStatus ? 'Quick enabled maintenance mode' : 'Quick disabled maintenance mode');
        setFlash('success', $newStatus ? 'Maintenance mode ENABLED!' : 'Maintenance mode DISABLED!');
        redirect('/admin/maintenance/');
    }
}

// Get current settings
$settings = $db->query("SELECT * FROM maintenance_mode WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $settings = [
        'is_active' => 0,
        'message' => 'We are currently performing scheduled maintenance to improve your experience. We will be back shortly!',
        'scheduled_start' => null,
        'scheduled_end' => null,
        'allow_admin_access' => 1
    ];
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="admin-page">
    <!-- Quick Toggle Card -->
    <div class="maintenance-status-card <?php echo $settings['is_active'] ? 'active' : 'inactive'; ?>">
        <div class="status-content">
            <div class="status-icon">
                <?php if ($settings['is_active']): ?>
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                <?php else: ?>
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                <?php endif; ?>
            </div>
            <div class="status-text">
                <h2><?php echo $settings['is_active'] ? 'Maintenance Mode is ACTIVE' : 'Site is Online'; ?></h2>
                <p><?php echo $settings['is_active'] ? 'Visitors are seeing the maintenance page' : 'All users can access the website normally'; ?></p>
            </div>
        </div>
        <form method="POST" style="margin: 0;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="toggle_quick">
            <button type="submit" class="btn <?php echo $settings['is_active'] ? 'btn-success' : 'btn-danger'; ?> btn-lg">
                <?php if ($settings['is_active']): ?>
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Disable Maintenance
                <?php else: ?>
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    Enable Maintenance
                <?php endif; ?>
            </button>
        </form>
    </div>
    
    <!-- Settings Form -->
    <div class="card">
        <div class="card-header">
            <h3>
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Maintenance Settings
            </h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-section">
                    <h4>Status</h4>
                    <div class="form-group">
                        <label class="toggle-switch">
                            <input type="checkbox" name="is_active" <?php echo $settings['is_active'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                            <span class="toggle-label">Enable Maintenance Mode</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-switch">
                            <input type="checkbox" name="allow_admin_access" <?php echo $settings['allow_admin_access'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                            <span class="toggle-label">Allow Admin Access During Maintenance</span>
                        </label>
                        <p class="form-text">When enabled, logged-in administrators can still access the site.</p>
                    </div>
                </div>
                
                <div class="form-section">
                    <h4>Maintenance Message</h4>
                    <div class="form-group">
                        <label>Message to Display <span class="required">*</span></label>
                        <textarea name="message" class="form-control" rows="4" required><?php echo sanitize($settings['message']); ?></textarea>
                        <p class="form-text">This message will be shown to visitors during maintenance.</p>
                    </div>
                </div>
                
                <div class="form-section">
                    <h4>Schedule Maintenance (Optional)</h4>
                    <p class="form-text" style="margin-bottom: 16px;">Schedule a maintenance window. If set, a countdown will be shown to users. The site will automatically return to normal after the end time.</p>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date & Time</label>
                            <input type="datetime-local" name="scheduled_start" class="form-control" value="<?php echo $settings['scheduled_start'] ? date('Y-m-d\TH:i', strtotime($settings['scheduled_start'])) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>End Date & Time</label>
                            <input type="datetime-local" name="scheduled_end" class="form-control" value="<?php echo $settings['scheduled_end'] ? date('Y-m-d\TH:i', strtotime($settings['scheduled_end'])) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Settings
                    </button>
                    <a href="/maintenance" target="_blank" class="btn btn-secondary">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        Preview Maintenance Page
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.maintenance-status-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px 32px;
    border-radius: 16px;
    margin-bottom: 24px;
    transition: all 0.3s ease;
}

.maintenance-status-card.inactive {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border: 1px solid #10b981;
}

.maintenance-status-card.active {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #ef4444;
    animation: pulse-warning 2s infinite;
}

@keyframes pulse-warning {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    50% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
}

.status-content {
    display: flex;
    align-items: center;
    gap: 20px;
}

.status-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.maintenance-status-card.inactive .status-icon {
    background: rgba(16, 185, 129, 0.2);
    color: #059669;
}

.maintenance-status-card.active .status-icon {
    background: rgba(239, 68, 68, 0.2);
    color: #dc2626;
}

.status-text h2 {
    margin: 0 0 4px 0;
    font-size: 1.4rem;
    font-weight: 700;
}

.maintenance-status-card.inactive .status-text h2 { color: #059669; }
.maintenance-status-card.active .status-text h2 { color: #dc2626; }

.status-text p {
    margin: 0;
    color: var(--text-muted);
    font-size: 0.95rem;
}

.form-section {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border-color);
}

.form-section:last-of-type {
    border-bottom: none;
    margin-bottom: 0;
}

.form-section h4 {
    margin: 0 0 16px 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-heading);
}

.toggle-switch {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    user-select: none;
}

.toggle-switch input {
    display: none;
}

.toggle-slider {
    width: 48px;
    height: 26px;
    background: #cbd5e1;
    border-radius: 26px;
    position: relative;
    transition: all 0.3s ease;
}

.toggle-slider::after {
    content: '';
    position: absolute;
    width: 22px;
    height: 22px;
    background: white;
    border-radius: 50%;
    top: 2px;
    left: 2px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.toggle-switch input:checked + .toggle-slider {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.toggle-switch input:checked + .toggle-slider::after {
    transform: translateX(22px);
}

.toggle-label {
    font-weight: 500;
    color: var(--text-body);
}

.btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.btn-success:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}

.btn-danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.btn-danger:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}

@media (max-width: 768px) {
    .maintenance-status-card {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .status-content {
        flex-direction: column;
    }
    
    .form-actions {
        flex-direction: column;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
