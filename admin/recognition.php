<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recognition.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'recognition');
$actor = recognition_actor($user);
if ($actor === '') {
    http_response_code(403);
    exit('Your role cannot open this page.');
}

// College OIC, OSA, and Registrar accounts are strictly monitoring oversight
$isMonitoringRole = in_array($actor, ['college_oic', 'osa', 'registrar'], true);

$semesterId = (int) ($_GET['semester'] ?? $_POST['semester_id'] ?? 0);
$semesters = db()->query('SELECT sem.id, sem.name, ay.label, sem.is_current FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id ORDER BY ay.label DESC')->fetchAll();
if ($semesterId <= 0) {
    foreach ($semesters as $semester) {
        if ((int) $semester['is_current'] === 1) {
            $semesterId = (int) $semester['id'];
        }
    }
    if ($semesterId <= 0 && $semesters) {
        $semesterId = (int) $semesters[0]['id'];
    }
}

$search = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));
$stage = (string) ($_POST['stage'] ?? $_GET['stage'] ?? ($isMonitoringRole ? '' : 'review'));
$allowedStages = ['', 'review', 'endorsed', 'approved', 'returned', 'rejected', 'deferred', 'verified', 'history'];
if (!in_array($stage, $allowedStages, true)) {
    $stage = '';
}
$sort = (($_POST['sort'] ?? $_GET['sort'] ?? '') === 'gwa') ? 'gwa' : 'name';
$dir = (($_POST['dir'] ?? $_GET['dir'] ?? '') === 'desc') ? 'desc' : 'asc';

