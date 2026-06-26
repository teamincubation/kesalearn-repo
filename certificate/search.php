<?php
/**
 * KESA Learn - Certificate Search & Validation (public)
 *
 * One page to find and validate any KESA certificate:
 *   - Learner certificates  : by code, certificate number, email or mobile
 *   - Instructor certificates: KESA-INST-… codes
 *
 * Learner certificate codes link through to the detailed /certificate/verify
 * page; instructor certificates are validated right here.
 * Privacy: emails are masked; searching by email/mobile only ever shows
 * certificates tied to that exact contact.
 */
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$q       = trim($_GET['code'] ?? $_GET['q'] ?? '');
$results = ['learner' => [], 'instructor' => []];
$searched = $q !== '';

function maskMail(string $e): string {
    if (!str_contains($e, '@')) return '—';
    [$u, $d] = explode('@', $e, 2);
    $n = strlen($u);
    return ($n <= 2 ? str_repeat('*', $n) : $u[0] . str_repeat('*', max(1, $n - 2)) . $u[$n - 1]) . '@' . $d;
}

if ($searched && mb_strlen($q) >= 4 && mb_strlen($q) <= 120) {
    $digits = preg_replace('/\D/', '', $q);

    // Instructor certificates - match by auto code OR the printed certificate number
    try {
        $st = $db->prepare(
            "SELECT ic.*, i.name AS instructor_name, i.designation, i.profile_image, i.photo,
                    e.title AS event_title, e.start_date, e.end_date
             FROM instructor_certificates ic
             JOIN instructors i ON i.id = ic.instructor_id
             LEFT JOIN events e ON e.id = ic.event_id
             WHERE (ic.certificate_code = ? OR ic.certificate_number = ?) AND ic.is_active = 1
             LIMIT 5"
        );
        $st->execute([strtoupper($q), $q]);
        $results['instructor'] = $st->fetchAll();
    } catch (PDOException $e) { /* table not migrated yet */ }

    if (!$results['instructor']) {
        // Learner certificates: code, number, email, or mobile
        $conds  = ["c.certificate_code = ?", "c.certificate_number = ?"];
        $params = [$q, $q];
        if (filter_var($q, FILTER_VALIDATE_EMAIL)) {
            $conds[]  = "(LOWER(c.user_email) = LOWER(?) OR LOWER(u.email) = LOWER(?))";
            $params[] = $q; $params[] = $q;
        }
        if (strlen($digits) === 10) {
            $conds[]  = "(RIGHT(REGEXP_REPLACE(COALESCE(u.mobile_number,''),'[^0-9]',''),10) = ?
                       OR RIGHT(REGEXP_REPLACE(COALESCE(u.phone,''),'[^0-9]',''),10) = ?)";
            $params[] = $digits; $params[] = $digits;
        }
        $st = $db->prepare(
            "SELECT c.certificate_code, c.certificate_number, c.recipient_name, c.issue_date, c.generated_at,
                    c.user_email, u.name AS user_name, u.email AS account_email,
                    e.title AS event_title, e.start_date, e.end_date
             FROM certificates c
             LEFT JOIN users u ON u.id = c.user_id
             LEFT JOIN events e ON e.id = c.event_id
             WHERE " . implode(' OR ', $conds) . "
             ORDER BY c.generated_at DESC LIMIT 25"
        );
        $st->execute($params);
        $results['learner'] = $st->fetchAll();
    }
}

