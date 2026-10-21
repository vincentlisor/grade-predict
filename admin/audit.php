<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'audit');

$q = trim((string) ($_GET['q'] ?? ''));
$page = (int) ($_GET['page'] ?? 1);
$where = '';
$params = [];

if ($q !== '') {
    $where = 'WHERE a.action LIKE ? OR a.entity LIKE ? OR a.details LIKE ? OR u.username LIKE ?';
    $params = array_fill(0, 4, '%' . $q . '%');
}

$count = db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $where");
$count->execute($params);
$totalLogs = (int) $count->fetchColumn();

$pager = paginate($totalLogs, $page, 20);

$rows = db()->prepare(
    "SELECT a.*, u.username, u.full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     $where
     ORDER BY a.id DESC
     LIMIT {$pager['per']} OFFSET {$pager['offset']}"
);
$rows->execute($params);
$rows = $rows->fetchAll();

// KPI Metrics Calculations
$systemLogsCount = (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE user_id IS NULL OR action LIKE '%system%'")->fetchColumn();

layout_start('Audit log', 'audit', [['label' => 'Audit log']]);
?>
<div class="page-head">
    <div>
        <h1>System Audit Log</h1>
        <p class="lede">Track institutional activities, user authentication, grade imports, and automated system actions.</p>
    </div>
</div>

<!-- KPI Metrics Summary -->
<section class="kpis">
    <article class="kpi">
        <span>Total Logged Events</span>
        <strong><?= $totalLogs ?></strong>
    </article>
    <article class="kpi">
        <span>System Automated Events</span>
        <strong><?= $systemLogsCount ?></strong>
    </article>
    <article class="kpi">
        <span>Active Search Scope</span>
        <strong><?= $q !== '' ? h($q) : 'All System Activity' ?></strong>
    </article>
</section>

<!-- Search Filter Bar -->
<section class="card" style="margin-bottom: 18px;">
    <form class="filters" method="get" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 280px;">
            <label for="q">Search Audit Trail</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="Action, user, record, or details..." style="width: 100%;">
        </div>
        <div>
            <button class="btn" type="submit"><?= icon('search') ?> Search</button>
        </div>
    </form>
</section>

<!-- Audit Logs Table -->
<section class="card">
    <h2><?= icon('audit') ?> Audit Trail Log Records</h2>
    <?php if ($rows === []): ?>
        <p class="empty">No activity records found matching your search parameter.</p>
    <?php else: ?>
        <div class="table-wrap" style="margin-top: 12px;">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User Account</th>
                        <th>Action</th>
                        <th>Record Entity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td style="white-space: nowrap;"><?= fmt_when($row['created_at']) ?></td>
                        <td>
                            <strong><?= h($row['full_name'] ?: 'System') ?></strong>
                            <?php if (!empty($row['username'])): ?>
                                <br><span class="mono" style="font-size: 12px; color: var(--text-muted, #666);"><?= h($row['username']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            $act = (string) $row['action'];
                            if (in_array($act, ['login', 'logout', 'import', 'scholarship_assign', 'scholarship_revoke'], true)): ?>
                                <?= badge($act, $act) ?>
                            <?php else: ?>
                                <span class="badge" style="background: #f0f4f8; color: #102a43; font-weight: 600;"><?= h($act) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="mono"><?= h($row['entity']) ?> <?= h((string) $row['entity_id']) ?></span></td>
                        <td><?= h($row['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 16px;">
            <?= pager_html($pager, 'admin/audit.php', ['q' => $q]) ?>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>