<?php
/**
 * KESA Learn - Registration Success Page
 * Professional popup with registration details
 * Removed email confirmation message
 * Added conditional WhatsApp button with proper icon
 */
require_once __DIR__ . '/../includes/auth_check.php';

// Redirect if no success data
if (empty($_SESSION['registration_success'])) {
    redirect('/events/');
}

$data = $_SESSION['registration_success'];
unset($_SESSION['registration_success']);

$pageTitle = 'Registration Successful';
include __DIR__ . '/../includes/header.php';
?>

<style>
.success-container {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 9999;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.success-card {
    background: white;
    border-radius: 20px;
    box-shadow: 0 25px 70px rgba(0, 0, 0, 0.25);
    width: 100%;
    max-width: 550px;
    overflow: hidden;
    animation: slideUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.success-header {
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    padding: 35px 25px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.success-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
}

.success-header::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 150px;
    height: 150px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
}

.success-icon {
    position: relative;
    z-index: 1;
    width: 75px;
    height: 75px;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
    animation: checkmarkBounce 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55) 0.2s backwards;
}

@keyframes checkmarkBounce {
    0% {
        opacity: 0;
        transform: scale(0);
    }
    50% {
        opacity: 1;
    }
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

.success-icon::after {
    content: '✓';
    font-size: 40px;
    color: white;
    font-weight: bold;
    line-height: 1;
}

.success-header h1 {
    color: white;
    margin: 10px 0 5px 0;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.5px;
}

.success-header p {
    color: rgba(255, 255, 255, 0.95);
    margin: 0;
    font-size: 14px;
    font-weight: 500;
}

.success-body {
    padding: 25px 25px;
}

.details-grid {
    display: grid;
    gap: 18px;
    margin-bottom: 20px;
}

.detail-item {
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 15px;
}

.detail-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.detail-label {
    font-size: 11px;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
    display: block;
}

.detail-value {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
    line-height: 1.3;
    word-break: break-word;
}

.detail-value.highlight {
    color: #16a34a;
    font-size: 20px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.btn-dashboard {
    flex: 1;
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    color: white;
    border: none;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.25);
}

.btn-dashboard:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(34, 197, 94, 0.35);
}

.btn-whatsapp {
    flex: 1;
    background: #ffffff;
    color: #25d366;
    border: 2px solid #25d366;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-whatsapp:hover {
    background: #25d366;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 211, 102, 0.3);
}

.btn-icon {
    font-size: 18px;
    display: inline-flex;
}

@media (max-width: 600px) {
    .success-container {
        padding: 12px;
        align-items: flex-start;
        padding-top: 20px;
    }

    .success-card {
        border-radius: 14px;
    }

    .success-header {
        padding: 28px 20px;
    }

    .success-header h1 {
        font-size: 24px;
        margin: 8px 0 4px 0;
    }

    .success-header p {
        font-size: 13px;
    }

    .success-body {
        padding: 20px 20px;
    }

    .details-grid {
        gap: 15px;
        margin-bottom: 15px;
    }

    .detail-item {
        padding-bottom: 12px;
    }

    .detail-label {
        font-size: 10px;
        margin-bottom: 4px;
    }

    .detail-value {
        font-size: 15px;
    }

    .detail-value.highlight {
        font-size: 16px;
    }

    .action-buttons {
        gap: 8px;
        margin-top: 12px;
    }

    .btn-dashboard,
    .btn-whatsapp {
        padding: 10px 12px;
        font-size: 14px;
    }
}
</style>

<div class="success-container">
    <div class="success-card">
        <!-- Header Section -->
        <div class="success-header">
            <div class="success-icon"></div>
            <h1>Registration Successful!</h1>
            <p>Your registration has been confirmed</p>
        </div>

        <!-- Body Section -->
        <div class="success-body">
            <!-- Registration Details -->
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">Name</span>
                    <div class="detail-value"><?php echo htmlspecialchars($data['name']); ?></div>
                </div>

                <div class="detail-item">
                    <span class="detail-label">Event</span>
                    <div class="detail-value"><?php echo htmlspecialchars($data['event_title']); ?></div>
                </div>

                <div class="detail-item">
                    <span class="detail-label">Admission</span>
                    <div class="detail-value highlight"><?php echo $data['is_free'] ? 'Free' : htmlspecialchars($data['amount']); ?></div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="/user/dashboard" class="btn-dashboard">
                    <span class="btn-icon">→</span>
                    Go to Dashboard
                </a>
                <?php if (!empty($data['whatsapp_link'])): ?>
                <a href="<?php echo htmlspecialchars($data['whatsapp_link']); ?>" 
                   target="_blank" 
                   rel="noopener noreferrer"
                   class="btn-whatsapp">
                    <i class="fa fa-whatsapp" aria-hidden="true"></i>
                    Join Group
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../includes/footer.php';
?>
