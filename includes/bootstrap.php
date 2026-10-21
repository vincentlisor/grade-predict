<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/grades.php';

set_exception_handler(static function (Throwable $e): void {
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    $message = APP_DEBUG
        ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : 'The page could not be completed. The details were written to the server log.';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head><body style="font-family:Segoe UI,sans-serif;background:#F5F7FA;color:#142B49;padding:40px;"><h1>Something went wrong</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</p></body></html>';
    exit;
});

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function url(string $path = '', array $query = []): string
{
    $fragment = '';
    $hash = strpos($path, '#');
    if ($hash !== false) {
        $fragment = substr($path, $hash + 1);
        $path = substr($path, 0, $hash);
    }
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        parse_str(substr($path, $qpos + 1), $inline);
        foreach ($inline as $key => $value) {
            if (!array_key_exists($key, $query)) {
                $query[$key] = $value;
            }
        }
        $path = substr($path, 0, $qpos);
    }
    $base = rtrim(APP_BASE_PATH, '/');
    $path = ltrim($path, '/');
    $full = $path === '' ? $base : ($base === '' ? '/' . $path : $base . '/' . $path);
    if ($full === '') {
        $full = '/';
    }
    $parts = explode('/', $full);
    $encoded = implode('/', array_map(static fn (string $part): string => rawurlencode($part), $parts));
    if ($query !== []) {
        $encoded .= '?' . http_build_query($query);
    }
    if ($fragment !== '') {
        $encoded .= '#' . rawurlencode($fragment);
    }
    return $encoded;
}

function app_scope(): string
{
    $scope = rtrim(url(''), '/');
    return ($scope === '' ? '' : $scope) . '/';
}

function pwa_tags(): void
{
    ?>
    <link rel="manifest" href="<?= h(url('manifest.php')) ?>">
    <meta name="theme-color" content="#142B49">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="SPCC Grades">
    <link rel="apple-touch-icon" href="<?= h(url('img/icon-192.png')) ?>">
    <meta name="draft-url" content="<?= h(url('api/draft.php')) ?>">
    <meta name="professor-login" content="<?= h(url('professor/login.php')) ?>">
    <meta name="admin-login" content="<?= h(url('admin/login.php')) ?>">
    <meta name="sw-url" content="<?= h(url('sw.js')) ?>">
    <meta name="sw-scope" content="<?= h(app_scope()) ?>">
    <?php
}

function asset_url(string $path): string
{
    $relative = ltrim($path, '/');
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $query = is_file($file) ? ['v' => (string) filemtime($file)] : [];
    return url($relative, $query);
}

function redirect(string $path, array $query = []): void
{
    header('Location: ' . url($path, $query));
    exit;
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf(is_string($token) ? $token : null)) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_out(['ok' => false, 'message' => 'The security token expired. Refresh the page and try again.'], 403);
        }
        http_response_code(403);
        exit('The security token expired. Go back, refresh the page, and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

function audit(?int $userId, string $action, string $entity, ?string $entityId, string $details): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $action,
        $entity,
        $entityId,
        $details,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

function notify(int $userId, string $title, string $body, string $link): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, title, body, link_url) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $body, $link]);
}

function fmt_pct(?float $value): string
{
    return $value === null ? '—' : number_format($value, 2);
}

function fmt_eqv(?float $value): string
{
    return $value === null ? '—' : number_format($value, 2);
}

function fmt_date(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $time = strtotime($value);
    return $time === false ? h($value) : date('M j, Y', $time);
}

function fmt_when(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $time = strtotime($value);
    return $time === false ? h($value) : date('M j, Y g:i A', $time);
}

function submission_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'submitted' => 'With the Program Chair',
        'returned' => 'Returned for correction',
        'chair_verified' => 'Verified by the Program Chair',
        'oic_approved' => 'Approved by the College OIC',
        'osa_endorsed' => 'Endorsed by OSA',
        'registrar_finalized' => 'Finalized by the Registrar',
    ][$status] ?? $status;
}

function badge(string $label, ?string $key = null): string
{
    $key = $key ?? $label;
    $class = [
        'draft' => 'muted',
        'submitted' => 'info',
        'returned' => 'warn',
        'chair_verified' => 'info',
        'oic_approved' => 'info',
        'osa_endorsed' => 'info',
        'registrar_finalized' => 'ok',
        'Possible PL' => 'ok',
        'Possible DL' => 'ok',
        'Not Eligible' => 'warn',
        'Pending' => 'muted',
        'Final Approved' => 'gold',
        'chair_review' => 'info',
        'chair_endorsed' => 'info',
        'chair_returned' => 'warn',
        'chair_rejected' => 'bad',
        'oic_returned' => 'warn',
        'oic_rejected' => 'bad',
        'osa_approved' => 'ok',
        'osa_rejected' => 'bad',
        'osa_deferred' => 'warn',
        'registrar_returned' => 'warn',
        'registrar_verified' => 'gold',
        'Returned' => 'warn',
        'PASSED' => 'ok',
        'FAILED' => 'bad',
        'INC' => 'warn',
        'Regular' => 'info',
        'Irregular' => 'muted',
        'assigned' => 'ok',
        'revoked' => 'bad',
    ][$key] ?? 'muted';
    return '<span class="badge badge-' . $class . '">' . h($label) . '</span>';
}

function paginate(int $total, int $page, int $perPage = 15): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return [
        'page' => $page,
        'pages' => $pages,
        'per' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'total' => $total,
    ];
}

function pager_html(array $pager, string $path, array $query = []): string
{
    if ($pager['pages'] <= 1) {
        return '';
    }
    $html = '<nav class="pager" aria-label="Pages">';
    for ($i = 1; $i <= $pager['pages']; $i++) {
        $q = $query;
        $q['page'] = $i;
        $class = $i === $pager['page'] ? ' class="is-current"' : '';
        $html .= '<a' . $class . ' href="' . h(url($path, $q)) . '">' . $i . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM school_settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function users_with_role(string $role, ?int $programId = null): array
{
    $sql = 'SELECT u.id, u.full_name, u.username
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE u.is_active = 1 AND r.code = ?';
    $params = [$role];
    if ($programId !== null) {
        $sql .= ' AND ur.program_id = ?';
        $params[] = $programId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function render_setup_error(Throwable $e): void
{
    http_response_code(503);
    $detail = APP_DEBUG ? h($e->getMessage()) : 'The database rejected the connection.';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Setup required</title>
    <style>body{font-family:Segoe UI,sans-serif;background:#142B49;color:#fff;margin:0;min-height:100vh;display:grid;place-items:center}main{max-width:640px;background:#fff;color:#142B49;padding:32px;border-radius:16px}code{background:#F5F7FA;padding:2px 6px;border-radius:6px}</style>
    </head><body><main><h1>Database setup is still needed</h1><p>' . $detail . '</p>
    <ol><li>Start Apache and MySQL in the XAMPP Control Panel.</li>
    <li>Open phpMyAdmin and import <code>database/database.sql</code>. The script creates <code>spcc_academic</code>.</li>
    <li>Check <code>.env</code> if local MySQL is not using user <code>root</code> with an empty password.</li></ol>
    </main></body></html>';
    exit;
}

function db_or_setup(): PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        render_setup_error($e);
    }
}
