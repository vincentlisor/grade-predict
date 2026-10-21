<?php
declare(strict_types=1);

function load_env_file(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

load_env_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'spcc_academic');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('APP_DEBUG', ($_ENV['APP_DEBUG'] ?? 'false') === 'true');

define('SCHOOL_NAME', 'Systems Plus Computer College');
define('SCHOOL_ESTABLISHED', '1985');
define('APP_NAME', 'College Grading and Academic Evaluation');

function detect_base_path(): string
{
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    $realApp = realpath($appRoot);
    if (is_string($realApp) && $realApp !== '') {
        $appRoot = str_replace('\\', '/', $realApp);
    }
    $appRoot = rtrim($appRoot, '/');

    $docRoot = str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $realDoc = $docRoot !== '' ? realpath($docRoot) : false;
    if (is_string($realDoc) && $realDoc !== '') {
        $docRoot = str_replace('\\', '/', $realDoc);
    }
    $docRoot = rtrim($docRoot, '/');
    if ($docRoot !== '' && str_starts_with(strtolower($appRoot), strtolower($docRoot))) {
        $rel = substr($appRoot, strlen($docRoot));
        return ($rel === '' || $rel === '/') ? '' : $rel;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $realScript = $scriptFile !== '' ? realpath($scriptFile) : false;
    if (is_string($realScript) && $realScript !== '') {
        $scriptFile = str_replace('\\', '/', $realScript);
    }
    if ($scriptName !== '' && $scriptFile !== '' && str_starts_with(strtolower($scriptFile), strtolower($appRoot))) {
        $suffix = substr($scriptFile, strlen($appRoot));
        if ($suffix !== '' && str_ends_with(strtolower($scriptName), strtolower($suffix))) {
            $base = rtrim(substr($scriptName, 0, strlen($scriptName) - strlen($suffix)), '/');
            return ($base === '' || $base === '/') ? '' : $base;
        }
    }

    return '';
}

define('APP_BASE_PATH', detect_base_path());


define('PERIODS', [
    'prelim' => 'Prelim',
    'midterm' => 'Midterm',
    'finals' => 'Finals',
]);

define('CATEGORIES', [
    'attendance' => 'Attendance',
    'recitation' => 'Recitation',
    'activities' => 'Activities',
    'quizzes' => 'Quizzes',
    'project' => 'Project',
    'exam' => 'Exam',
]);

if (session_status() === PHP_SESSION_NONE) {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $side = $_GET['side'] ?? $_POST['side'] ?? '';
    $sessionName = (str_contains($script, '/admin/') || $side === 'admin') ? 'SPCCADMIN' : 'SPCCPROF';
    session_name($sessionName);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $https,
    ]);
    session_start();
}

date_default_timezone_set('Asia/Manila');

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
ini_set('log_errors', '1');
