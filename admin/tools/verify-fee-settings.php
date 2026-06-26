<?php
/**
 * Fee Settings Database Verification Script
 * Checks if payment fee columns exist and data is properly configured
 * Place in /admin/tools/verify-fee-settings.php and access via browser
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/../../includes/admin_check.php';
    
    if (!isset($db) || !$db) {
        $db = getDB();
    }
    if (!$db) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    http_response_code(500);
    die("Error: " . htmlspecialchars($e->getMessage()));
}

$results = [];
$allGood = true;

// ===== Check 1: Database columns exist =====
$results['step1'] = [
    'title' => 'Check 1: Database Columns',
    'items' => []
];

$columnsToCheck = ['gst_percent', 'gateway_fee_percent', 'discount_percent'];
foreach ($columnsToCheck as $col) {
    $stmt = $db->prepare("SHOW COLUMNS FROM payment_settings LIKE ?");
    $stmt->execute([$col]);
    $exists = $stmt->rowCount() > 0;
    $results['step1']['items'][] = [
        'label' => "Column: $col",
        'status' => $exists ? 'OK' : 'MISSING',
        'details' => $exists ? 'Column exists' : 'Column NOT found - run migration!',
        'ok' => $exists
    ];
    if (!$exists) $allGood = false;
}

// ===== Check 2: Fee settings row exists =====
$results['step2'] = [
    'title' => 'Check 2: Fee Settings Row',
    'items' => []
];

$stmt = $db->prepare("SELECT id, gst_percent, gateway_fee_percent, discount_percent, is_active FROM payment_settings WHERE setting_type = 'fees' LIMIT 1");
$stmt->execute();
$feeRow = $stmt->fetch(PDO::FETCH_ASSOC);

if ($feeRow) {
    $results['step2']['items'][] = [
        'label' => 'Fee row exists',
        'status' => 'OK',
        'details' => "ID: {$feeRow['id']}, Active: " . ($feeRow['is_active'] ? 'Yes' : 'No'),
        'ok' => true
    ];
    $results['step2']['items'][] = [
        'label' => 'GST %',
        'status' => 'OK',
        'details' => "Value: {$feeRow['gst_percent']}%",
        'ok' => true
    ];
    $results['step2']['items'][] = [
        'label' => 'Gateway Fee %',
        'status' => 'OK',
        'details' => "Value: {$feeRow['gateway_fee_percent']}%",
        'ok' => true
    ];
    $results['step2']['items'][] = [
        'label' => 'Discount %',
        'status' => 'OK',
        'details' => "Value: {$feeRow['discount_percent']}%",
        'ok' => true
    ];
} else {
    $results['step2']['items'][] = [
        'label' => 'Fee row exists',
        'status' => 'MISSING',
        'details' => 'No fee row found - run migration and ensure INSERT executed',
        'ok' => false
    ];
    $allGood = false;
}

// ===== Check 3: Admin form variables =====
$results['step3'] = [
    'title' => 'Check 3: Admin Form Access',
    'items' => []
];

$adminFormFile = __DIR__ . '/../../admin/settings/index.php';
if (file_exists($adminFormFile)) {
    $content = file_get_contents($adminFormFile);
    $hasGstInput = strpos($content, 'name="gst_percent"') !== false;
    $hasFeeInput = strpos($content, 'name="gateway_fee_percent"') !== false;
    $hasDiscountInput = strpos($content, 'name="discount_percent"') !== false;
    
    $results['step3']['items'][] = [
        'label' => 'admin/settings has gst_percent input',
        'status' => $hasGstInput ? 'OK' : 'MISSING',
        'details' => $hasGstInput ? 'Form field found' : 'Form field NOT found',
        'ok' => $hasGstInput
    ];
    $results['step3']['items'][] = [
        'label' => 'admin/settings has gateway_fee_percent input',
        'status' => $hasFeeInput ? 'OK' : 'MISSING',
        'details' => $hasFeeInput ? 'Form field found' : 'Form field NOT found',
        'ok' => $hasFeeInput
    ];
    $results['step3']['items'][] = [
        'label' => 'admin/settings has discount_percent input',
        'status' => $hasDiscountInput ? 'OK' : 'MISSING',
        'details' => $hasDiscountInput ? 'Form field found' : 'Form field NOT found',
        'ok' => $hasDiscountInput
    ];
    
    if (!$hasGstInput || !$hasFeeInput || !$hasDiscountInput) {
        $allGood = false;
    }
} else {
    $results['step3']['items'][] = [
        'label' => 'admin/settings file',
        'status' => 'ERROR',
        'details' => "File not found: $adminFormFile",
        'ok' => false
    ];
    $allGood = false;
}

// ===== Check 4: Payment page integration =====
$results['step4'] = [
    'title' => 'Check 4: Payment Page Integration',
    'items' => []
];

$paymentFile = __DIR__ . '/../../events/payment.php';
if (file_exists($paymentFile)) {
    $content = file_get_contents($paymentFile);
    $hasFeesFetch = strpos($content, "setting_type = 'fees'") !== false;
    $hasCalcs = strpos($content, 'finalUPIAmount') !== false && strpos($content, 'finalCardAmount') !== false;
    $hasDisplay = strpos($content, 'summaryTotalUPI') !== false;
    
    $results['step4']['items'][] = [
        'label' => 'Payment page fetches fees from DB',
        'status' => $hasFeesFetch ? 'OK' : 'MISSING',
        'details' => $hasFeesFetch ? 'Fee fetch query found' : 'Fee fetch NOT found',
        'ok' => $hasFeesFetch
    ];
    $results['step4']['items'][] = [
        'label' => 'Payment page calculates UPI & Card amounts',
        'status' => $hasCalcs ? 'OK' : 'MISSING',
        'details' => $hasCalcs ? 'Calculation logic found' : 'Calculation logic NOT found',
        'ok' => $hasCalcs
    ];
    $results['step4']['items'][] = [
        'label' => 'Payment page displays price breakdown',
        'status' => $hasDisplay ? 'OK' : 'MISSING',
        'details' => $hasDisplay ? 'Summary display found' : 'Summary display NOT found',
        'ok' => $hasDisplay
    ];
    
    if (!$hasFeesFetch || !$hasCalcs || !$hasDisplay) {
        $allGood = false;
    }
} else {
    $results['step4']['items'][] = [
        'label' => 'Payment page file',
        'status' => 'ERROR',
        'details' => "File not found: $paymentFile",
        'ok' => false
    ];
    $allGood = false;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Settings Verification</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 40px;
        }
        .section {
            margin-bottom: 40px;
        }
        .section h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section h2 .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }
        .item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 16px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #e0e0e0;
        }
        .item.ok {
            background: #f0fdf4;
            border-left-color: #22c55e;
        }
        .item.error {
            background: #fef2f2;
            border-left-color: #ef4444;
        }
        .item.warning {
            background: #fffbeb;
            border-left-color: #f59e0b;
        }
        .item-icon {
            font-size: 20px;
            min-width: 20px;
        }
        .item-content {
            flex: 1;
        }
        .item-label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        .item-details {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .item-status {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 4px;
            white-space: nowrap;
        }
        .item.ok .item-status {
            background: #dcfce7;
            color: #166534;
        }
        .item.error .item-status {
            background: #fee2e2;
            color: #991b1b;
        }
        .item.warning .item-status {
            background: #fef3c7;
            color: #92400e;
        }
        .summary {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            border-radius: 8px;
            padding: 20px;
            margin-top: 40px;
            text-align: center;
        }
        .summary.error {
            background: #fef2f2;
            border-color: #ef4444;
        }
        .summary h3 {
            font-size: 20px;
            color: #166534;
            margin-bottom: 10px;
        }
        .summary.error h3 {
            color: #991b1b;
        }
        .summary p {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }
        .button {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
            margin: 0 5px;
        }
        .button:hover {
            background: #5568d3;
        }
        .button.secondary {
            background: #e0e7ff;
            color: #4c1d95;
        }
        .button.secondary:hover {
            background: #c7d2fe;
        }
        .code {
            background: #1f2937;
            color: #e5e7eb;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
            margin-top: 10px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✓ Fee Settings Verification</h1>
            <p>KESA Learn Payment — GST, Gateway Fee & Discount Configuration</p>
        </div>
        
        <div class="content">
            <?php foreach ($results as $step): ?>
            <div class="section">
                <h2>
                    <span class="badge" style="background: #667eea;"><?php echo substr($step['title'], 6, 1); ?></span>
                    <?php echo $step['title']; ?>
                </h2>
                
                <?php foreach ($step['items'] as $item): ?>
                <div class="item <?php echo $item['ok'] ? 'ok' : 'error'; ?>">
                    <div class="item-icon">
                        <?php echo $item['ok'] ? '✓' : '✗'; ?>
                    </div>
                    <div class="item-content">
                        <div class="item-label"><?php echo htmlspecialchars($item['label']); ?></div>
                        <div class="item-details"><?php echo htmlspecialchars($item['details']); ?></div>
                    </div>
                    <div class="item-status">
                        <?php echo htmlspecialchars($item['status']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            
            <div class="summary <?php echo $allGood ? '' : 'error'; ?>">
                <?php if ($allGood): ?>
                    <h3>✓ All Systems Operational!</h3>
                    <p>Your fee settings are properly configured and ready to use.</p>
                    <p>Users should now see:</p>
                    <ul style="text-align: left; display: inline-block; font-size: 13px; margin-top: 10px;">
                        <li>✓ Fee fields in Admin Settings</li>
                        <li>✓ Price breakdown on Payment page</li>
                        <li>✓ Discount badge on UPI method card</li>
                        <li>✓ Different amounts for UPI vs Card</li>
                    </ul>
                    <div style="margin-top: 20px;">
                        <a href="/admin/settings/index.php" class="button">Go to Admin Settings</a>
                        <a href="run-migrations.php" class="button secondary">View Migration Status</a>
                    </div>
                <?php else: ?>
                    <h3>✗ Configuration Issues Found</h3>
                    <p>Please fix the errors above before proceeding.</p>
                    <p><strong>Most common issues:</strong></p>
                    <ol style="text-align: left; display: inline-block; font-size: 13px; margin-top: 10px;">
                        <li>Database migration not run — execute: <code style="background: #1f2937; padding: 2px 6px; border-radius: 3px; color: #e5e7eb;">/admin/tools/run-migrations.php</code></li>
                        <li>Missing database columns — check with: <code style="background: #1f2937; padding: 2px 6px; border-radius: 3px; color: #e5e7eb;">SHOW COLUMNS FROM payment_settings;</code></li>
                        <li>File permissions — ensure PHP can read/write payment files</li>
                    </ol>
                    <div style="margin-top: 20px;">
                        <a href="run-migrations.php" class="button">Run Database Migration</a>
                        <a href="javascript:location.reload()" class="button secondary">Refresh Check</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
