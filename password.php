<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

db_or_setup();
$user = current_user();
$side = (($_GET['side'] ?? $_POST['side'] ?? $_SESSION['portal'] ?? '') === 'admin') ? 'admin' : 'professor';
if (!$user) {
    redirect(login_path($side));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $next = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        flash('error', 'The current password is not correct.');
    } elseif (strlen($next) < 8) {
        flash('error', 'Use at least 8 characters for the new password.');
    } elseif ($next !== $confirm) {
        flash('error', 'The new password and confirmation do not match.');
    } else {
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);
        audit((int) $user['id'], 'password_change', 'user', (string) $user['id'], 'Password updated');
        flash('ok', 'Password updated.');
        redirect(($user['portal'] ?? '') === 'admin' ? 'admin/dashboard.php' : 'professor/dashboard.php');
    }
    redirect('password.php', ['side' => $side]);
}

layout_start('Change password', 'dash', [['label' => 'Change password']]);
?>
<div style="max-width: 520px; margin: 24px auto 0;">
    <section class="card" style="margin: 0;">
        <h2><?= icon('key') ?> Choose a new password</h2>
        <form method="post" action="<?= h(url('password.php', ['side' => $side])) ?>" data-device-password="<?= h($user['username']) ?>" data-device-portal="<?= h($side) ?>">
            <?= csrf_field() ?>
            <div style="margin-bottom:14px;">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            </div>
            <div style="margin-bottom:14px;">
                <label for="new_password">New password</label>
                <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
            </div>
            <div style="margin-bottom:20px;">
                <label for="confirm_password">Confirm new password</label>
                <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
            </div>
            <button class="btn btn-gold" type="submit" style="width: 100%;">Update password</button>
        </form>
    </section>
</div>
<?php layout_end(); ?>