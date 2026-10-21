<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'scholarships');

// Auto-seed Standard Scholarship Categories with exact discounts (100% PL, 50% DL, Fixed TES)
$categoriesCount = (int) db()->query("SELECT COUNT(*) FROM scholarship_categories")->fetchColumn();
if ($categoriesCount === 0) {
    db()->exec("INSERT INTO scholarship_categories (name, discount_percent, description, is_active) VALUES 
        ('President\'s List scholarship', 100.00, '100% Tuition value: Automatically deducted from remaining balance, or issued in Cash if fully paid', 1),
        ('Dean\'s List scholarship', 50.00, '50% Tuition value: Automatically deducted from remaining balance, or issued in Cash if fully paid', 1),
        ('TES (Tertiary Education Subsidy)', 0.00, 'CHED UniFAST Grant: Applied against remaining balance or disbursed as Cash Payout if fully paid', 1)");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'category') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $discount = trim((string) ($_POST['discount_percent'] ?? ''));
            if ($name === '') throw new RuntimeException('Please enter a scholarship category name.');
            db()->prepare('INSERT INTO scholarship_categories (name, discount_percent, description) VALUES (?, ?, ?)')
                ->execute([$name, $discount === '' ? null : (float) $discount, trim((string) ($_POST['description'] ?? ''))]);
            flash('ok', 'Scholarship category saved successfully.');
        } elseif ($action === 'assign') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $semesterId = (int) ($_POST['semester_id'] ?? 0);
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            
            $category = db()->prepare('SELECT * FROM scholarship_categories WHERE id = ? AND is_active = 1');
            $category->execute([$categoryId]);
            $category = $category->fetch();
            
            if (!$category) {
                throw new RuntimeException('Invalid scholarship category selected.');
            }

            $isTes = str_contains(strtoupper($category['name']), 'TES') || str_contains(strtoupper($category['name']), 'TERTIARY');
            
            // Verify eligibility if DL/PL
            if (!$isTes) {
                $eval = db()->prepare('SELECT status FROM dl_pl_evaluations WHERE student_id = ? AND semester_id = ? AND basis = "official"');
                $eval->execute([$studentId, $semesterId]);
                $eval = $eval->fetch();
                $recognized = db()->prepare('SELECT id FROM recognition_cases WHERE student_id = ? AND semester_id = ? AND stage = "registrar_verified"');
                $recognized->execute([$studentId, $semesterId]);
                $recognized = (bool) $recognized->fetch();

                $standingOk = $eval && in_array($eval['status'], ['Possible DL', 'Possible PL', 'Final Approved'], true);
                if (!$standingOk && !$recognized) {
                    throw new RuntimeException('Assigning DL (50%) or PL (100%) discounts requires official "Possible DL / PL" status or Registrar recognition verification.');
                }
            }
            
            $discountVal = $category['discount_percent'] !== null ? (float) $category['discount_percent'] : 0.00;

            db()->prepare(
                'INSERT INTO scholarship_assignments (student_id, semester_id, category_id, discount_percent, status, reviewer_id, notes, assigned_at)
                 VALUES (?, ?, ?, ?, "assigned", ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), discount_percent = VALUES(discount_percent), status = "assigned", reviewer_id = VALUES(reviewer_id), notes = VALUES(notes), assigned_at = NOW()'
            )->execute([$studentId, $semesterId, $categoryId, $discountVal, (int) $user['id'], trim((string) ($_POST['notes'] ?? ''))]);
            
            audit((int) $user['id'], 'scholarship_assign', 'student', (string) $studentId, $category['name']);
            flash('ok', $category['name'] . ' assigned successfully for this term. (Deducted from unpaid balance, or issued as Cash if fully paid).');
        } elseif ($action === 'revoke') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            db()->prepare('UPDATE scholarship_assignments SET status = "revoked", reviewer_id = ? WHERE id = ?')->execute([(int) $user['id'], $assignmentId]);
            audit((int) $user['id'], 'scholarship_revoke', 'scholarship_assignment', (string) $assignmentId, 'Revoked');
            flash('ok', 'Scholarship grant revoked successfully.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/scholarships.php');
}

$categories = db()->query('SELECT * FROM scholarship_categories ORDER BY name')->fetchAll();
$students = db()->query('SELECT id, student_number, full_name FROM students ORDER BY full_name')->fetchAll();

// Multi-Semester Dropdown Query: Ordered by Academic Year and Semester Name
$semesters = db()->query(
    'SELECT sem.id, sem.name, ay.label 
     FROM semesters sem 
     JOIN academic_years ay ON ay.id = sem.academic_year_id 
     ORDER BY ay.label DESC, sem.name ASC'
)->fetchAll();

$assignments = db()->query(
    'SELECT sa.*, st.full_name, st.student_number, sc.name AS category_name, sem.name AS semester_name, ay.label
     FROM scholarship_assignments sa
     JOIN students st ON st.id = sa.student_id
     JOIN scholarship_categories sc ON sc.id = sa.category_id
     JOIN semesters sem ON sem.id = sa.semester_id
     JOIN academic_years ay ON ay.id = sem.academic_year_id
     ORDER BY sa.assigned_at DESC'
)->fetchAll();

// Statistics
$totalCategories = count($categories);
$activeGrants = 0;
foreach ($assignments as $a) {
    if ($a['status'] === 'assigned') $activeGrants++;
}
$totalAssignments = count($assignments);

layout_start('Scholarships', 'scholarships', [['label' => 'Scholarships']]);
?>
<div class="page-head">
    <div>
        <h1>Scholarship & TES Subsidy Management</h1>
        <p class="lede">Manage President's List (100% discount), Dean's List (50% discount), and CHED TES Grants per semester term.</p>
    </div>
