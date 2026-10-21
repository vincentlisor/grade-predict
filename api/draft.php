<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

header('Cache-Control: no-store');

$user = current_user();
if (!$user || ($user['portal'] ?? '') !== 'professor') {
    json_out(['ok' => false, 'message' => 'Sign in again to sync draft scores.'], 401);
}

json_out([
    'ok' => true,
    'csrf' => csrf_token(),
]);
