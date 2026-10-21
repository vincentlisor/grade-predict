<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$portal = ($_GET['portal'] ?? '') === 'admin' ? 'admin' : 'professor';
$query = [];
if (($_GET['error'] ?? '') !== '') {
    $query['error'] = (string) $_GET['error'];
}
redirect(login_path($portal), $query);
