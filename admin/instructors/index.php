<?php
/**
 * KESA Learn - Admin: Instructor Management
 * Professionally redesigned instructor management system
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'instructors';
$pageTitle = 'Instructor Management';

// Check permission
checkSectionPermission('instructors');

// Handle template download BEFORE any output
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="instructors_template.csv"');
    echo "name,mobile,email,qualification,designation,experience,whatsapp,languages,bio\n";
    echo "Dr. John Smith,9876543210,john@email.com,PhD Computer Science,Professor,15+ years,9876543210,\"English,Hindi\",Expert in AI and Machine Learning\n";
    echo "Jane Doe,8765432109,jane@email.com,MBA,Business Trainer,8 years,8765432109,English,Corporate training specialist\n";
    exit;
}

// Auto-ensure table has all columns
try {
    $db->exec("ALTER TABLE `instructors` MODIFY COLUMN `mobile` VARCHAR(20) DEFAULT NULL");
    $db->exec("ALTER TABLE `instructors` MODIFY COLUMN `email` VARCHAR(255) DEFAULT NULL");
    $db->exec("ALTER TABLE `instructors` MODIFY COLUMN `photo` VARCHAR(255) DEFAULT NULL");
    $db->exec("ALTER TABLE `instructors` ADD COLUMN IF NOT EXISTS `bio` TEXT DEFAULT NULL");
    $db->exec("ALTER TABLE `instructors` ADD COLUMN IF NOT EXISTS `linkedin` VARCHAR(255) DEFAULT NULL");
    $db->exec("ALTER TABLE `instructors` ADD COLUMN IF NOT EXISTS `specializations` TEXT DEFAULT NULL");
} catch (PDOException $e) {
    // Columns may already exist
}

// Fetch available languages
$languages = $db->query("SELECT * FROM languages WHERE is_active = 1 ORDER BY name")->fetchAll();

// Handle bulk delete
if (isset($_POST['bulk_delete']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? '')));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM instructors WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $deleted = $stmt->rowCount();
        logActivity('instructors_bulk_deleted', "Deleted $deleted instructors");
        setFlash('success', "$deleted instructor(s) deleted successfully.");
    }
    redirect('/admin/instructors/');
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/instructors/');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $mobile = sanitize($_POST['mobile'] ?? '');
        $email = sanitizeEmail($_POST['email'] ?? '');
        $qualification = sanitize($_POST['qualification'] ?? '');
        $designation = sanitize($_POST['designation'] ?? '');
        $experience = sanitize($_POST['experience'] ?? '');
        $whatsapp = sanitize($_POST['whatsapp'] ?? '');
        $bio = sanitize($_POST['bio'] ?? '');
        $linkedin = sanitize($_POST['linkedin'] ?? '');
        $specializations = sanitize($_POST['specializations'] ?? '');
        $selectedLangs = isset($_POST['languages']) ? implode(',', $_POST['languages']) : '';
        
        if (empty($name)) {
            setFlash('error', 'Name is required.');
            redirect('/admin/instructors/');
        }
        
        // Handle photo upload
        $photo = null;
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'instructors');
            if ($upload['success']) {
                $photo = $upload['filename'];
            }
        }
        
        if ($action === 'create') {
            $mobileValue = !empty($mobile) ? $mobile : null;
            $emailValue = !empty($email) ? $email : null;
            $photoValue = !empty($photo) ? $photo : null;
            
            $stmt = $db->prepare("INSERT INTO instructors (name, mobile, email, photo, qualification, designation, experience, whatsapp, languages, bio, linkedin, specializations) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $mobileValue, $emailValue, $photoValue, $qualification, $designation, $experience, $whatsapp, $selectedLangs, $bio, $linkedin, $specializations]);
            logActivity('instructor_created', "Created instructor: $name");
            setFlash('success', 'Instructor added successfully!');
        } else {
            if ($photo) {
                $stmt = $db->prepare("UPDATE instructors SET name=?, mobile=?, email=?, photo=?, qualification=?, designation=?, experience=?, whatsapp=?, languages=?, bio=?, linkedin=?, specializations=? WHERE id=?");
                $stmt->execute([$name, $mobile ?: null, $email ?: null, $photo, $qualification, $designation, $experience, $whatsapp, $selectedLangs, $bio, $linkedin, $specializations, $id]);
            } else {
                $stmt = $db->prepare("UPDATE instructors SET name=?, mobile=?, email=?, qualification=?, designation=?, experience=?, whatsapp=?, languages=?, bio=?, linkedin=?, specializations=? WHERE id=?");
                $stmt->execute([$name, $mobile ?: null, $email ?: null, $qualification, $designation, $experience, $whatsapp, $selectedLangs, $bio, $linkedin, $specializations, $id]);
            }
            logActivity('instructor_updated', "Updated instructor: $name (ID: $id)");
            setFlash('success', 'Instructor updated successfully!');
        }
        redirect('/admin/instructors/');
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM instructors WHERE id = ?")->execute([$id]);
        logActivity('instructor_deleted', "Deleted instructor ID: $id");
        setFlash('success', 'Instructor deleted.');
        redirect('/admin/instructors/');
    }
    
    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        $db->prepare("UPDATE instructors SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        logActivity('instructor_toggled', "Toggled instructor status ID: $id");
        redirect('/admin/instructors/');
    }
    
    // Bulk CSV Upload
    if ($action === 'bulk_upload' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'File upload failed.');
            redirect('/admin/instructors/');
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            setFlash('error', 'Please upload a CSV file.');
            redirect('/admin/instructors/');
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            setFlash('error', 'Could not read the file.');
            redirect('/admin/instructors/');
        }
        
        // Skip header row
        $header = fgetcsv($handle);
        
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($row) < 1 || empty(trim($row[0]))) {
                $skipped++;
                continue;
            }
            
            $name = sanitize(trim($row[0] ?? ''));
            $mobile = sanitize(trim($row[1] ?? ''));
            $email = sanitizeEmail(trim($row[2] ?? ''));
            $qualification = sanitize(trim($row[3] ?? ''));
            $designation = sanitize(trim($row[4] ?? ''));
            $experience = sanitize(trim($row[5] ?? ''));
            $whatsapp = sanitize(trim($row[6] ?? ''));
            $languageCodes = sanitize(trim($row[7] ?? ''));
            $bio = sanitize(trim($row[8] ?? ''));
            
            // Check for duplicate email only if email is provided
            if (!empty($email)) {
                $checkStmt = $db->prepare("SELECT id FROM instructors WHERE email = ?");
                $checkStmt->execute([$email]);
                if ($checkStmt->fetch()) {
                    $errors[] = "Row $rowNum: Duplicate email ($email)";
                    $skipped++;
                    continue;
                }
            }
            
            try {
                $stmt = $db->prepare("INSERT INTO instructors (name, mobile, email, qualification, designation, experience, whatsapp, languages, bio, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([
                    $name, 
                    !empty($mobile) ? $mobile : null, 
                    !empty($email) ? $email : null, 
                    $qualification, 
                    $designation, 
                    $experience, 
                    $whatsapp, 
                    $languageCodes,
                    $bio
                ]);
                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row $rowNum: Database error";
                $skipped++;
            }
        }
        
        fclose($handle);
        
        logActivity('instructors_bulk_upload', "Imported $imported instructors, skipped $skipped");
        
        $msg = "Successfully imported $imported instructor(s).";
        if ($skipped > 0) {
            $msg .= " Skipped $skipped row(s).";
        }
        setFlash('success', $msg);
        
        if (!empty($errors)) {
            $_SESSION['import_errors'] = array_slice($errors, 0, 5);
        }
        
        redirect('/admin/instructors/');
    }
}

// Filtering and Search
$search = sanitize($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$view = $_GET['view'] ?? 'grid';

$where = ['1=1'];
$params = [];

if (!empty($search)) {
    $where[] = "(name LIKE ? OR email LIKE ? OR designation LIKE ? OR qualification LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($status === 'active') {
    $where[] = "is_active = 1";
} elseif ($status === 'inactive') {
    $where[] = "is_active = 0";
}

$whereClause = implode(' AND ', $where);
$instructors = $db->prepare("SELECT * FROM instructors WHERE $whereClause ORDER BY name ASC");
$instructors->execute($params);
$instructors = $instructors->fetchAll();

// Stats
$totalInstructors = $db->query("SELECT COUNT(*) FROM instructors")->fetchColumn();
$activeInstructors = $db->query("SELECT COUNT(*) FROM instructors WHERE is_active = 1")->fetchColumn();
$inactiveInstructors = $totalInstructors - $activeInstructors;

// Get events count per instructor (for display)
$instructorEvents = [];
try {
    $eventsQuery = $db->query("SELECT instructor_id, COUNT(*) as count FROM events WHERE instructor_id IS NOT NULL GROUP BY instructor_id");
    while ($row = $eventsQuery->fetch()) {
        $instructorEvents[$row['instructor_id']] = $row['count'];
    }
} catch (PDOException $e) {
    // Column might not exist
}

include __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* Instructor Management Styles */
.instructor-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.instructor-header-left h2 {
    margin: 0 0 8px 0;
    font-size: 1.5rem;
}
.instructor-header-left p {
    margin: 0;
    color: var(--text-muted);
    font-size: 0.9rem;
}
.instructor-header-right {
    display: flex;
    gap: 10px;
}

