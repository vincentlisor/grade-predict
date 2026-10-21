<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_user();
$id = (int) ($_GET['id'] ?? $_POST['assignment_id'] ?? 0);
$assignment = $id ? fetch_assignment($id) : null;
if (!$assignment || !user_can_view_assignment($assignment, $user)) {
    http_response_code(404);
    exit('This class is not on your list.');
}

$view = (string) ($_GET['view'] ?? 'sheet');
if (!in_array($view, ['sheet', 'summary', 'final', 'settings', 'equivalent'], true)) {
    $view = 'sheet';
}
$period = (string) ($_GET['period'] ?? 'prelim');
if (!isset(PERIODS[$period])) {
    $period = 'prelim';
}
$periodEditable = period_is_editable($assignment, $user, $period);
$structureEditable = structure_is_editable($assignment, $user);
$editable = in_array($view, ['sheet', 'summary'], true) ? $periodEditable : $structureEditable;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($user['portal'] ?? '') !== 'professor' || !in_array('professor', $user['roles'], true)) {
        http_response_code(403);
        exit('Grade changes must be submitted from the Professor portal.');
    }
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'submit') {
            $scope = (string) ($_POST['submit_scope'] ?? '');
            $periods = $scope === 'all' ? ['all'] : [$scope];
            $sent = submit_assignment($user, $id, $periods);
            flash('ok', $sent . ' was submitted to the Program Chair.');
        } elseif (!$structureEditable) {
            throw new RuntimeException('Column changes stay locked while a period is under review. You can still encode a period that has not been submitted.');
        } elseif ($action === 'restore_category') {
            $catKey = (string) ($_POST['new_category'] ?? '');
            restore_category($id, $catKey, (string) ($_POST['new_period'] ?? ''), $_POST['weight'] ?? null);
            $catName = CATEGORIES[$catKey] ?? 'Category';
            audit((int) $user['id'], 'restore_category', 'professor_assignment', (string) $id, 'Restored ' . $catName);
            flash('ok', $catName . ' is back. The categories add up to 100%.');
        } elseif ($action === 'add_component') {
            $added = add_component($id, $_POST);
            $catKey = (string) ($_POST['new_category'] ?? '');
            $catName = [
                'attendance' => 'week',
                'recitation' => 'recitation',
                'activities' => 'activity',
                'quizzes'    => 'quiz',
                'project'    => 'project',
                'exam'       => 'exam',
            ][$catKey] ?? 'column';
            audit((int) $user['id'], 'add_column', 'professor_assignment', (string) $id, 'Added ' . $added . ' ' . $catName . ' column(s)');
            flash('ok', $added === 1 ? 'Added 1 ' . $catName . '.' : 'Added ' . $added . ' ' . $catName . ' columns.');
        } elseif ($action === 'delete_component') {
            $cleared = delete_component($id, (int) ($_POST['component_id'] ?? 0));
            audit((int) $user['id'], 'delete_column', 'professor_assignment', (string) $id, 'Removed one column');
            flash('ok', $cleared ? 'Column removed. That category and its percent are off this period.' : 'Column removed, including its scores.');
        } elseif ($action === 'delete_category') {
            $category = (string) ($_POST['category'] ?? '');
            $scope = (string) ($_POST['column_period'] ?? '');
            $kept = rebalance_weights($category, $_POST['weight'] ?? null);
            $removed = delete_category_columns($id, $category, $scope);
            foreach (column_periods($scope) as $weightPeriod) {
                foreach ($kept as $keptCategory => $percent) {
                    set_category_percent($id, $weightPeriod, $keptCategory, $percent);
                }
            }
            $catName = CATEGORIES[$category] ?? 'Category';
            audit((int) $user['id'], 'delete_category_columns', 'professor_assignment', (string) $id, 'Removed ' . $removed . ' ' . $catName . ' column(s)');
            flash('ok', $catName . ' was removed. The categories that stayed add up to 100%.');
        } elseif ($action === 'save_settings') {
            $scope = (string) ($_POST['apply_scope'] ?? 'all');
            
            if ($scope === 'all') {
                $targetPeriods = array_keys(PERIODS);
            } else {
                $targetPeriods = isset(PERIODS[$scope]) ? [$scope] : [$period];
            }

            if (isset($_POST['weights']) && is_array($_POST['weights'])) {
                $total = 40.0; // Fixed Exam weight at 40%
                foreach ($_POST['weights'] as $cat => $w) {
                    if ($cat !== 'exam') {
                        $total += (float) $w;
                    }
                }
                if (abs($total - 100.0) > 0.01) {
                    throw new RuntimeException('The total configured weight must equal exactly 100%.');
                }

                foreach ($targetPeriods as $p) {
                    foreach ($_POST['weights'] as $cat => $w) {
                        if (isset(CATEGORIES[$cat])) {
                            set_category_percent($id, $p, $cat, (float) $w);
                        }
                    }
                }
            }

            $msg = ($scope === 'all') 
                ? 'Settings applied across ALL periods (Prelim, Midterm, & Finals).' 
                : 'Settings updated for ' . strtoupper($scope) . ' period only.';
            flash('ok', $msg);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('professor/class.php', sheet_query(['id' => $id, 'view' => $view, 'period' => $period]));
}

