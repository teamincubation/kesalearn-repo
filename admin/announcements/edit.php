<?php
/**
 * Redirect to create.php which handles both create and edit
 */
require_once __DIR__ . '/../../includes/functions.php';
if (!isset($_GET['id'])) {
    redirect('/admin/announcements/');
}
include __DIR__ . '/create.php';
