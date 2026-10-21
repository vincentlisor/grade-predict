<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $_SESSION['uid']]);
    $row = $stmt->fetch();
    if (!$row) {
        $_SESSION = [];
        return null;
    }
    $roles = db()->prepare(
        'SELECT r.code, r.name, ur.program_id
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ?'
    );
    $roles->execute([(int) $row['id']]);
    $row['role_rows'] = $roles->fetchAll();
    $row['roles'] = array_column($row['role_rows'], 'code');
    $row['portal'] = $_SESSION['portal'] ?? 'admin';
    $row['role_label'] = role_label($row);
    return $user = $row;
}

function role_label(array $user): string
{
    $names = [];
    foreach ($user['role_rows'] as $role) {
        $names[] = $role['name'];
    }
    return $names === [] ? 'User' : implode(', ', $names);
}

function display_name(array $person): string
{
    $title = trim((string) ($person['title'] ?? ''));
    $name = trim((string) ($person['full_name'] ?? ''));
    return trim($title . ' ' . $name);
}

function can(array $user, string $permission): bool
{
    $map = [
        'manage_users' => ['system_admin'],
        'manage_faculty' => ['system_admin'],
        'manage_academics' => ['system_admin'],
        'import' => ['system_admin'],
        'rules' => ['system_admin'],
        'settings' => ['system_admin'],
        'queue' => ['program_chair', 'college_oic', 'osa', 'registrar', 'system_admin'],
        'scholarships' => ['registrar', 'system_admin'],
        'reports' => ['program_chair', 'college_oic', 'osa', 'registrar', 'system_admin'],
        'evaluations' => ['system_admin'],
        'recognition' => ['program_chair', 'college_oic', 'osa', 'registrar', 'system_admin'],
        'analytics' => ['system_admin'],
        'audit' => ['system_admin'],
        'students_write' => ['system_admin'],
        'students_read' => ['program_chair', 'registrar', 'system_admin'],
        'finalize' => ['registrar'],
    ];
    return (bool) array_intersect($user['roles'], $map[$permission] ?? []);
}

function scoped_program_ids(array $user): ?array
{
    if (array_intersect($user['roles'], ['system_admin', 'college_oic', 'osa', 'registrar'])) {
        return null;
    }
    if (in_array('program_chair', $user['roles'], true)) {
        return chair_program_ids($user);
    }
    return [];
}

function chair_program_ids(array $user): array
{
    $ids = [];
    foreach ($user['role_rows'] as $role) {
        if ($role['code'] === 'program_chair' && $role['program_id']) {
            $ids[] = (int) $role['program_id'];
        }
    }
    return $ids;
}

function login_path(string $portal): string
{
    return $portal === 'admin' ? 'admin/login.php' : 'professor/login.php';
}

function home_path(string $portal): string
{
    return $portal === 'admin' ? 'admin/dashboard.php' : 'professor/dashboard.php';
}

function attempt_portal_login(string $portal): string
{
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $roles = [];
    if ($user) {
        $roleStmt = db()->prepare(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?'
        );
        $roleStmt->execute([(int) $user['id']]);
        $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $adminRoles = ['system_admin', 'program_chair', 'college_oic', 'osa', 'registrar'];
    $passwordOk = $user && password_verify($password, $user['password_hash']);
    $portalOk = $passwordOk && (
        ($portal === 'professor' && in_array('professor', $roles, true))
        || ($portal === 'admin' && array_intersect($roles, $adminRoles))
    );
    if (!$portalOk) {
        audit($user ? (int) $user['id'] : null, 'login_failed', 'user', $username, $portal);
        return $passwordOk
            ? 'This account cannot sign in here.'
            : 'The username or password is not correct.';
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['portal'] = $portal;
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    audit((int) $user['id'], 'login', 'user', (string) $user['id'], $portal . ' portal');
    redirect(home_path($portal));
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $side = $_GET['side'] ?? $_POST['side'] ?? '';
        if (str_contains($script, '/admin/') || $side === 'admin') {
            redirect(login_path('admin'));
        }
        if (str_contains($script, '/professor/') || str_contains($script, '/api/')) {
            redirect(login_path('professor'));
        }
        redirect(login_path($side === 'admin' ? 'admin' : 'professor'));
    }
    return $user;
}

function require_portal(string $portal): array
{
    $user = current_user();
    if (!$user || ($user['portal'] ?? '') !== $portal) {
        redirect(login_path($portal), $user ? ['error' => 'portal'] : []);
    }
    if ($portal === 'professor' && !in_array('professor', $user['roles'], true)) {
        redirect(login_path('professor'), ['error' => 'portal']);
    }
    if ($portal === 'admin' && !array_intersect($user['roles'], ['system_admin', 'program_chair', 'college_oic', 'osa', 'registrar'])) {
        redirect(login_path('admin'), ['error' => 'portal']);
    }
    return $user;
}

function sheet_query(array $query = []): array
{
    if (($_SESSION['portal'] ?? '') === 'admin') {
        $query['side'] = 'admin';
    }
    return $query;
}

function require_permission(array $user, string $permission): void
{
    if (!can($user, $permission)) {
        http_response_code(403);
        exit('Your role cannot open this page.');
    }
}

function unread_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}
