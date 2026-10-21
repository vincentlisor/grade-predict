<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recognition.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'recognition');

if (recognition_actor($user) !== 'registrar' && !in_array('system_admin', $user['roles'], true)) {
    http_response_code(403);
    exit('Only authorized personnel can open the Official Masterlist and certificates.');
}

$semesterId = (int) ($_GET['semester'] ?? 0);
if ($semesterId <= 0) {
    $semesterId = (int) db()->query('SELECT id FROM semesters WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
}

$doc = ($_GET['doc'] ?? '') === 'certificates' ? 'certificates' : 'masterlist';

// Fetch candidates
$rows = recognition_official($semesterId);

audit((int) $user['id'], $doc === 'certificates' ? 'recognition_certificates' : 'recognition_masterlist', 'semester', (string) $semesterId, count($rows) . ' verified students');

$term = $rows[0]['year_label'] ?? '';
$semesterName = $rows[0]['semester_name'] ?? '';

if ($term === '') {
    $meta = db()->prepare('SELECT ay.label, sem.name FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id WHERE sem.id = ?');
    $meta->execute([$semesterId]);
    $meta = $meta->fetch();
    $term = (string) ($meta['label'] ?? '');
    $semesterName = (string) ($meta['name'] ?? '');
}

// Group candidates by Program and Year Level for the Masterlist view
$groupedRows = [];
foreach ($rows as $row) {
    $programKey = ($row['program_code'] ?? 'GENERAL') . ' - Year ' . ($row['year_level'] ?? '1');
    $groupedRows[$programKey][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $doc === 'certificates' ? 'Certificates' : 'Official Masterlist' ?> · <?= h(defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Systems Plus Computer College') ?></title>
    <link rel="icon" href="<?= h(url('img/logo.png')) ?>">
    <style>
        body { margin: 0; background: #F5F7FA; color: #142B49; font-family: "Segoe UI", sans-serif; }
        .toolbar { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 16px 24px; background: #142B49; color: #fff; }
        .toolbar a, .toolbar button { color: #142B49; background: #C5A45B; border: 0; border-radius: 8px; padding: 8px 14px; text-decoration: none; font: inherit; cursor: pointer; font-weight: 600; }
        .sheet { max-width: 980px; margin: 24px auto; background: #fff; padding: 32px; box-shadow: 0 10px 30px rgba(20,43,73,.08); }
        h1 { font-weight: 500; margin: 8px 0; }
        .group-header { margin-top: 28px; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 2px solid #C5A45B; color: #142B49; display: flex; justify-content: space-between; align-items: flex-end; }
        .group-header h2 { font-size: 16px; margin: 0; text-transform: uppercase; letter-spacing: .02em; }
        .group-header span { font-size: 13px; color: #5C6B7A; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #E2E8F0; font-size: 14px; }
        th { font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: #5C6B7A; background-color: #F8FAFC; }
        .cert { min-height: 640px; border: 8px solid #142B49; padding: 48px; text-align: center; page-break-after: always; }
        .cert:last-child { page-break-after: auto; }
        .gold { color: #8C6B2F; letter-spacing: .14em; text-transform: uppercase; font-size: 13px; font-weight: 600; }
        .name { font-size: 36px; font-weight: 600; margin: 18px 0 8px; color: #142B49; }
        .signs { display: flex; justify-content: space-between; gap: 24px; margin-top: 64px; text-align: center; }
        .signs div { flex: 1; border-top: 1px solid #142B49; padding-top: 8px; font-size: 13px; }
        img.logo { width: 72px; height: 72px; object-fit: contain; }
        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; width: 100%; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <strong><?= $doc === 'certificates' ? 'Certificates of Recognition' : 'Official Masterlist' ?></strong>
    <div>
        <button type="button" onclick="window.print()">Print Document</button>
        <a href="<?= h(url('admin/recognition.php', ['semester' => $semesterId])) ?>">Back to Recognition</a>
    </div>
</div>

<?php if ($doc === 'masterlist'): ?>
<main class="sheet">
    <img class="logo" src="<?= h(url('img/logo.png')) ?>" alt="Logo">
    <div class="gold"><?= h(defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Systems Plus Computer College') ?></div>
    <h1>Official Masterlist</h1>
    <p><strong>Dean’s List and President’s List</strong> · <?= h(trim($term . ' ' . $semesterName)) ?></p>
    <p class="hint">Students listed here are officially endorsed candidates by the Program Chair.</p>
    
    <?php if ($rows === []): ?>
        <p>No honor students recorded for this semester yet.</p>
    <?php else: ?>
        <?php foreach ($groupedRows as $groupName => $candidates): ?>
            <div class="group-header">
                <h2><?= h($groupName) ?></h2>
                <span>Total Candidates: <?= count($candidates) ?></span>
            </div>
            <table>
                <thead>
                    <tr><th style="width: 40px;">No.</th><th>Student Details</th><th>Classification</th><th style="width: 80px;">GWA</th></tr>
                </thead>
                <tbody>
                <?php foreach ($candidates as $index => $row): ?>
                    <?php 
                    $listType = (string) ($row['list_type'] ?? '');
                    $listLabel = function_exists('recognition_list_label') ? recognition_list_label($listType) : ($listType ?: "Dean's List");
                    ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= h($row['full_name']) ?></strong><br><small><?= h($row['student_number']) ?></small></td>
                        <td><strong><?= h($listLabel) ?></strong></td>
                        <td><?= h(fmt_eqv(isset($row['gwa']) ? (float) $row['gwa'] : 1.25)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<?php else: ?>

<?php if ($rows === []): ?>
<main class="sheet"><p>No honor students found for this semester, so there are no certificates to generate.</p></main>
<?php endif; ?>

<?php foreach ($rows as $row): ?>
<?php 
$listType = (string) ($row['list_type'] ?? '');
$listLabel = function_exists('recognition_list_label') ? recognition_list_label($listType) : ($listType ?: "Dean's List");
?>
<main class="sheet cert">
    <img class="logo" src="<?= h(url('img/logo.png')) ?>" alt="Logo">
    <div class="gold"><?= h(defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Systems Plus Computer College') ?></div>
    <h1>Certificate of Recognition</h1>
    <p>This certifies that</p>
    <div class="name"><?= h($row['full_name']) ?></div>
    <p><?= h($row['student_number']) ?> · <?= h($row['program_code'] ?? '') ?></p>
    <p>is officially included in the <strong><?= h($listLabel) ?></strong><br>for <?= h(trim($term . ' ' . $semesterName)) ?> with a General Weighted Average (GWA) of <strong><?= h(fmt_eqv(isset($row['gwa']) ? (float) $row['gwa'] : 1.25)) ?></strong>.</p>
    <p>Officially endorsed and approved by the Program Chair with oversight monitoring by College OIC, OSA, and College Registrar.</p>
    
    <div class="signs">
        <div>
            <strong><?= h(trim((string)($row['chair_title'] ?? '') . ' ' . (string)($row['chair_name'] ?? ''))) ?: 'Program Chair' ?></strong><br>Program Chair
        </div>
        <div>
            <strong><?= h(trim((string)($row['oic_title'] ?? '') . ' ' . (string)($row['oic_name'] ?? ''))) ?: 'College OIC' ?></strong><br>College OIC
        </div>
        <div>
            <strong><?= h(trim((string)($row['osa_title'] ?? '') . ' ' . (string)($row['osa_name'] ?? ''))) ?: 'Office of Student Affairs' ?></strong><br>Office of Student Affairs
        </div>
        <div>
            <strong><?= h(trim((string)($row['registrar_title'] ?? '') . ' ' . (string)($row['registrar_name'] ?? ''))) ?: 'College Registrar' ?></strong><br>College Registrar
        </div>
    </div>
</main>
<?php endforeach; ?>

<?php endif; ?>
</body>
</html>