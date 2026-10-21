<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'manage_faculty');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || $name === '' || strlen($password) < 8) {
                throw new RuntimeException('Username, name, and a password of at least 8 characters are required.');
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO users (username, password_hash, full_name, title, must_change_password) VALUES (?, ?, ?, ?, 1)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $title]);
            $userId = (int) db()->lastInsertId();
            $roleId = (int) db()->query("SELECT id FROM roles WHERE code = 'professor'")->fetchColumn();
            db()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
            db()->prepare('INSERT INTO professors (user_id) VALUES (?)')->execute([$userId]);
            audit((int) $user['id'], 'account_create', 'user', (string) $userId, 'Professor ' . $username);
            db()->commit();
            flash('ok', 'Professor account created. They must change the temporary password upon initial login.');
        } elseif ($action === 'reset') {
            $target = (int) ($_POST['user_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                throw new RuntimeException('The temporary password must be at least 8 characters.');
            }
            db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $target]);
            audit((int) $user['id'], 'password_reset', 'user', (string) $target, 'Temporary password issued');
            flash('ok', 'Password reset successfully. The professor must change it at their next sign-in.');
        } elseif ($action === 'toggle') {
            $target = (int) ($_POST['user_id'] ?? 0);
            db()->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?')->execute([$target]);
            audit((int) $user['id'], 'account_status', 'user', (string) $target, 'Active flag toggled');
            flash('ok', 'Professor account status updated.');
        } elseif ($action === 'assign') {
            $professorId = (int) ($_POST['professor_id'] ?? 0);
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $subject = db()->prepare('SELECT program_id FROM subjects WHERE id = ?');
            $subject->execute([$subjectId]);
            $subject = $subject->fetch();
            $section = db()->prepare('SELECT program_id, semester_id FROM sections WHERE id = ?');
            $section->execute([$sectionId]);
            $section = $section->fetch();
            if (!$subject || !$section || (int) $subject['program_id'] !== (int) $section['program_id']) {
                throw new RuntimeException('The subject and section must belong to the same academic program.');
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO professor_assignments (professor_id, subject_id, section_id, semester_id) VALUES (?, ?, ?, ?)')
                ->execute([$professorId, $subjectId, $sectionId, (int) $section['semester_id']]);
            $assignmentId = (int) db()->lastInsertId();
            provision_assignment($assignmentId);
            $enrolled = enroll_section($assignmentId);
            audit((int) $user['id'], 'assignment_create', 'professor_assignment', (string) $assignmentId, "Enrolled $enrolled students");
            db()->commit();
            flash('ok', "Grading sheet created and $enrolled section students were enrolled.");
        } elseif ($action === 'enroll') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            $count = enroll_section($assignmentId);
            flash('ok', "$count newly matching section students were enrolled into the grading sheet.");
        } elseif ($action === 'delete') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            $found = db()->prepare(
                'SELECT pa.id, s.code, sec.name AS section_name
                 FROM professor_assignments pa
                 JOIN subjects s ON s.id = pa.subject_id
                 JOIN sections sec ON sec.id = pa.section_id
                 WHERE pa.id = ?'
            );
            $found->execute([$assignmentId]);
            $found = $found->fetch();
            if (!$found) {
                throw new RuntimeException('That class is no longer assigned.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $submission = $pdo->prepare('SELECT id FROM grade_submissions WHERE assignment_id = ?');
            $submission->execute([$assignmentId]);
            $submissionId = (int) $submission->fetchColumn();
            if ($submissionId > 0) {
                $pdo->prepare('DELETE FROM submission_reviews WHERE submission_id = ?')->execute([$submissionId]);
                $pdo->prepare('DELETE FROM submission_snapshots WHERE submission_id = ?')->execute([$submissionId]);
                $pdo->prepare('DELETE FROM grade_submissions WHERE id = ?')->execute([$submissionId]);
            }
            $pdo->prepare(
                'DELETE og FROM official_grades og
                 JOIN enrollments e ON e.id = og.enrollment_id
                 WHERE e.assignment_id = ?'
            )->execute([$assignmentId]);
            $pdo->prepare('DELETE FROM enrollments WHERE assignment_id = ?')->execute([$assignmentId]);
            $pdo->prepare('DELETE FROM professor_assignments WHERE id = ?')->execute([$assignmentId]);
            audit((int) $user['id'], 'assignment_delete', 'professor_assignment', (string) $assignmentId, $found['code'] . ' ' . $found['section_name']);
            $pdo->commit();
            flash('ok', $found['code'] . ' · ' . $found['section_name'] . ' assignment was deleted.');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'That class assignment or username already exists.' : $e->getMessage());
    }
    redirect('admin/faculty.php');
}

$faculty = db()->query(
    'SELECT u.id AS user_id, u.username, u.full_name, u.title, u.is_active, pr.id AS professor_id
     FROM professors pr JOIN users u ON u.id = pr.user_id ORDER BY u.full_name'
)->fetchAll();
$subjects = db()->query('SELECT s.id, s.code, s.name, p.code AS program_code FROM subjects s JOIN programs p ON p.id = s.program_id ORDER BY p.code, s.code')->fetchAll();
$sections = db()->query('SELECT sec.id, sec.name, sec.year_level, p.code AS program_code FROM sections sec JOIN programs p ON p.id = sec.program_id ORDER BY p.code, sec.name')->fetchAll();
$assignments = db()->query(
    'SELECT pa.id, s.code, sec.name AS section_name, u.full_name, gs.status
     FROM professor_assignments pa
     JOIN subjects s ON s.id = pa.subject_id
     JOIN sections sec ON sec.id = pa.section_id
     JOIN professors pr ON pr.id = pa.professor_id
     JOIN users u ON u.id = pr.user_id
     LEFT JOIN grade_submissions gs ON gs.assignment_id = pa.id
     ORDER BY s.code'
)->fetchAll();

