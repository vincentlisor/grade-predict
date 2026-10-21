<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'students_read');
ensure_student_adviser_column();

$programs = db()->query('SELECT id, code FROM programs ORDER BY code')->fetchAll();
$faculty = faculty_masterlist();
$sectionChoices = db()->query(
    'SELECT sec.id, sec.name, sec.year_level, sec.program_id, p.code AS program_code
     FROM sections sec
     JOIN programs p ON p.id = sec.program_id
     ORDER BY p.code, sec.year_level, sec.name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission($user, 'students_write');
    require_csrf();
    try {
        $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $programId = (int) ($_POST['program_id'] ?? 0);
        $year = trim((string) ($_POST['year_level'] ?? ''));
        $type = ($_POST['student_type'] ?? '') === 'Irregular' ? 'Irregular' : 'Regular';
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $professorId = (int) ($_POST['professor_id'] ?? 0);
        $scope = scoped_program_ids($user);

        if ($studentNumber === '' || $name === '' || $programId <= 0 || $year === '') {
            throw new RuntimeException('Complete the student number, name, program, and year level.');
        }
        if (is_array($scope) && !in_array($programId, $scope, true)) {
            throw new RuntimeException('That program is outside your access.');
        }
        if ($sectionId > 0) {
            $section = db()->prepare('SELECT program_id FROM sections WHERE id = ?');
            $section->execute([$sectionId]);
            $sectionProgram = (int) $section->fetchColumn();
            if ($sectionProgram !== $programId) {
                throw new RuntimeException('The section must belong to the selected program.');
            }
        }
        $professorName = '';
        if ($professorId > 0) {
            $professor = db()->prepare(
                'SELECT pr.id, u.full_name, u.title
                 FROM professors pr
                 JOIN users u ON u.id = pr.user_id
                 WHERE pr.id = ? AND u.is_active = 1'
            );
            $professor->execute([$professorId]);
            $professor = $professor->fetch();
            if (!$professor) {
                throw new RuntimeException('Choose an active professor from the faculty masterlist.');
            }
            $professorName = trim($professor['title'] . ' ' . $professor['full_name']);
        }

        db()->prepare(
            'INSERT INTO students (student_number, full_name, student_type, year_level, program_id, section_id, adviser_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $studentNumber,
            $name,
            $type,
            $year,
            $programId,
            $sectionId > 0 ? $sectionId : null,
            $professorId > 0 ? $professorId : null,
        ]);

        $studentId = (int) db()->lastInsertId();
        $enrolled = enroll_student_for_professor($studentId, $professorId, $sectionId);
        $detail = $studentNumber . ' ' . $name;
        if ($professorName !== '') {
            $detail .= ' · professor ' . $professorName;
        }
        audit((int) $user['id'], 'student_create', 'student', (string) $studentId, $detail);
        $message = 'Student record added successfully.';
        if ($professorName !== '') {
            $message .= ' Assigned professor: ' . $professorName . '.';
            if ($sectionId <= 0) {
                $message .= ' Choose a section so they can be placed on that professor\'s grade sheet.';
            } elseif ($enrolled > 0) {
                $message .= ' Placed on ' . $enrolled . ' class sheet' . ($enrolled === 1 ? '' : 's') . '.';
            } else {
                $message .= ' That professor has no class sheet for this section yet.';
            }
        }
        flash('ok', $message);
    } catch (Throwable $e) {
        flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'That student number already exists.' : $e->getMessage());
    }
    redirect('admin/students.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$program = (int) ($_GET['program'] ?? 0);
$year = trim((string) ($_GET['year'] ?? ''));
$section = (int) ($_GET['section'] ?? 0);
$professor = (int) ($_GET['professor'] ?? 0);
$assignment = ($_GET['assignment'] ?? '') === 'assigned' ? 'assigned' : ((($_GET['assignment'] ?? '') === 'unassigned') ? 'unassigned' : '');
$type = ($_GET['type'] ?? '') === 'Irregular' ? 'Irregular' : ((($_GET['type'] ?? '') === 'Regular') ? 'Regular' : '');
$page = (int) ($_GET['page'] ?? 1);
$scope = scoped_program_ids($user);

$where = 'WHERE 1 = 1';
$params = [];

if (is_array($scope)) {
    if ($scope === []) {
        $where .= ' AND 1 = 0';
    } else {
        $where .= ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}

if ($program > 0) {
    $where .= ' AND st.program_id = ?';
    $params[] = $program;
}
if ($year !== '') {
    $where .= ' AND st.year_level = ?';
    $params[] = $year;
}
if ($section > 0) {
    $where .= ' AND st.section_id = ?';
    $params[] = $section;
}
if ($assignment === 'assigned') {
    $where .= ' AND st.adviser_id IS NOT NULL';
} elseif ($assignment === 'unassigned') {
    $where .= ' AND st.adviser_id IS NULL';
}
if ($professor > 0 && $assignment !== 'unassigned') {
    $where .= ' AND st.adviser_id = ?';
    $params[] = $professor;
}
if ($type !== '') {
    $where .= ' AND st.student_type = ?';
    $params[] = $type;
}
if ($q !== '') {
    $where .= ' AND (st.student_number LIKE ? OR st.full_name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$count = db()->prepare("SELECT COUNT(*) FROM students st $where");
$count->execute($params);
$totalMatching = (int) $count->fetchColumn();
$pager = paginate($totalMatching, $page);

$list = db()->prepare(
    "SELECT st.*, p.code AS program_code, sec.name AS section_name,
            au.full_name AS adviser_name, au.title AS adviser_title
     FROM students st
     JOIN programs p ON p.id = st.program_id
     LEFT JOIN sections sec ON sec.id = st.section_id
     LEFT JOIN professors adv ON adv.id = st.adviser_id
     LEFT JOIN users au ON au.id = adv.user_id
     $where
     ORDER BY st.full_name
     LIMIT {$pager['per']} OFFSET {$pager['offset']}"
);
$list->execute($params);
$rows = $list->fetchAll();

// Overall Stats Counters (for Scope)
$statWhere = 'WHERE 1 = 1';
if (is_array($scope) && $scope !== []) {
    $statWhere .= ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
}
$totalAll = (int) db()->query("SELECT COUNT(*) FROM students st $statWhere")->fetchColumn();
$regularAll = (int) db()->query("SELECT COUNT(*) FROM students st $statWhere AND st.student_type = 'Regular'")->fetchColumn();
$irregularAll = (int) db()->query("SELECT COUNT(*) FROM students st $statWhere AND st.student_type = 'Irregular'")->fetchColumn();

$yearSql = 'SELECT DISTINCT st.year_level FROM students st WHERE st.year_level <> ""';
$sectionSql = 'SELECT sec.id, sec.name, sec.year_level, p.code AS program_code
    FROM sections sec JOIN programs p ON p.id = sec.program_id WHERE 1 = 1';

if (is_array($scope) && $scope !== []) {
    $scopeIn = implode(',', array_map('intval', $scope));
    $yearSql .= " AND st.program_id IN ($scopeIn)";
    $sectionSql .= " AND sec.program_id IN ($scopeIn)";
}
if ($program > 0) {
    $sectionSql .= ' AND sec.program_id = ' . $program;
}

$years = db()->query($yearSql . ' ORDER BY st.year_level')->fetchAll(PDO::FETCH_COLUMN);
$sections = db()->query($sectionSql . ' ORDER BY p.code, sec.year_level, sec.name')->fetchAll();

layout_start('Students Masterlist', 'students', [['label' => 'Students']]);
?>
<div class="page-head">
    <div>
        <h1>Students Masterlist</h1>
        <p class="lede">Add a student, then assign their professor from the faculty masterlist. Matching class sheets pick the student up automatically.</p>
    </div>
    <?php if (can($user, 'import')): ?>
        <a class="btn" href="<?= h(url('admin/import.php')) ?>"><?= icon('import') ?> Import Student Records</a>
    <?php endif; ?>
</div>

<!-- Summary Stats Header -->
<section class="kpis">
    <article class="kpi">
        <span>Total Masterlist Students</span>
        <strong><?= $totalAll ?></strong>
    </article>
    <article class="kpi">
        <span>Regular Students</span>
        <strong><?= $regularAll ?></strong>
    </article>
    <article class="kpi">
        <span>Irregular Students</span>
        <strong><?= $irregularAll ?></strong>
    </article>
    <article class="kpi">
        <span>Active Sections</span>
        <strong><?= count($sections) ?></strong>
    </article>
</section>

<!-- Search and Filtering Toolbar -->
<form class="filters" method="get" action="<?= h(url('admin/students.php')) ?>" data-live="students">
    <div>
        <label for="q">Search Record</label>
        <input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Name or student number" autocomplete="off">
    </div>
    <div>
        <label for="program">Program</label>
        <select id="program" name="program">
            <option value="0">All Programs</option>
            <?php foreach ($programs as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $program === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="year">Year Level</label>
        <select id="year" name="year">
            <option value="">All Years</option>
            <?php foreach ($years as $item): ?>
                <option value="<?= h((string) $item) ?>" <?= $year === (string) $item ? 'selected' : '' ?>><?= h((string) $item) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="section">Section</label>
        <select id="section" name="section">
            <option value="0">All Sections</option>
            <?php foreach ($sections as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $section === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['program_code'] . ' · ' . $item['year_level'] . ' · ' . $item['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="type">Academic Type</label>
        <select id="type" name="type">
            <option value="">All Types</option>
            <option value="Regular" <?= $type === 'Regular' ? 'selected' : '' ?>>Regular</option>
            <option value="Irregular" <?= $type === 'Irregular' ? 'selected' : '' ?>>Irregular</option>
        </select>
    </div>
    <div>
        <label for="professor">Assigned Professor</label>
        <select id="professor" name="professor">
            <option value="0">All Professors</option>
            <?php foreach ($faculty as $person): ?>
                <option value="<?= (int) $person['professor_id'] ?>" <?= $professor === (int) $person['professor_id'] ? 'selected' : '' ?>><?= h(trim($person['title'] . ' ' . $person['full_name'])) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="assignment">Professor Assignment</label>
        <select id="assignment" name="assignment">
            <option value="">All Students</option>
            <option value="assigned" <?= $assignment === 'assigned' ? 'selected' : '' ?>>Has assigned professor</option>
            <option value="unassigned" <?= $assignment === 'unassigned' ? 'selected' : '' ?>>No assigned professor</option>
        </select>
    </div>
    <div style="display:flex; align-items:flex-end;">
        <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter</button>
    </div>
</form>

<?php if (can($user, 'students_write')): ?>
<details class="card">
    <summary><strong>+ Add New Student Record</strong></summary>
    <form method="post" class="form-grid" style="margin-top:14px;" data-offline-save data-offline-label="New student">
        <?= csrf_field() ?>
        <div>
            <label for="new_student_number">Student ID / Number</label>
            <input id="new_student_number" name="student_number" placeholder="e.g. 08-15-41258" required>
        </div>
        <div>
            <label for="new_full_name">Full Name</label>
            <input id="new_full_name" name="full_name" placeholder="Last Name, First Name M." required>
        </div>
        <div>
            <label for="new_program">Program</label>
            <select id="new_program" name="program_id">
                <?php foreach ($programs as $item): ?>
                    <option value="<?= (int) $item['id'] ?>"><?= h($item['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="new_year">Year Level</label>
            <input id="new_year" name="year_level" placeholder="e.g. 4th Year" required>
        </div>
        <div>
            <label for="new_type">Academic Standing Type</label>
            <select id="new_type" name="student_type">
                <option value="Regular">Regular</option>
                <option value="Irregular">Irregular</option>
            </select>
        </div>
        <div>
            <label for="new_section">Section</label>
            <select id="new_section" name="section_id">
                <option value="0">Unassigned / None</option>
                <?php foreach ($sectionChoices as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" data-program="<?= (int) $item['program_id'] ?>"><?= h($item['program_code'] . ' · ' . $item['year_level'] . ' · ' . $item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="new_professor">Assigned Professor</label>
            <select id="new_professor" name="professor_id">
                <option value="0">Unassigned / None</option>
                <?php foreach ($faculty as $person): ?>
                    <?php if ((int) $person['is_active'] !== 1) continue; ?>
                    <option value="<?= (int) $person['professor_id'] ?>"><?= h(professor_choice_label($person)) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Faculty masterlist. The student is placed on this professor's grade sheets for the selected section.</p>
        </div>
        <div class="wide" style="margin-top:8px;">
            <button class="btn btn-gold" type="submit">Save Student Record</button>
        </div>
    </form>
</details>
<?php endif; ?>

<section class="card" id="student-results" aria-live="polite">
    <?php if ($rows === []): ?>
        <p class="empty">No student records match your active search filters.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Number</th>
                        <th>Full Name</th>
                        <th>Program</th>
                        <th>Year</th>
                        <th>Section</th>
                        <th>Assigned Professor</th>
                        <th>Type</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono"><strong><?= h($row['student_number']) ?></strong></td>
                        <td><strong><?= h($row['full_name']) ?></strong></td>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h($row['year_level']) ?></td>
                        <td><?= h($row['section_name'] ?? '—') ?></td>
                        <td><?= !empty($row['adviser_name']) ? h(trim((string) $row['adviser_title'] . ' ' . $row['adviser_name'])) : '—' ?></td>
                        <td><?= badge($row['student_type'], $row['student_type']) ?></td>
                        <td style="text-align:right;">
                            <a class="btn btn-ghost btn-sm" href="<?= h(url('admin/student.php', ['id' => $row['id']])) ?>">
                                <?= icon('record') ?> View Record
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pager_html($pager, 'admin/students.php', ['q' => $q, 'program' => $program, 'year' => $year, 'section' => $section, 'professor' => $professor, 'assignment' => $assignment, 'type' => $type]) ?>
    <?php endif; ?>
</section>
<script>
(function () {
    var program = document.getElementById('new_program');
    var section = document.getElementById('new_section');
    if (!program || !section) return;
    var options = Array.prototype.slice.call(section.options);
    function sync() {
        var pid = program.value;
        options.forEach(function (opt) {
            if (!opt.getAttribute('data-program')) return;
            var show = opt.getAttribute('data-program') === pid;
            opt.hidden = !show;
            opt.disabled = !show;
            if (!show && opt.selected) section.value = '0';
        });
    }
    program.addEventListener('change', sync);
    sync();
})();
</script>
<?php layout_end(); ?>