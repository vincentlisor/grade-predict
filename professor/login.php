<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

db_or_setup();
$error = '';
if (current_user() && ($_SESSION['portal'] ?? '') === 'professor') {
    redirect('professor/dashboard.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $error = attempt_portal_login('professor');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Professor sign in · <?= h(SCHOOL_NAME) ?></title>
    <link rel="icon" href="<?= h(url('img/logo.png')) ?>">
    <?php pwa_tags(); ?>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/app.css')) ?>">
</head>
<body class="auth auth-faculty">
<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-mark">
            <img src="<?= h(url('img/logo.png')) ?>" alt="School Logo">
            <div>
                <strong>Systems Plus</strong>
                <span>Computer College · <?= h(SCHOOL_ESTABLISHED) ?></span>
            </div>
        </div>
        <div class="auth-copy">
            <p class="auth-kicker">Faculty</p>
            <h1 style="font-size: clamp(28px, 3.2vw, 42px); line-height: 1.15; margin: 12px 0 16px;">
                Your grading sheet,<br>ready to encode.
            </h1>
            <p>Open the classes assigned to you and record prelim, midterm, and finals the way the workbook does.</p>
            <ul class="auth-points">
                <li>Attendance through examination, in the sheet colors</li>
                <li>Scores save as you type</li>
                <li>Submit a period after every score in that period is filled in</li>
            </ul>
        </div>
        <p class="auth-year">Systems Plus Computer College</p>
    </aside>
    <main class="auth-main">
        <form class="auth-form" method="post" action="<?= h(url('professor/login.php')) ?>">
            <?= csrf_field() ?>
            <p class="auth-kicker">Professor sign in</p>
            <h2>Welcome back</h2>
            <p class="lede">Use the faculty account issued for your classes. After one sign-in on this device, the same account can sign in without internet.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if (($_GET['error'] ?? '') === 'portal'): ?>
                <div class="alert alert-error">This account cannot sign in here.</div>
            <?php endif; ?>

            <div class="auth-field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" placeholder="Faculty username" required autofocus>
            </div>
            <div class="auth-field">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Password" required>
                    <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-shut" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-3.2 4.2"/><path d="M6.1 6.1C3.5 8 2 12 2 12s3.5 7 10 7a10.8 10.8 0 0 0 4.1-.8"/></svg>
                    </button>
                </div>
            </div>
            <button class="btn btn-gold" type="submit">Log in</button>

            <?php if (defined('DEMO_MODE') && DEMO_MODE): ?>
            <details class="demo-accounts">
                <summary>Sample professor accounts</summary>
                <p>Starting password: <strong>ChangeMe!2026</strong>. A new password is required after sign-in.</p>
                <table>
                    <tr><th>Username</th><th>Professor</th></tr>
                    <tr><td class="mono">fquiza</td><td>Mr. Febrick Quiza</td></tr>
                    <tr><td class="mono">psantos</td><td>Ms. Santos, Patricia</td></tr>
                </table>
            </details>
            <?php endif; ?>

            <p class="auth-foot"><a href="<?= h(url('index.php')) ?>">College home</a></p>
        </form>
    </main>
</div>
<script src="<?= h(asset_url('assets/js/drafts.js')) ?>"></script>
</body>
</html>