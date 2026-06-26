<?php
$adminPage = 'name_change_requests';
$pageTitle  = 'Name Change Requests';
require_once __DIR__ . '/../../includes/admin_check.php';

$db    = getDB();
$flash = getFlash();
$dbError = null;

// ── POST: approve / reject ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']     ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($requestId && in_array($action, ['approve', 'reject', 'delete'])) {
        try {
            if ($action === 'delete') {
                $db->prepare("DELETE FROM name_change_requests WHERE id = ?")->execute([$requestId]);
                setFlash('info', 'Request deleted.');
            } else {
                $reqStmt = $db->prepare("SELECT * FROM name_change_requests WHERE id = ?");
                $reqStmt->execute([$requestId]);
                $req = $reqStmt->fetch(PDO::FETCH_ASSOC);

                if ($req) {
                    $newStatus  = $action === 'approve' ? 'approved' : 'rejected';
                    $reviewerId = $_SESSION['user_id'] ?? null;

                    $db->prepare("UPDATE name_change_requests SET status=?, admin_note=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")
                       ->execute([$newStatus, $adminNote, $reviewerId, $requestId]);

                    if ($action === 'approve') {
                        // Update user's name in users table
                        $db->prepare("UPDATE users SET name=? WHERE id=?")
                           ->execute([$req['requested_name'], $req['user_id']]);
                        setFlash('success', 'Approved — name updated to "' . htmlspecialchars($req['requested_name']) . '".');
                    } else {
                        setFlash('info', 'Request rejected.');
                    }
                }
            }
        } catch (Exception $e) {
            setFlash('error', 'Error: ' . $e->getMessage());
        }
    }
    header('Location: /admin/users/name-change-requests.php?status=' . ($_POST['status_tab'] ?? 'pending'));
    exit;
}

// ── Fetch requests ────────────────────────────────────────────────────────
$filterStatus    = $_GET['status'] ?? 'pending';
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filterStatus, $allowedStatuses)) $filterStatus = 'pending';

$requests = [];
$counts   = ['pending' => 0, 'approved' => 0, 'rejected' => 0];

try {
    $sql    = "SELECT ncr.*, u.email, u.phone
               FROM name_change_requests ncr
               JOIN users u ON u.id = ncr.user_id";
    $params = [];
    if ($filterStatus !== 'all') {
        $sql     .= " WHERE ncr.status = ?";
        $params[] = $filterStatus;
    }
    $sql .= " ORDER BY ncr.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($db->query("SELECT status, COUNT(*) c FROM name_change_requests GROUP BY status")->fetchAll() as $r) {
        $counts[$r['status']] = (int)$r['c'];
    }

} catch (Exception $e) {
    $dbError = $e->getMessage();
}

