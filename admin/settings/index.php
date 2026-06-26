<?php
/**
 * KESA Learn - Admin: Settings
 */
require_once __DIR__ . '/../../includes/admin_check.php';

$db = getDB();
$adminPage = 'settings';
$pageTitle = 'Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request.');
        redirect('/admin/settings/');
    }
    
    try {
        // Save all settings
        $settings = [
            'show_max_seats' => isset($_POST['show_max_seats']) ? '1' : '0',
            'base_years_of_impact' => intval($_POST['base_years_of_impact'] ?? 10),
            'base_events_conducted' => intval($_POST['base_events_conducted'] ?? 0),
            'base_learners_trained' => intval($_POST['base_learners_trained'] ?? 0),
            'base_community_members' => intval($_POST['base_community_members'] ?? 0),
            'linkedin_org_name' => trim($_POST['linkedin_org_name'] ?? 'KESA Learn'),
            'otp_module_enabled' => isset($_POST['otp_module_enabled']) ? '1' : '0',
            'MSG91_WIDGET_ID' => trim($_POST['msg91_widget_id'] ?? ''),
            'MSG91_AUTH_KEY' => trim($_POST['msg91_auth_key'] ?? ''),
            'MSG91_TOKEN_AUTH' => trim($_POST['msg91_token_auth'] ?? '512162TlENC56eM369f0c572P1'),
        ];
        
        $settingsSaved = 0;
        foreach ($settings as $key => $value) {
            try {
                setSetting($key, (string)$value);
                $settingsSaved++;
                error_log("[Settings] Updated $key = $value");
            } catch (Exception $e) {
                error_log("[Settings] Failed to save $key: " . $e->getMessage());
            }
        }
        
        // Handle payment settings updates
        if (isset($_POST['payment_section'])) {
            try {
                // Update UPI settings
                $upiId        = trim($_POST['upi_id'] ?? '');
                $upiName      = trim($_POST['upi_beneficiary_name'] ?? '');
                $upiLink      = trim($_POST['upi_payment_link'] ?? '');
                
                if ($upiId && $upiName) {
                    // Handle QR code upload
                    $upiQrCode = null;
                    if (!empty($_FILES['upi_qr_code']['name'])) {
                        $qrUpload = uploadFile($_FILES['upi_qr_code'], 'payment_qr', ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);
                        if ($qrUpload['success']) {
                            $upiQrCode = $qrUpload['path'];
                        } else {
                            error_log("[Settings] QR code upload failed: " . $qrUpload['error']);
                        }
                    }

                    // If no new QR uploaded, preserve existing
                    if ($upiQrCode === null) {
                        $stmtExistingQR = $db->prepare("SELECT upi_qr_code FROM payment_settings WHERE setting_type = 'upi' AND upi_id = ? LIMIT 1");
                        $stmtExistingQR->execute([$upiId]);
                        $existingQR = $stmtExistingQR->fetchColumn();
                        $upiQrCode = $existingQR ?: null;
                    }

                    // Handle QR code removal
                    if (isset($_POST['remove_qr_code']) && $_POST['remove_qr_code'] === '1') {
                        $upiQrCode = null;
                    }

                    $stmtUPI = $db->prepare("
                        INSERT INTO payment_settings (setting_type, upi_id, upi_beneficiary_name, upi_payment_link, upi_qr_code, is_active, is_primary, created_by, updated_by)
                        VALUES ('upi', ?, ?, ?, ?, 1, 1, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        upi_beneficiary_name = VALUES(upi_beneficiary_name),
                        upi_payment_link     = VALUES(upi_payment_link),
                        upi_qr_code          = IF(VALUES(upi_qr_code) IS NOT NULL, VALUES(upi_qr_code), upi_qr_code),
                        is_active            = 1,
                        is_primary           = 1,
                        updated_by           = VALUES(updated_by),
                        updated_at           = NOW()
                    ");
                    // Handle explicit removal: set NULL
                    if (isset($_POST['remove_qr_code']) && $_POST['remove_qr_code'] === '1') {
                        $stmtUPI = $db->prepare("
                            INSERT INTO payment_settings (setting_type, upi_id, upi_beneficiary_name, upi_payment_link, upi_qr_code, is_active, is_primary, created_by, updated_by)
                            VALUES ('upi', ?, ?, ?, NULL, 1, 1, ?, ?)
                            ON DUPLICATE KEY UPDATE
                            upi_beneficiary_name = VALUES(upi_beneficiary_name),
                            upi_payment_link     = VALUES(upi_payment_link),
                            upi_qr_code          = NULL,
                            is_active            = 1,
                            is_primary           = 1,
                            updated_by           = VALUES(updated_by),
                            updated_at           = NOW()
                        ");
                        $result = $stmtUPI->execute([$upiId, $upiName, $upiLink, $_SESSION['user_id'], $_SESSION['user_id']]);
                    } else {
                        $result = $stmtUPI->execute([$upiId, $upiName, $upiLink, $upiQrCode, $_SESSION['user_id'], $_SESSION['user_id']]);
                    }
                    if ($result) {
                        error_log("[Settings] UPI settings updated successfully");
                        $settingsSaved++;
                    }
                }
                
                // Update Razorpay settings
                $razorpayKey = trim($_POST['razorpay_key'] ?? '');
                $razorpaySecret = trim($_POST['razorpay_secret'] ?? '');
                
                if ($razorpayKey && $razorpaySecret) {
                    $stmtRazorpay = $db->prepare("
                        INSERT INTO payment_settings (setting_type, gateway_name, gateway_key_id, gateway_key_secret, is_active, is_primary, created_by, updated_by)
                        VALUES ('gateway', 'Razorpay', ?, ?, 1, 1, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        gateway_key_id = VALUES(gateway_key_id),
                        gateway_key_secret = VALUES(gateway_key_secret),
                        is_active = 1,
                        is_primary = 1,
                        updated_by = VALUES(updated_by),
                        updated_at = NOW()
                    ");
                    $result = $stmtRazorpay->execute([$razorpayKey, $razorpaySecret, $_SESSION['user_id'], $_SESSION['user_id']]);
                    if ($result) {
                        error_log("[Settings] Razorpay settings updated successfully");
                        $settingsSaved++;
                    }
                }
                
                // Update fee/tax settings — saved into the existing rows by setting_type
                $gstPercent        = max(0, min(100, floatval($_POST['gst_percent']        ?? 0)));
                $gatewayFeePercent = max(0, min(100, floatval($_POST['gateway_fee_percent'] ?? 0)));
                $upiDiscountPercent = max(0, min(100, floatval($_POST['upi_discount_percent'] ?? 0)));

                // GST applies to all rows — update both upi (primary) and gateway (primary) rows
                $stmtGST = $db->prepare("
                    UPDATE payment_settings
                    SET gst_percent = ?, updated_by = ?, updated_at = NOW()
                    WHERE is_active = 1 AND is_primary = 1
                ");
                $stmtGST->execute([$gstPercent, $_SESSION['user_id']]);

                // Gateway fee — update only gateway row
                $stmtGwFee = $db->prepare("
                    UPDATE payment_settings
                    SET gateway_fee_percent = ?, updated_by = ?, updated_at = NOW()
                    WHERE setting_type = 'gateway' AND is_active = 1 AND is_primary = 1
                ");
                $stmtGwFee->execute([$gatewayFeePercent, $_SESSION['user_id']]);

                // UPI discount — update only UPI row
                $stmtUPIDisc = $db->prepare("
                    UPDATE payment_settings
                    SET discount_percent = ?, updated_by = ?, updated_at = NOW()
                    WHERE setting_type = 'upi' AND is_active = 1 AND is_primary = 1
                ");
                $feeResult = $stmtUPIDisc->execute([$upiDiscountPercent, $_SESSION['user_id']]);
                if ($feeResult) {
                    error_log("[Settings] Fee settings saved: GST={$gstPercent}% GW={$gatewayFeePercent}% UPI_Disc={$upiDiscountPercent}%");
                    $settingsSaved++;
                }

                // Save account labels for each payment_settings row
                if (!empty($_POST['account_labels']) && is_array($_POST['account_labels'])) {
                    $stmtLabel = $db->prepare("UPDATE payment_settings SET account_label = ?, label_color = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    foreach ($_POST['account_labels'] as $psId => $labelText) {
                        $psId      = intval($psId);
                        $label     = trim(substr($labelText, 0, 80));
                        $color     = $_POST['label_colors'][$psId] ?? '#6366f1';
                        $color     = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#6366f1';
                        if ($psId > 0) {
                            $stmtLabel->execute([$label ?: null, $color, $_SESSION['user_id'], $psId]);
                        }
                    }
                    error_log("[Settings] Account labels saved for " . count($_POST['account_labels']) . " rows");
                    $settingsSaved++;
                }

                logActivity('payment_settings_updated', 'Payment settings updated - UPI, Razorpay and fees');
            } catch (Exception $e) {
                error_log("[Settings] Payment settings update error: " . $e->getMessage());
                setFlash('warning', 'Some payment settings could not be saved: ' . $e->getMessage());
            }
        }
        
        logActivity('settings_updated', 'Admin settings updated - ' . $settingsSaved . ' settings saved');
        setFlash('success', 'Settings saved successfully!');
        redirect('/admin/settings/');
    } catch (Exception $e) {
        error_log("[Settings] Form processing error: " . $e->getMessage());
        setFlash('error', 'Error saving settings: ' . $e->getMessage());
        redirect('/admin/settings/');
    }
}

// Load current settings
$showMaxSeats = getSetting('show_max_seats', '1');
$baseYears = getSetting('base_years_of_impact', '10');
$baseEvents = getSetting('base_events_conducted', '0');
$baseLearners = getSetting('base_learners_trained', '0');
$baseCommunity = getSetting('base_community_members', '0');
$linkedInOrgName = getSetting('linkedin_org_name', 'KESA Learn');
$otpModuleEnabled = getSetting('otp_module_enabled', '1');
$msg91WidgetId = getSetting('MSG91_WIDGET_ID', '');
$msg91AuthKey = getSetting('MSG91_AUTH_KEY', '');
$msg91TokenAuth = getSetting('MSG91_TOKEN_AUTH', '512162TlENC56eM369f0c572P1');

// Load current payment settings from database
$currentUPI              = '';
$currentUPIName          = '';
$currentUPILink          = '';
$currentUPIQrCode        = '';
$currentRazorpayKey      = '';
$currentRazorpaySecret   = '';
$currentGstPercent       = '0';
$currentGatewayFeePercent = '0';
$currentDiscountPercent  = '0';

// All payment accounts (for label management table)
$allPaymentAccounts = [];
try {
    $stmtAll = $db->prepare("SELECT id, setting_type, upi_id, upi_beneficiary_name, gateway_name, gateway_key_id, account_label, label_color, is_active, is_primary FROM payment_settings WHERE setting_type IN ('upi','gateway') ORDER BY setting_type ASC, is_primary DESC, id ASC");
    $stmtAll->execute();
    $allPaymentAccounts = $stmtAll->fetchAll();
} catch (Exception $e) {
    // account_label column may not exist yet — migration needed
    try {
        $stmtAll = $db->prepare("SELECT id, setting_type, upi_id, upi_beneficiary_name, gateway_name, gateway_key_id, is_active, is_primary FROM payment_settings WHERE setting_type IN ('upi','gateway') ORDER BY setting_type ASC, is_primary DESC, id ASC");
        $stmtAll->execute();
        $allPaymentAccounts = $stmtAll->fetchAll();
    } catch (Exception $e2) { /* ignore */ }
}

try {
    // Get UPI settings
    $stmtUPI = $db->prepare("SELECT upi_id, upi_beneficiary_name, upi_payment_link, upi_qr_code FROM payment_settings WHERE setting_type = 'upi' AND is_active = 1 ORDER BY is_primary DESC LIMIT 1");
    $stmtUPI->execute();
    $upiData = $stmtUPI->fetch();
    if ($upiData) {
        $currentUPI       = $upiData['upi_id'];
        $currentUPIName   = $upiData['upi_beneficiary_name'];
        $currentUPILink   = $upiData['upi_payment_link'] ?? '';
        $currentUPIQrCode = $upiData['upi_qr_code'] ?? '';
    }
    
    // Get Razorpay settings
    $stmtRazorpay = $db->prepare("SELECT gateway_key_id, gateway_key_secret FROM payment_settings WHERE setting_type = 'gateway' AND gateway_name = 'Razorpay' AND is_active = 1 ORDER BY is_primary DESC LIMIT 1");
    $stmtRazorpay->execute();
    $razorpayData = $stmtRazorpay->fetch();
    if ($razorpayData) {
        $currentRazorpayKey = $razorpayData['gateway_key_id'];
        $currentRazorpaySecret = $razorpayData['gateway_key_secret'];
    }
    // Get fee/tax settings — GST from UPI row, gateway fee from gateway row, UPI discount from UPI row
    $stmtFeesUPI = $db->prepare("SELECT gst_percent, discount_percent FROM payment_settings WHERE setting_type = 'upi' AND is_active = 1 AND is_primary = 1 LIMIT 1");
    $stmtFeesUPI->execute();
    $feesUPIData = $stmtFeesUPI->fetch();
    if ($feesUPIData) {
        $currentGstPercent      = rtrim(rtrim(number_format((float)$feesUPIData['gst_percent'], 2), '0'), '.');
        $currentDiscountPercent = rtrim(rtrim(number_format((float)$feesUPIData['discount_percent'], 2), '0'), '.');
    }
    $stmtFeesGW = $db->prepare("SELECT gateway_fee_percent FROM payment_settings WHERE setting_type = 'gateway' AND is_active = 1 AND is_primary = 1 LIMIT 1");
    $stmtFeesGW->execute();
    $feesGWData = $stmtFeesGW->fetch();
    if ($feesGWData) {
        $currentGatewayFeePercent = rtrim(rtrim(number_format((float)$feesGWData['gateway_fee_percent'], 2), '0'), '.');
    }
} catch (Exception $e) {
    error_log("Error fetching payment settings: " . $e->getMessage());
}

include __DIR__ . '/../includes/sidebar.php';
?>

<form method="POST" enctype="multipart/form-data" class="admin-form">
    <?php echo csrfField(); ?>
    
    <!-- Display Settings -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 20px;">Display Settings</h3>
        
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                <input type="checkbox" name="show_max_seats" value="1" <?php echo $showMaxSeats === '1' ? 'checked' : ''; ?> style="width: 20px; height: 20px;">
                <span>
                    <strong>Show maximum seat capacity on events</strong><br>
                    <span style="font-size: 0.85rem; color: var(--text-muted);">When enabled, users can see the total seat capacity and remaining seats for each event on the public website.</span>
                </span>
            </label>
        </div>
    </div>
    
    <!-- Base Stats -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 6px;">Base Statistics Counter</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
            These base numbers are added to the automatically calculated counts and displayed on the homepage statistics bar. Auto-increment starts from these values.
        </p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label for="base_years_of_impact">Years of Impact</label>
                <input type="number" id="base_years_of_impact" name="base_years_of_impact" class="form-control" value="<?php echo sanitize($baseYears); ?>" min="0">
            </div>
            
            <div class="form-group">
                <label for="base_events_conducted">Events Conducted</label>
                <input type="number" id="base_events_conducted" name="base_events_conducted" class="form-control" value="<?php echo sanitize($baseEvents); ?>" min="0">
            </div>
            
            <div class="form-group">
                <label for="base_learners_trained">Learners Trained</label>
                <input type="number" id="base_learners_trained" name="base_learners_trained" class="form-control" value="<?php echo sanitize($baseLearners); ?>" min="0">
            </div>
            
            <div class="form-group">
                <label for="base_community_members">Community Members</label>
                <input type="number" id="base_community_members" name="base_community_members" class="form-control" value="<?php echo sanitize($baseCommunity); ?>" min="0">
            </div>
        </div>
    </div>
    
    <!-- LinkedIn Integration -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" fill="#0a66c2" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
            LinkedIn Integration
        </h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
            Configure LinkedIn settings for certificate sharing. When users add certificates to their LinkedIn profile, this organization name will appear as the "Issuing Organization".
        </p>
        
        <div class="form-group">
            <label for="linkedin_org_name">Issuing Organization Name</label>
            <input type="text" id="linkedin_org_name" name="linkedin_org_name" class="form-control" value="<?php echo sanitize($linkedInOrgName); ?>" placeholder="e.g., KESA Learn" required>
            <small style="color: var(--text-muted); margin-top: 6px; display: block;">This name will appear in the "Issuing Organization" field when users share certificates to LinkedIn.</small>
        </div>
    </div>
    
    <!-- Phone OTP Authentication -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" fill="#7c3aed" viewBox="0 0 24 24"><path d="M17 1H7C5.9 1 5 1.9 5 3v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm-5 20c-.83 0-1.5-.67-1.5-1.5S11.17 18 12 18s1.5.67 1.5 1.5S12.83 21 12 21zm5-4H7V4h10v13z"/></svg>
            Phone Number OTP Authentication
        </h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
            Controls whether users can sign up and log in using a mobile number with OTP verification. When disabled, only Google Sign-In is available.
        </p>
        <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="position: relative; flex-shrink: 0; margin-top: 2px;">
                <input type="checkbox" name="otp_module_enabled" id="otp_module_enabled" value="1" <?php echo $otpModuleEnabled === '1' ? 'checked' : ''; ?>
                    style="width: 20px; height: 20px; cursor: pointer; accent-color: #7c3aed;">
            </div>
            <div>
                <label for="otp_module_enabled" style="font-weight: 600; font-size: 0.95rem; cursor: pointer; display: block; margin-bottom: 4px;">
                    Enable OTP Signup / Login
                </label>
                <p style="font-size: 0.83rem; color: var(--text-muted); margin: 0;">
                    When enabled, users can register or log in with their mobile number via OTP. 
                    OTP is verified during signup; Google users are linked automatically if the same email is used.
                    When disabled, only the Google Sign-In button is shown on the auth pages.
                </p>
            </div>
        </div>
    </div>

    <!-- MSG91 OTP Configuration Settings -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" fill="#8b5cf6" viewBox="0 0 24 24"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56-.35-.12-.74-.03-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.01 21c.71 0 .99-.63.99-1.18v-3.45c0-.54-.45-.99-.99-.99z"/></svg>
            MSG91 OTP Configuration
        </h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
            Configure MSG91 service for sending OTP via SMS during user registration and login.
            Get your credentials from <a href="https://control.msg91.com/" target="_blank" style="color: #3b82f6; text-decoration: none;">MSG91 Dashboard</a>.
        </p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <!-- MSG91 Widget ID -->
            <div>
                <div class="form-group">
                    <label for="msg91_widget_id">MSG91 Widget ID</label>
                    <input type="text" id="msg91_widget_id" name="msg91_widget_id" class="form-control"
                           value="<?php echo sanitize($msg91WidgetId); ?>"
                           placeholder="e.g., 3664426e7542333535323938">
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                        Found in MSG91 Dashboard → OTP Widget → Settings → Widget ID
                    </small>
                </div>
            </div>

            <!-- MSG91 Auth Key -->
            <div>
                <div class="form-group">
                    <label for="msg91_auth_key">MSG91 Auth Key</label>
                    <input type="password" id="msg91_auth_key" name="msg91_auth_key" class="form-control"
                           value="<?php echo sanitize($msg91AuthKey); ?>"
                           placeholder="Your MSG91 authentication key">
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                        Found in MSG91 Dashboard → Integrations → Auth Key
                    </small>
                </div>
            </div>

            <!-- MSG91 Token Auth (hidden by default, for advanced users) -->
            <div style="grid-column: 1 / -1;">
                <div class="form-group">
                    <label for="msg91_token_auth">MSG91 Token Auth (Advanced)</label>
                    <input type="password" id="msg91_token_auth" name="msg91_token_auth" class="form-control"
                           value="<?php echo sanitize($msg91TokenAuth); ?>"
                           placeholder="Your MSG91 token auth">
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                        Optional. Used for OTP widget authentication. Default is provided.
                    </small>
                </div>
            </div>

            <!-- Connection Status -->
            <div style="grid-column: 1 / -1; background: var(--bg-secondary); border-left: 4px solid #8b5cf6; padding: 14px; border-radius: 6px;">
                <p style="font-size: 0.85rem; margin: 0; color: var(--text-muted);">
                    <strong>Status:</strong>
                    <?php
                        if (!empty($msg91WidgetId) && !empty($msg91AuthKey)) {
                            echo '<span style="color: #10b981;">✓ Configured</span> — OTP signup/login is ready to use.';
                        } else {
                            echo '<span style="color: #ef4444;">✗ Not Configured</span> — Complete the fields above to enable OTP.';
                        }
                    ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Payment Settings -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" fill="#059669" viewBox="0 0 24 24"><path d="M12 1C6.48 1 2 5.48 2 11s4.48 10 10 10 10-4.48 10-10S17.52 1 12 1zm-2 15l-5-5 1.41-1.41L10 13.17l7.59-7.59L19 7l-9 9z"/></svg>
            Payment Settings
        </h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
            Configure payment methods for events. These settings will be used on the event payment pages for users to make payments via UPI or Razorpay.
        </p>
        
        <input type="hidden" name="payment_section" value="1">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <!-- UPI Settings -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 20px;">
                <h4 style="font-size: 0.95rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" fill="#3b82f6" viewBox="0 0 24 24"><path d="M11 17c0 .55.45 1 1 1s1-.45 1-1-.45-1-1-1-1 .45-1 1zm6-5c1.66 0 2.99-1.34 2.99-3S18.66 6 17 6c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-6 0c1.66 0 2.99-1.34 2.99-3S12.66 6 11 6C9.34 6 8 7.34 8 9s1.34 3 3 3z"/></svg>
                    UPI Payment
                </h4>
                
                <div class="form-group">
                    <label for="upi_id">UPI ID</label>
                    <input type="text" id="upi_id" name="upi_id" class="form-control" value="<?php echo sanitize($currentUPI); ?>" placeholder="e.g., 9400423233@upi" required>
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">Phone number or UPI handle associated with your account</small>
                </div>
                
                <div class="form-group">
                    <label for="upi_beneficiary_name">Beneficiary Name</label>
                    <input type="text" id="upi_beneficiary_name" name="upi_beneficiary_name" class="form-control" value="<?php echo sanitize($currentUPIName); ?>" placeholder="e.g., Sayyid Shaheer V" required>
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">Name displayed to users during payment</small>
                </div>

                <div class="form-group">
                    <label for="upi_payment_link">UPI Payment Link <span style="font-weight:400; color:var(--text-muted);">(optional)</span></label>
                    <input type="url" id="upi_payment_link" name="upi_payment_link" class="form-control"
                           value="<?php echo sanitize($currentUPILink); ?>"
                           placeholder="e.g., upi://pay?pa=id@bank&pn=Name&am=&cu=INR">
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">Deep link that opens a UPI app directly. Leave blank to hide the "Click to Pay" button on payment pages.</small>
                </div>

                <div class="form-group">
                    <label>QR Code Image <span style="font-weight:400; color:var(--text-muted);">(optional)</span></label>
                    <?php if (!empty($currentUPIQrCode)): ?>
                    <div style="display:flex; align-items:center; gap:16px; margin-bottom:12px; padding:12px; background:var(--bg-secondary); border-radius:var(--radius-sm); border:1px solid var(--border-color);">
                        <img src="<?php echo sanitize(UPLOAD_URL . $currentUPIQrCode); ?>" alt="UPI QR Code"
                             style="width:80px; height:80px; object-fit:contain; border-radius:4px; border:1px solid var(--border-color);">
                        <div>
                            <p style="font-size:0.85rem; font-weight:600; margin-bottom:4px;">Current QR Code</p>
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; color:#dc2626;">
                                <input type="checkbox" name="remove_qr_code" value="1" id="remove_qr_code">
                                Remove this QR code
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <input type="file" id="upi_qr_code" name="upi_qr_code" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">Upload a PNG/JPG of your UPI QR code. Max 5MB. Leave blank to keep existing. When uploaded, users can scan it directly from the payment page.</small>
                </div>
            </div>
            
            <!-- Razorpay Settings -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 20px;">
                <h4 style="font-size: 0.95rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" fill="#f59e0b" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/></svg>
                    Razorpay (Live)
                </h4>
                
                <div class="form-group">
                    <label for="razorpay_key">API Key</label>
                    <div style="position: relative;">
                        <input type="password" id="razorpay_key" name="razorpay_key" class="form-control" value="<?php echo sanitize($currentRazorpayKey); ?>" placeholder="rzp_live_..." style="padding-right: 40px;" required>
                        <button type="button" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);" onclick="togglePasswordVisibility('razorpay_key')">
                            <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                        </button>
                    </div>
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">Your Razorpay Live API Key (starts with rzp_live_)</small>
                </div>
                
                <div class="form-group">
                    <label for="razorpay_secret">API Secret</label>
                    <div style="position: relative;">
                        <input type="password" id="razorpay_secret" name="razorpay_secret" class="form-control" value="<?php echo sanitize($currentRazorpaySecret); ?>" placeholder="Your API Secret" style="padding-right: 40px;" required>
                        <button type="button" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);" onclick="togglePasswordVisibility('razorpay_secret')">
                            <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                        </button>
                    </div>
                    <small style="color: var(--text-muted); margin-top: 4px; display: block;">Your Razorpay API Secret (keep this secure!)</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Payment Account Labels -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" fill="#6366f1" viewBox="0 0 24 24"><path d="M17.63 5.84C17.27 5.33 16.67 5 16 5L5 5.01C3.9 5.01 3 5.9 3 7v10c0 1.1.9 1.99 2 1.99L16 19c.67 0 1.27-.33 1.63-.84L22 12l-4.37-6.16z"/></svg>
            Payment Account Labels
        </h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
            Assign coloured labels to each payment account below. These labels are automatically attached to verified registrations so you can track which account received each payment.
        </p>

        <?php if (empty($allPaymentAccounts)): ?>
        <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.88rem; border: 1px dashed var(--border-color); border-radius: var(--radius-sm);">
            No payment accounts found. Add UPI or Razorpay settings above and save first.
        </div>
        <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php foreach ($allPaymentAccounts as $acc): ?>
            <?php
                $accLabel = $acc['account_label'] ?? '';
                $accColor = $acc['label_color'] ?? '#6366f1';
                $isUPI    = $acc['setting_type'] === 'upi';
                $accName  = $isUPI
                    ? ($acc['upi_beneficiary_name'] ?: $acc['upi_id'])
                    : ($acc['gateway_name'] ?: 'Razorpay');
                $accSub   = $isUPI ? $acc['upi_id'] : ($acc['gateway_key_id'] ? substr($acc['gateway_key_id'], 0, 12) . '...' : '');
                $typeBadgeColor = $isUPI ? '#0ea5e9' : '#f59e0b';
                $typeLabel      = $isUPI ? 'UPI' : 'Gateway';
            ?>
            <div style="display: flex; align-items: center; gap: 16px; padding: 14px 16px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                <!-- Type badge -->
                <span style="flex-shrink:0; display:inline-flex; align-items:center; padding: 3px 10px; background: <?php echo $typeBadgeColor; ?>22; color: <?php echo $typeBadgeColor; ?>; border: 1px solid <?php echo $typeBadgeColor; ?>44; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
                    <?php echo $typeLabel; ?>
                </span>
                <!-- Account info -->
                <div style="flex: 1; min-width: 0;">
                    <div style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($accName); ?></div>
                    <?php if ($accSub): ?>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 1px;"><?php echo htmlspecialchars($accSub); ?></div>
                    <?php endif; ?>
                </div>
                <!-- Active pill -->
                <span style="flex-shrink:0; font-size:0.72rem; padding:2px 8px; border-radius:20px; background:<?php echo $acc['is_active'] ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $acc['is_active'] ? '#16a34a' : '#dc2626'; ?>; font-weight:600;">
                    <?php echo $acc['is_active'] ? 'Active' : 'Inactive'; ?>
                </span>
                <!-- Label preview -->
                <span id="lp-<?php echo $acc['id']; ?>" style="flex-shrink:0; display:inline-flex; align-items:center; gap:5px; padding: 4px 12px; background: <?php echo htmlspecialchars($accColor); ?>22; color: <?php echo htmlspecialchars($accColor); ?>; border: 1px solid <?php echo htmlspecialchars($accColor); ?>66; border-radius: 20px; font-size: 0.78rem; font-weight: 600; min-width: 80px; white-space: nowrap;">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?php echo htmlspecialchars($accColor); ?>;display:inline-block;flex-shrink:0;"></span>
                    <span id="lt-<?php echo $acc['id']; ?>"><?php echo $accLabel ? htmlspecialchars($accLabel) : 'No label'; ?></span>
                </span>
                <!-- Colour picker -->
                <input type="color" name="label_colors[<?php echo $acc['id']; ?>]"
                       value="<?php echo htmlspecialchars($accColor); ?>"
                       style="width:34px; height:34px; border-radius:50%; border:2px solid var(--border-color); cursor:pointer; padding:2px; flex-shrink:0;"
                       title="Pick label colour"
                       oninput="updateLabelPreview(<?php echo $acc['id']; ?>, this.value)">
                <!-- Label text input -->
                <input type="text" name="account_labels[<?php echo $acc['id']; ?>]"
                       value="<?php echo htmlspecialchars($accLabel); ?>"
                       placeholder="e.g. KESA UPI Primary"
                       maxlength="80"
                       style="flex: 1.2; min-width: 160px; max-width: 260px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 8px 12px; font-size: 0.88rem; background: var(--bg-primary); color: var(--text-primary);"
                       oninput="updateLabelText(<?php echo $acc['id']; ?>, this.value)">
            </div>
            <?php endforeach; ?>
        </div>
        <p style="font-size:0.78rem; color:var(--text-muted); margin-top:12px;">
            Labels are shown as coloured badges on registration records and in analytics reports.
        </p>
        <?php endif; ?>
    </div>
    <script>
    function updateLabelPreview(id, color) {
        var lp = document.getElementById('lp-' + id);
        if (lp) {
            lp.style.background = color + '22';
            lp.style.color = color;
            lp.style.borderColor = color + '66';
            var dot = lp.querySelector('span');
            if (dot) dot.style.background = color;
        }
    }
    function updateLabelText(id, text) {
        var lt = document.getElementById('lt-' + id);
        if (lt) lt.textContent = text || 'No label';
    }
    </script>

    <!-- Fee & Tax Settings -->
    <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 28px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; margin-bottom: 6px; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" fill="#f59e0b" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            Fee, Tax &amp; Discount Settings
        </h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 22px;">
            GST applies to both UPI and Card payments. Gateway Fee applies to Card Payment only. UPI Discount is applied only when user pays via UPI — not for Card payments.
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div class="form-group" style="margin-bottom:0;">
                <label for="gst_percent" style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:0.9rem;">
                    <svg width="15" height="15" fill="#059669" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    GST %
                </label>
                <div style="position:relative; margin-top:6px;">
                    <input type="number" id="gst_percent" name="gst_percent" class="form-control"
                           value="<?php echo sanitize($currentGstPercent); ?>"
                           min="0" max="100" step="0.01" placeholder="e.g. 18"
                           style="padding-right: 36px;">
                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.9rem; pointer-events:none;">%</span>
                </div>
                <small style="color:var(--text-muted); margin-top:6px; display:block;">Added to both UPI &amp; Card payments. Set 0 to disable.</small>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="gateway_fee_percent" style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:0.9rem;">
                    <svg width="15" height="15" fill="#3b82f6" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" stroke="#3b82f6" stroke-width="2" fill="none"/><line x1="1" y1="10" x2="23" y2="10" stroke="#3b82f6" stroke-width="2"/></svg>
                    Payment Gateway Fee %
                </label>
                <div style="position:relative; margin-top:6px;">
                    <input type="number" id="gateway_fee_percent" name="gateway_fee_percent" class="form-control"
                           value="<?php echo sanitize($currentGatewayFeePercent); ?>"
                           min="0" max="100" step="0.01" placeholder="e.g. 2"
                           style="padding-right: 36px;">
                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.9rem; pointer-events:none;">%</span>
                </div>
                <small style="color:var(--text-muted); margin-top:6px; display:block;">Card Payment only. Covers Razorpay processing fee. Set 0 to disable.</small>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="upi_discount_percent" style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:0.9rem;">
                    <svg width="15" height="15" fill="#7c3aed" viewBox="0 0 24 24"><path stroke="none" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" fill="#7c3aed"/></svg>
                    UPI Discount %
                </label>
                <div style="position:relative; margin-top:6px;">
                    <input type="number" id="upi_discount_percent" name="upi_discount_percent" class="form-control"
                           value="<?php echo sanitize($currentDiscountPercent); ?>"
                           min="0" max="100" step="0.01" placeholder="e.g. 10"
                           style="padding-right: 36px;">
                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.9rem; pointer-events:none;">%</span>
                </div>
                <small style="color:var(--text-muted); margin-top:6px; display:block;">Deducted from base price for UPI only — not applied to Card payments. Set 0 to disable.</small>
            </div>
        </div>

        <div style="margin-top:18px; padding:14px 16px; background:#fffbeb; border:1px solid #fcd34d; border-radius:var(--radius-sm); font-size:0.82rem; color:#92400e; display:flex; gap:10px; align-items:flex-start;">
            <svg width="16" height="16" fill="#d97706" style="flex-shrink:0;margin-top:1px;" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <span>
                <strong>UPI:</strong> Base Price &minus; UPI Discount &plus; GST = Final UPI Amount &nbsp;&nbsp;
                <strong>Card:</strong> Base Price &plus; GST &plus; Gateway Fee = Final Card Amount.
                Users always see amounts in rupees, never percentages.
            </span>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">Save Settings</button>
</form>

<script>
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
    } else {
        input.type = 'password';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
