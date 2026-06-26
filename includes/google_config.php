<?php
/**
 * Google OAuth Configuration
 * 
 * INSTRUCTIONS:
 * 1. Replace 'YOUR_CLIENT_ID_HERE' with your actual Google Client ID
 * 2. Replace 'YOUR_CLIENT_SECRET_HERE' with your actual Google Client Secret
 * 3. Save the file
 * 
 * Your Client ID looks like: 123456789-abcdefgh.apps.googleusercontent.com
 * Your Client Secret looks like: GOCSPX-xxxxxxxxxxxxx
 */

// ============================================
// PASTE YOUR GOOGLE CREDENTIALS BELOW
// ============================================

define('GOOGLE_CLIENT_ID', '630887901334-aeun7gidisjfchmrp9m51u0aht2vadj6.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// ============================================
// DO NOT EDIT BELOW THIS LINE
// ============================================

// Set as environment variables for compatibility
putenv('GOOGLE_CLIENT_ID=' . GOOGLE_CLIENT_ID);
putenv('GOOGLE_CLIENT_SECRET=' . GOOGLE_CLIENT_SECRET);

$_ENV['GOOGLE_CLIENT_ID'] = GOOGLE_CLIENT_ID;
$_ENV['GOOGLE_CLIENT_SECRET'] = GOOGLE_CLIENT_SECRET;