include __DIR__ . '/../includes/sidebar.php';
?>
<style>
.ncr-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
.ncr-header h1 { margin:0 0 4px; font-size:1.45rem; font-weight:800; color:var(--text-primary); }
.ncr-header p  { margin:0; font-size:0.87rem; color:var(--text-muted); }
.ncr-pending-badge { background:#fef9c3; color:#92400e; padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; border:1px solid #fde68a; }

.ncr-dberr { background:#fff7ed; border:1.5px solid #fed7aa; border-radius:12px; padding:18px 20px; margin-bottom:24px; color:#9a3412; font-size:0.88rem; }
.ncr-dberr a { color:#ea580c; font-weight:700; }

.ncr-tabs { display:flex; gap:6px; margin-bottom:22px; flex-wrap:wrap; }
.ncr-tab { padding:7px 16px; border-radius:20px; font-size:0.84rem; font-weight:600; border:1.5px solid var(--border-color); background:var(--bg-primary); color:var(--text-secondary); text-decoration:none; display:inline-flex; align-items:center; gap:7px; transition:all 0.15s; }
.ncr-tab:hover { border-color:#0ea5e9; color:#0ea5e9; }
.ncr-tab.active { background:#0ea5e9; border-color:#0ea5e9; color:#fff; }
.ncr-badge { background:rgba(0,0,0,.07); border-radius:10px; padding:1px 7px; font-size:0.72rem; font-weight:700; }
.ncr-tab.active .ncr-badge { background:rgba(255,255,255,.25); }

.ncr-grid { display:flex; flex-direction:column; gap:12px; }

.ncr-card { background:var(--bg-primary); border-radius:14px; border:1px solid var(--border-light,#e5e7eb); padding:20px 22px; box-shadow:0 1px 4px rgba(0,0,0,.04); display:flex; gap:20px; align-items:flex-start; transition:box-shadow 0.15s; }
.ncr-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }

.ncr-left { flex:1; min-width:0; }
.ncr-user-row { display:flex; align-items:center; gap:11px; margin-bottom:14px; }
.ncr-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#0ea5e9,#0284c7); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1rem; flex-shrink:0; }
.ncr-uname { font-size:0.93rem; font-weight:700; color:var(--text-primary); display:block; }
.ncr-umeta { font-size:0.78rem; color:var(--text-muted); margin-top:2px; }

.ncr-name-row { display:flex; align-items:center; gap:10px; background:var(--bg-secondary,#f9fafb); border-radius:9px; padding:10px 14px; margin-bottom:12px; font-size:0.875rem; flex-wrap:wrap; }
.ncr-old { color:var(--text-muted); text-decoration:line-through; }
.ncr-new { color:var(--text-primary); font-weight:700; }
.ncr-arrow { color:var(--text-muted); }

.ncr-doc { display:inline-flex; align-items:center; gap:6px; font-size:0.81rem; color:#0ea5e9; text-decoration:none; font-weight:600; background:#f0f9ff; padding:5px 12px; border-radius:20px; border:1px solid #bae6fd; transition:background 0.15s; }
.ncr-doc:hover { background:#e0f2fe; }
.ncr-date { font-size:0.76rem; color:var(--text-muted); margin-top:10px; }

.ncr-right { flex-shrink:0; width:240px; display:flex; flex-direction:column; gap:10px; }
.ncr-pill { display:inline-flex; align-items:center; gap:6px; padding:5px 13px; border-radius:20px; font-size:0.78rem; font-weight:700; }
.ncr-pill.pending  { background:#fef9c3; color:#92400e; }
.ncr-pill.approved { background:#dcfce7; color:#15803d; }
.ncr-pill.rejected { background:#fee2e2; color:#dc2626; }

.ncr-form { display:flex; flex-direction:column; gap:8px; }
.ncr-note { width:100%; padding:8px 10px; border:1.5px solid var(--border-color); border-radius:8px; font-size:0.82rem; background:var(--bg-secondary); color:var(--text-primary); resize:none; outline:none; box-sizing:border-box; min-height:52px; transition:border-color 0.15s; }
.ncr-note:focus { border-color:#0ea5e9; background:var(--bg-primary); }
.ncr-btns { display:flex; gap:8px; }
.btn-approve { flex:1; padding:9px 0; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:0.83rem; font-weight:700; cursor:pointer; transition:background 0.15s; display:flex; align-items:center; justify-content:center; gap:5px; }
.btn-approve:hover { background:#15803d; }
.btn-reject  { flex:1; padding:9px 0; background:transparent; border:1.5px solid #fca5a5; color:#dc2626; border-radius:8px; font-size:0.83rem; font-weight:700; cursor:pointer; transition:all 0.15s; display:flex; align-items:center; justify-content:center; gap:5px; }
.btn-reject:hover  { background:#dc2626; color:#fff; border-color:#dc2626; }
.btn-delete  { padding:7px 12px; background:transparent; border:1.5px solid var(--border-color); color:var(--text-muted); border-radius:8px; font-size:0.8rem; cursor:pointer; transition:all 0.15s; }
.btn-delete:hover  { border-color:#dc2626; color:#dc2626; }
.ncr-reviewed { font-size:0.8rem; color:var(--text-muted); font-style:italic; }
.ncr-rdate    { font-size:0.77rem; color:var(--text-muted); }

.ncr-empty { background:var(--bg-primary); border:1px dashed var(--border-color); border-radius:14px; padding:56px 24px; text-align:center; color:var(--text-muted); }
.ncr-empty svg { margin-bottom:14px; opacity:.3; }
.ncr-empty h3 { font-size:1rem; font-weight:700; color:var(--text-secondary); margin:0 0 6px; }
.ncr-empty p  { margin:0; font-size:0.85rem; }

.alert { padding:12px 18px; border-radius:10px; margin-bottom:18px; font-size:0.88rem; font-weight:600; }
.alert-success { background:#dcfce7; color:#15803d; }
.alert-info    { background:#e0f2fe; color:#0369a1; }
.alert-error   { background:#fee2e2; color:#dc2626; }

@media(max-width:700px){ .ncr-card{flex-direction:column;} .ncr-right{width:100%;} }
</style>

<?php if ($flash): ?>
<div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
<?php endif; ?>

<div class="ncr-header">
    <div>
        <h1>Name Change Requests</h1>
        <p>Review, approve or reject user name change requests with ID verification.</p>
    </div>
    <?php if ($counts['pending'] > 0): ?>
    <span class="ncr-pending-badge"><?php echo $counts['pending']; ?> pending</span>
    <?php endif; ?>
</div>

<?php if ($dbError): ?>
<div class="ncr-dberr">
    <strong>Database error</strong> — the <code>name_change_requests</code> table may be missing.
    <a href="/admin/tools/run-setup.php">Run DB migration</a> then reload this page.<br>
    <small style="opacity:.7;"><?php echo htmlspecialchars($dbError); ?></small>
</div>
<?php else: ?>

<div class="ncr-tabs">
<?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $k=>$label): ?>
    <a href="?status=<?php echo $k; ?>" class="ncr-tab <?php echo $filterStatus===$k?'active':''; ?>">
        <?php echo $label; ?>
        <?php if (isset($counts[$k]) && $counts[$k] > 0): ?>
        <span class="ncr-badge"><?php echo $counts[$k]; ?></span>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
</div>

<?php if (empty($requests)): ?>
<div class="ncr-empty">
    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <h3>No <?php echo $filterStatus !== 'all' ? $filterStatus : ''; ?> requests</h3>
    <p>Nothing to review right now.</p>
</div>
<?php else: ?>
<div class="ncr-grid">
<?php foreach ($requests as $r): ?>
<div class="ncr-card">

    <div class="ncr-left">
        <div class="ncr-user-row">
            <div class="ncr-avatar"><?php echo strtoupper(mb_substr($r['current_name'] ?: '?', 0, 1)); ?></div>
            <div>
                <span class="ncr-uname"><?php echo htmlspecialchars($r['current_name']); ?></span>
                <div class="ncr-umeta">
                    <?php echo htmlspecialchars($r['email']); ?>
                    <?php if (!empty($r['phone'])): ?> &middot; <?php echo htmlspecialchars($r['phone']); ?><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ncr-name-row">
            <span class="ncr-old"><?php echo htmlspecialchars($r['current_name']); ?></span>
            <svg class="ncr-arrow" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            <span class="ncr-new"><?php echo htmlspecialchars($r['requested_name']); ?></span>
        </div>

        <?php if (!empty($r['id_document_path'])): ?>
        <a href="<?php echo htmlspecialchars($r['id_document_path']); ?>" target="_blank" class="ncr-doc">
            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            View ID Document
        </a>
        <?php endif; ?>
        <div class="ncr-date">Submitted <?php echo date('d M Y, g:i A', strtotime($r['created_at'])); ?></div>
    </div>

    <div class="ncr-right">
        <span class="ncr-pill <?php echo htmlspecialchars($r['status']); ?>">
            <?php if ($r['status']==='approved'): ?><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            <?php elseif ($r['status']==='rejected'): ?><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            <?php else: ?><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
            <?php endif; ?>
            <?php echo ucfirst($r['status']); ?>
        </span>

        <?php if ($r['status'] === 'pending'): ?>
        <form method="POST" class="ncr-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
            <input type="hidden" name="status_tab" value="<?php echo htmlspecialchars($filterStatus); ?>">
            <textarea name="admin_note" class="ncr-note" placeholder="Optional note to user..."></textarea>
            <div class="ncr-btns">
                <button type="submit" name="action" value="approve" class="btn-approve"
                        onclick="return confirm('Approve and set name to: <?php echo addslashes($r['requested_name']); ?>?')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> Approve
                </button>
                <button type="submit" name="action" value="reject" class="btn-reject"
                        onclick="return confirm('Reject this request?')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg> Reject
                </button>
            </div>
            <button type="submit" name="action" value="delete" class="btn-delete"
                    onclick="return confirm('Permanently delete this request?')">Delete</button>
        </form>
        <?php else: ?>
            <?php if (!empty($r['admin_note'])): ?><p class="ncr-reviewed">"<?php echo htmlspecialchars($r['admin_note']); ?>"</p><?php endif; ?>
            <?php if (!empty($r['reviewed_at'])): ?><p class="ncr-rdate">Reviewed <?php echo date('d M Y', strtotime($r['reviewed_at'])); ?></p><?php endif; ?>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="status_tab" value="<?php echo htmlspecialchars($filterStatus); ?>">
                <button type="submit" name="action" value="delete" class="btn-delete"
                        onclick="return confirm('Permanently delete this request?')">Delete</button>
            </form>
        <?php endif; ?>
    </div>

</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
