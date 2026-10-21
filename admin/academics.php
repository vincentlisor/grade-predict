<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'manage_academics');
$years = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'program') {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($code === '' || $name === '') throw new RuntimeException('Enter the program code and name.');
            db()->prepare('INSERT INTO programs (code, name) VALUES (?, ?)')->execute([$code, $name]);
            flash('ok', 'Program added successfully.');
        } elseif ($action === 'year') {
            $label = trim((string) ($_POST['label'] ?? ''));
            if ($label === '') throw new RuntimeException('Enter the academic year label.');
            if (!empty($_POST['is_current'])) {
                db()->exec('UPDATE academic_years SET is_current = 0');
            }
            db()->prepare('INSERT INTO academic_years (label, is_current) VALUES (?, ?)')->execute([$label, empty($_POST['is_current']) ? 0 : 1]);
            flash('ok', 'Academic year added successfully.');
        } elseif ($action === 'semester') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $yearId = (int) ($_POST['academic_year_id'] ?? 0);
            if ($name === '' || $yearId <= 0) throw new RuntimeException('Choose a year and name the semester.');
            if (!empty($_POST['is_current'])) {
                db()->exec('UPDATE semesters SET is_current = 0');
            }
            db()->prepare(
                'INSERT INTO semesters (academic_year_id, name, is_current, prelim_deadline, midterm_deadline, finals_deadline)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $yearId,
                $name,
                empty($_POST['is_current']) ? 0 : 1,
                $_POST['prelim_deadline'] ?: null,
                $_POST['midterm_deadline'] ?: null,
                $_POST['finals_deadline'] ?: null,
            ]);
            flash('ok', 'Semester added successfully.');
        } elseif ($action === 'subject') {
            $code = trim((string) ($_POST['code'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $programId = (int) ($_POST['program_id'] ?? 0);
            $year = (string) ($_POST['year_level'] ?? '');
            if ($code === '' || $name === '' || $programId <= 0 || !in_array($year, $years, true)) {
                throw new RuntimeException('Complete the subject form.');
            }
            db()->prepare('INSERT INTO subjects (program_id, code, name, year_level) VALUES (?, ?, ?, ?)')->execute([$programId, $code, $name, $year]);
            flash('ok', 'Subject added successfully.');
        } elseif ($action === 'section') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $programId = (int) ($_POST['program_id'] ?? 0);
            $semesterId = (int) ($_POST['semester_id'] ?? 0);
            $year = (string) ($_POST['year_level'] ?? '');
            $sem = db()->prepare('SELECT academic_year_id FROM semesters WHERE id = ?');
            $sem->execute([$semesterId]);
            $sem = $sem->fetch();
            if ($name === '' || !$sem || !in_array($year, $years, true)) {
                throw new RuntimeException('Complete the section form.');
            }
            db()->prepare('INSERT INTO sections (program_id, academic_year_id, semester_id, year_level, name) VALUES (?, ?, ?, ?, ?)')
                ->execute([$programId, (int) $sem['academic_year_id'], $semesterId, $year, $name]);
            flash('ok', 'Section added successfully.');
        }
        audit((int) $user['id'], 'academics', 'catalog', $action, 'Catalog record added');
    } catch (Throwable $e) {
        flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'That record already exists.' : $e->getMessage());
    }
    redirect('admin/academics.php');
}

$programs = db()->query('SELECT * FROM programs ORDER BY code')->fetchAll();
$yearRows = db()->query('SELECT * FROM academic_years ORDER BY label DESC')->fetchAll();
$semesters = db()->query('SELECT sem.*, ay.label FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id ORDER BY ay.label DESC, sem.name')->fetchAll();
$subjects = db()->query('SELECT s.*, p.code AS program_code FROM subjects s JOIN programs p ON p.id = s.program_id ORDER BY p.code, s.code')->fetchAll();
$sections = db()->query('SELECT sec.*, p.code AS program_code, ay.label FROM sections sec JOIN programs p ON p.id = sec.program_id JOIN academic_years ay ON ay.id = sec.academic_year_id ORDER BY p.code, sec.name')->fetchAll();

