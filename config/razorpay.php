<?php
/**
 * KESA Learn - Razorpay Configuration
 * 
 * ======================================================
 * IMPORTANT: YOU MUST UPDATE THESE KEYS TO MAKE PAYMENTS WORK
 * ======================================================
 * 
 * HOW TO GET YOUR RAZORPAY API KEYS:
 * 1. Go to https://dashboard.razorpay.com/
 * 2. Sign up or log in to your Razorpay account
 * 3. Navigate to Settings > API Keys
 * 4. Generate a new key pair (Test Mode for testing, Live Mode for production)
 * 5. Copy the Key ID and Key Secret below
 * 
 * For testing: Use "Test" mode keys (start with rzp_test_)
 * For production: Use "Live" mode keys (start with rzp_live_)
 */

// REPLACE THESE WITH YOUR ACTUAL RAZORPAY API KEYS
define('RAZORPAY_KEY_ID', 'rzp_live_SYkYqHCsYPCCAG');      // Your Key ID (e.g., rzp_test_abc123 or rzp_live_abc123)
define('RAZORPAY_KEY_SECRET', 'VzpEHdIy9FtrgloGlF4RBDx4'); // Your Key Secret

// Webhook Secret (optional but recommended for production)
// Get this from Razorpay Dashboard > Settings > Webhooks
define('RAZORPAY_WEBHOOK_SECRET', 'KesaRaz@adm2026#');

// UPI Payment Details (for manual payments)
define('UPI_ID', '9400423233');                        // Your UPI ID
define('UPI_NAME', 'Sayyid Shaheer');
define('UPI_QR_IMAGE', SITE_URL . '/assets/images/upi-qr.png'); // Upload your QR code image
