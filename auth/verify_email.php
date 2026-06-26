<?php
/**
 * KESA Learn - Email Verification
 */
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    setFlash('error', 'Invalid verification link.');
    redirect('/');
}

$db = getDB();
$stmt = $db->prepare("SELECT ev.*, u.email FROM email_verifications ev JOIN users u ON ev.user_id = u.id WHERE ev.token = ? AND ev.expires_at > NOW()");
$stmt->execute([$token]);
$verification = $stmt->fetch();

if (!$verification) {
    setFlash('error', 'This verification link has expired or is invalid.');
    redirect('/auth/login');
}

// Mark email as verified
$stmt = $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
$stmt->execute([$verification['user_id']]);

// Delete verification token
$stmt = $db->prepare("DELETE FROM email_verifications WHERE user_id = ?");
$stmt->execute([$verification['user_id']]);

logActivity('email_verified', "Email verified: {$verification['email']}", $verification['user_id']);

setFlash('success', 'Your email has been verified successfully! You can now log in.');
redirect('/auth/login');