/* Stats Cards */
.instructor-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.stat-icon.blue { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #2563eb; }
.stat-icon.green { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; }
.stat-icon.gray { background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); color: #64748b; }
.stat-content h4 {
    margin: 0;
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
}
.stat-content p {
    margin: 4px 0 0 0;
    font-size: 0.85rem;
    color: var(--text-muted);
}

/* Filter Bar */
.filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.filter-bar-left {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.filter-bar-left .search-box {
    position: relative;
}
.filter-bar-left .search-box input {
    padding-left: 40px;
    min-width: 280px;
}
.filter-bar-left .search-box svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
}
.filter-bar-right {
    display: flex;
    gap: 8px;
}
.view-toggle {
    display: flex;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
}
.view-toggle a {
    padding: 8px 12px;
    color: var(--text-muted);
    text-decoration: none;
    display: flex;
    align-items: center;
}
.view-toggle a.active {
    background: var(--blue);
    color: white;
}

/* Instructor Grid */
.instructor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}
.instructor-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}
.instructor-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}
.instructor-card-top {
    position: relative;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 24px;
    text-align: center;
}
.instructor-card.inactive .instructor-card-top {
    background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
}
.instructor-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.3);
    margin: 0 auto 12px;
    overflow: hidden;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
}
.instructor-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.instructor-name {
    color: white;
    font-size: 1.15rem;
    font-weight: 600;
    margin: 0 0 4px 0;
}
.instructor-designation {
    color: rgba(255,255,255,0.85);
    font-size: 0.85rem;
    margin: 0;
}
.instructor-status-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}
.instructor-status-badge.active {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
.instructor-status-badge.inactive {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}
.instructor-card-body {
    padding: 20px;
}
.instructor-info-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.85rem;
}
.instructor-info-row:last-child {
    border-bottom: none;
}
.instructor-info-row svg {
    width: 18px;
    height: 18px;
    color: var(--text-muted);
    flex-shrink: 0;
}
.instructor-info-row span {
    color: var(--text-primary);
    word-break: break-word;
}
.instructor-info-row .empty {
    color: var(--text-muted);
    font-style: italic;
}
.instructor-languages {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 12px;
}
.instructor-languages .lang-tag {
    background: var(--bg-tertiary);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    color: var(--text-secondary);
}
.instructor-card-footer {
    padding: 16px 20px;
    background: var(--bg-secondary);
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

/* Table View */
.instructor-table {
    width: 100%;
    border-collapse: collapse;
}
.instructor-table th,
.instructor-table td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
.instructor-table th {
    background: var(--bg-secondary);
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--text-secondary);
}
.instructor-table tr:hover {
    background: var(--bg-secondary);
}
.instructor-table .instructor-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}
.instructor-table .instructor-cell-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.instructor-table .instructor-cell-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}
.instructor-table .instructor-cell-info h4 {
    margin: 0 0 2px 0;
    font-size: 0.95rem;
}
.instructor-table .instructor-cell-info p {
    margin: 0;
    font-size: 0.8rem;
    color: var(--text-muted);
}
.instructor-table .actions-cell {
    white-space: nowrap;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-primary);
    border: 2px dashed var(--border-color);
    border-radius: 16px;
}
.empty-state svg {
    width: 64px;
    height: 64px;
    color: var(--text-muted);
    margin-bottom: 16px;
}
.empty-state h3 {
    margin: 0 0 8px 0;
    color: var(--text-primary);
}
.empty-state p {
    margin: 0 0 20px 0;
    color: var(--text-muted);
}

