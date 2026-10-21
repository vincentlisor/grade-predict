<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

try {
    db_or_setup();
} catch (Throwable $e) {
    render_setup_error($e);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(SCHOOL_NAME) ?></title>
    <link rel="icon" href="<?= h(url('img/logo.png')) ?>">
    <?php pwa_tags(); ?>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/app.css')) ?>">
</head>
<body>
<main class="gate">
    <section class="gate-card">
        <div class="gate-seal">
            <img src="<?= h(url('img/logo.png')) ?>" alt="Systems Plus Computer College seal">
            <h1>Systems Plus</h1>
            <p>Computer College · <?= h(SCHOOL_ESTABLISHED) ?></p>
        </div>
        <div class="gate-main">
            <h2 style="font-family:var(--display);color:var(--navy);margin-top:0;">College grading sheet</h2>
            <p class="lede">Faculty encode grades the way the current workbook does. Offices review the same record through to the Registrar.</p>
            <div class="portal-choices" style="margin-top:22px;">
                <a href="<?= h(url('professor/login.php')) ?>">
                    <span class="kpi-ico"><?= icon('classes') ?></span>
                    <span><strong>Professors</strong><span>Faculty grading sheet</span></span>
                </a>
                <a href="<?= h(url('admin/login.php')) ?>">
                    <span class="kpi-ico"><?= icon('academics') ?></span>
                    <span><strong>College offices</strong><span>Chair, OIC, OSA, Registrar, Administrator</span></span>
                </a>
            </div>
        </div>
    </section>
</main>
<script src="<?= h(asset_url('assets/js/drafts.js')) ?>"></script>
</body>
</html>