$found = count($results['learner']) + count($results['instructor']);
$pageTitle = 'Certificate Search & Validation';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="max-width:760px;padding:110px 16px 70px;">
  <div style="text-align:center;margin-bottom:28px;">
    <h1 style="font-size:1.8rem;font-weight:800;color:var(--text-primary);">Certificate <span style="color:var(--red);">Search &amp; Validation</span></h1>
    <p style="color:var(--text-secondary);max-width:520px;margin:10px auto 0;">Verify the authenticity of any KESA Learn certificate — learner or instructor. Search by certificate code, certificate number, registered email or mobile number.</p>
  </div>

  <form method="get" class="cs-form" role="search">
    <input type="text" name="code" value="<?php echo sanitize($q); ?>" class="cs-input"
           placeholder="Certificate number, code (KESA-… / KESA-INST-…), email or mobile" maxlength="120" required>
    <button class="btn btn-primary cs-btn">Search</button>
  </form>

  <?php if ($searched): ?>
    <?php if (!$found): ?>
      <div class="cs-none">
        <div class="cs-none-icon">✕</div>
        <h2>No certificate found</h2>
        <p>Nothing matches "<strong><?php echo sanitize($q); ?></strong>". Check the spelling of the code, or try the email / mobile number used during registration. If you believe this is an error, contact <a href="mailto:hello@kesalearn.com">hello@kesalearn.com</a>.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($results['instructor'] as $ic): ?>
      <div class="cs-card cs-valid">
        <div class="cs-badge">✓ VERIFIED INSTRUCTOR CERTIFICATE</div>
        <h2 class="cs-name"><?php echo sanitize($ic['instructor_name']); ?></h2>
        <?php if ($ic['designation']): ?><p class="cs-line"><?php echo sanitize($ic['designation']); ?></p><?php endif; ?>
        <p class="cs-title"><?php echo sanitize($ic['title']); ?></p>
        <?php if ($ic['description']): ?><p class="cs-desc"><?php echo sanitize($ic['description']); ?></p><?php endif; ?>
        <div class="cs-meta">
          <span><strong>Code:</strong> <?php echo sanitize($ic['certificate_code']); ?></span>
          <?php if (!empty($ic['certificate_number'])): ?><span><strong>Certificate No:</strong> <?php echo sanitize($ic['certificate_number']); ?></span><?php endif; ?>
          <?php if ($ic['event_title']): ?><span><strong>Event:</strong> <?php echo sanitize($ic['event_title']); ?></span><?php endif; ?>
          <?php if ($ic['issue_date']): ?><span><strong>Issued:</strong> <?php echo date('d M Y', strtotime($ic['issue_date'])); ?></span><?php endif; ?>
        </div>
        <?php if ($ic['certificate_file']): ?>
          <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener"
               href="/<?php echo sanitize($ic['certificate_file']); ?>">View Certificate</a>
            <a class="btn btn-primary btn-sm"
               href="/certificate/download-instructor?code=<?php echo urlencode($ic['certificate_code']); ?>">Download</a>
          </div>
        <?php endif; ?>
        <p class="cs-issuer">Issued and digitally verified by KESA — Knowledge Enhancement &amp; Skill Acquisition Program.</p>
      </div>
    <?php endforeach; ?>

    <?php if ($results['learner']): ?>
      <p style="color:var(--text-secondary);font-size:.9rem;margin:18px 4px 10px;"><?php echo count($results['learner']); ?> certificate<?php echo count($results['learner']) === 1 ? '' : 's'; ?> found:</p>
      <?php foreach ($results['learner'] as $c): ?>
        <div class="cs-card">
          <div class="cs-row">
            <div>
              <div class="cs-name" style="font-size:1.1rem;"><?php echo sanitize($c['recipient_name'] ?: $c['user_name'] ?: 'Learner'); ?></div>
              <div class="cs-line"><?php echo sanitize($c['event_title'] ?? 'KESA Program'); ?></div>
              <div class="cs-meta" style="margin-top:6px;">
                <span><strong>Code:</strong> <?php echo sanitize($c['certificate_code']); ?></span>
                <?php if ($c['certificate_number']): ?><span><strong>No:</strong> <?php echo sanitize($c['certificate_number']); ?></span><?php endif; ?>
                <span><strong>Email:</strong> <?php echo sanitize(maskMail($c['user_email'] ?: $c['account_email'] ?: '')); ?></span>
                <span><strong>Issued:</strong> <?php echo date('d M Y', strtotime($c['issue_date'] ?: $c['generated_at'])); ?></span>
              </div>
            </div>
            <a class="btn btn-primary btn-sm" href="/certificate/verify?code=<?php echo urlencode($c['certificate_code']); ?>">Validate&nbsp;→</a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.cs-form{display:flex;gap:10px;margin-bottom:26px}
.cs-input{flex:1;padding:14px 18px;border:2px solid var(--border-color);border-radius:var(--radius-md);font-size:1rem;font-family:inherit}
.cs-input:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 4px rgba(231,64,74,.1)}
.cs-btn{padding:14px 28px;white-space:nowrap}
.cs-card{background:#fff;border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:22px 24px;margin-bottom:14px}
.cs-card.cs-valid{border-color:#86efac;background:linear-gradient(180deg,#f0fdf4 0,#fff 70%)}
.cs-badge{display:inline-block;background:#15803d;color:#fff;font-size:.72rem;font-weight:800;letter-spacing:.06em;padding:5px 12px;border-radius:99px;margin-bottom:12px}
.cs-name{font-size:1.3rem;font-weight:800;color:var(--text-primary)}
.cs-line{color:var(--text-secondary);font-size:.9rem}
.cs-title{font-weight:700;color:var(--red);margin-top:8px}
.cs-desc{color:var(--text-secondary);font-size:.9rem;margin-top:6px;line-height:1.55}
.cs-meta{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:.83rem;color:var(--text-secondary);margin-top:10px}
.cs-row{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.cs-issuer{font-size:.78rem;color:var(--text-muted);margin-top:14px}
.cs-none{text-align:center;background:#fff;border:1px dashed var(--border-color);border-radius:var(--radius-lg);padding:38px 24px;color:var(--text-secondary)}
.cs-none-icon{width:52px;height:52px;border-radius:50%;background:var(--red-light);color:var(--red);font-size:1.4rem;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.cs-none h2{font-size:1.15rem;color:var(--text-primary);margin-bottom:8px}
@media(max-width:540px){.cs-form{flex-direction:column}.cs-btn{width:100%}.cs-row{flex-direction:column;align-items:flex-start}.cs-row .btn{width:100%;text-align:center}}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
