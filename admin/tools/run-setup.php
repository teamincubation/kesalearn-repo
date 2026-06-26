<?php
/**
 * KESA Learn — One-time DB Setup Runner
 * Visit: /admin/tools/run-setup.php
 * Runs setup_new_features.sql against the live database.
 * Safe to run multiple times (all statements use IF NOT EXISTS / IF EXISTS).
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$results = [];
$hasError = false;

// Each statement to run independently so we can report per-statement status
$statements = [

    // ── users table: add new columns ────────────────────────────────────────
    ['label' => 'users: add certificate_name',
     'sql'   => "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `certificate_name` VARCHAR(255) DEFAULT NULL AFTER `name`"],

    ['label' => 'users: add certificate_name_verified_at',
     'sql'   => "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `certificate_name_verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `certificate_name`"],

    ['label' => 'users: add whatsapp_collected',
     'sql'   => "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `whatsapp_collected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone`"],

    ['label' => 'users: add dob',
     'sql'   => "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `dob` DATE DEFAULT NULL AFTER `whatsapp_collected`"],

    ['label' => 'users: drop whatsapp_number column (if exists)',
     'sql'   => "ALTER TABLE `users` DROP COLUMN IF EXISTS `whatsapp_number`"],

    // ── backfill whatsapp_collected ──────────────────────────────────────────
    ['label' => 'users: backfill whatsapp_collected for existing users with phone',
     'sql'   => "UPDATE `users` SET `whatsapp_collected` = 1 WHERE `phone` IS NOT NULL AND `phone` != '' AND `whatsapp_collected` = 0"],

    // ── name_change_requests: DROP and recreate to fix any schema issues ─────
    ['label' => 'DROP TABLE name_change_requests (if exists)',
     'sql'   => "DROP TABLE IF EXISTS `name_change_requests`"],

    ['label' => 'CREATE TABLE name_change_requests',
     'sql'   => "CREATE TABLE `name_change_requests` (
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id`          INT UNSIGNED NOT NULL,
        `current_name`     VARCHAR(255) NOT NULL,
        `requested_name`   VARCHAR(255) NOT NULL,
        `id_document_path` VARCHAR(512) NOT NULL,
        `status`           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `admin_note`       TEXT DEFAULT NULL,
        `reviewed_by`      INT UNSIGNED DEFAULT NULL,
        `reviewed_at`      TIMESTAMP NULL DEFAULT NULL,
        `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_ncr_user_id` (`user_id`),
        KEY `idx_ncr_status`  (`status`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

    // ── event_materials ───────────────────────────────────────────────────────
    ['label' => 'CREATE TABLE event_materials',
     'sql'   => "CREATE TABLE IF NOT EXISTS `event_materials` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `event_id`       INT UNSIGNED NOT NULL,
        `title`          VARCHAR(255) NOT NULL,
        `description`    TEXT DEFAULT NULL,
        `file_path`      VARCHAR(512) NOT NULL,
        `file_type`      VARCHAR(20) NOT NULL DEFAULT 'pdf',
        `file_size`      BIGINT UNSIGNED DEFAULT NULL,
        `thumbnail_path` VARCHAR(512) DEFAULT NULL,
        `available_from` DATETIME DEFAULT NULL,
        `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
        `created_by`     INT UNSIGNED DEFAULT NULL,
        `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_em_event_id` (`event_id`),
        KEY `idx_em_available` (`available_from`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

    // ── patch: add created_by if missing (for tables created before this column was added) ──
    ['label' => 'event_materials: add created_by if missing',
     'sql'   => "ALTER TABLE `event_materials` ADD COLUMN IF NOT EXISTS `created_by` INT UNSIGNED DEFAULT NULL"],

    // ── material_reads ────────────────────────────────────────────────────────
    ['label' => 'CREATE TABLE material_reads',
     'sql'   => "CREATE TABLE IF NOT EXISTS `material_reads` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `material_id`  INT UNSIGNED NOT NULL,
        `user_id`      INT UNSIGNED NOT NULL,
        `event_id`     INT UNSIGNED NOT NULL,
        `first_read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_material_user` (`material_id`, `user_id`),
        KEY `idx_mr_user_event` (`user_id`, `event_id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

    // ── session_attendance ────────────────────────────────────────────────────
    ['label' => 'CREATE TABLE session_attendance',
     'sql'   => "CREATE TABLE IF NOT EXISTS `session_attendance` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `session_id` INT UNSIGNED NOT NULL,
        `user_id`    INT UNSIGNED NOT NULL,
        `event_id`   INT UNSIGNED NOT NULL,
        `attended`   TINYINT(1) NOT NULL DEFAULT 0,
        `marked_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_sa_session_user` (`session_id`, `user_id`),
        KEY `idx_sa_user_event` (`user_id`, `event_id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

    // ── user_event_activities ─────────────────────────────────────────────────
    ['label' => 'CREATE TABLE user_event_activities',
     'sql'   => "CREATE TABLE IF NOT EXISTS `user_event_activities` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id`       INT UNSIGNED NOT NULL,
        `event_id`      INT UNSIGNED NOT NULL,
        `activity_type` ENUM(
            'registered','session_attended','session_missed','material_read',
            'assignment_submitted','quiz_attempted','quiz_completed',
            'feedback_submitted','certificate_downloaded','whatsapp_updated'
        ) NOT NULL,
        `reference_id`   INT UNSIGNED DEFAULT NULL,
        `reference_name` VARCHAR(255) DEFAULT NULL,
        `score`          DECIMAL(5,2) DEFAULT NULL,
        `max_score`      DECIMAL(5,2) DEFAULT NULL,
        `meta`           JSON DEFAULT NULL,
        `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_uea_user_event` (`user_id`, `event_id`),
        KEY `idx_uea_type`       (`activity_type`),
        KEY `idx_uea_created`    (`created_at`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

    // ── ensure uploads dirs exist (touch a placeholder) ───────────────────────
];

// Create upload directories
$uploadDirs = [
    __DIR__ . '/../../uploads/name_change_docs',
    __DIR__ . '/../../uploads/reading_materials',
];
foreach ($uploadDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        $results[] = ['label' => 'mkdir ' . basename($dir), 'ok' => true, 'msg' => 'Created'];
    } else {
        $results[] = ['label' => 'mkdir ' . basename($dir), 'ok' => true, 'msg' => 'Already exists'];
    }
}

// Run each SQL statement
foreach ($statements as $s) {
    try {
        $db->exec($s['sql']);
        $results[] = ['label' => $s['label'], 'ok' => true, 'msg' => 'OK'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Treat "Can't DROP ... check that column/key exists" as a soft warning
        if (stripos($msg, "can't drop") !== false || stripos($msg, 'check that column') !== false) {
            $results[] = ['label' => $s['label'], 'ok' => true, 'msg' => 'Skipped (already applied)'];
        } else {
            $results[] = ['label' => $s['label'], 'ok' => false, 'msg' => $msg];
            $hasError = true;
        }
    }
}

$adminPage = 'tools';
$pageTitle = 'Run DB Setup';
include __DIR__ . '/../includes/sidebar.php';
?>

<style>
.setup-card {
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    border: 1px solid var(--border-light);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    max-width: 760px;
}
.setup-card-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    align-items: center;
    gap: 14px;
}
.setup-card-header svg { color: #0ea5e9; flex-shrink: 0; }
.setup-card-header h2 { margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
.setup-card-header p  { margin: 2px 0 0; font-size: 0.83rem; color: var(--text-muted); }
.setup-result-list { padding: 16px 24px; display: flex; flex-direction: column; gap: 8px; }
.setup-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    border-radius: var(--radius-md);
    font-size: 0.84rem;
}
.setup-row.ok  { background: #f0fdf4; }
.setup-row.err { background: #fef2f2; }
.setup-row-icon { flex-shrink: 0; margin-top: 1px; }
.setup-row-label { font-weight: 600; color: var(--text-primary); }
.setup-row-msg   { color: var(--text-muted); font-size: 0.79rem; margin-top: 2px; }
.setup-summary {
    padding: 16px 24px;
    border-top: 1px solid var(--border-light);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.setup-summary-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
}
.badge-success { background: #dcfce7; color: #15803d; }
.badge-error   { background: #fee2e2; color: #dc2626; }
</style>

<div class="admin-page-header">
    <div>
        <h1>Database Setup</h1>
        <p>Run new-feature migrations against the live database.</p>
    </div>
</div>

<div class="setup-card">
    <div class="setup-card-header">
        <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
        </svg>
        <div>
            <h2>Migration Results</h2>
            <p><?php echo count($results); ?> operations executed</p>
        </div>
    </div>
    <div class="setup-result-list">
        <?php foreach ($results as $r): ?>
        <div class="setup-row <?php echo $r['ok'] ? 'ok' : 'err'; ?>">
            <div class="setup-row-icon">
                <?php if ($r['ok']): ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                <?php else: ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                <?php endif; ?>
            </div>
            <div>
                <div class="setup-row-label"><?php echo htmlspecialchars($r['label']); ?></div>
                <div class="setup-row-msg"><?php echo htmlspecialchars($r['msg']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="setup-summary">
        <?php if (!$hasError): ?>
        <span class="setup-summary-badge badge-success">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            All migrations applied successfully
        </span>
        <?php else: ?>
        <span class="setup-summary-badge badge-error">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            Some operations failed — check messages above
        </span>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="/admin/reading-materials/" class="btn btn-sm btn-secondary">Reading Materials</a>
            <a href="/admin/users/name-change-requests.php" class="btn btn-sm btn-secondary">Name Change Requests</a>
            <a href="/admin/" class="btn btn-sm btn-primary">Dashboard</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
