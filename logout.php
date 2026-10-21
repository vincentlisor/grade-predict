<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$portal = $_SESSION['portal'] ?? 'professor';
if ($user) {
    try {
        audit((int) $user['id'], 'logout', 'user', (string) $user['id'], 'Signed out');
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
header('Location: ' . url(login_path($portal === 'admin' ? 'admin' : 'professor')));
exit;
