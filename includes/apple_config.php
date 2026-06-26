<?php
/**
 * Apple Sign-in Configuration
 * 
 * INSTRUCTIONS:
 * 1. Go to https://developer.apple.com/account/resources/identifiers/list/serviceId
 * 2. Create a Services ID for your app
 * 3. Copy your Team ID from Apple Developer Account
 * 4. Create a private key and download it
 * 5. Replace the values below with your credentials
 * 
 * Example Team ID: ABCD123456
 * Example Service ID: com.kesalearn.auth
 */

define('APPLE_TEAM_ID', 'YOUR_APPLE_TEAM_ID');
define('APPLE_CLIENT_ID', 'com.kesalearn.auth');  // Service ID
define('APPLE_KEY_ID', 'YOUR_KEY_ID');
define('APPLE_PRIVATE_KEY_PATH', __DIR__ . '/../config/apple_private_key.p8');

// Set as environment variables for compatibility
putenv('APPLE_TEAM_ID=' . APPLE_TEAM_ID);
putenv('APPLE_CLIENT_ID=' . APPLE_CLIENT_ID);
putenv('APPLE_KEY_ID=' . APPLE_KEY_ID);

$_ENV['APPLE_TEAM_ID'] = APPLE_TEAM_ID;
$_ENV['APPLE_CLIENT_ID'] = APPLE_CLIENT_ID;
$_ENV['APPLE_KEY_ID'] = APPLE_KEY_ID;
