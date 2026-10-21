<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'settings');

$allowed = [
    'notifications_enabled' => [
        'label' => 'Notifications',
        'desc' => 'Enable automated system alerts and workflow status updates for faculty and administrators.'
    ],
    'workflow_returns_enabled' => [
        'label' => 'Returned-record workflow',
        'desc' => 'Allow approvers and evaluators to send back grade sheets for necessary professor revisions.'
    ],
    'workflow_remarks_required' => [
        'label' => 'Remarks required for adverse decisions',
        'desc' => 'Mandate detailed reason comments whenever a grade sheet or candidate record is rejected or returned.'
    ],
    'export_include_history' => [
        'label' => 'Include approval history in exports',
        'desc' => 'Attach complete approval timeline logs and evaluator timestamps in generated CSV/PDF reports.'
    ],
];

if (($_GET['export'] ?? '') === 'json') {
    $rows = db()->query('SELECT setting_key, setting_value FROM school_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_KEY_PAIR);
    audit((int) $user['id'], 'settings_export', 'school_settings', null, 'Configuration JSON');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="system-settings.json"');
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $save = db()->prepare(
        'INSERT INTO school_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($allowed as $key => $info) {
        $save->execute([$key, empty($_POST[$key]) ? '0' : '1']);
    }
    audit((int) $user['id'], 'settings_update', 'school_settings', null, 'Workflow and notification settings');
    flash('ok', 'System settings saved successfully.');
    redirect('admin/settings.php');
}

$settings = db()->query('SELECT setting_key, setting_value FROM school_settings')->fetchAll(PDO::FETCH_KEY_PAIR);

// Calculate Active Features Count
$activeCount = 0;
foreach ($allowed as $key => $info) {
    if (($settings[$key] ?? '1') === '1') {
        $activeCount++;
    }
}

layout_start('System Settings', 'settings', [['label' => 'System settings']]);
?>
<div class="page-head">
    <div>
        <h1>System Technical Settings</h1>
        <p class="lede">Technical settings do not grant approval authority. Workflow decisions remain strictly with designated personnel.</p>
    </div>
    <div>
        <a class="btn btn-ghost btn-sm" href="<?= h(url('admin/settings.php', ['export' => 'json'])) ?>">
            <?= icon('download') ?> Export Configuration JSON
        </a>
    </div>
</div>

<!-- Summary Status KPIs -->
<section class="kpis">
    <article class="kpi">
        <span>Active Configuration Modules</span>
        <strong><?= $activeCount ?> / <?= count($allowed) ?> Enabled</strong>
    </article>
    <article class="kpi">
        <span>Notifications System</span>
        <strong><?= ($settings['notifications_enabled'] ?? '1') === '1' ? 'ACTIVE' : 'DISABLED' ?></strong>
    </article>
    <article class="kpi">
        <span>Revision Return Workflow</span>
        <strong><?= ($settings['workflow_returns_enabled'] ?? '1') === '1' ? 'ENABLED' : 'DISABLED' ?></strong>
    </article>
</section>

<!-- Settings Configuration Form Card -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('settings') ?> Workflow & Operational Preferences</h2>
    
    <form method="post" style="margin-top: 14px;" data-offline-save data-offline-label="System settings">
        <?= csrf_field() ?>
        
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <?php foreach ($allowed as $key => $info): ?>
                <?php $isChecked = ($settings[$key] ?? '1') === '1'; ?>
                <div style="padding: 12px 16px; background: var(--bg-hover, #f8f9fa); border-radius: 8px; border: 1px solid var(--border-color, #e9ecef);">
                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; font-weight: 600; margin: 0;">
                        <input type="checkbox" name="<?= h($key) ?>" value="1" <?= $isChecked ? 'checked' : '' ?> style="margin-top: 3px; width: 18px; height: 18px;">
                        <div>
                            <span style="font-size: 15px;"><?= h($info['label']) ?></span>
                            <p style="font-weight: normal; font-size: 13px; color: var(--text-muted, #6c757d); margin: 3px 0 0 0; line-height: 1.4;">
                                <?= h($info['desc']) ?>
                            </p>
                        </div>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top: 20px; display: flex; align-items: center; justify-content: space-between;">
            <button class="btn btn-gold" type="submit">Save Settings Changes</button>
            <span class="hint" style="margin: 0;">Academic years and DL/PL ranges are managed under Academics & DL/PL Criteria.</span>
        </div>
    </form>
</section>

<div class="alert alert-info" style="margin-top: 16px;">
    <strong>Governance Note:</strong> Configuration adjustments take effect immediately across all administrative portal modules.
</div>

<?php layout_end(); ?>