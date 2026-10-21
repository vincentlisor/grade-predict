<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'reports');

$type = (string) ($_GET['type'] ?? 'submissions');
if (!in_array($type, ['submissions', 'qualifiers', 'program', 'gwa', 'workflow', 'returned', 'verified', 'scholarships'], true)) {
    $type = 'submissions';
}

$program = (int) ($_GET['program'] ?? 0);
$scope = scoped_program_ids($user);
$programs = db()->query('SELECT id, code, name FROM programs ORDER BY code')->fetchAll();

function report_rows(string $type, int $program, ?array $scope): array
{
    $programSql = '';
    $params = [];
    if (is_array($scope)) {
        if ($scope === []) {
            return [];
        }
        $programSql .= ' AND p.id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
    if ($program > 0) {
        $programSql .= ' AND p.id = ?';
        $params[] = $program;
    }

    if ($type === 'submissions') {
        $sql = "SELECT p.code AS program, s.code AS subject, sec.name AS section, u.full_name AS professor, gs.status, gs.submitted_at
                FROM grade_submissions gs
                JOIN professor_assignments pa ON pa.id = gs.assignment_id
                JOIN subjects s ON s.id = pa.subject_id
                JOIN programs p ON p.id = s.program_id
                JOIN sections sec ON sec.id = pa.section_id
                JOIN professors pr ON pr.id = pa.professor_id
                JOIN users u ON u.id = pr.user_id
                WHERE 1 = 1 $programSql
                ORDER BY p.code, s.code";
    } elseif ($type === 'qualifiers') {
        $sql = "SELECT st.student_number, st.full_name, p.code AS program, e.basis, e.gwa, e.status, e.reason
                FROM dl_pl_evaluations e
                JOIN students st ON st.id = e.student_id
                JOIN programs p ON p.id = st.program_id
                WHERE 1 = 1 $programSql
                ORDER BY e.basis, st.full_name";
    } elseif ($type === 'gwa') {
        $sql = "SELECT st.student_number, st.full_name, p.code AS program, ay.label AS academic_year,
                       sem.name AS semester, e.gwa, e.status AS academic_standing, e.reason
                FROM dl_pl_evaluations e
                JOIN students st ON st.id = e.student_id
                JOIN programs p ON p.id = st.program_id
                JOIN semesters sem ON sem.id = e.semester_id
                JOIN academic_years ay ON ay.id = sem.academic_year_id
                WHERE e.basis = 'official' $programSql
                ORDER BY ay.label DESC, sem.id DESC, e.gwa, st.full_name";
    } elseif (in_array($type, ['workflow', 'returned', 'verified'], true)) {
        $extra = $type === 'returned'
            ? " AND (rc.stage LIKE '%returned%' OR rc.stage IN ('chair_rejected','oic_rejected','osa_rejected','osa_deferred'))"
            : ($type === 'verified' ? " AND rc.stage = 'registrar_verified'" : '');
        $sql = "SELECT st.student_number, st.full_name, p.code AS program, rc.list_type, rc.gwa,
                       rc.stage AS workflow_status, rc.last_note, rc.required_action, rc.updated_at
                FROM recognition_cases rc
                JOIN students st ON st.id = rc.student_id
                JOIN programs p ON p.id = rc.program_id
                WHERE 1 = 1 $programSql $extra
                ORDER BY rc.updated_at DESC, st.full_name";
    } elseif ($type === 'scholarships') {
        $sql = "SELECT st.student_number, st.full_name, p.code AS program, sc.name AS category, sa.discount_percent, sa.status, ay.label, sem.name AS semester_name
                FROM scholarship_assignments sa
                JOIN students st ON st.id = sa.student_id
                JOIN programs p ON p.id = st.program_id
                JOIN scholarship_categories sc ON sc.id = sa.category_id
                JOIN semesters sem ON sem.id = sa.semester_id
                JOIN academic_years ay ON ay.id = sem.academic_year_id
                WHERE 1 = 1 $programSql
                ORDER BY st.full_name";
    } else {
        $sql = "SELECT p.code AS program, s.code AS subject, sec.name AS section, st.student_number, st.full_name, og.final_percent, og.final_eqv, og.remarks
                FROM official_grades og
                JOIN enrollments en ON en.id = og.enrollment_id
                JOIN students st ON st.id = en.student_id
                JOIN professor_assignments pa ON pa.id = en.assignment_id
                JOIN subjects s ON s.id = pa.subject_id
                JOIN programs p ON p.id = s.program_id
                JOIN sections sec ON sec.id = pa.section_id
                WHERE 1 = 1 $programSql
                ORDER BY p.code, s.code, st.full_name";
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$rows = report_rows($type, $program, $scope);

// Handle UTF-8 CSV Export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($rows !== []) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}

$titles = [
    'submissions' => 'Grade submission summary',
    'qualifiers' => 'DL / PL qualifier list',
    'program' => 'Official grades by program',
    'gwa' => 'GWA and academic standing',
    'workflow' => 'Approval workflow report',
    'returned' => 'Returned, rejected, and deferred records',
    'verified' => 'Registrar verification report',
    'scholarships' => 'Scholarship & grant report',
];

// Calculate KPI Metrics dynamically
$totalRows = count($rows);
$activeCount = 0;
foreach ($rows as $r) {
    $st = strtolower((string)($r['status'] ?? $r['workflow_status'] ?? $r['academic_standing'] ?? ''));
    if (in_array($st, ['submitted', 'verified', 'final approved', 'registrar_verified', 'assigned', 'possible dl', 'possible pl'], true)) {
        $activeCount++;
    }
}

layout_start('Reports', 'reports', [['label' => 'Reports']]);
?>
<div class="page-head">
    <div>
        <h1><?= h($titles[$type]) ?></h1>
        <p class="lede">Use Print and choose Save as PDF, or download the CSV for Excel.</p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a class="btn btn-ghost" href="<?= h(url('admin/reports.php', ['type' => $type, 'program' => $program, 'export' => 'csv'])) ?>">
            <?= icon('download') ?> CSV
        </a>
        <button class="btn" type="button" onclick="window.print()">
            <?= icon('print') ?> Print
        </button>
    </div>
</div>

<!-- KPI Summary Section -->
<section class="kpis">
    <article class="kpi">
        <span>Filtered Report Records</span>
        <strong><?= $totalRows ?></strong>
    </article>
    <article class="kpi">
        <span>Verified / Active Items</span>
        <strong><?= $activeCount ?></strong>
    </article>
    <article class="kpi">
        <span>Scope Filter</span>
        <strong><?= $program > 0 ? 'Selected Program' : 'All in Scope' ?></strong>
    </article>
</section>

<!-- Report Selection & Program Scope Filters -->
<section class="card" style="margin-bottom: 18px;">
    <form class="filters" method="get" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 220px;">
            <label for="type">Report</label>
            <select id="type" name="type" style="width: 100%;">
                <?php foreach ($titles as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="flex: 1; min-width: 200px;">
            <label for="program">Program</label>
            <select id="program" name="program" style="width: 100%;">
                <option value="0">All in my scope</option>
                <?php foreach ($programs as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= $program === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <button class="btn" type="submit"><?= icon('search') ?> Show</button>
        </div>
    </form>
</section>

<!-- Report Output Table -->
<section class="card">
    <h2><?= icon('reports') ?> <?= h($titles[$type]) ?></h2>
    <?php if ($rows === []): ?>
        <p class="empty">Nothing is ready for this report filter parameter yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php foreach (array_keys($rows[0]) as $column): ?>
                            <th><?= h(ucwords(str_replace('_', ' ', $column))) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $colName => $value): ?>
                            <td>
                                <?php 
                                $valStr = (string) $value;
                                if (in_array($colName, ['status', 'workflow_status', 'academic_standing'], true)): ?>
                                    <?= badge($valStr, $valStr) ?>
                                <?php elseif ($colName === 'discount_percent' && $valStr !== ''): ?>
                                    <span class="badge" style="background: #e3f2fd; color: #1565c0; font-weight:600;">
                                        <?= (float)$valStr > 0 ? h(number_format((float)$valStr, 0)) . '% Discount' : 'Cash Grant' ?>
                                    </span>
                                <?php elseif (in_array($colName, ['submitted_at', 'updated_at'], true) && $valStr !== ''): ?>
                                    <?= h(date('Y-m-d H:i', strtotime($valStr))) ?>
                                <?php else: ?>
                                    <?= h($valStr) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>