if (($_GET['export'] ?? '') === 'csv') {
    if ($actor !== 'registrar' && !in_array('system_admin', $user['roles'], true)) {
        http_response_code(403);
        exit('Only authorized administrative personnel can export the Official Masterlist.');
    }
    $official = recognition_official($semesterId);
    audit((int) $user['id'], 'recognition_export', 'semester', (string) $semesterId, 'Official Masterlist CSV');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="official-masterlist.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student number', 'Name', 'Program', 'Year', 'List', 'GWA', 'Endorsed', 'Committee approval', 'OSA', 'Registrar verified']);
    foreach ($official as $row) {
        fputcsv($out, [
            $row['student_number'],
            $row['full_name'],
            $row['program_code'],
            $row['year_level'],
            recognition_list_label((string) $row['list_type']),
            $row['gwa'],
            $row['endorsed_at'],
            $row['committee_at'],
            $row['osa_at'],
            $row['verified_at'],
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($isMonitoringRole) {
        flash('error', 'OIC, OSA, and Registrar accounts have monitoring-only oversight access.');
        redirect('admin/recognition.php', ['semester' => $semesterId, 'q' => $search, 'stage' => $stage]);
    }
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if ($action === 'endorse') {
            $result = recognition_endorse($user, $semesterId, (array) ($_POST['ids'] ?? []), $note);
            $message = 'Endorsed ' . $result['endorsed'] . ' student' . ($result['endorsed'] === 1 ? '' : 's') . ' for institutional recognition.';
            if ($result['skipped'] !== []) {
                $message .= ' Skipped: ' . implode(' ', $result['skipped']);
            }
            flash('ok', $message);
        } else {
            $moved = recognition_act($user, $semesterId, (array) ($_POST['ids'] ?? []), $action, $note);
            $done = [
                'chair_return' => 'Returned ',
                'chair_reject' => 'Rejected ',
                'oic_approve' => 'OIC monitoring recorded for ',
                'oic_return' => 'Returned to the Program Chair ',
                'oic_reject' => 'OIC rejection recorded for ',
                'osa_approve' => 'Committee monitoring recorded for ',
                'osa_reject' => 'Committee rejection recorded for ',
                'osa_defer' => 'Committee deferral recorded for ',
                'registrar_verify' => 'Registrar archive verification recorded for ',
                'registrar_return' => 'Returned to Program Chair ',
            ];
            flash('ok', ($done[$action] ?? 'Updated ') . $moved . ' student' . ($moved === 1 ? '' : 's') . '.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/recognition.php', ['semester' => $semesterId, 'q' => $search, 'stage' => $stage, 'sort' => $sort, 'dir' => $dir]);
}

$scope = scoped_program_ids($user);
$refreshSql = 'SELECT id FROM students WHERE 1 = 1';
if (is_array($scope)) {
    $refreshSql .= $scope === [] ? ' AND 1 = 0' : ' AND program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
}

// Auto-sync for candidate records
foreach (db()->query($refreshSql)->fetchAll() as $student) {
    refresh_student_evaluation((int) $student['id'], $semesterId, (int) $user['id']);
    recognition_sync_candidate((int) $student['id'], $semesterId);
}

$rows = recognition_rows($user, $semesterId, $search, $stage, $sort, $dir);
$caseIds = [];
foreach ($rows as $row) {
    if (!empty($row['case_id'])) {
        $caseIds[] = (int) $row['case_id'];
    }
}
$history = recognition_history($caseIds);

$headings = [
    'program_chair' => 'Program Chair Recognition Queue',
    'college_oic' => display_name($user) . ' · OIC Monitoring Dashboard',
    'osa' => 'Office of Student Affairs (OSA) Monitoring Dashboard',
    'registrar' => 'Institutional Recognition Masterlist',
    'viewer' => 'Recognition Workflow Records',
];
$ledes = [
    'program_chair' => 'Review and approve system-qualified candidates in your assigned program.',
    'college_oic' => 'Read-only monitoring dashboard for Dean’s List and President’s List candidate records.',
    'osa' => 'Read-only oversight portal for Dean’s List and President’s List candidate records.',
    'registrar' => 'Read-only masterlist archive for officially endorsed and recognized candidates.',
    'viewer' => 'Each status change stays with the office that owns that step.',
];

$nextSort = ['semester' => $semesterId, 'q' => $search, 'stage' => $stage];

// Calculate summary stats
$totalVisible = count($rows);
$reviewCount = 0;
$verifiedCount = 0;
foreach ($rows as $r) {
    if (!empty($r['case_id'])) {
        if ($r['stage'] === 'registrar_verified' || $r['stage'] === 'final' || $r['stage'] === 'chair_endorsed') $verifiedCount++;
        else $reviewCount++;
    } else {
        $reviewCount++;
    }
}

layout_start('Recognition', 'recognition', [['label' => 'Recognition']]);
?>
<div class="page-head">
    <div>
        <h1><?= h($headings[$actor] ?? 'Recognition') ?></h1>
        <p class="lede"><?= h($ledes[$actor] ?? '') ?></p>
    </div>
    <?php if ($actor === 'registrar' || in_array('system_admin', $user['roles'], true)): ?>
        <div class="recog-bar">
            <a class="btn btn-sm" href="<?= h(url('admin/recognition.php', ['semester' => $semesterId, 'export' => 'csv'])) ?>"><?= icon('download') ?> Export masterlist</a>
            <a class="btn btn-sm" href="<?= h(url('admin/recognition_doc.php', ['semester' => $semesterId, 'doc' => 'masterlist'])) ?>" target="_blank"><?= icon('print') ?> Print masterlist</a>
            <a class="btn btn-sm" href="<?= h(url('admin/recognition_doc.php', ['semester' => $semesterId, 'doc' => 'certificates'])) ?>" target="_blank"><?= icon('print') ?> Print certificates</a>
        </div>
    <?php endif; ?>
</div>

<!-- Recognition Metric Summary -->
<section class="kpis">
    <article class="kpi">
        <span>Total Candidates Listed</span>
        <strong><?= $totalVisible ?></strong>
    </article>
    <article class="kpi">
        <span>In Workflow / Pending</span>
        <strong><?= $reviewCount ?></strong>
    </article>
    <article class="kpi">
        <span>Verified Official Records</span>
        <strong><?= $verifiedCount ?></strong>
    </article>
</section>

<form class="filters" method="get">
    <div>
        <label for="semester">Semester</label>
        <select id="semester" name="semester">
            <?php foreach ($semesters as $semester): ?>
                <option value="<?= (int) $semester['id'] ?>" <?= $semesterId === (int) $semester['id'] ? 'selected' : '' ?>><?= h($semester['label'] . ' ' . $semester['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="q">Search Candidate</label>
        <input id="q" name="q" value="<?= h($search) ?>" placeholder="Name or student number">
    </div>
    <div>
        <label for="stage">Status Filter</label>
        <select id="stage" name="stage">
            <option value="">All visible</option>
            <?php
            $tabs = match ($actor) {
                'program_chair' => ['review' => 'For Review', 'endorsed' => 'Endorsed', 'returned' => 'Returned', 'rejected' => 'Rejected'],
                'college_oic', 'osa', 'registrar' => ['' => 'All Endorsed Candidates', 'review' => 'In Workflow', 'verified' => 'Official Verified'],
                default => ['history' => 'All workflow records'],
            };
            ?>
            <?php foreach ($tabs as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= $stage === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <input type="hidden" name="sort" value="<?= h($sort) ?>">
    <input type="hidden" name="dir" value="<?= h($dir) ?>">
    <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter Records</button>
</form>

<nav class="period-switch" aria-label="Recognition status">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="<?= $stage === $key ? 'is-active' : '' ?>" href="<?= h(url('admin/recognition.php', ['semester' => $semesterId, 'q' => $search, 'stage' => $key, 'sort' => $sort, 'dir' => $dir])) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><a href="<?= h(url('admin/recognition.php', $nextSort + ['sort' => 'name', 'dir' => $sort === 'name' && $dir === 'asc' ? 'desc' : 'asc'])) ?>">Student Details</a></th>
                    <th>Program</th>
                    <th><a href="<?= h(url('admin/recognition.php', $nextSort + ['sort' => 'gwa', 'dir' => $sort === 'gwa' && $dir === 'asc' ? 'desc' : 'asc'])) ?>">Standing / GWA</a></th>
                    <th>Honor List</th>
                    <th>Workflow Stage</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php $columns = 6; ?>
            <?php if ($rows === []): ?>
                <tr><td class="empty" colspan="<?= $columns ?>">No candidate records match this status view.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $caseId = (int) ($row['case_id'] ?? 0);
                $standing = (string) ($row['standing'] ?? '');
                $list = $caseId ? (string) $row['list_type'] : (recognition_list_for_standing($standing) ?? '');
                ?>
                <tr>
                    <td>
                        <strong><?= h($row['full_name']) ?></strong>
                        <br><span class="mono hint"><?= h($row['student_number']) ?></span> 
                        <?= badge((string) $row['student_type'], (string) $row['student_type']) ?>
                    </td>
                    <td><?= h($row['program_code']) ?><br><span class="hint">Year <?= h((string) $row['year_level']) ?></span></td>
                    <td>
                        <?= $standing !== '' ? badge($standing, $standing) : '—' ?>
                        <div class="hint" style="margin-top:2px;">
                            <strong><?= h(fmt_eqv(isset($row['gwa']) ? (float) $row['gwa'] : null)) ?></strong>
                            <?= !empty($row['reason']) ? ' · ' . h((string) $row['reason']) : '' ?>
                        </div>
                    </td>
                    <td><?= $list !== '' ? h(recognition_list_label($list)) : '—' ?></td>
                    <td>
                        <?php if ($caseId): ?>
                            <?= badge(recognition_stage_label((string) $row['stage']), (string) $row['stage']) ?>
                            <details class="history" style="margin-top:4px;">
                                <summary>History Timeline</summary>
                                <ul>
                                    <?php foreach ($history[$caseId] ?? [] as $event): ?>
                                        <li>
                                            <?= h(recognition_when((string) $event['created_at'])) ?>
                                            · <?= h(!empty($event['full_name']) ? trim((string)($event['title'] ?? '') . ' ' . (string)$event['full_name']) : 'System') ?>
                                            · <?= h(recognition_stage_label((string) $event['to_stage'])) ?>
                                            <?php if (!empty($event['note'])): ?><br><em><?= h((string) $event['note']) ?></em><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php else: ?>
                            <?= badge('Pending Chair Verification', 'draft') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn btn-sm btn-ghost" href="<?= h(url('admin/recognition_review.php', ['id' => $caseId])) ?>">
                            <?= icon('open') ?> <?= $isMonitoringRole ? 'View Record' : 'Review Record' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php layout_end(); ?>