</div>

<!-- Summary Metrics Header -->
<section class="kpis">
    <article class="kpi">
        <span>Scholarship Categories</span>
        <strong><?= $totalCategories ?></strong>
    </article>
    <article class="kpi">
        <span>Active Term Grants</span>
        <strong><?= $activeGrants ?></strong>
    </article>
    <article class="kpi">
        <span>Total Beneficiaries</span>
        <strong><?= $totalAssignments ?></strong>
    </article>
</section>

<section class="split">
    <!-- Scholarship Category Setup Form -->
    <article class="card">
        <h2><?= icon('scholarships') ?> Scholarship Category Setup</h2>
        <p class="hint">Define tuition discounts (100% PL, 50% DL) or fixed cash grant (CHED TES). Automatically deducts from unpaid balance or converts to Cash Payout if fully paid.</p>
        
        <form method="post" style="margin-top: 12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="category">
            
            <label for="name">Category Name</label>
            <input id="name" name="name" placeholder="e.g. Special Founder Scholarship" required>
            
            <label for="discount_percent">Tuition Discount Percent (%)</label>
            <input id="discount_percent" name="discount_percent" type="number" step="0.01" min="0" max="100" placeholder="e.g. 50 or 100 (0 for cash grant)">
            
            <label for="description">Category Description / Policy</label>
            <textarea id="description" name="description" rows="2" placeholder="Brief qualification overview..."></textarea>
            
            <div style="margin-top: 14px;">
                <button class="btn btn-gold" type="submit">Save Category</button>
            </div>
        </form>

        <hr style="margin: 16px 0; border: 0; border-top: 1px solid var(--border-color, #eee);">
        
        <p class="hint" style="font-weight: 600; color: var(--text-dark, #222); margin-bottom: 8px;">Configured Categories & Benefit Rules:</p>
        <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; line-height: 1.6;">
            <?php foreach ($categories as $category): ?>
                <li style="padding: 6px 0; border-bottom: 1px dashed var(--border-color, #f0f0f0); display: flex; justify-content: space-between; align-items: center;">
                    <span><strong><?= h($category['name']) ?></strong></span>
                    <span style="font-weight: 600; color: var(--color-primary, #003366);">
                        <?= (float)$category['discount_percent'] > 0 ? h(number_format((float) $category['discount_percent'], 2)) . '% Discount / Cash' : 'CHED Grant / Cash' ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </article>

    <!-- Term Grant Assignment Form -->
    <article class="card">
        <h2><?= icon('calendar') ?> Assign Grant for Term</h2>
        <p class="hint">Assign 100% PL, 50% DL, or CHED TES Grant per term. Applies to remaining balance or triggers Cash Payout if tuition is fully paid.</p>
        
        <form method="post" style="margin-top: 12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            
            <label for="student_id">Target Student</label>
            <select id="student_id" name="student_id">
                <?php foreach ($students as $student): ?>
                    <option value="<?= (int) $student['id'] ?>"><?= h($student['student_number'] . ' · ' . $student['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="semester_id">Academic Semester Term</label>
            <select id="semester_id" name="semester_id">
                <?php foreach ($semesters as $semester): ?>
                    <option value="<?= (int) $semester['id'] ?>"><?= h($semester['label'] . ' ' . $semester['name']) ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="category_id">Scholarship / Grant Category</label>
            <select id="category_id" name="category_id">
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>">
                        <?= h($category['name']) ?> 
                        (<?= (float)$category['discount_percent'] > 0 ? h(number_format((float)$category['discount_percent'], 0)) . '% Value' : 'Cash Grant' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            
            <label for="notes">Grant Notes / Remarks</label>
            <textarea id="notes" name="notes" rows="2" placeholder="e.g. Deduct from unpaid tuition balance; or issue as Cash if account is fully paid"></textarea>
            
            <div style="margin-top: 14px;">
                <button class="btn" type="submit">Assign Term Grant</button>
            </div>
        </form>
    </article>
</section>

<!-- Active Term Grant Assignments Table -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('reports') ?> Term Grant Assignments & Settlement Status</h2>
    <?php if ($assignments === []): ?>
        <p class="empty">No scholarship or grant has been assigned yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Name & ID</th>
                        <th>Semester Term</th>
                        <th>Scholarship Category</th>
                        <th>Benefit Mode</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assignments as $row): ?>
                    <tr>
                        <td>
                            <strong><?= h($row['full_name']) ?></strong><br>
                            <span class="mono" style="font-size: 12px; color: var(--text-muted, #666);"><?= h($row['student_number']) ?></span>
                        </td>
                        <td><?= h($row['label'] . ' ' . $row['semester_name']) ?></td>
                        <td><strong><?= h($row['category_name']) ?></strong></td>
                        <td>
                            <?php if ((float)$row['discount_percent'] > 0): ?>
                                <span class="badge" style="background: #e3f2fd; color: #1565c0; font-weight: 600;">
                                    <?= h(number_format((float) $row['discount_percent'], 0)) ?>% (Balance Deduction / Cash)
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: #e8f5e9; color: #2e7d32; font-weight: 600;">
                                    CHED Grant (Deduction / Cash Payout)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= badge($row['status'], $row['status']) ?></td>
                        <td style="text-align: right;">
                            <?php if ($row['status'] === 'assigned'): ?>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">Revoke Grant</button>
                                </form>
                            <?php else: ?>
                                <span class="hint" style="font-size: 12px;">Revoked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>