<?php
declare(strict_types=1);

function icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'classes' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'queue' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4.5h6V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1z"/><path d="m9 12 2 2 4-4"/>',
        'award' => '<circle cx="12" cy="8" r="5"/><path d="M8.2 13.9 7 21l5-2.5L17 21l-1.2-7.1"/>',
        'students' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1"/><circle cx="17" cy="9" r="2"/><path d="M21 20v-1a3 3 0 0 0-2.4-2.9"/>',
        'faculty' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1"/>',
        'academics' => '<path d="M3 21h18"/><path d="M5 21V8l7-4 7 4v13"/><path d="M9 21v-6h6v6"/>',
        'import' => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 20h16"/>',
        'users' => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M8 17.5c.6-1.6 2-2.5 4-2.5s3.4.9 4 2.5"/>',
        'rules' => '<path d="M12 3v18"/><path d="M8 21h8"/><path d="M12 7H6l-2 5h8z"/><path d="M12 7h6l2 5h-8z"/>',
        'scholarships' => '<circle cx="12" cy="8" r="4"/><path d="m9 12 3 9 3-9"/>',
        'reports' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M8 13h8"/><path d="M8 17h5"/>',
        'analytics' => '<path d="M4 19V10"/><path d="M10 19V5"/><path d="M16 19v-7"/><path d="M22 19H2"/>',
        'audit' => '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'key' => '<path d="m21 2-2 2m-1.5 1.5L14 9.5a5 5 0 1 0 4.2 4.2l3.8-3.7V7h-3V4h-3z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
        'sheet' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/>',
        'summary' => '<path d="M4 5h16"/><path d="M4 12h10"/><path d="M4 19h13"/>',
        'final' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2"/><path d="M12 19v2"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="m5 5 1.5 1.5"/><path d="m17.5 17.5 1.5 1.5"/><path d="m19 5-1.5 1.5"/><path d="m6.5 17.5-1.5 1.5"/>',
        'adjust' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
        'open' => '<path d="M7 17 17 7"/><path d="M9 7h8v8"/>',
        'record' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><circle cx="12" cy="13" r="2"/><path d="M9 18c.4-1.2 1.6-2 3-2s2.6.8 3 2"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'download' => '<path d="M12 4v12"/><path d="m7 11 5 5 5-5"/><path d="M5 20h14"/>',
        'print' => '<path d="M6 9V3h12v6"/><rect x="6" y="13" width="12" height="8"/><path d="M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
    ];
    $body = $paths[$name] ?? $paths['record'];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function nav_items(array $user): array
{
    if (($user['portal'] ?? '') === 'professor') {
        return [
            ['id' => 'dash', 'href' => 'professor/dashboard.php', 'icon' => 'dashboard', 'label' => 'Dashboard'],
            ['id' => 'classes', 'href' => 'professor/classes.php', 'icon' => 'classes', 'label' => 'My classes'],
        ];
    }
    $items = [
        ['id' => 'dash', 'href' => 'admin/dashboard.php', 'icon' => 'dashboard', 'label' => 'Dashboard'],
    ];
    if (can($user, 'queue')) {
        $items[] = ['id' => 'queue', 'href' => 'admin/queue.php', 'icon' => 'queue', 'label' => 'Review queue'];
    }
    if (can($user, 'evaluations')) {
        $items[] = ['id' => 'evaluations', 'href' => 'admin/evaluations.php', 'icon' => 'award', 'label' => 'DL / PL'];
    }
    if (can($user, 'recognition')) {
        $items[] = ['id' => 'recognition', 'href' => 'admin/recognition.php', 'icon' => 'final', 'label' => 'Recognition'];
    }
    if (can($user, 'students_read')) {
        $items[] = ['id' => 'students', 'href' => 'admin/students.php', 'icon' => 'students', 'label' => 'Students'];
    }
    if (can($user, 'manage_faculty')) {
        $items[] = ['id' => 'faculty', 'href' => 'admin/faculty.php', 'icon' => 'faculty', 'label' => 'Faculty'];
    }
    if (can($user, 'manage_academics')) {
        $items[] = ['id' => 'academics', 'href' => 'admin/academics.php', 'icon' => 'academics', 'label' => 'Academics'];
    }
    if (can($user, 'rules')) {
        $items[] = ['id' => 'rules', 'href' => 'admin/rules.php', 'icon' => 'rules', 'label' => 'DL / PL criteria'];
    }
    if (can($user, 'settings')) {
        $items[] = ['id' => 'settings', 'href' => 'admin/settings.php', 'icon' => 'settings', 'label' => 'System settings'];
    }
    if (can($user, 'import')) {
        $items[] = ['id' => 'import', 'href' => 'admin/import.php', 'icon' => 'import', 'label' => 'Import'];
    }
    if (can($user, 'manage_users')) {
        $items[] = ['id' => 'users', 'href' => 'admin/users.php', 'icon' => 'users', 'label' => 'User management'];
    }
    if (can($user, 'scholarships')) {
        $items[] = ['id' => 'scholarships', 'href' => 'admin/scholarships.php', 'icon' => 'scholarships', 'label' => 'Scholarships'];
    }
    if (can($user, 'reports')) {
        $items[] = ['id' => 'reports', 'href' => 'admin/reports.php', 'icon' => 'reports', 'label' => 'Reports'];
    }
    if (can($user, 'analytics')) {
        $items[] = ['id' => 'analytics', 'href' => 'admin/analytics.php', 'icon' => 'analytics', 'label' => 'Analytics'];
    }
    if (can($user, 'audit')) {
        $items[] = ['id' => 'audit', 'href' => 'admin/audit.php', 'icon' => 'audit', 'label' => 'Audit'];
    }
    return $items;
}

