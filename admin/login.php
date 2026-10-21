<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

db_or_setup();
$error = '';
if (current_user() && ($_SESSION['portal'] ?? '') === 'admin') {
    redirect('admin/dashboard.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $error = attempt_portal_login('admin');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>College offices sign in · <?= h(SCHOOL_NAME) ?></title>
    <link rel="icon" href="<?= h(url('img/logo.png')) ?>">
    <?php pwa_tags(); ?>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/app.css')) ?>">
</head>
<body class="auth auth-offices">
<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-mark">
            <img src="<?= h(url('img/logo.png')) ?>" alt="">
            <div>
                <strong>Systems Plus</strong>
                <span>Computer College · <?= h(SCHOOL_ESTABLISHED) ?></span>
            </div>
        </div>
        <div class="auth-copy">
            <p class="auth-kicker">College offices</p>
            <h1>Review, endorse, and release the official record.</h1>
            <p>Program chairs, the College OIC, Student Affairs, and the Registrar share one offices entrance.</p>
            <ul class="auth-roles">
                <li><span>01</span> Program Chair</li>
                <li><span>02</span> College Department OIC</li>
                <li><span>03</span> Office of Student Affairs</li>
                <li><span>04</span> Registrar</li>
                <li><span>05</span> System Administrator</li>
            </ul>
        </div>
        <p class="auth-year">Restricted to college offices</p>
    </aside>
    <main class="auth-main">
        <form class="auth-form" method="post" action="<?= h(url('admin/login.php')) ?>">
            <?= csrf_field() ?>
            <p class="auth-kicker">Offices sign in</p>
            <h2>College access</h2>
            <p class="lede">Sign in with the account issued for your office. After one sign-in on this device, the same account can open office pages and save student records without internet.</p>
            <?php if ($error !== ''): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
            <?php if (($_GET['error'] ?? '') === 'portal'): ?><div class="alert alert-error">This account cannot sign in here.</div><?php endif; ?>
            <div class="auth-field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" placeholder="Office username" required>
            </div>
            <div class="auth-field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Password" required>
            </div>
            <button class="btn" type="submit">Enter offices</button>
            <details class="demo-accounts">
                <summary>Sample office accounts</summary>
                <p>Starting password: <strong>ChangeMe!2026</strong>. A new password is required after sign-in.</p>
                <table>
                    <tr><th>Username</th><th>Office</th></tr>
                    <tr><td class="mono">admin</td><td>System Administrator</td></tr>
                    <tr><td class="mono">chair.bsit</td><td>BSIT Program Chair</td></tr>
                    <tr><td class="mono">chair.bshm</td><td>BSHM Program Chair</td></tr>
                    <tr><td class="mono">oic</td><td>College Department OIC</td></tr>
                    <tr><td class="mono">osa</td><td>Office of Student Affairs</td></tr>
                    <tr><td class="mono">registrar</td><td>Registrar</td></tr>
                </table>
            </details>
            <p class="auth-foot"><a href="<?= h(url('index.php')) ?>">College home</a></p>
        </form>
    </main>
</div>
<script src="<?= h(asset_url('assets/js/drafts.js')) ?>"></script>
</body>
</html>
