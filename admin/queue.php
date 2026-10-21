<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'queue');

$scope = $_GET['scope'] ?? 'mine';
$program = (int) ($_GET['program'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

$isOicOrOsa = in_array('college_oic', $user['roles'], true) || in_array('osa', $user['roles'], true);

$waiting = [];
if ($isOicOrOsa || $scope === 'all' || in_array('system_admin', $user['roles'], true)) {
    // Oversight mode for OIC/OSA: view all active/processed grade submissions
    $waiting = ['submitted', 'returned', 'chair_verified', 'oic_approved', 'osa_endorsed', 'registrar_finalized'];
} else {
    foreach ([
        'program_chair' => 'submitted',
        'college_oic' => 'chair_verified',
        'osa' => 'oic_approved',
        'registrar' => 'osa_endorsed',
    ] as $role => $status) {
        if (in_array($role, $user['roles'], true)) {
            $waiting[] = $status;
        }
    }
}

$programs = db()->query('SELECT id, code FROM programs ORDER BY code')->fetchAll();

$sql = 'SELECT gs.id, gs.status, gs.submitted_at, s.code AS subject_code, s.name AS subject_name,
               sec.name AS section_name, p.code AS program_code, p.id AS program_id,
               u.full_name, u.title, COUNT(e.id) AS student_count
        FROM grade_submissions gs
        JOIN professor_assignments pa ON pa.id = gs.assignment_id
        JOIN subjects s ON s.id = pa.subject_id
        JOIN programs p ON p.id = s.program_id
        JOIN sections sec ON sec.id = pa.section_id
        JOIN professors pr ON pr.id = pa.professor_id
        JOIN users u ON u.id = pr.user_id
        LEFT JOIN enrollments e ON e.assignment_id = pa.id
        WHERE 1 = 1';
$params = [];

if ($waiting === []) {
    $sql .= ' AND 1 = 0';
} else {
    $sql .= ' AND gs.status IN (' . implode(',', array_fill(0, count($waiting), '?')) . ')';
    $params = $waiting;
}

$scopeIds = scoped_program_ids($user);
if (is_array($scopeIds)) {
    if ($scopeIds === []) {
        $sql .= ' AND 1 = 0';
    } else {
        $sql .= ' AND s.program_id IN (' . implode(',', array_map('intval', $scopeIds)) . ')';
    }
}

if ($program > 0) {
    $sql .= ' AND s.program_id = ?';
    $params[] = $program;
}

if ($search !== '') {
    $sql .= ' AND (s.code LIKE ? OR s.name LIKE ? OR sec.name LIKE ? OR u.full_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sql .= ' GROUP BY gs.id, gs.status, gs.submitted_at, s.code, s.name, sec.name, p.code, p.id, u.full_name, u.title
          ORDER BY gs.submitted_at IS NULL, gs.submitted_at DESC, gs.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

layout_start('Review queue', 'queue', [['label' => 'Review queue']]);
?>
<div class="page-head">
    <div>
        <h1>Review queue</h1>
        <p class="lede">
            <?= $isOicOrOsa ? 'Read-only monitoring dashboard for institutional class grade submissions.' : 'Each class grade sheet can move only one step at a time through official review.' ?>
        </p>
    </div>
    <?php if (in_array('system_admin', $user['roles'], true)): ?>
        <a class="btn btn-ghost" href="<?= h(url('admin/queue.php', ['scope' => $scope === 'all' ? 'mine' : 'all'])) ?>"><?= $scope === 'all' ? 'Waiting for me' : 'Show more statuses' ?></a>
    <?php endif; ?>
</div>

<!-- Queue Summary KPIs -->
<section class="kpis">
    <article class="kpi">
        <span>Submissions In Queue</span>
        <strong><?= count($rows) ?></strong>
    </article>
    <article class="kpi">
        <span>Scope Mode</span>
        <strong style="font-size:18px;">
            <?= $isOicOrOsa ? 'Monitoring Scope (Read-Only)' : ($scope === 'all' ? 'All System Statuses' : 'Waiting For My Office') ?>
        </strong>
    </article>
</section>

<!-- Filter Form with Program & Search Bar -->
<form class="filters" method="get">
    <input type="hidden" name="scope" value="<?= h((string) $scope) ?>">
    <div>
        <label for="program">Program</label>
        <select id="program" name="program">
            <option value="0">All in my scope</option>
            <?php foreach ($programs as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $program === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="q">Search</label>
        <input id="q" name="q" value="<?= h($search) ?>" placeholder="Subject, section, or professor">
    </div>
    <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter Queue</button>
</form>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="empty">No submissions are currently recorded in your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Class / Subject</th><th>Program</th><th>Professor</th><th>Students</th><th>Status</th><th>Submitted</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= h($row['subject_code']) ?></strong> · <?= h($row['section_name']) ?>
                            <br><small class="hint"><?= h($row['subject_name']) ?></small>
                        </td>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h(trim(($row['title'] ?? '') . ' ' . $row['full_name'])) ?></td>
                        <td><?= (int) $row['student_count'] ?></td>
                        <td><?= badge(submission_label($row['status']), $row['status']) ?></td>
                        <td><?= fmt_when($row['submitted_at']) ?></td>
                        <td>
                            <a class="btn btn-sm <?= $isOicOrOsa ? 'btn-ghost' : '' ?>" href="<?= h(url('admin/review.php', ['id' => $row['id']])) ?>">
                                <?= icon('open') ?> <?= $isOicOrOsa ? 'View Sheet' : 'Review' ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>