layout_start('Academics', 'academics', [['label' => 'Academics']]);
?>
<div class="page-head">
    <div>
        <h1>Academic Catalog Management</h1>
        <p class="lede">Configure academic programs, school years, semesters, curriculum subjects, and active student sections.</p>
    </div>
</div>

<!-- Summary Metrics Header -->
<section class="kpis">
    <article class="kpi">
        <span>Academic Programs</span>
        <strong><?= count($programs) ?></strong>
    </article>
    <article class="kpi">
        <span>School Years</span>
        <strong><?= count($yearRows) ?></strong>
    </article>
    <article class="kpi">
        <span>Curriculum Subjects</span>
        <strong><?= count($subjects) ?></strong>
    </article>
    <article class="kpi">
        <span>Configured Sections</span>
        <strong><?= count($sections) ?></strong>
    </article>
</section>

<!-- Add Program Card -->
<section class="card">
    <h2><?= icon('academics') ?> Add Program / Department</h2>
    <form method="post" class="form-grid" style="margin-top: 12px;" data-offline-save data-offline-label="New program">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="program">
        <div>
            <label for="program_code">Program Code</label>
            <input id="program_code" name="code" placeholder="e.g. BSIT" required>
        </div>
        <div>
            <label for="program_name">Full Program Name</label>
            <input id="program_name" name="name" placeholder="e.g. Bachelor of Science in Information Technology" required>
        </div>
        <div class="wide">
            <button class="btn btn-gold" type="submit">Add Program</button>
        </div>
    </form>
</section>

<!-- Active Programs Grid -->
<section class="kpis" style="margin-top: 14px; margin-bottom: 20px;">
    <?php foreach ($programs as $program): ?>
        <article class="kpi">
            <span><?= h($program['code']) ?></span>
            <strong style="font-size:15px; font-weight: 600;"><?= h($program['name']) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<!-- Academic Year & Semester Setup -->