// Calculate Summary Metrics
$totalFaculty = count($faculty);
$activeFaculty = 0;
foreach ($faculty as $f) {
    if ((int)$f['is_active'] === 1) $activeFaculty++;
}
$totalAssignments = count($assignments);

layout_start('Faculty', 'faculty', [['label' => 'Faculty']]);
?>
<div class="page-head">
    <div>
        <h1>Faculty Management</h1>
        <p class="lede">Manage professor accounts, temporary password resets, and class grading sheet assignments.</p>
    </div>
</div>

<!-- Summary Metrics Header -->
<section class="kpis">
    <article class="kpi">
        <span>Total Faculty Professors</span>
        <strong><?= $totalFaculty ?></strong>
    </article>
    <article class="kpi">
        <span>Active Accounts</span>
        <strong><?= $activeFaculty ?></strong>
    </article>
    <article class="kpi">
        <span>Active Class Assignments</span>
        <strong><?= $totalAssignments ?></strong>
    </article>
</section>

<section class="split">
    <!-- New Professor Form -->
    <article class="card">
        <h2><?= icon('faculty') ?> New Professor Account</h2>
        <form method="post" style="margin-top: 12px;" data-offline-save data-offline-label="New professor">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            
            <label for="title">Title / Prefix</label>
            <input id="title" name="title" value="Mr." required>
            
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" placeholder="e.g. Febrick Quiza" required>
            
            <label for="username">System Username</label>
            <input id="username" name="username" placeholder="e.g. fquiza" required>
            
            <label for="password">Temporary Password</label>
            <input id="password" name="password" type="text" minlength="8" placeholder="At least 8 characters" required>
            
            <div style="margin-top: 16px;">
                <button class="btn btn-gold" type="submit">Create Professor Account</button>
            </div>
        </form>
    </article>

    <!-- Class Assignment Form -->
    <article class="card">
        <h2><?= icon('classes') ?> Assign Class Grading Sheet</h2>
        <form method="post" style="margin-top: 12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            
            <label for="professor_id">Assigned Professor</label>
            <select id="professor_id" name="professor_id">
                <?php foreach ($faculty as $person): ?>
                    <option value="<?= (int) $person['professor_id'] ?>"><?= h(trim($person['title'] . ' ' . $person['full_name'])) ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="subject_id">Subject Course</label>
            <select id="subject_id" name="subject_id">
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?= (int) $subject['id'] ?>"><?= h($subject['program_code'] . ' ' . $subject['code'] . ' · ' . $subject['name']) ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="section_id">Section Group</label>
            <select id="section_id" name="section_id">
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>"><?= h($section['program_code'] . ' ' . $section['year_level'] . ' ' . $section['name']) ?></option>
                <?php endforeach; ?>
            </select>
            
            <div style="margin-top: 16px;">
                <button class="btn" type="submit">Create Grading Sheet Assignment</button>
            </div>
        </form>
    </article>
</section>

<!-- Accounts List -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('users') ?> Professor Accounts</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Professor Name</th>
                    <th>Username</th>
                    <th>Account Status</th>
                    <th>Reset Password</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($faculty as $person): ?>
                <tr>
                    <td><strong><?= h(trim($person['title'] . ' ' . $person['full_name'])) ?></strong></td>
                    <td class="mono"><?= h($person['username']) ?></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?= (int) $person['is_active'] === 1 ? badge('Active', 'PASSED') : badge('Disabled', 'FAILED') ?>
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int) $person['user_id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">
                                    <?= (int) $person['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                    <td>
                        <form method="post" class="filters" style="margin: 0; padding: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="user_id" value="<?= (int) $person['user_id'] ?>">
                            <input name="password" type="text" minlength="8" placeholder="Temporary password" required style="width: 170px;">
                            <button class="btn btn-sm" type="submit">Reset</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Assigned Classes -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('classes') ?> Active Class Assignments</h2>
    <?php if ($assignments === []): ?>
        <p class="empty">No active class assignments created yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Class Subject & Section</th>
                        <th>Assigned Professor</th>
                        <th>Submission Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assignments as $assignment): ?>
                    <tr>
                        <td><strong><?= h($assignment['code']) ?></strong> · <?= h($assignment['section_name']) ?></td>
                        <td><?= h($assignment['full_name']) ?></td>
                        <td><?= badge(submission_label((string) ($assignment['status'] ?? 'draft')), (string) ($assignment['status'] ?? 'draft')) ?></td>
                        <td style="text-align: right;">
                            <div style="display: flex; justify-content: flex-end; gap: 6px;">
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="enroll">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Enroll Section</button>
                                </form>
                                <form method="post" style="display:inline;" data-confirm-title="Delete assigned class" data-confirm="<?= h('Delete ' . $assignment['code'] . ' · ' . $assignment['section_name'] . '?') ?>" data-confirm-note="The grading sheet, scores, and class enrollment are removed. This cannot be undone." data-confirm-ok="Delete class" data-confirm-tone="remove">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>