$book = build_gradebook($id);
if ($view === 'final') {
    store_advisory_predictions($book);

    require_once __DIR__ . '/../includes/ml_gwa.php';

    foreach ($book['students'] as &$student) {
        $student['semester_prediction'] = ml_student_semester_prediction(
            (int) $student['student_id'],
            (int) $assignment['semester_id']
        );
    }

    unset($student);
}

$sheetTabs = [
    'sheet'      => ['Grading sheet', 'sheet'],
    'summary'    => ['Summary', 'summary'],
    'final'      => ['Final grade(s)', 'final'],
    'settings'   => ['Settings & Format', 'sliders'],
    'equivalent' => ['Grade Equivalent', 'award'],
];
$sideItems = [];
foreach ($sheetTabs as $key => $tab) {
    $sideItems[] = [
        'id'    => $key,
        'href'  => 'professor/class.php',
        'query' => sheet_query(['id' => $assignment['id'], 'view' => $key, 'period' => $period]),
        'icon'  => $tab[1],
        'label' => $tab[0],
    ];
}
layout_start(
    $assignment['subject_code'] . ' · ' . $assignment['section_name'],
    $view,
    [
        ['label' => 'My classes', 'href' => 'professor/classes.php'],
        ['label' => $assignment['subject_code'] . ' ' . $assignment['section_name']],
    ],
    'page-sheet',
    [
        'label' => $assignment['subject_code'] . ' · ' . $assignment['section_name'],
        'items' => $sideItems,
    ]
);
require __DIR__ . '/views/class_head.php';
if ($view === 'summary') {
    require __DIR__ . '/views/summary.php';
} elseif ($view === 'final') {
    require __DIR__ . '/views/final.php';
} elseif ($view === 'settings') {
    require __DIR__ . '/views/settings.php';
} elseif ($view === 'equivalent') {
    require __DIR__ . '/views/equivalent.php';
} else {
    require __DIR__ . '/views/sheet.php';
}
layout_end('assets/js/gradebook.js');

function component_defaults(string $category, int $number): array
{
    return match ($category) {
        'attendance' => ['code' => 'WK' . $number, 'label' => 'Attendance ' . $number, 'max' => 10.0],
        'recitation' => ['code' => 'REC#' . $number, 'label' => 'Recitation ' . $number, 'max' => 10.0],
        'activities' => ['code' => 'ACT#' . $number, 'label' => 'Activity ' . $number, 'max' => 10.0],
        'quizzes'    => ['code' => 'Q' . $number, 'label' => 'Quiz ' . $number, 'max' => 20.0],
        'project'    => [
            'code'  => $number === 1 ? 'PR' : 'PR' . $number,
            'label' => $number === 1 ? 'Project' : 'Project ' . $number,
            'max'   => 100.0,
        ],
        'exam'       => [
            'code'  => $number === 1 ? 'EX' : 'EX' . $number,
            'label' => $number === 1 ? 'Exam' : 'Exam ' . $number,
            'max'   => 100.0,
        ],
        default      => throw new RuntimeException('Choose a category.'),
    };
}

