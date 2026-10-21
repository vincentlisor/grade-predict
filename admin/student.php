<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'students_read');
ensure_student_adviser_column();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT st.*, p.code AS program_code, p.name AS program_name, sec.name AS section_name,
            au.full_name AS adviser_name, au.title AS adviser_title
     FROM students st
     JOIN programs p ON p.id = st.program_id
     LEFT JOIN sections sec ON sec.id = st.section_id
     LEFT JOIN professors adv ON adv.id = st.adviser_id
     LEFT JOIN users au ON au.id = adv.user_id
     WHERE st.id = ?'
);
$stmt->execute([$id]);
$student = $stmt->fetch();

$scope = scoped_program_ids($user);
if (!$student || (is_array($scope) && !in_array((int) $student['program_id'], $scope, true))) {
    http_response_code(404);
    exit('Student record not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && can($user, 'students_write')) {
    require_csrf();
    $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $type = ($_POST['student_type'] ?? '') === 'Irregular' ? 'Irregular' : 'Regular';
    $year = trim((string) ($_POST['year_level'] ?? ''));
    $programId = (int) ($_POST['program_id'] ?? 0);
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    $professorId = (int) ($_POST['professor_id'] ?? 0);

    if ($studentNumber === '' || $name === '' || $year === '' || $programId <= 0) {
        flash('error', 'Student ID, name, program, and year level are required.');
    } else {
        try {
            if (is_array($scope) && !in_array($programId, $scope, true)) {
                throw new RuntimeException('That program is outside your access.');
            }
            if ($sectionId > 0) {
                $section = db()->prepare('SELECT program_id FROM sections WHERE id = ?');
                $section->execute([$sectionId]);
                if ((int) $section->fetchColumn() !== $programId) {
                    throw new RuntimeException('The section must belong to the selected program.');
                }
            }
            $professorName = '';
            if ($professorId > 0) {
                $professor = db()->prepare(
                    'SELECT pr.id, u.full_name, u.title
                     FROM professors pr
                     JOIN users u ON u.id = pr.user_id
                     WHERE pr.id = ?'
                );
                $professor->execute([$professorId]);
                $professor = $professor->fetch();
                if (!$professor) {
                    throw new RuntimeException('Choose a professor from the faculty masterlist.');
                }
                $professorName = trim($professor['title'] . ' ' . $professor['full_name']);
            }
            $before = $student['student_number'] . ' | ' . $student['full_name'] . ' | program ' . $student['program_id'];
            db()->prepare('UPDATE students SET student_number = ?, full_name = ?, student_type = ?, year_level = ?, program_id = ?, section_id = ?, adviser_id = ? WHERE id = ?')
                ->execute([$studentNumber, $name, $type, $year, $programId, $sectionId > 0 ? $sectionId : null, $professorId > 0 ? $professorId : null, $id]);
            $enrolled = enroll_student_for_professor($id, $professorId, $sectionId);
            audit((int) $user['id'], 'student_update', 'student', (string) $id, $before . ' -> ' . $studentNumber . ' | ' . $name . ' | program ' . $programId . ($professorName !== '' ? ' | professor ' . $professorName : ''));
            $message = 'Student record updated successfully.';
            if ($professorName !== '') {
                $message .= ' Assigned professor: ' . $professorName . '.';
                if ($sectionId <= 0) {
                    $message .= ' Choose a section so they can be placed on that professor\'s grade sheet.';
                } elseif ($enrolled > 0) {
                    $message .= ' Placed on ' . $enrolled . ' class sheet' . ($enrolled === 1 ? '' : 's') . '.';
                }
            }
            flash('ok', $message);
        } catch (Throwable $e) {
            flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'That student number already exists.' : $e->getMessage());
        }
    }
    redirect('admin/student.php', ['id' => $id]);
}

$sections = db()->prepare('SELECT id, name, year_level FROM sections WHERE program_id = ? ORDER BY year_level, name');
$sections->execute([(int) $student['program_id']]);
$sections = $sections->fetchAll();

$programs = db()->query('SELECT id, code, name FROM programs ORDER BY code')->fetchAll();
$faculty = faculty_masterlist();
$adviserLabel = trim((string) ($student['adviser_title'] ?? '') . ' ' . (string) ($student['adviser_name'] ?? ''));

$classes = db()->prepare(
    'SELECT pa.id, s.code, s.name AS subject_name, sec.name AS section_name, u.full_name AS professor_name, u.title AS professor_title,
            gs.status, og.final_percent, og.final_eqv, og.remarks
     FROM enrollments e
     JOIN professor_assignments pa ON pa.id = e.assignment_id
     JOIN subjects s ON s.id = pa.subject_id
     JOIN sections sec ON sec.id = pa.section_id
     JOIN professors pr ON pr.id = pa.professor_id
     JOIN users u ON u.id = pr.user_id
     LEFT JOIN grade_submissions gs ON gs.assignment_id = pa.id
     LEFT JOIN official_grades og ON og.enrollment_id = e.id
     WHERE e.student_id = ?'
);
$classes->execute([$id]);
$classes = $classes->fetchAll();

$evals = db()->prepare('SELECT * FROM dl_pl_evaluations WHERE student_id = ? ORDER BY basis ASC');
$evals->execute([$id]);
$evals = $evals->fetchAll();

