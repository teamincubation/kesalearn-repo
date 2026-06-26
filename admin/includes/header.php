<?php
/**
 * KESA Learn - Admin Header (compatibility shim)
 *
 * Some legacy admin pages include admin/includes/header.php, which never
 * existed - they fataled with a 500. The sidebar include renders the full
 * document head and opens the admin layout, and admin/includes/footer.php
 * closes it, so delegating keeps those pages working with the standard
 * admin look.
 */
require __DIR__ . '/sidebar.php';
