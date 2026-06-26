<?php
/**
 * KESA Learn - Auth Middleware
 * Include this file at the top of any page that requires login.
 *
 * Now also runs the account gates: mandatory contact completion and
 * duplicate-account merge approval (see includes/account_gates.php).
 */

require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    setFlash('error', 'Please log in to access this page.');
    redirect('/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
}

require_once __DIR__ . '/account_gates.php';
runAccountGates();