/* Import Errors */
.import-errors {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}
.import-errors h4 {
    margin: 0 0 8px 0;
    color: #991b1b;
    font-size: 0.9rem;
}
.import-errors ul {
    margin: 0;
    padding-left: 20px;
    color: #dc2626;
    font-size: 0.85rem;
}
</style>

<!-- Header -->
<div class="instructor-header">
    <div class="instructor-header-left">
        <h2>Instructor Management</h2>
        <p>Manage your instructors, faculty members, and trainers</p>
    </div>
    <div class="instructor-header-right">
        <button type="button" class="btn btn-secondary" onclick="showModal('bulkModal')">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            Bulk Import
        </button>
        <button type="button" class="btn btn-primary" onclick="showModal('addModal')">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            Add Instructor
        </button>
    </div>
</div>

<!-- Import Errors (if any) -->
<?php if (!empty($_SESSION['import_errors'])): ?>
<div class="import-errors">
    <h4>Import Issues:</h4>
    <ul>
        <?php foreach ($_SESSION['import_errors'] as $err): ?>
            <li><?php echo sanitize($err); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php unset($_SESSION['import_errors']); endif; ?>

<!-- Stats -->
<div class="instructor-stats">
    <div class="stat-card">
        <div class="stat-icon blue">
            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        </div>
        <div class="stat-content">
            <h4><?php echo $totalInstructors; ?></h4>
            <p>Total Instructors</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="stat-content">
            <h4><?php echo $activeInstructors; ?></h4>
            <p>Active Instructors</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon gray">
            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
        </div>
        <div class="stat-content">
            <h4><?php echo $inactiveInstructors; ?></h4>
            <p>Inactive Instructors</p>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-bar-left">
        <form method="GET" class="search-box">
            <input type="hidden" name="view" value="<?php echo sanitize($view); ?>">
            <input type="hidden" name="status" value="<?php echo sanitize($status); ?>">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="q" class="form-control" placeholder="Search instructors..." value="<?php echo sanitize($search); ?>">
        </form>
        <select class="form-control" style="width: auto;" onchange="window.location.href='?view=<?php echo $view; ?>&q=<?php echo urlencode($search); ?>&status='+this.value">
            <option value="">All Status</option>
            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active Only</option>
            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive Only</option>
        </select>
        <?php if (!empty($search) || !empty($status)): ?>
            <a href="/admin/instructors/?view=<?php echo $view; ?>" class="btn btn-sm btn-secondary">Clear Filters</a>
        <?php endif; ?>
    </div>
    <div class="filter-bar-right">
        <div class="view-toggle">
            <a href="?view=grid&q=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>" class="<?php echo $view === 'grid' ? 'active' : ''; ?>" title="Grid View">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            </a>
            <a href="?view=table&q=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>" class="<?php echo $view === 'table' ? 'active' : ''; ?>" title="Table View">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            </a>
        </div>
    </div>