function layout_start(string $title, string $active, array $crumbs = [], string $bodyClass = '', array $side = []): void
{
    $user = current_user();
    $unread = $user ? unread_count((int) $user['id']) : 0;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · <?= h(SCHOOL_NAME) ?></title>
    <link rel="icon" href="<?= h(url('img/logo.png')) ?>">
    <?php pwa_tags(); ?>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/app.css')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<a class="skip" href="#content">Skip to content</a>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= h(url(($user['portal'] ?? '') === 'admin' ? 'admin/dashboard.php' : 'professor/dashboard.php')) ?>">
            <img src="<?= h(url('img/logo.png')) ?>" alt="">
            <span>
                <strong>Systems Plus</strong>
                <em>Computer College</em>
            </span>
        </a>
        <p class="portal-tag"><?= ($user['portal'] ?? '') === 'admin' ? 'Administrative portal' : 'Professor portal' ?></p>
        <nav class="sidenav" aria-label="Primary">
            <?php foreach (nav_items($user) as $item): ?>
                <a class="<?= $item['id'] === $active ? 'is-active' : '' ?>" href="<?= h(url($item['href'])) ?>"><?= icon($item['icon']) ?><span><?= h($item['label']) ?></span></a>
            <?php endforeach; ?>
            <?php if (($side['items'] ?? []) !== []): ?>
                <p class="nav-label"><?= h((string) ($side['label'] ?? 'Class')) ?></p>
                <?php foreach ($side['items'] as $item): ?>
                    <a class="<?= ($item['id'] ?? '') === $active ? 'is-active' : '' ?>" href="<?= h(url((string) $item['href'], $item['query'] ?? [])) ?>"><?= icon((string) $item['icon']) ?><span><?= h((string) $item['label']) ?></span></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
        <div class="side-foot">
            <a href="<?= h(url('password.php', ['side' => ($user['portal'] ?? '') === 'admin' ? 'admin' : 'professor'])) ?>"><?= icon('key') ?><span>Password</span></a>
            <a href="<?= h(url('logout.php', ['side' => ($user['portal'] ?? '') === 'admin' ? 'admin' : 'professor'])) ?>"><?= icon('logout') ?><span>Sign out</span></a>
        </div>
    </aside>
    <div class="nav-scrim" id="navScrim"></div>
    <div class="workspace">
        <header class="topbar">
            <button type="button" class="icon-btn" id="navToggle" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </button>
            <div class="crumbs">
                <?php if ($crumbs === []): ?>
                    <span><?= h($title) ?></span>
                <?php else: ?>
                    <?php foreach ($crumbs as $i => $crumb): ?>
                        <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
                        <?php if (!empty($crumb['href'])): ?>
                            <a href="<?= h(url($crumb['href'])) ?>"><?= h($crumb['label']) ?></a>
                        <?php else: ?>
                            <span><?= h($crumb['label']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="top-actions">
                <a class="bell" href="<?= h(url('notifications.php', ['side' => ($user['portal'] ?? '') === 'admin' ? 'admin' : 'professor'])) ?>" aria-label="Notifications">
                    <?= icon('bell') ?>
                    <?php if ($unread > 0): ?><span><?= $unread ?></span><?php endif; ?>
                </a>
                <div class="who">
                    <strong><?= h(display_name($user)) ?></strong>
                    <small><?= h($user['role_label']) ?></small>
                </div>
            </div>
        </header>
        <main id="content" class="content">
            <?php foreach (pull_flashes() as $flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
            <?php endforeach; ?>
    <?php
}

function layout_end(string $extraJs = ''): void
{
    ?>
        </main>
    </div>
</div>
<div class="toaster" id="toaster" aria-live="polite"></div>
<div class="modal" id="confirmModal" hidden>
    <div class="modal-scrim" data-modal-cancel></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMessage">
        <h2 id="confirmTitle"></h2>
        <p id="confirmMessage"></p>
        <p class="modal-note" id="confirmNote" hidden></p>
        <div id="confirmFields" hidden></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" id="confirmCancel">Cancel</button>
            <button type="button" class="btn" id="confirmOk">Confirm</button>
        </div>
    </div>
</div>
<?php
    $viewer = function_exists('current_user') ? current_user() : null;
    if ($viewer && ($viewer['portal'] ?? '') === 'professor') {
        require_once __DIR__ . '/gradebook.php';
        $offlinePages = offline_gradebook_urls((int) $viewer['id']);
    } elseif ($viewer && ($viewer['portal'] ?? '') === 'admin') {
        require_once __DIR__ . '/gradebook.php';
        $offlinePages = offline_admin_urls($viewer);
    } else {
        $offlinePages = [];
    }
    if ($offlinePages !== []) {
        echo '<script type="application/json" id="offlinePages">'
            . json_encode($offlinePages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
    }
    ?>
<script src="<?= h(asset_url('assets/js/app.js')) ?>"></script>
<script src="<?= h(asset_url('assets/js/drafts.js')) ?>"></script>
<?php if ($extraJs !== ''): ?>
<script src="<?= h(asset_url($extraJs)) ?>"></script>
<?php endif; ?>
</body>
</html>
    <?php
}

function scale_card(array $scale): string
{
    $html = '<aside class="scale-card"><h2>' . icon('award') . ' Grade equivalent</h2><ul class="scale-list">';
    foreach ($scale as $band) {
        $min = (float) $band['min_percent'];
        $max = (float) $band['max_percent'];
        if ($min <= 0) {
            $label = 'Below 75%';
        } elseif ($min >= 98) {
            $label = '98–100%';
        } elseif ($min >= 75 && $max < 78) {
            $label = '75–76%';
        } else {
            $label = (int) round($min) . '–' . (int) floor($max) . '%';
        }
        $html .= '<li><span>' . h($label) . '</span><strong>' . number_format((float) $band['equivalent'], 2) . '</strong><em>' . h((string) ($band['description'] ?? '')) . '</em></li>';
    }
    $html .= '</ul><p>1.00 is the highest mark. 3.00 is the lowest passing mark. Below 75% is 5.00, Failed.</p></aside>';
    return $html;
}