function next_component_number(int $assignmentId, string $period, string $category): int
{
    $stmt = db()->prepare('SELECT code FROM assessment_components WHERE assignment_id = ? AND period = ? AND category = ?');
    $stmt->execute([$assignmentId, $period, $category]);
    $max = 0;
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $count++;
        if (preg_match('/(\d+)/', (string) $code, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return max($max, $count) + 1;
}

function rebalance_weights(string $removed, mixed $posted): array
{
    if (!isset(CATEGORIES[$removed])) {
        throw new RuntimeException('Choose a category.');
    }
    if (!is_array($posted) || $posted === []) {
        throw new RuntimeException('Set the other percents so they add up to 100% before removing this category.');
    }
    $kept = [];
    foreach ($posted as $category => $raw) {
        $category = (string) $category;
        if (!isset(CATEGORIES[$category]) || $category === $removed) {
            continue;
        }
        if (!is_numeric($raw)) {
            throw new RuntimeException('Percents must be numbers.');
        }
        $percent = round((float) $raw, 2);
        if ($percent < 0 || $percent > 100) {
            throw new RuntimeException('Each percent must be from 0 to 100.');
        }
        $kept[$category] = $percent;
    }
    if ($kept === []) {
        throw new RuntimeException('Keep at least one category, and set its percent to 100%.');
    }
    $total = round(array_sum($kept), 2);
    if (abs($total - 100.0) > 0.01) {
        throw new RuntimeException('The categories that stay must add up to 100% before this one can be removed.');
    }
    return $kept;
}

function column_periods(string $scope): array
{
    if ($scope === 'all') {
        return array_keys(PERIODS);
    }
    if (!isset(PERIODS[$scope])) {
        throw new RuntimeException('Choose a period.');
    }
    return [$scope];
}

function add_component(int $assignmentId, array $post): int
{
    $category = (string) ($post['new_category'] ?? '');
    if (!isset(CATEGORIES[$category])) {
        throw new RuntimeException('Choose a category.');
    }
    $periods = column_periods((string) ($post['new_period'] ?? ''));
    $customLabel = trim((string) ($post['new_label'] ?? ''));
    $customMax = trim((string) ($post['new_max'] ?? ''));
    $pdo = db();
    $insert = $pdo->prepare(
        'INSERT INTO assessment_components (assignment_id, period, category, code, label, max_score, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $exists = $pdo->prepare('SELECT id FROM assessment_components WHERE assignment_id = ? AND period = ? AND category = ? AND code = ?');
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM assessment_components WHERE assignment_id = ? AND period = ? AND category = ?');
    $added = 0;
    foreach ($periods as $period) {
        $countStmt->execute([$assignmentId, $period, $category]);
        if ((int) $countStmt->fetchColumn() === 0) {
            throw new RuntimeException(CATEGORIES[$category] . ' is not on ' . (PERIODS[$period] ?? 'that period') . '. Add it back, then set the percents so they add up to 100%.');
        }
    }
    foreach ($periods as $period) {
        $meta = component_defaults($category, next_component_number($assignmentId, $period, $category));
        $label = $customLabel !== '' ? $customLabel : $meta['label'];
        if ($customMax !== '' && !is_numeric($customMax)) {
            throw new RuntimeException('Number of items must be greater than 0.');
        }
        $maxScore = $customMax === '' ? (float) $meta['max'] : round((float) $customMax, 2);
        if ($maxScore <= 0 || $maxScore > 1000) {
            throw new RuntimeException('Number of items must be greater than 0.');
        }
        $code = $meta['code'];
        if ($customLabel !== '') {
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $customLabel) ?: 'ITEM', 0, 12));
        }
        $base = $code;
        $suffix = 1;
        while (true) {
            $exists->execute([$assignmentId, $period, $category, $code]);
            if (!$exists->fetch()) {
                break;
            }
            $suffix++;
            $code = $base . $suffix;
            if ($suffix > 40) {
                throw new RuntimeException('Could not create a unique column code.');
            }
        }
        $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM assessment_components WHERE assignment_id = ?');
        $sortStmt->execute([$assignmentId]);
        $sort = (int) $sortStmt->fetchColumn();
        $insert->execute([$assignmentId, $period, $category, $code, $label, $maxScore, $sort]);
        $added++;
    }
    return $added;
}

function set_category_percent(int $assignmentId, string $period, string $category, float $percent): void
{
    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM category_weights WHERE assignment_id = ? AND period = ? AND category = ?');
    $exists->execute([$assignmentId, $period, $category]);
    if ($exists->fetch()) {
        $pdo->prepare('UPDATE category_weights SET weight_percent = ? WHERE assignment_id = ? AND period = ? AND category = ?')
            ->execute([$percent, $assignmentId, $period, $category]);
        return;
    }
    $pdo->prepare('INSERT INTO category_weights (assignment_id, period, category, weight_percent) VALUES (?, ?, ?, ?)')
        ->execute([$percent, $assignmentId, $period, $category]);
}