<section class="split">
    <!-- Academic Year Card -->
    <article class="card">
        <h2><?= icon('calendar') ?> Academic Year</h2>
        <form method="post" style="margin-top: 12px;" data-offline-save data-offline-label="New year">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="year">
            <label for="label">Year Label</label>
            <input id="label" name="label" placeholder="e.g. 2027-2028" required>
            <label style="margin-top: 8px; display: inline-flex; align-items: center; gap: 6px;">
                <input type="checkbox" name="is_current" value="1"> Set as Current Academic Year
            </label>
            <div style="margin-top: 12px;">
                <button class="btn btn-sm" type="submit">Add Academic Year</button>
            </div>
        </form>
        <hr style="margin: 16px 0; border: 0; border-top: 1px solid var(--border-color, #eee);">
        <ul style="list-style: none; padding: 0; margin: 0;">
            <?php foreach ($yearRows as $year): ?>
                <li style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0;">
                    <span><strong><?= h($year['label']) ?></strong></span>
                    <?= (int) $year['is_current'] === 1 ? badge('Current', 'PASSED') : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </article>

    <!-- Semester Card -->
    <article class="card">
        <h2><?= icon('calendar') ?> Semester Setup</h2>
        <form method="post" style="margin-top: 12px;" data-offline-save data-offline-label="New semester">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="semester">
            <label for="academic_year_id">Academic Year</label>
            <select id="academic_year_id" name="academic_year_id">
                <?php foreach ($yearRows as $year): ?>
                    <option value="<?= (int) $year['id'] ?>"><?= h($year['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="sem_name">Semester Name</label>
            <input id="sem_name" name="name" placeholder="e.g. 1st Semester / 2nd Semester" required>
            
            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; margin-top: 8px;">
                <div>
                    <label for="prelim_deadline">Prelim Deadline</label>
                    <input id="prelim_deadline" name="prelim_deadline" type="date">
                </div>
                <div>
                    <label for="midterm_deadline">Midterm Deadline</label>
                    <input id="midterm_deadline" name="midterm_deadline" type="date">
                </div>
                <div>
                    <label for="finals_deadline">Finals Deadline</label>
                    <input id="finals_deadline" name="finals_deadline" type="date">
                </div>
            </div>
            
            <label style="margin-top: 10px; display: inline-flex; align-items: center; gap: 6px;">
                <input type="checkbox" name="is_current" value="1"> Set as Current Active Semester
            </label>
            <div style="margin-top: 12px;">
                <button class="btn btn-sm" type="submit">Add Semester</button>
            </div>
        </form>
        <hr style="margin: 16px 0; border: 0; border-top: 1px solid var(--border-color, #eee);">
        <ul style="list-style: none; padding: 0; margin: 0;">
            <?php foreach ($semesters as $semester): ?>
                <li style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0;">
                    <span><?= h($semester['label'] . ' · ' . $semester['name']) ?></span>
                    <?= (int) $semester['is_current'] === 1 ? badge('Current Active', 'PASSED') : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </article>
</section>

<!-- Subject & Section Configuration Split -->
<section class="split" style="margin-top: 18px;">
    <!-- Add Subject Form -->
    <article class="card">
        <h2><?= icon('classes') ?> Add Curriculum Subject</h2>
        <form method="post" style="margin-top: 12px;" data-offline-save data-offline-label="New subject">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="subject">
            <label for="program_id">Program Degree</label>
            <select id="program_id" name="program_id">
                <?php foreach ($programs as $program): ?>
                    <option value="<?= (int) $program['id'] ?>"><?= h($program['code'] . ' · ' . $program['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="code">Subject Code</label>
            <input id="code" name="code" placeholder="e.g. IT101" required>
            <label for="name">Subject Title / Description</label>
            <input id="name" name="name" placeholder="e.g. Introduction to Computing" required>
            <label for="year_level">Year Level</label>
            <select id="year_level" name="year_level">
                <?php foreach ($years as $year): ?>
                    <option value="<?= h($year) ?>"><?= h($year) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="margin-top: 14px;">
                <button class="btn btn-sm" type="submit">Save Subject</button>
            </div>
        </form>
    </article>

    <!-- Add Section Form -->
    <article class="card">
        <h2><?= icon('students') ?> Add Section Group</h2>
        <form method="post" style="margin-top: 12px;" data-offline-save data-offline-label="New section">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="section">
            <label for="sec_program">Program Degree</label>
            <select id="sec_program" name="program_id">
                <?php foreach ($programs as $program): ?>
                    <option value="<?= (int) $program['id'] ?>"><?= h($program['code'] . ' · ' . $program['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="semester_id">Semester Term</label>
            <select id="semester_id" name="semester_id">
                <?php foreach ($semesters as $semester): ?>
                    <option value="<?= (int) $semester['id'] ?>"><?= h($semester['label'] . ' ' . $semester['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="sec_year">Year Level</label>
            <select id="sec_year" name="year_level">
                <?php foreach ($years as $year): ?>
                    <option value="<?= h($year) ?>"><?= h($year) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="sec_name">Section Name / Code</label>
            <input id="sec_name" name="name" placeholder="e.g. 401-C" required>
            <div style="margin-top: 14px;">
                <button class="btn btn-sm" type="submit">Save Section</button>
            </div>
        </form>
    </article>
</section>

<!-- Subjects Catalog Table -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('reports') ?> Offered Curriculum Subjects</h2>
    <?php if ($subjects === []): ?>
        <p class="empty">No subjects added to the catalog yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Subject Code</th>
                        <th>Subject Title / Name</th>
                        <th>Year Level</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><strong><?= h($subject['program_code']) ?></strong></td>
                        <td class="mono"><strong><?= h($subject['code']) ?></strong></td>
                        <td><?= h($subject['name']) ?></td>
                        <td><?= h($subject['year_level']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Sections Catalog Table -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('students') ?> Configured Student Sections</h2>
    <?php if ($sections === []): ?>
        <p class="empty">No student sections added yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Year Level</th>
                        <th>Section Name</th>
                        <th>Academic Year</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sections as $section): ?>
                    <tr>
                        <td><strong><?= h($section['program_code']) ?></strong></td>
                        <td><?= h($section['year_level']) ?></td>
                        <td><strong><?= h($section['name']) ?></strong></td>
                        <td><?= h($section['label']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>