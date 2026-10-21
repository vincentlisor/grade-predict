<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';

try {
    db_or_setup();
    $user = current_user();
    if (!$user || ($user['portal'] ?? '') !== 'professor') {
        json_out(['ok' => false, 'message' => 'Your session has ended. Sign in again.'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'message' => 'Use the grading sheet to save scores.'], 405);
    }
    require_csrf();

    $kind = (string) ($_POST['kind'] ?? 'score');
    if ($kind === 'weight') {
        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $period = (string) ($_POST['period'] ?? '');
        $category = (string) ($_POST['category'] ?? '');
        $raw = trim((string) ($_POST['weight'] ?? ''));
        if (!isset(PERIODS[$period]) || !isset(CATEGORIES[$category])) {
            json_out(['ok' => false, 'message' => 'Choose a category on this period.'], 422);
        }
        if ($raw === '' || !is_numeric($raw)) {
            json_out(['ok' => false, 'message' => 'Enter a percent from 0 to 100.'], 422);
        }
        $weight = round((float) $raw, 2);
        if ($weight < 0 || $weight > 100) {
            json_out(['ok' => false, 'message' => 'Keep the percent from 0 to 100.'], 422);
        }
        $assignment = fetch_assignment($assignmentId);
        if (!$assignment || !period_is_editable($assignment, $user, $period)) {
            json_out(['ok' => false, 'message' => (PERIODS[$period] ?? 'This period') . ' is already submitted. You can edit it again if it is returned.'], 403);
        }
        $existing = db()->prepare('SELECT category, weight_percent FROM category_weights WHERE assignment_id = ? AND period = ?');
        $existing->execute([$assignmentId, $period]);
        $total = 0.0;
        $found = false;
        foreach ($existing->fetchAll() as $row) {
            if ($row['category'] === $category) {
                $found = true;
                $total += $weight;
            } else {
                $total += (float) $row['weight_percent'];
            }
        }
        if (!$found) {
            $total += $weight;
        }
        $total = round($total, 2);
        if (abs($total - 100) > 0.01) {
            $shown = rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.');
            $message = $total > 100
                ? 'These percents add up to ' . $shown . '%. A period cannot go over 100%.'
                : 'These percents add up to ' . $shown . '%. Put the other percents so this period adds up to 100%.';
            json_out(['ok' => false, 'message' => $message], 422);
        }
        $updated = db()->prepare('UPDATE category_weights SET weight_percent = ? WHERE assignment_id = ? AND period = ? AND category = ?');
        $updated->execute([$weight, $assignmentId, $period, $category]);
        if ($updated->rowCount() < 1) {
            $exists = db()->prepare('SELECT id FROM category_weights WHERE assignment_id = ? AND period = ? AND category = ?');
            $exists->execute([$assignmentId, $period, $category]);
            if (!$exists->fetch()) {
                db()->prepare('INSERT INTO category_weights (assignment_id, period, category, weight_percent) VALUES (?, ?, ?, ?)')
                    ->execute([$assignmentId, $period, $category, $weight]);
            }
        }
        audit((int) $user['id'], 'grade_edit', 'category_weight', (string) $assignmentId, $period . ' ' . $category . ' = ' . $weight);
        json_out(['ok' => true, 'weight' => $weight]);
    }
    if ($kind === 'weights') {
        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $period = (string) ($_POST['period'] ?? '');
        $posted = $_POST['weight'] ?? null;
        if (!isset(PERIODS[$period]) || !is_array($posted) || $posted === []) {
            json_out(['ok' => false, 'message' => 'Set every category so this period adds up to 100%.'], 422);
        }
        $assignment = fetch_assignment($assignmentId);
        if (!$assignment || !period_is_editable($assignment, $user, $period)) {
            json_out(['ok' => false, 'message' => (PERIODS[$period] ?? 'This period') . ' is already submitted. You can edit it again if it is returned.'], 403);
        }
        $kept = [];
        foreach ($posted as $category => $raw) {
            $category = (string) $category;
            if (!isset(CATEGORIES[$category])) {
                continue;
            }
            if (!is_numeric($raw)) {
                json_out(['ok' => false, 'message' => 'Percents must be numbers.'], 422);
            }
            $percent = round((float) $raw, 2);
            if ($percent < 0 || $percent > 100) {
                json_out(['ok' => false, 'message' => 'Each percent must be from 0 to 100.'], 422);
            }
            $kept[$category] = $percent;
        }
        if ($kept === []) {
            json_out(['ok' => false, 'message' => 'Set every category so this period adds up to 100%.'], 422);
        }
        $total = round(array_sum($kept), 2);
        if (abs($total - 100) > 0.01) {
            $shown = rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.');
            $message = $total > 100
                ? 'These percents add up to ' . $shown . '%. A period cannot go over 100%.'
                : 'These percents add up to ' . $shown . '%. Put the other percents so this period adds up to 100%.';
            json_out(['ok' => false, 'message' => $message], 422);
        }
        $existing = db()->prepare('SELECT category, weight_percent FROM category_weights WHERE assignment_id = ? AND period = ?');
        $existing->execute([$assignmentId, $period]);
        foreach ($existing->fetchAll() as $row) {
            if ((float) $row['weight_percent'] > 0 && !isset($kept[$row['category']])) {
                json_out(['ok' => false, 'message' => 'Set every category on this period so they add up to 100%.'], 422);
            }
        }
        $pdo = db();
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE category_weights SET weight_percent = ? WHERE assignment_id = ? AND period = ? AND category = ?');
        $insert = $pdo->prepare('INSERT INTO category_weights (assignment_id, period, category, weight_percent) VALUES (?, ?, ?, ?)');
        foreach ($kept as $category => $percent) {
            $update->execute([$percent, $assignmentId, $period, $category]);
            if ($update->rowCount() < 1) {
                $exists = $pdo->prepare('SELECT id FROM category_weights WHERE assignment_id = ? AND period = ? AND category = ?');
                $exists->execute([$assignmentId, $period, $category]);
                if (!$exists->fetch()) {
                    $insert->execute([$assignmentId, $period, $category, $percent]);
                }
            }
        }
        $pdo->commit();
        audit((int) $user['id'], 'grade_edit', 'category_weight', (string) $assignmentId, $period . ' percents now add up to 100');
        json_out(['ok' => true, 'weights' => $kept]);
    }
    if ($kind === 'batch') {
        $items = json_decode((string) ($_POST['scores'] ?? ''), true);
        if (!is_array($items) || $items === []) {
            json_out(['ok' => false, 'message' => 'There are no scores to sync.'], 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        $written = [];
        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            try {
                $meta = save_one_score($user, $fields);
                $written[] = [
                    'id' => $id,
                    'fields' => $fields,
                    'assignment_id' => $meta['assignment_id'],
                    'enrollment_id' => $meta['enrollment_id'],
                ];
            } catch (RuntimeException $e) {
                $results[] = ['id' => $id, 'ok' => false, 'fields' => $fields, 'message' => $e->getMessage()];
            }
        }
        $pdo->commit();
        $books = [];
        foreach ($written as $row) {
            $assignmentId = $row['assignment_id'];
            if (!isset($books[$assignmentId])) {
                $books[$assignmentId] = build_gradebook($assignmentId);
            }
            $student = find_student_row($books[$assignmentId], $row['enrollment_id']);
            $results[] = [
                'id' => $row['id'],
                'ok' => true,
                'fields' => $row['fields'],
                'student' => $student ? student_payload($student) : null,
            ];
        }
        json_out(['ok' => true, 'results' => $results]);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $meta = save_one_score($user, $_POST);
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;
        json_out(['ok' => false, 'message' => $e->getMessage()], $status);
    }
    $book = build_gradebook($meta['assignment_id']);
    $student = find_student_row($book, $meta['enrollment_id']);
    json_out(['ok' => true, 'student' => $student ? student_payload($student) : null]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log($e->getMessage());
    json_out(['ok' => false, 'message' => APP_DEBUG ? $e->getMessage() : 'The score could not be saved.'], 500);
}
