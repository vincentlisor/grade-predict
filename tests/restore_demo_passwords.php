<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/database.php';
$hash = password_hash('ChangeMe!2026', PASSWORD_DEFAULT);
db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1')->execute([$hash]);
$stored = db()->query("SELECT password_hash, must_change_password FROM users WHERE username = 'fquiza'")->fetch();
if (!password_verify('ChangeMe!2026', $stored['password_hash']) || (int) $stored['must_change_password'] !== 1) {
    fwrite(STDERR, "restore failed\n");
    exit(1);
}
echo "demo passwords restored\n";