</div>

<!-- Content -->
<?php if (empty($instructors)): ?>
    <div class="empty-state">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        <h3>No Instructors Found</h3>
        <p>Get started by adding your first instructor or adjust your filters.</p>
        <button type="button" class="btn btn-primary" onclick="showModal('addModal')">Add First Instructor</button>
    </div>
<?php elseif ($view === 'table'): ?>
    <!-- Table View -->
    <div class="table-responsive" style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden;">
        <table class="instructor-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll" class="select-all-checkbox" title="Select all"></th>
                    <th>Instructor</th>
                    <th>Contact</th>
                    <th>Qualification</th>
                    <th>Experience</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($instructors as $ins): ?>
                <tr>
                    <td><input type="checkbox" class="row-checkbox" value="<?php echo $ins['id']; ?>"></td>
                    <td>
                        <div class="instructor-cell">
                            <div class="instructor-cell-avatar">
                                <?php if (!empty($ins['photo'])): ?>
                                    <img src="/uploads/instructors/<?php echo sanitize($ins['photo']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($ins['name'], 0, 2)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="instructor-cell-info">
                                <h4><?php echo sanitize($ins['name']); ?></h4>
                                <p><?php echo sanitize($ins['designation'] ?: 'No designation'); ?></p>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($ins['email'])): ?>
                            <div style="font-size: 0.85rem;"><?php echo sanitize($ins['email']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($ins['mobile'])): ?>
                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo sanitize($ins['mobile']); ?></div>
                        <?php endif; ?>
                        <?php if (empty($ins['email']) && empty($ins['mobile'])): ?>
                            <span style="color: var(--text-muted); font-style: italic;">No contact</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sanitize($ins['qualification'] ?: '-'); ?></td>
                    <td><?php echo sanitize($ins['experience'] ?: '-'); ?></td>
                    <td>
                        <?php if ($ins['is_active']): ?>
                            <span class="badge badge-green">Active</span>
                        <?php else: ?>
                            <span class="badge badge-gray">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions-cell">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="editInstructor(<?php echo htmlspecialchars(json_encode($ins)); ?>)">Edit</button>
                        <form method="POST" style="display:inline;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?php echo $ins['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"><?php echo $ins['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this instructor?');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $ins['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <!-- Grid View -->
    <div class="instructor-grid">
        <?php foreach ($instructors as $ins): 
            $langArray = !empty($ins['languages']) ? explode(',', $ins['languages']) : [];
        ?>
        <div class="instructor-card <?php echo !$ins['is_active'] ? 'inactive' : ''; ?>">
            <div class="instructor-card-top">
                <span class="instructor-status-badge <?php echo $ins['is_active'] ? 'active' : 'inactive'; ?>">
                    <?php echo $ins['is_active'] ? 'Active' : 'Inactive'; ?>
                </span>
                <div class="instructor-avatar">
                    <?php if (!empty($ins['photo'])): ?>
                        <img src="/uploads/instructors/<?php echo sanitize($ins['photo']); ?>" alt="">
                    <?php else: ?>
                        <?php echo strtoupper(substr($ins['name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>
                <h3 class="instructor-name"><?php echo sanitize($ins['name']); ?></h3>
                <p class="instructor-designation"><?php echo sanitize($ins['designation'] ?: 'Instructor'); ?></p>
            </div>
            <div class="instructor-card-body">
                <div class="instructor-info-row">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span class="<?php echo empty($ins['email']) ? 'empty' : ''; ?>"><?php echo !empty($ins['email']) ? sanitize($ins['email']) : 'No email'; ?></span>
                </div>
                <div class="instructor-info-row">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    <span class="<?php echo empty($ins['mobile']) ? 'empty' : ''; ?>"><?php echo !empty($ins['mobile']) ? sanitize($ins['mobile']) : 'No phone'; ?></span>
                </div>
                <div class="instructor-info-row">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                    <span class="<?php echo empty($ins['qualification']) ? 'empty' : ''; ?>"><?php echo !empty($ins['qualification']) ? sanitize($ins['qualification']) : 'Not specified'; ?></span>
                </div>
                <div class="instructor-info-row">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="<?php echo empty($ins['experience']) ? 'empty' : ''; ?>"><?php echo !empty($ins['experience']) ? sanitize($ins['experience']) : 'Not specified'; ?></span>
                </div>
                <?php if (!empty($langArray)): ?>
                <div class="instructor-languages">
                    <?php foreach ($langArray as $lang): ?>
                        <span class="lang-tag"><?php echo sanitize(trim($lang)); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="instructor-card-footer">
                <form method="POST" style="display:inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo $ins['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"><?php echo $ins['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                </form>
                <button type="button" class="btn btn-sm btn-primary" onclick="editInstructor(<?php echo htmlspecialchars(json_encode($ins)); ?>)">Edit</button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this instructor?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $ins['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add Instructor Modal -->
<div id="addModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h3>Add New Instructor</h3>
            <button type="button" class="modal-close" onclick="hideModal('addModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create">
            
            <div style="padding: 24px; max-height: 60vh; overflow-y: auto;">
                <!-- Basic Info -->
                <div style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Basic Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="Dr. John Smith">
                        </div>
                        <div class="form-group">
                            <label>Designation</label>
                            <input type="text" name="designation" class="form-control" placeholder="Professor, Trainer, etc.">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Photo</label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                        <small style="color: var(--text-muted);">Recommended: Square image, 300x300px minimum</small>
                    </div>
                </div>
                
                <!-- Contact Info -->
                <div style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Contact Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="instructor@email.com">
                        </div>
                        <div class="form-group">
                            <label>Mobile Number</label>
                            <input type="tel" name="mobile" class="form-control" placeholder="+91 98765 43210">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>WhatsApp Number</label>
                            <input type="tel" name="whatsapp" class="form-control" placeholder="+91 98765 43210">
                        </div>
                        <div class="form-group">
                            <label>LinkedIn Profile URL</label>
                            <input type="url" name="linkedin" class="form-control" placeholder="https://linkedin.com/in/username">
                        </div>
                    </div>
                </div>
                
                <!-- Professional Info -->
                <div style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Professional Details</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Qualification</label>
                            <input type="text" name="qualification" class="form-control" placeholder="PhD, MBA, M.Tech, etc.">
                        </div>
                        <div class="form-group">
                            <label>Experience</label>
                            <input type="text" name="experience" class="form-control" placeholder="10+ years">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Specializations</label>
                        <input type="text" name="specializations" class="form-control" placeholder="AI, Machine Learning, Data Science">
                        <small style="color: var(--text-muted);">Comma-separated list of areas of expertise</small>
                    </div>
                    <div class="form-group">
                        <label>Languages Spoken</label>
                        <select name="languages[]" class="form-control" multiple style="height: 100px;">
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo sanitize($lang['name']); ?>"><?php echo sanitize($lang['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted);">Hold Ctrl/Cmd to select multiple</small>
                    </div>
                </div>
                
                <!-- Bio -->
                <div>
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Biography</h4>
                    <div class="form-group">
                        <label>Short Bio</label>
                        <textarea name="bio" class="form-control" rows="3" placeholder="Brief description of the instructor's background and expertise..."></textarea>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Instructor</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Instructor Modal -->
<div id="editModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h3>Edit Instructor</h3>
            <button type="button" class="modal-close" onclick="hideModal('editModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            
            <div style="padding: 24px; max-height: 60vh; overflow-y: auto;">
                <!-- Basic Info -->
                <div style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Basic Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Designation</label>
                            <input type="text" name="designation" id="edit_designation" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Photo</label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                        <small style="color: var(--text-muted);">Leave empty to keep current photo</small>
                    </div>
                </div>
                
                <!-- Contact Info -->
                <div style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Contact Information</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Mobile Number</label>
                            <input type="tel" name="mobile" id="edit_mobile" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>WhatsApp Number</label>
                            <input type="tel" name="whatsapp" id="edit_whatsapp" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>LinkedIn Profile URL</label>
                            <input type="url" name="linkedin" id="edit_linkedin" class="form-control">
                        </div>
                    </div>
                </div>
                
                <!-- Professional Info -->
                <div style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Professional Details</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Qualification</label>
                            <input type="text" name="qualification" id="edit_qualification" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Experience</label>
                            <input type="text" name="experience" id="edit_experience" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Specializations</label>
                        <input type="text" name="specializations" id="edit_specializations" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Languages Spoken</label>
                        <select name="languages[]" id="edit_languages" class="form-control" multiple style="height: 100px;">
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo sanitize($lang['name']); ?>"><?php echo sanitize($lang['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Bio -->
                <div>
                    <h4 style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Biography</h4>
                    <div class="form-group">
                        <label>Short Bio</label>
                        <textarea name="bio" id="edit_bio" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Instructor</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Upload Modal -->
<div id="bulkModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:650px;">
        <div class="modal-header">
            <h3>Bulk Import Instructors</h3>
            <button type="button" class="modal-close" onclick="hideModal('bulkModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="bulk_upload">
            
            <div style="padding: 24px;">
                <!-- Instructions -->
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 8px 0; display: flex; align-items: center; gap: 8px;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        CSV Format Required
                    </h4>
                    <p style="font-size: 0.9rem; opacity: 0.9; margin-bottom: 12px;">Upload a CSV file with the following columns (in order):</p>
                    <code style="background: rgba(255,255,255,0.15); padding: 10px 14px; border-radius: 6px; display: block; font-size: 0.8rem; line-height: 1.5;">
                        name, mobile, email, qualification, designation, experience, whatsapp, languages, bio
                    </code>
                </div>
                
                <!-- Example -->
                <div style="background: var(--bg-tertiary); border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                    <h5 style="margin: 0 0 10px 0; font-size: 0.85rem; color: var(--text-secondary);">Example CSV Content:</h5>
                    <pre style="font-size: 0.75rem; margin: 0; overflow-x: auto; white-space: pre-wrap; color: var(--text-primary); line-height: 1.5;">name,mobile,email,qualification,designation,experience,whatsapp,languages,bio
Dr. John Smith,9876543210,john@email.com,PhD,Professor,15+ years,9876543210,"English,Hindi",AI Expert
Jane Doe,8765432109,jane@email.com,MBA,Trainer,8 years,8765432109,English,Corporate trainer</pre>
                </div>
                
                <!-- File Upload -->
                <div class="form-group">
                    <label style="font-weight: 600;">Select CSV File <span class="required">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required style="padding: 12px;">
                    <small style="color: var(--text-muted); display: block; margin-top: 6px;">Only .csv files accepted. Maximum 500 rows per upload.</small>
                </div>
                
                <!-- Notes -->
                <div style="background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; padding: 14px; border-radius: 8px; font-size: 0.85rem;">
                    <strong>Important Notes:</strong>
                    <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                        <li>Only the <strong>name</strong> field is required</li>
                        <li>Duplicate emails will be skipped</li>
                        <li>For multiple languages, use comma-separated values in quotes</li>
                    </ul>
                </div>
            </div>
            
            <div class="modal-footer">
                <a href="/admin/instructors/?download_template=1" class="btn btn-secondary" style="margin-right: auto;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 4px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download Template
                </a>
                <button type="button" class="btn btn-secondary" onclick="hideModal('bulkModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Import Instructors</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <div class="bulk-actions-info">
        <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        <span>instructor(s)</span>
    </div>
    <div class="bulk-actions-buttons">
        <button type="button" class="btn-bulk-cancel" onclick="document.getElementById('selectAll').click();">Cancel</button>
        <button type="button" id="bulkDeleteBtn" class="btn-bulk-delete" data-item-name="instructors">
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
function showModal(id) {
    document.getElementById(id).style.display = 'flex';
}
function hideModal(id) {
    document.getElementById(id).style.display = 'none';
}

function editInstructor(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_name').value = data.name || '';
    document.getElementById('edit_mobile').value = data.mobile || '';
    document.getElementById('edit_email').value = data.email || '';
    document.getElementById('edit_whatsapp').value = data.whatsapp || '';
    document.getElementById('edit_qualification').value = data.qualification || '';
    document.getElementById('edit_designation').value = data.designation || '';
    document.getElementById('edit_experience').value = data.experience || '';
    document.getElementById('edit_bio').value = data.bio || '';
    document.getElementById('edit_linkedin').value = data.linkedin || '';
    document.getElementById('edit_specializations').value = data.specializations || '';
    
    // Set languages multi-select
    var langSelect = document.getElementById('edit_languages');
    var langs = (data.languages || '').split(',').map(function(l) { return l.trim(); });
    for (var i = 0; i < langSelect.options.length; i++) {
        langSelect.options[i].selected = langs.indexOf(langSelect.options[i].value) > -1;
    }
    
    showModal('editModal');
}

// Close modal on outside click
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === m) hideModal(m.id);
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(function(m) {
            if (m.style.display === 'flex') hideModal(m.id);
        });
    }
});
</script>

<script src="/assets/js/admin-bulk-actions.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
