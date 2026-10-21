<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recognition.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
$scope = scoped_program_ids($user);
$programSql = '';
$params = [];

if (is_array($scope)) {
    if ($scope === []) {
        $programSql = ' AND 1 = 0';
    } else {
        $programSql = ' AND s.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}

$students = (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn();
if (is_array($scope) && $scope !== []) {
    $students = (int) db()->query('SELECT COUNT(*) FROM students WHERE program_id IN (' . implode(',', array_map('intval', $scope)) . ')')->fetchColumn();
}

$waiting = [
    'program_chair' => 'submitted',
    'college_oic' => 'chair_verified',
    'osa' => 'oic_approved',
    'registrar' => 'osa_endorsed',
];

$myStatuses = [];
foreach ($waiting as $role => $status) {
    if (in_array($role, $user['roles'], true)) {
        $myStatuses[] = $status;
    }
}

$queueCount = 0;
if ($myStatuses !== []) {
    $in = implode(',', array_fill(0, count($myStatuses), '?'));
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM grade_submissions gs
         JOIN professor_assignments pa ON pa.id = gs.assignment_id
         JOIN subjects s ON s.id = pa.subject_id
         WHERE gs.status IN ($in) $programSql"
    );
    $stmt->execute($myStatuses);
    $queueCount = (int) $stmt->fetchColumn();
}

$finalizedSql = "SELECT COUNT(*) FROM grade_submissions gs
    JOIN professor_assignments pa ON pa.id = gs.assignment_id
    JOIN subjects s ON s.id = pa.subject_id
    WHERE gs.status = 'registrar_finalized' $programSql";
$finalizedStmt = db()->prepare($finalizedSql);
$finalizedStmt->execute();
$finalized = (int) $finalizedStmt->fetchColumn();

$possibleSql = "SELECT COUNT(*) FROM dl_pl_evaluations e
    JOIN students st ON st.id = e.student_id
    WHERE e.basis = 'predicted' AND e.status IN ('Possible DL','Possible PL')";
if (is_array($scope)) {
    $possibleSql .= $scope === [] ? ' AND 1 = 0' : ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
}
$possible = (int) db()->query($possibleSql)->fetchColumn();

$recognitionCount = 0;
$recognitionActor = recognition_actor($user);
$currentSemester = (int) db()->query('SELECT id FROM semesters WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();

if ($currentSemester > 0 && $recognitionActor === 'program_chair' && is_array($scope) && $scope !== []) {
    $in = implode(',', array_fill(0, count($scope), '?'));
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM recognition_cases rc
         WHERE rc.semester_id = ? AND rc.stage IN ('chair_review','chair_returned','oic_returned')
           AND rc.program_id IN ($in)"
    );
    $stmt->execute(array_merge([$currentSemester], $scope));
    $recognitionCount = (int) $stmt->fetchColumn();
} elseif ($currentSemester > 0 && in_array($recognitionActor, ['college_oic', 'osa', 'registrar'], true)) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM recognition_cases WHERE semester_id = ?');
    $stmt->execute([$currentSemester]);
    $recognitionCount = (int) $stmt->fetchColumn();
}

$isAdmin = in_array('system_admin', $user['roles'], true);
$adminStats = [];
$stageStats = [];
$recent = [];

if ($isAdmin) {
    foreach (['students' => 'SELECT COUNT(*) FROM students', 'professors' => 'SELECT COUNT(*) FROM professors'] as $key => $sql) {
        $adminStats[$key] = (int) db()->query($sql)->fetchColumn();
    }
    foreach (['program_chair', 'college_oic', 'osa', 'registrar'] as $role) {
        $stmt = db()->prepare('SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = ?');
        $stmt->execute([$role]);
        $adminStats[$role] = (int) $stmt->fetchColumn();
    }
    if ($currentSemester > 0) {
        $stmt = db()->prepare('SELECT stage, COUNT(*) AS total FROM recognition_cases WHERE semester_id = ? GROUP BY stage');
        $stmt->execute([$currentSemester]);
        foreach ($stmt as $row) $stageStats[$row['stage']] = (int) $row['total'];
        $stmt = db()->prepare('SELECT list_type, COUNT(*) AS total FROM recognition_cases WHERE semester_id = ? GROUP BY list_type');
        $stmt->execute([$currentSemester]);
        foreach ($stmt as $row) $adminStats[strtolower($row['list_type'])] = (int) $row['total'];
    }
    $recent = db()->query(
        'SELECT a.action, a.entity, a.details, a.created_at, u.full_name
         FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.id DESC LIMIT 10'
    )->fetchAll();
}

layout_start('Dashboard', 'dash', [['label' => 'Dashboard']]);
?>
<?php if ($isAdmin): ?>
    <div class="page-head">
        <div>
            <h1>System administration</h1>
            <p class="lede">Manage records and monitor every workflow step. Academic approval actions remain with the assigned personnel.</p>
        </div>
    </div>

    <!-- Grouped System Overview Metrics -->
    <section class="card">
        <h2><?= icon('reports') ?> Academic & Personnel Overview</h2>
        <div class="kpis">
            <article class="kpi"><span>Total Students</span><strong><?= $adminStats['students'] ?></strong></article>
            <article class="kpi"><span>Faculty / Professors</span><strong><?= $adminStats['professors'] ?></strong></article>
            <article class="kpi"><span>DL Candidates</span><strong><?= $adminStats['dl'] ?? 0 ?></strong></article>
            <article class="kpi"><span>PL Candidates</span><strong><?= $adminStats['pl'] ?? 0 ?></strong></article>
        </div>
        <div class="kpis" style="margin-top: 12px;">
            <article class="kpi"><span>Program Chairs</span><strong><?= $adminStats['program_chair'] ?></strong></article>
            <article class="kpi"><span>College OIC</span><strong><?= $adminStats['college_oic'] ?></strong></article>
            <article class="kpi"><span>OSA Personnel</span><strong><?= $adminStats['osa'] ?></strong></article>
            <article class="kpi"><span>Registrars</span><strong><?= $adminStats['registrar'] ?></strong></article>
        </div>
    </section>

    <!-- Clean Workflow Pipeline Monitoring -->
    <section class="card">
        <div class="card-head">
            <h2><?= icon('queue') ?> Recognition Workflow Monitoring</h2>
            <a class="btn btn-sm" href="<?= h(url('admin/recognition.php')) ?>">Open all records</a>
        </div>
        <div class="kpis">
            <article class="kpi"><span>Chair Review</span><strong><?= $stageStats['chair_review'] ?? 0 ?></strong></article>
            <article class="kpi"><span>OIC Oversight</span><strong><?= $stageStats['chair_endorsed'] ?? 0 ?></strong></article>
            <article class="kpi"><span>Committee Oversight</span><strong><?= ($stageStats['oic_approved'] ?? 0) + ($stageStats['registrar_returned'] ?? 0) ?></strong></article>
            <article class="kpi"><span>Registrar Oversight</span><strong><?= $stageStats['osa_approved'] ?? 0 ?></strong></article>
            <article class="kpi"><span>Finalized Records</span><strong><?= $stageStats['registrar_verified'] ?? 0 ?></strong></article>
        </div>
    </section>

    <!-- Recent Audit Logs -->
    <section class="card">
        <div class="card-head">
            <h2><?= icon('audit') ?> Recent System Activities</h2>
            <a class="btn btn-sm btn-ghost" href="<?= h(url('admin/audit.php')) ?>">View Full Audit Log</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>When</th><th>Personnel</th><th>Action</th><th>Record</th><th>Details</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $item): ?>
                    <tr>
                        <td><?= fmt_when($item['created_at']) ?></td>
                        <td><?= h($item['full_name'] ?: 'System') ?></td>
                        <td><?= h($item['action']) ?></td>
                        <td><?= h($item['entity']) ?></td>
                        <td><?= h((string) $item['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <?php
    $roleCopy = [
        'program_chair' => ['Program Chair dashboard', 'Review and approve system-qualified students in your assigned program.', 'Candidates for approval'],
        'college_oic' => [display_name($user) . ' · OIC dashboard', 'Oversight and monitoring dashboard for records endorsed by Program Chairs.', 'Oversight records'],
        'osa' => ['OSA / Committee dashboard', 'Oversight and monitoring dashboard for DL / PL recognition cases.', 'Oversight records'],
        'registrar' => ['Registrar dashboard', 'Monitor institutional recognition and endorsed candidate masterlists.', 'Monitored records'],
    ][$recognitionActor] ?? [$user['role_label'], 'Complete the records assigned to your office.', 'Waiting for you'];
    ?>
    <div class="page-head">
        <div><h1><?= h($roleCopy[0]) ?></h1><p class="lede"><?= h($roleCopy[1]) ?></p></div>
    </div>
    <section class="kpis">
        <article class="kpi"><span><?= h($roleCopy[2]) ?></span><strong><?= $recognitionCount ?></strong></article>
        <article class="kpi"><span>Grade sheets waiting</span><strong><?= $queueCount ?></strong></article>
        <article class="kpi"><span>Students in scope</span><strong><?= $students ?></strong></article>
        <article class="kpi"><span>Finalized classes</span><strong><?= $finalized ?></strong></article>
    </section>
    <section class="split">
        <article class="card">
            <div class="card-head">
                <h2><?= icon('award') ?> DL / PL Work</h2>
                <a class="btn btn-sm" href="<?= h(url('admin/recognition.php')) ?>">
                    <?= in_array($recognitionActor, ['college_oic', 'osa', 'registrar'], true) ? 'View monitoring queue' : 'Open my queue' ?>
                </a>
            </div>
            <p><?= h($roleCopy[1]) ?> Program Chairs hold final approval authority for recognition candidates.</p>
        </article>
        <article class="card">
            <div class="card-head"><h2><?= icon('queue') ?> Grade review</h2><a class="btn btn-sm btn-ghost" href="<?= h(url('admin/queue.php')) ?>">Review grade sheets</a></div>
            <p>Grade submissions use a separate academic-record verification chain before recognition begins.</p>
        </article>
    </section>
<?php endif; ?>
<?php layout_end(); ?>