layout_start($student['full_name'], 'students', [
    ['label' => 'Students', 'href' => 'admin/students.php'],
    ['label' => $student['student_number']],
]);
?>
<div class="page-head">
    <div>
        <h1><?= h($student['full_name']) ?></h1>
        <p class="lede"><?= h($student['program_code']) ?> · <?= h($student['program_name']) ?> · Year <?= h((string) $student['year_level']) ?> · Section <?= h($student['section_name'] ?? 'Unassigned') ?> · Professor <?= h($adviserLabel !== '' ? $adviserLabel : 'Unassigned') ?></p>
    </div>
    <div>
        <?= badge($student['student_type'], $student['student_type']) ?>
    </div>
</div>

<section class="split">
    <!-- Student Overview Card -->
    <article class="card">
        <h2><?= icon('record') ?> Student Information Overview</h2>
        <div class="kpis" style="margin-top: 12px; margin-bottom: 16px;">
            <article class="kpi">
                <span>Student ID Number</span>
                <strong class="mono" style="font-size:16px;"><?= h($student['student_number']) ?></strong>
            </article>
            <article class="kpi">
                <span>Academic Type</span>
                <strong style="font-size:16px;"><?= h($student['student_type']) ?></strong>
            </article>
            <article class="kpi">
                <span>Assigned Professor</span>
                <strong style="font-size:16px;"><?= h($adviserLabel !== '' ? $adviserLabel : 'Unassigned') ?></strong>
            </article>
        </div>

        <h3>DL / PL Evaluation Summary</h3>
        <?php if ($evals === []): ?>
            <p class="hint">Honor evaluations will appear here once grade sheets are submitted and refreshed.</p>
        <?php else: ?>
            <ul style="list-style: none; padding: 0; margin-top: 8px;">
                <?php foreach ($evals as $eval): ?>
                    <li style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border-color, #eee);">
                        <span><strong><?= h(ucfirst((string)$eval['basis'])) ?> Evaluation:</strong></span>
                        <div>
                            <span class="mono" style="margin-right: 8px;">GWA <strong><?= fmt_eqv($eval['gwa'] === null ? null : (float) $eval['gwa']) ?></strong></span>
                            <?= badge((string)$eval['status'], (string)$eval['status']) ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>

    <!-- Student Update Form Card -->
    <?php if (can($user, 'students_write')): ?>
    <article class="card">
        <h2><?= icon('edit') ?> Update Profile Record</h2>
        <form method="post" style="margin-top: 12px;" data-offline-save data-offline-label="Student update">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            
            <label for="student_number">Student ID Number</label>
            <input id="student_number" name="student_number" value="<?= h($student['student_number']) ?>" required>
            
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" value="<?= h($student['full_name']) ?>" required>
            
            <label for="year_level">Year Level</label>
            <input id="year_level" name="year_level" value="<?= h($student['year_level']) ?>" required>
            
            <label for="student_type">Academic Standing Type</label>
            <select id="student_type" name="student_type">
                <option value="Regular" <?= $student['student_type'] === 'Regular' ? 'selected' : '' ?>>Regular</option>
                <option value="Irregular" <?= $student['student_type'] === 'Irregular' ? 'selected' : '' ?>>Irregular</option>
            </select>
            
            <label for="program_id">Program Degree</label>
            <select id="program_id" name="program_id">
                <?php foreach ($programs as $program): ?>
                    <option value="<?= (int) $program['id'] ?>" <?= (int) $student['program_id'] === (int) $program['id'] ? 'selected' : '' ?>><?= h($program['code'] . ' · ' . $program['name']) ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="section_id">Assigned Section</label>
            <select id="section_id" name="section_id">
                <option value="0">Unassigned / None</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>" <?= (int) $student['section_id'] === (int) $section['id'] ? 'selected' : '' ?>><?= h($section['year_level'] . ' ' . $section['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="professor_id">Assigned Professor</label>
            <select id="professor_id" name="professor_id">
                <option value="0">Unassigned / None</option>
                <?php foreach ($faculty as $person): ?>
                    <?php if ((int) $person['is_active'] !== 1 && (int) $student['adviser_id'] !== (int) $person['professor_id']) continue; ?>
                    <option value="<?= (int) $person['professor_id'] ?>" <?= (int) $student['adviser_id'] === (int) $person['professor_id'] ? 'selected' : '' ?>><?= h(professor_choice_label($person)) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Chosen from the faculty masterlist. Saving places the student on that professor's grade sheets for the selected section.</p>
            
            <div style="margin-top: 16px;">
                <button class="btn btn-gold" type="submit">Save Changes</button>
            </div>
        </form>
    </article>
    <?php endif; ?>
</section>

<!-- Enrolled Classes & Grades -->
<section class="card">
    <h2><?= icon('classes') ?> Enrolled Academic Classes</h2>
    <?php if ($classes === []): ?>
        <p class="empty">This student is not enrolled in any academic subject classes for this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Subject Code & Title</th>
                        <th>Professor</th>
                        <th>Section</th>
                        <th>Submission Stage</th>
                        <th>Final %</th>
                        <th>Equivalent</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td>
                            <strong><?= h($class['code']) ?></strong>
                            <br><span class="hint"><?= h($class['subject_name']) ?></span>
                        </td>
                        <td><?= h(trim($class['professor_title'] . ' ' . $class['professor_name'])) ?></td>
                        <td><?= h($class['section_name']) ?></td>
                        <td><?= badge(submission_label((string) ($class['status'] ?? 'draft')), (string) ($class['status'] ?? 'draft')) ?></td>
                        <td><?= fmt_pct($class['final_percent'] === null ? null : (float) $class['final_percent']) ?></td>
                        <td><strong><?= fmt_eqv($class['final_eqv'] === null ? null : (float) $class['final_eqv']) ?></strong></td>
                        <td><?= $class['remarks'] ? badge((string)$class['remarks'], (string)$class['remarks']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>