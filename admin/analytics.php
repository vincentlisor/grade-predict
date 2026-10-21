<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'analytics');

$program = (int) ($_GET['program'] ?? 0);
$yearLevel = (string) ($_GET['year_level'] ?? '');
$semester = (int) ($_GET['semester'] ?? 0);
$scope = scoped_program_ids($user);

$sql = 'SELECT pa.id FROM professor_assignments pa
        JOIN subjects s ON s.id = pa.subject_id
        JOIN sections sec ON sec.id = pa.section_id
        WHERE 1 = 1';
$params = [];
if (is_array($scope)) {
    $sql .= $scope === [] ? ' AND 1 = 0' : ' AND s.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
}
if ($program > 0) {
    $sql .= ' AND s.program_id = ?';
    $params[] = $program;
}
if ($yearLevel !== '') {
    $sql .= ' AND sec.year_level = ?';
    $params[] = $yearLevel;
}
if ($semester > 0) {
    $sql .= ' AND pa.semester_id = ?';
    $params[] = $semester;
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$statusCounts = [];
$subjectPredicted = [];
$periodTotals = ['prelim' => [], 'midterm' => [], 'finals' => []];
$totalStudentsEvaluated = 0;
$overallPredictedSum = 0;

foreach ($ids as $id) {
    $book = build_gradebook((int) $id);
    $status = $book['assignment']['submission_status'] ?? 'draft';
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $sum = 0;
    $n = 0;
    foreach ($book['students'] as $student) {
        if ($student['predicted_percent'] !== null) {
            $sum += $student['predicted_percent'];
            $overallPredictedSum += $student['predicted_percent'];
            $totalStudentsEvaluated++;
            $n++;
        }
        foreach ($periodTotals as $period => $ignored) {
            if ($student['periods'][$period]['percent'] !== null) {
                $periodTotals[$period][] = $student['periods'][$period]['percent'];
            }
        }
    }
    if ($n > 0) {
        $subjectPredicted[$book['assignment']['subject_code']] = round($sum / $n, 2);
    }
}

$dlpl = db()->query("SELECT status, COUNT(*) AS total FROM dl_pl_evaluations WHERE basis = 'official' GROUP BY status")->fetchAll();
$programs = db()->query('SELECT id, code FROM programs ORDER BY code')->fetchAll();
$semesters = db()->query('SELECT sem.id, sem.name, ay.label FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id')->fetchAll();
$legacy = db()->query('SELECT * FROM legacy_predictions ORDER BY recorded_at')->fetchAll();

$periodAverages = [];
foreach ($periodTotals as $period => $values) {
    $periodAverages[PERIODS[$period]] = $values === [] ? null : round(array_sum($values) / count($values), 2);
}

$chart = [
    'dlplLabels' => array_column($dlpl, 'status'),
    'dlplValues' => array_map('intval', array_column($dlpl, 'total')),
    'subjectLabels' => array_keys($subjectPredicted),
    'subjectValues' => array_values($subjectPredicted),
    'periodLabels' => array_keys($periodAverages),
    'periodValues' => array_values($periodAverages),
    'statusLabels' => array_map(static fn ($s) => submission_label($s), array_keys($statusCounts)),
    'statusValues' => array_values($statusCounts),
];

// Top Metrics
$totalFilteredAssignments = count($ids);
$totalDlPlQualifiers = array_sum(array_column($dlpl, 'total'));
$overallAveragePredicted = $totalStudentsEvaluated > 0 ? round($overallPredictedSum / $totalStudentsEvaluated, 2) . '%' : 'N/A';

layout_start('Analytics', 'analytics', [['label' => 'Analytics']]);
?>
<div class="page-head">
    <div>
        <h1>Analytics</h1>
        <p class="lede">Charts use records already in the database. They do not approve grades or scholarships.</p>
    </div>
</div>

<!-- KPI Summary Header -->
<section class="kpis">
    <article class="kpi">
        <span>Filtered Class Sections</span>
        <strong><?= $totalFilteredAssignments ?></strong>
    </article>
    <article class="kpi">
        <span>Evaluated DL / PL Candidates</span>
        <strong><?= $totalDlPlQualifiers ?></strong>
    </article>
    <article class="kpi">
        <span>Overall Average Performance</span>
        <strong><?= $overallAveragePredicted ?></strong>
    </article>
</section>

<!-- Filter Controls -->
<section class="card" style="margin-bottom: 18px;">
    <form class="filters" method="get" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 180px;">
            <label for="program">Program</label>
            <select id="program" name="program" style="width: 100%;">
                <option value="0">All</option>
                <?php foreach ($programs as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= $program === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="flex: 1; min-width: 180px;">
            <label for="year_level">Year level</label>
            <select id="year_level" name="year_level" style="width: 100%;">
                <option value="">All</option>
                <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $year): ?>
                    <option value="<?= h($year) ?>" <?= $yearLevel === $year ? 'selected' : '' ?>><?= h($year) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="flex: 1; min-width: 200px;">
            <label for="semester">Semester</label>
            <select id="semester" name="semester" style="width: 100%;">
                <option value="0">All</option>
                <?php foreach ($semesters as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= $semester === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['label'] . ' ' . $item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter</button>
        </div>
    </form>
</section>

<!-- Analytical Charts Grid -->
<section class="charts" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 18px; margin-bottom: 18px;">
    <article class="card chart-box">
        <h2><?= icon('award') ?> Official DL / PL</h2>
        <canvas id="dlplChart"></canvas>
    </article>
    <article class="card chart-box">
        <h2><?= icon('analytics') ?> Average predicted percent</h2>
        <canvas id="subjectChart"></canvas>
    </article>
    <article class="card chart-box">
        <h2><?= icon('sheet') ?> Encoded period averages</h2>
        <canvas id="periodChart"></canvas>
    </article>
    <article class="card chart-box">
        <h2><?= icon('queue') ?> Submission progress</h2>
        <canvas id="statusChart"></canvas>
    </article>
</section>

<!-- Preserved History Table -->
<section class="card">
    <h2><?= icon('audit') ?> Preserved predictor history</h2>
    <p class="hint">These three sessions were kept from the previous grade predictor. They are not official grades.</p>
    
    <div class="table-wrap" style="margin-top: 12px;">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Subject</th>
                    <th>Predicted %</th>
                    <th>Equivalent</th>
                    <th>Status</th>
                    <th>Recorded</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($legacy as $row): ?>
                <tr>
                    <td><?= h($row['student_name']) ?><br><span class="mono" style="font-size: 12px; color: var(--text-muted, #666);"><?= h($row['student_number']) ?></span></td>
                    <td><?= h($row['subject_name']) ?></td>
                    <td><strong><?= h((string) $row['predicted_percent']) ?>%</strong></td>
                    <td><span class="mono"><?= h((string) $row['numerical_grade']) ?></span></td>
                    <td><?= badge($row['dlpl_status'], $row['dlpl_status']) ?></td>
                    <td><?= fmt_when($row['recorded_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script type="application/json" id="chartData"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const data = JSON.parse(document.getElementById('chartData').textContent);
if (window.Chart) {
    const navy = '#142B49';
    const gold = '#C5A45B';
    const make = (id, type, labels, values) => new Chart(document.getElementById(id), {
        type,
        data: { labels, datasets: [{ data: values, backgroundColor: [navy, gold, '#1F9D45', '#2457D6', '#D7263D', '#6B3FE0'] }] },
        options: { plugins: { legend: { display: type === 'doughnut' } }, scales: type === 'doughnut' ? {} : { y: { beginAtZero: true } } }
    });
    make('dlplChart', 'doughnut', data.dlplLabels, data.dlplValues);
    make('subjectChart', 'bar', data.subjectLabels, data.subjectValues);
    make('periodChart', 'bar', data.periodLabels, data.periodValues);
    make('statusChart', 'bar', data.statusLabels, data.statusValues);
}
</script>

<?php layout_end(); ?>