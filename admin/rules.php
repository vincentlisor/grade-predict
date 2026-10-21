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
require_permission($user, 'rules');

$semesterId = (int) ($_GET['semester'] ?? $_POST['semester_id'] ?? 0);
$semesters = db()->query(
    'SELECT sem.id, sem.name, sem.is_current, ay.label
     FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id
     ORDER BY ay.label DESC, sem.id DESC'
)->fetchAll();

if ($semesterId <= 0) {
    foreach ($semesters as $semester) {
        if ((int) $semester['is_current'] === 1) {
            $semesterId = (int) $semester['id'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $fields = ['disqualify_at', 'pl_min', 'pl_max', 'dl_min', 'dl_max'];
        $values = [];
        foreach ($fields as $field) {
            $raw = trim((string) ($_POST[$field] ?? ''));
            if (!is_numeric($raw)) {
                throw new RuntimeException('Every grade range threshold must be a valid number.');
            }
            $values[$field] = round((float) $raw, 2);
        }
        if (
            $values['pl_min'] > $values['pl_max']
            || $values['dl_min'] > $values['dl_max']
            || $values['pl_max'] >= $values['dl_min']
        ) {
            throw new RuntimeException('Please enter valid, non-overlapping President\'s List and Dean\'s List GWA ranges.');
        }
        $existing = db()->prepare('SELECT id FROM eligibility_rules WHERE semester_id = ? ORDER BY id DESC LIMIT 1');
        $existing->execute([$semesterId]);
        $ruleId = (int) $existing->fetchColumn();
        $params = [
            0,
            $values['disqualify_at'],
            $values['pl_min'],
            $values['pl_max'],
            $values['dl_min'],
            $values['dl_max'],
            trim((string) ($_POST['notes'] ?? '')),
            (int) $user['id'],
        ];
        if ($ruleId > 0) {
            db()->prepare(
                'UPDATE eligibility_rules
                 SET irregular_ineligible = ?, disqualify_at = ?, pl_min = ?, pl_max = ?,
                     dl_min = ?, dl_max = ?, confirmed = 1, is_active = 1, notes = ?, updated_by = ?
                 WHERE id = ?'
            )->execute([...$params, $ruleId]);
        } else {
            db()->prepare(
                'INSERT INTO eligibility_rules
                    (semester_id, irregular_ineligible, disqualify_at, pl_min, pl_max, dl_min, dl_max,
                     confirmed, is_active, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)'
            )->execute([$semesterId, ...$params]);
            $ruleId = (int) db()->lastInsertId();
        }
        $students = db()->query('SELECT id FROM students')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($students as $studentId) {
            refresh_student_evaluation((int) $studentId, $semesterId, (int) $user['id']);
            recognition_sync_candidate((int) $studentId, $semesterId);
        }
        audit((int) $user['id'], 'criteria_update', 'eligibility_rule', (string) $ruleId, 'Semester ' . $semesterId);
        flash('ok', 'Active DL/PL qualification criteria saved and student evaluations recalculated.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/rules.php', ['semester' => $semesterId]);
}

$rules = load_rules($semesterId);

layout_start('DL / PL Criteria', 'rules', [['label' => 'DL / PL criteria']]);
?>
<div class="page-head">
    <div>
        <h1>DL / PL Qualification Criteria</h1>
        <p class="lede">Configure academic GWA cutoffs and disqualification thresholds per semester term.</p>
    </div>
</div>

<!-- Term Filter Toolbar -->
<form class="filters" method="get">
    <div>
        <label for="semester">Target Semester Term</label>
        <select id="semester" name="semester">
            <?php foreach ($semesters as $semester): ?>
                <option value="<?= (int) $semester['id'] ?>" <?= $semesterId === (int) $semester['id'] ? 'selected' : '' ?>>
                    <?= h($semester['label'] . ' ' . $semester['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex; align-items:flex-end;">
        <button class="btn btn-sm" type="submit"><?= icon('search') ?> Load Term Criteria</button>
    </div>
</form>

<!-- Active Criteria Summary Counters -->
<section class="kpis">
    <article class="kpi">
        <span>President's List GWA Target</span>
        <strong><?= fmt_eqv((float)$rules['pl_min']) ?> — <?= fmt_eqv((float)$rules['pl_max']) ?></strong>
    </article>
    <article class="kpi">
        <span>Dean's List GWA Target</span>
        <strong><?= fmt_eqv((float)$rules['dl_min']) ?> — <?= fmt_eqv((float)$rules['dl_max']) ?></strong>
    </article>
    <article class="kpi">
        <span>Disqualifying Grade Limit</span>
        <strong style="color: #d32f2f;">&ge; <?= fmt_eqv((float)$rules['disqualify_at']) ?></strong>
    </article>
</section>

<!-- Criteria Setup Form Card -->
<section class="card">
    <h2><?= icon('settings') ?> Criteria Threshold Settings</h2>
    
    <form method="post" style="margin-top: 14px;" data-offline-save data-offline-label="Criteria settings">
        <?= csrf_field() ?>
        <input type="hidden" name="semester_id" value="<?= $semesterId ?>">
        
        <div class="form-grid">
            <div>
                <label for="pl_min">President's List (PL) Best GWA</label>
                <input id="pl_min" name="pl_min" type="number" min="1" max="5" step=".01" value="<?= h((string) $rules['pl_min']) ?>" required>
            </div>
            <div>
                <label for="pl_max">President's List (PL) Cutoff GWA</label>
                <input id="pl_max" name="pl_max" type="number" min="1" max="5" step=".01" value="<?= h((string) $rules['pl_max']) ?>" required>
            </div>
            <div>
                <label for="dl_min">Dean's List (DL) Best GWA</label>
                <input id="dl_min" name="dl_min" type="number" min="1" max="5" step=".01" value="<?= h((string) $rules['dl_min']) ?>" required>
            </div>
            <div>
                <label for="dl_max">Dean's List (DL) Cutoff GWA</label>
                <input id="dl_max" name="dl_max" type="number" min="1" max="5" step=".01" value="<?= h((string) $rules['dl_max']) ?>" required>
            </div>
            <div class="wide">
                <label for="disqualify_at">Disqualifying Subject Grade Threshold</label>
                <input id="disqualify_at" name="disqualify_at" type="number" min="1" max="5" step=".01" value="<?= h((string) $rules['disqualify_at']) ?>" required>
                <p class="hint">Any single subject grade equal to or worse than this number automatically disqualifies the student.</p>
            </div>
            <div class="wide" style="margin-top: 6px;">
                <label for="notes">Official Policy Notes / Guidelines</label>
                <textarea id="notes" name="notes" rows="3" placeholder="Enter academic policy guidelines or memo reference..."><?= h((string) ($rules['notes'] ?? '')) ?></textarea>
            </div>
            <div class="wide" style="margin-top: 10px;">
                <button class="btn btn-gold" type="submit">Save Active Criteria Rules</button>
            </div>
        </div>
    </form>
</section>

<div class="alert alert-info" style="margin-top: 16px;">
    <strong>System Recalculation Note:</strong> Saving updated criteria automatically triggers a background recalculation of student GWA eligibility for the selected term. Personnel decisions already recorded in the workflow remain intact.
</div>

<?php layout_end(); ?>