function added_weights(string $added, mixed $posted): array
{
    if (!isset(CATEGORIES[$added])) {
        throw new RuntimeException('Choose a category.');
    }
    if (!is_array($posted) || $posted === []) {
        throw new RuntimeException('Set the percents so they add up to 100% before adding this category.');
    }
    $kept = [];
    foreach ($posted as $category => $raw) {
        $category = (string) $category;
        if (!isset(CATEGORIES[$category])) {
            continue;
        }
        if (!is_numeric($raw)) {
            throw new RuntimeException('Percents must be numbers.');
        }
        $percent = round((float) $raw, 2);
        if ($percent < 0 || $percent > 100) {
            throw new RuntimeException('Each percent must be from 0 to 100.');
        }
        $kept[$category] = $percent;
    }
    if (!isset($kept[$added])) {
        throw new RuntimeException('Set a percent for ' . CATEGORIES[$added] . '.');
    }
    $total = round(array_sum($kept), 2);
    if (abs($total - 100.0) > 0.01) {
        throw new RuntimeException('The categories must add up to 100% before this one can be added.');
    }
    return $kept;
}

function restore_category(int $assignmentId, string $category, string $scope, mixed $posted): array
{
    if (!isset(CATEGORIES[$category])) {
        throw new RuntimeException('Choose a category.');
    }
    $weights = added_weights($category, $posted);
    $periods = column_periods($scope);
    $percent = $weights[$category];
    $template = array_values(array_filter(
        default_component_template(),
        static fn (array $item): bool => $item['category'] === $category
    ));
    if ($template === []) {
        throw new RuntimeException('That category has no columns to restore.');
    }
    $pdo = db();
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM assessment_components WHERE assignment_id = ? AND period = ? AND category = ?');
    $insert = $pdo->prepare(
        'INSERT INTO assessment_components (assignment_id, period, category, code, label, max_score, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $restored = 0;
    foreach ($periods as $period) {
        $countStmt->execute([$assignmentId, $period, $category]);
        if ((int) $countStmt->fetchColumn() > 0) {
            continue;
        }
        foreach ($template as $item) {
            $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM assessment_components WHERE assignment_id = ?');
            $sortStmt->execute([$assignmentId]);
            $insert->execute([
                $assignmentId,
                $period,
                $category,
                $item['code'],
                $item['label'],
                $item['max_score'],
                (int) $sortStmt->fetchColumn(),
            ]);
        }
        foreach ($weights as $weightCategory => $weightPercent) {
            set_category_percent($assignmentId, $period, $weightCategory, $weightPercent);
        }
        $restored++;
    }
    if ($restored < 1) {
        throw new RuntimeException(CATEGORIES[$category] . ' is already on that period.');
    }
    return ['periods' => $restored, 'percent' => $percent];
}

function delete_component(int $assignmentId, int $componentId): bool
{
    $stmt = db()->prepare('SELECT id, period, category FROM assessment_components WHERE id = ? AND assignment_id = ?');
    $stmt->execute([$componentId, $assignmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('That column is not on this class.');
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM assessment_components WHERE id = ?')->execute([$componentId]);
    $left = $pdo->prepare('SELECT COUNT(*) FROM assessment_components WHERE assignment_id = ? AND period = ? AND category = ?');
    $left->execute([$assignmentId, $row['period'], $row['category']]);
    if ((int) $left->fetchColumn() > 0) {
        return false;
    }
    $pdo->prepare('UPDATE category_weights SET weight_percent = 0 WHERE assignment_id = ? AND period = ? AND category = ?')
        ->execute([$assignmentId, $row['period'], $row['category']]);
    return true;
}

function delete_category_columns(int $assignmentId, string $category, string $scope): int
{
    if (!isset(CATEGORIES[$category])) {
        throw new RuntimeException('Choose a category.');
    }
    $periods = column_periods($scope);
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($periods), '?'));
    $params = array_merge([$assignmentId, $category], $periods);
    $weightStmt = $pdo->prepare("SELECT COALESCE(SUM(weight_percent), 0) FROM category_weights WHERE assignment_id = ? AND category = ? AND period IN ($placeholders)");
    $weightStmt->execute($params);
    $hadWeight = (float) $weightStmt->fetchColumn() > 0;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("DELETE FROM assessment_components WHERE assignment_id = ? AND category = ? AND period IN ($placeholders)");
    $stmt->execute($params);
    $removed = $stmt->rowCount();
    if ($removed < 1 && !$hadWeight) {
        $pdo->rollBack();
        throw new RuntimeException('There is no ' . CATEGORIES[$category] . ' percent in that period.');
    }
    $pdo->prepare("UPDATE category_weights SET weight_percent = 0 WHERE assignment_id = ? AND category = ? AND period IN ($placeholders)")
        ->execute($params);
    $pdo->commit();
    return $removed;
}