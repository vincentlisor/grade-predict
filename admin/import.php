<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'import');

if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="masterlist-sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['student_number', 'full_name', 'student_type', 'program', 'year_level', 'section']);
    fputcsv($out, ['01-26-10999', 'Sample, Student A', 'Regular', 'BSIT', '4th Year', '401-C']);
    fclose($out);
    exit;
}

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $file = $_FILES['csv'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a valid CSV file to upload.');
        redirect('admin/import.php');
    }
    if (($file['size'] ?? 0) > 2_000_000) {
        flash('error', 'The uploaded file must be 2 MB or smaller.');
        redirect('admin/import.php');
    }
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        flash('error', 'The file could not be read.');
        redirect('admin/import.php');
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        flash('error', 'The CSV file is empty.');
        redirect('admin/import.php');
    }
    $map = [];
    foreach ($header as $index => $column) {
        $map[strtolower(trim((string) $column))] = $index;
    }
    foreach (['student_number', 'full_name', 'program', 'year_level', 'section', 'student_type'] as $required) {
        if (!isset($map[$required])) {
            fclose($handle);
            flash('error', 'Missing required column header: ' . $required);
            redirect('admin/import.php');
        }
    }
    $programs = [];
    foreach (db()->query('SELECT id, code FROM programs') as $row) {
        $programs[strtoupper($row['code'])] = (int) $row['id'];
    }
    $added = 0;
    $duplicates = 0;
    $rejected = [];
    $line = 1;
    $insert = db()->prepare(
        'INSERT INTO students (student_number, full_name, student_type, program_id, year_level, section_id, is_demo)
         VALUES (?, ?, ?, ?, ?, ?, 0)'
    );
    $exists = db()->prepare('SELECT id FROM students WHERE student_number = ?');
    $sectionLookup = db()->prepare('SELECT id FROM sections WHERE program_id = ? AND year_level = ? AND name = ?');
    db()->beginTransaction();
    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if (count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $number = trim((string) ($row[$map['student_number']] ?? ''));
        $name = trim((string) ($row[$map['full_name']] ?? ''));
        $type = trim((string) ($row[$map['student_type']] ?? ''));
        $programCode = strtoupper(trim((string) ($row[$map['program']] ?? '')));
        $year = trim((string) ($row[$map['year_level']] ?? ''));
        $sectionName = trim((string) ($row[$map['section']] ?? ''));
        if ($number === '' || $name === '') {
            $rejected[] = "Row $line: Missing student number or full name.";
            continue;
        }
        if (!in_array($type, ['Regular', 'Irregular'], true)) {
            $rejected[] = "Row $line: Invalid student type '$type'. Must be Regular or Irregular.";
            continue;
        }
        if (!isset($programs[$programCode])) {
            $rejected[] = "Row $line: Unknown program code '$programCode'.";
            continue;
        }
        $sectionLookup->execute([$programs[$programCode], $year, $sectionName]);
        $section = $sectionLookup->fetch();
        if (!$section) {
            $rejected[] = "Row $line: Section '$sectionName' not found for $programCode $year.";
            continue;
        }
        $exists->execute([$number]);
        if ($exists->fetch()) {
            $duplicates++;
            continue;
        }
        $insert->execute([$number, $name, $type, $programs[$programCode], $year, (int) $section['id']]);
        $added++;
    }
    fclose($handle);
    db()->commit();
    audit((int) $user['id'], 'import', 'students', null, "Added $added, duplicates $duplicates, rejected " . count($rejected));
    $report = compact('added', 'duplicates', 'rejected');
}

layout_start('Import Masterlist', 'import', [['label' => 'Import Masterlist']]);
?>
<div class="page-head">
    <div>
        <h1>Import Student Masterlist</h1>
        <p class="lede">Batch import official student records directly into the masterlist from a CSV file.</p>
    </div>
    <div>
        <a class="btn btn-ghost btn-sm" href="<?= h(url('admin/import.php', ['sample' => 1])) ?>">
            <?= icon('download') ?> Download Sample CSV Template
        </a>
    </div>
</div>

<section class="split">
    <!-- CSV Upload Box -->
    <article class="card">
        <h2><?= icon('import') ?> CSV Upload Tool</h2>
        <form method="post" enctype="multipart/form-data" style="margin-top: 14px;">
            <?= csrf_field() ?>
            <label for="csv">Select CSV File</label>
            <input id="csv" name="csv" type="file" accept=".csv,text/csv" required style="padding: 10px; margin-bottom: 12px; width: 100%;">
            <p class="hint" style="margin-bottom: 16px;">Maximum file size: 2 MB. Ensure columns match the exact template headers.</p>
            <button class="btn btn-gold" type="submit"><?= icon('import') ?> Start Import Process</button>
        </form>
    </article>

    <!-- Header Instructions Card -->
    <article class="card">
        <h2><?= icon('sheet') ?> Required CSV Headers</h2>
        <p class="hint">The first row of your CSV file must contain the following case-insensitive column names:</p>
        <ul style="margin-top: 8px; padding-left: 20px; line-height: 1.6;">
            <li><code>student_number</code> — e.g. <code>08-15-41258</code></li>
            <li><code>full_name</code> — e.g. <code>Agpoon, Cyrus</code></li>
            <li><code>student_type</code> — Must be <code>Regular</code> or <code>Irregular</code></li>
            <li><code>program</code> — Program code e.g. <code>BSIT</code> or <code>BSHM</code></li>
            <li><code>year_level</code> — e.g. <code>4th Year</code></li>
            <li><code>section</code> — Assigned section e.g. <code>401-C</code></li>
        </ul>
        <div class="alert alert-info" style="margin-top: 14px; font-size: 13px;">
            <strong>Note:</strong> Existing student numbers will be automatically skipped to prevent duplicate records. Unknown programs or sections will be rejected.
        </div>
    </article>
</section>

<?php if ($report): ?>
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('reports') ?> Batch Import Summary Report</h2>
    <div class="kpis" style="margin-top: 12px; margin-bottom: 16px;">
        <article class="kpi">
            <span>Successfully Added</span>
            <strong style="color: #2e7d32;"><?= (int) $report['added'] ?></strong>
        </article>
        <article class="kpi">
            <span>Duplicates Skipped</span>
            <strong><?= (int) $report['duplicates'] ?></strong>
        </article>
        <article class="kpi">
            <span>Rejected Rows</span>
            <strong style="color: <?= count($report['rejected']) > 0 ? '#d32f2f' : 'inherit' ?>;"><?= count($report['rejected']) ?></strong>
        </article>
    </div>

    <?php if ($report['rejected'] === []): ?>
        <div class="alert alert-ok">
            <strong>Great news!</strong> All valid rows were processed cleanly without any rejections.
        </div>
    <?php else: ?>
        <h3 style="margin-top: 14px; color: #d32f2f;">Rejection Details</h3>
        <ul style="padding-left: 20px; color: #c62828; font-size: 14px; line-height: 1.6;">
            <?php foreach ($report['rejected'] as $line): ?>
                <li><?= h($line) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php layout_end(); ?>