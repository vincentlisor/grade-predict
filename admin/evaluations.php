<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/recognition.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'evaluations');

$semesterId = (int) ($_GET['semester'] ?? $_POST['semester_id'] ?? 0);
$semesters = db()->query('SELECT sem.id, sem.name, ay.label, sem.is_current FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id ORDER BY ay.label DESC')->fetchAll();
if ($semesterId <= 0) {
    foreach ($semesters as $semester) {
        if ((int) $semester['is_current'] === 1) $semesterId = (int) $semester['id'];
    }
    if ($semesterId <= 0 && $semesters) $semesterId = (int) $semesters[0]['id'];
}

$programFilter = trim((string) ($_GET['program'] ?? ''));
$yearFilter = (int) ($_GET['year_level'] ?? 0);
$sectionFilter = trim((string) ($_GET['section'] ?? ''));
$standingFilter = trim((string) ($_GET['standing'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));

$scope = scoped_program_ids($user);

// Fetch available programs for dropdown
$programsSql = 'SELECT id, code, name FROM programs';
if (is_array($scope)) {
    if ($scope === []) {
        $programsSql .= ' WHERE 1=0';
    } else {
        $programsSql .= ' WHERE id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}
$programsSql .= ' ORDER BY code';
$availablePrograms = db()->query($programsSql)->fetchAll();

// Dynamic Section Filter Query: Fetch ONLY sections related to selected program & year level
$sectionWhere = ['pa.semester_id = ?'];
$sectionParams = [$semesterId];

if ($programFilter !== '') {
    $sectionWhere[] = 'p.code = ?';
    $sectionParams[] = $programFilter;
}

if ($yearFilter > 0) {
    $sectionWhere[] = '(st.year_level = ? OR st.year_level LIKE ?)';
    $sectionParams[] = $yearFilter;
    $sectionParams[] = $yearFilter . '%';
}

$sectionsSql = 'SELECT DISTINCT sec.name 
                FROM sections sec 
                JOIN professor_assignments pa ON pa.section_id = sec.id 
                JOIN enrollments e ON e.assignment_id = pa.id
                JOIN students st ON st.id = e.student_id
                JOIN programs p ON p.id = st.program_id
                WHERE ' . implode(' AND ', $sectionWhere) . ' 
                ORDER BY sec.name';

$sectionsStmt = db()->prepare($sectionsSql);
$sectionsStmt->execute($sectionParams);
$availableSections = $sectionsStmt->fetchAll(PDO::FETCH_COLUMN);

if ($sectionFilter !== '' && !in_array($sectionFilter, $availableSections, true)) {
    $sectionFilter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $evalId = (int) ($_POST['evaluation_id'] ?? 0);
    $eval = db()->prepare('SELECT e.*, st.program_id FROM dl_pl_evaluations e JOIN students st ON st.id = e.student_id WHERE e.id = ? AND e.basis = "official"');
    $eval->execute([$evalId]);
    $eval = $eval->fetch();
    if (!$eval || (is_array($scope) && !in_array((int) $eval['program_id'], $scope, true))) {
        flash('error', 'That evaluation is outside your scope.');
    } elseif (($_POST['decision'] ?? '') === 'note') {
        db()->prepare('UPDATE dl_pl_evaluations SET review_note = ?, reviewer_id = ? WHERE id = ?')
            ->execute([trim((string) ($_POST['review_note'] ?? '')), (int) $user['id'], $evalId]);
        flash('ok', 'Evaluation note saved.');
    } else {
        flash('error', 'That decision is not available for this record.');
    }
    redirect('admin/evaluations.php', [
        'semester' => $semesterId,
        'program' => $programFilter,
        'year_level' => $yearFilter,
        'section' => $sectionFilter,
        'standing' => $standingFilter,
        'q' => $search
    ]);
}

$where = ['1 = 1'];
$params = [];

if (is_array($scope)) {
    if ($scope === []) {
        $where[] = '1 = 0';
    } else {
        $where[] = 'st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}

if ($programFilter !== '') {
    $where[] = 'p.code = ?';
    $params[] = $programFilter;
}

if ($yearFilter > 0) {
    $where[] = '(st.year_level = ? OR st.year_level LIKE ?)';
    $params[] = $yearFilter;
    $params[] = $yearFilter . '%';
}

if ($sectionFilter !== '') {
    $where[] = 'sec.name = ?';
    $params[] = $sectionFilter;
}

if ($search !== '') {
    $where[] = '(st.full_name LIKE ? OR st.student_number LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sql = 'SELECT DISTINCT st.id, st.student_number, st.full_name, st.student_type, st.year_level, p.code AS program_code, sec.name AS section_name
        FROM students st 
        JOIN programs p ON p.id = st.program_id
        LEFT JOIN enrollments e ON e.student_id = st.id
        LEFT JOIN professor_assignments pa ON pa.id = e.assignment_id AND pa.semester_id = ?
        LEFT JOIN sections sec ON sec.id = pa.section_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.code ASC, st.year_level ASC, sec.name ASC, st.full_name ASC';

array_unshift($params, $semesterId);

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rawStudents = $stmt->fetchAll();

foreach ($rawStudents as $student) {
    refresh_student_evaluation((int) $student['id'], $semesterId, (int) $user['id']);
}

$evals = db()->prepare('SELECT * FROM dl_pl_evaluations WHERE semester_id = ?');
$evals->execute([$semesterId]);
$byStudent = [];

foreach ($evals as $row) {
    $byStudent[$row['student_id']][$row['basis']] = $row;
}

// Filter students to include ONLY DL / PL candidates (excluding Pending & Not Eligible)
$students = [];
$plCount = 0;
$dlCount = 0;

foreach ($rawStudents as $student) {
    $pred = $byStudent[$student['id']]['predicted'] ?? null;
    $off = $byStudent[$student['id']]['official'] ?? null;

    $predStatus = (string)($pred['status'] ?? '—');
    if ($pred && ($predStatus === 'Pending' || $predStatus === '' || $predStatus === '—')) {
        $predGwa = isset($pred['gwa']) ? (float)$pred['gwa'] : null;
        if ($predGwa !== null && $predGwa > 0) {
            if ($predGwa <= 1.25) $predStatus = 'Possible PL';
            elseif ($predGwa <= 1.75) $predStatus = 'Possible DL';
            else $predStatus = 'Not Eligible';
        }
    }

    $offStatus = (string)($off['status'] ?? '—');
    if ($off && ($offStatus === 'Pending' || $offStatus === '' || $offStatus === '—')) {
        $offGwa = isset($off['gwa']) ? (float)$off['gwa'] : null;
        if ($offGwa !== null && $offGwa > 0) {
            if ($offGwa <= 1.25) $offStatus = 'President\'s List';
            elseif ($offGwa <= 1.75) $offStatus = 'Dean\'s List';
            else $offStatus = 'Not Eligible';
        }
    }

    // Qualification Check: Only include if status is DL or PL in advisory or official
    $isQualifiedPL = in_array($predStatus, ['Possible PL', 'President\'s List'], true) || in_array($offStatus, ['Possible PL', 'President\'s List'], true);
    $isQualifiedDL = in_array($predStatus, ['Possible DL', 'Dean\'s List'], true) || in_array($offStatus, ['Possible DL', 'Dean\'s List'], true);

    if (!$isQualifiedPL && !$isQualifiedDL) {
        continue; // Skip non-DL/PL records
    }

    if ($standingFilter === 'pl' && !$isQualifiedPL) {
        continue;
    }
    if ($standingFilter === 'dl' && !$isQualifiedDL) {
        continue;
    }

    if ($isQualifiedPL) $plCount++;
    if ($isQualifiedDL) $dlCount++;

    $students[] = $student;
}

layout_start('DL / PL Evaluations', 'evaluations', [['label' => 'DL / PL']]);
?>
<div class="page-head">
    <div>
        <h1>Dean’s List and President’s List</h1>
        <p class="lede">GWA is calculated as the unweighted mean of subject equivalents. Official endorsements are handled under Recognition.</p>
    </div>
</div>

<!-- Evaluation Summary Counters -->
<section class="kpis">
    <article class="kpi">
        <span>Total Honor Candidates</span>
        <strong><?= count($students) ?></strong>
    </article>
    <article class="kpi">
        <span>President’s List Candidates</span>
        <strong><?= $plCount ?></strong>
    </article>
    <article class="kpi">
        <span>Dean’s List Candidates</span>
        <strong><?= $dlCount ?></strong>
    </article>
</section>

<!-- Auto-submitting Dynamic Filter Form -->
<form class="filters" method="get" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
    <div>
        <label for="semester">Semester</label>
        <select id="semester" name="semester" onchange="this.form.submit()">
            <?php foreach ($semesters as $semester): ?>
                <option value="<?= (int) $semester['id'] ?>" <?= $semesterId === (int) $semester['id'] ? 'selected' : '' ?>><?= h($semester['label'] . ' ' . $semester['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="program">Program</label>
        <select id="program" name="program" onchange="this.form.submit()">
            <option value="">All Programs</option>
            <?php foreach ($availablePrograms as $prog): ?>
                <option value="<?= h($prog['code']) ?>" <?= $programFilter === $prog['code'] ? 'selected' : '' ?>><?= h($prog['code'] . ' - ' . $prog['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="year_level">Year Level</label>
        <select id="year_level" name="year_level" onchange="this.form.submit()">
            <option value="0">All Years</option>
            <option value="1" <?= $yearFilter === 1 ? 'selected' : '' ?>>1st Year</option>
            <option value="2" <?= $yearFilter === 2 ? 'selected' : '' ?>>2nd Year</option>
            <option value="3" <?= $yearFilter === 3 ? 'selected' : '' ?>>3rd Year</option>
            <option value="4" <?= $yearFilter === 4 ? 'selected' : '' ?>>4th Year</option>
        </select>
    </div>

    <div>
        <label for="section">Section</label>
        <select id="section" name="section" onchange="this.form.submit()">
            <option value="">All Sections</option>
            <?php foreach ($availableSections as $secName): ?>
                <option value="<?= h($secName) ?>" <?= $sectionFilter === $secName ? 'selected' : '' ?>><?= h($secName) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="standing">Honor Category</label>
        <select id="standing" name="standing" onchange="this.form.submit()">
            <option value="all" <?= $standingFilter === 'all' ? 'selected' : '' ?>>All Honor Candidates (DL & PL)</option>
            <option value="pl" <?= $standingFilter === 'pl' ? 'selected' : '' ?>>President’s List Only</option>
            <option value="dl" <?= $standingFilter === 'dl' ? 'selected' : '' ?>>Dean’s List Only</option>
        </select>
    </div>

    <div>
        <label for="q">Search Student</label>
        <input id="q" name="q" value="<?= h($search) ?>" placeholder="Name or student number">
    </div>

    <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter Evaluations</button>
</form>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student Details</th>
                    <th>Program & Section</th>
                    <th>Advisory GWA</th>
                    <th>Advisory Standing</th>
                    <th>Official GWA</th>
                    <th>Official Standing</th>
                    <th>Remarks / Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($students === []): ?>
                <tr><td class="empty" colspan="7">No qualified DL/PL honor candidates found matching your criteria.</td></tr>
            <?php endif; ?>
            <?php foreach ($students as $student): ?>
                <?php 
                $pred = $byStudent[$student['id']]['predicted'] ?? null; 
                $off = $byStudent[$student['id']]['official'] ?? null; 

                $predStatus = (string)($pred['status'] ?? '—');
                if ($pred && ($predStatus === 'Pending' || $predStatus === '' || $predStatus === '—')) {
                    $predGwa = isset($pred['gwa']) ? (float)$pred['gwa'] : null;
                    if ($predGwa !== null && $predGwa > 0) {
                        if ($predGwa <= 1.25) $predStatus = 'Possible PL';
                        elseif ($predGwa <= 1.75) $predStatus = 'Possible DL';
                        else $predStatus = 'Not Eligible';
                    }
                }

                $offStatus = (string)($off['status'] ?? '—');
                if ($off && ($offStatus === 'Pending' || $offStatus === '' || $offStatus === '—')) {
                    $offGwa = isset($off['gwa']) ? (float)$off['gwa'] : null;
                    if ($offGwa !== null && $offGwa > 0) {
                        if ($offGwa <= 1.25) $offStatus = 'President\'s List';
                        elseif ($offGwa <= 1.75) $offStatus = 'Dean\'s List';
                        else $offStatus = 'Not Eligible';
                    }
                }
                ?>
                <tr>
                    <td>
                        <strong><?= h($student['full_name']) ?></strong>
                        <br><span class="mono hint"><?= h($student['student_number']) ?></span> 
                        <?= badge((string)$student['student_type'], (string)$student['student_type']) ?>
                    </td>
                    <td>
                        <strong><?= h($student['program_code']) ?></strong>
                        <br><span class="hint">Yr <?= h((string)$student['year_level']) ?><?= $student['section_name'] ? ' · Sec ' . h($student['section_name']) : '' ?></span>
                    </td>
                    <td><strong><?= fmt_eqv(isset($pred['gwa']) ? (float) $pred['gwa'] : null) ?></strong></td>
                    <td><?= $pred ? badge($predStatus, $predStatus) : '—' ?></td>
                    <td><strong><?= fmt_eqv(isset($off['gwa']) ? (float) $off['gwa'] : null) ?></strong></td>
                    <td><?= $off ? badge($offStatus, $offStatus) : '—' ?></td>
                    <td style="min-width: 220px;">
                        <?php if ($off): ?>
                            <form method="post" style="display:flex; gap:6px; align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="semester_id" value="<?= $semesterId ?>">
                                <input type="hidden" name="evaluation_id" value="<?= (int) $off['id'] ?>">
                                <input type="hidden" name="program" value="<?= h($programFilter) ?>">
                                <input type="hidden" name="year_level" value="<?= $yearFilter ?>">
                                <input type="hidden" name="section" value="<?= h($sectionFilter) ?>">
                                <input type="hidden" name="standing" value="<?= h($standingFilter) ?>">
                                <input type="hidden" name="q" value="<?= h($search) ?>">
                                <input name="review_note" value="<?= h((string) ($off['review_note'] ?? '')) ?>" placeholder="Add note..." style="padding:6px 10px; font-size:13px; flex:1;">
                                <button class="btn btn-ghost btn-sm" name="decision" value="note" type="submit" style="padding:6px 10px;">Save</button>
                            </form>
                        <?php else: ?>
                            <span class="hint">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php layout_end(); ?>