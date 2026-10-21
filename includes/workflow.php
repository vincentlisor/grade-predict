<?php
declare(strict_types=1);

function next_status(string $status, string $action): ?string
{
    $map = [
        'draft' => ['submit' => 'submitted'],
        'returned' => ['submit' => 'submitted'],
        'submitted' => ['approve' => 'chair_verified', 'return' => 'returned'],
        'chair_verified' => ['approve' => 'oic_approved', 'return' => 'returned'],
        'oic_approved' => ['approve' => 'osa_endorsed', 'return' => 'returned'],
        'osa_endorsed' => ['approve' => 'registrar_finalized', 'return' => 'returned'],
    ];
    return $map[$status][$action] ?? null;
}

function role_for_status(string $status): ?string
{
    return [
        'submitted' => 'program_chair',
        'chair_verified' => 'college_oic',
        'oic_approved' => 'osa',
        'osa_endorsed' => 'registrar',
    ][$status] ?? null;
}

function user_can_review(array $user, array $assignment, string $status): bool
{
    $role = role_for_status($status);
    if ($role === null || !in_array($role, $user['roles'], true)) {
        return false;
    }
    if ($role === 'program_chair' && !in_array((int) $assignment['program_id'], chair_program_ids($user), true)) {
        return false;
    }
    return true;
}

function snapshot_grades(int $submissionId, int $version, array $book): void
{
    $rows = [];
    foreach ($book['students'] as $student) {
        $rows[] = [
            'student_number' => $student['student_number'],
            'full_name' => $student['full_name'],
            'final_percent' => $student['final_percent'],
            'final_equivalent' => $student['final_equivalent'],
            'remarks' => $student['remarks'],
            'predicted_percent' => $student['predicted_percent'],
            'predicted_equivalent' => $student['predicted_equivalent'],
            'periods' => array_map(static function (array $period): array {
                return [
                    'percent' => $period['percent'],
                    'equivalent' => $period['equivalent'],
                ];
            }, $student['periods']),
        ];
    }
    $stmt = db()->prepare(
        'INSERT INTO submission_snapshots (submission_id, version, payload) VALUES (?, ?, ?)'
    );
    $stmt->execute([$submissionId, $version, json_encode($rows, JSON_UNESCAPED_UNICODE)]);
}

function submit_assignment(array $user, int $assignmentId, array $periods): string
{
    $assignment = fetch_assignment($assignmentId);
    if (!$assignment || (int) $assignment['professor_user_id'] !== (int) $user['id']) {
        throw new RuntimeException('This class is not assigned to you.');
    }
    if (in_array('all', $periods, true)) {
        $periods = array_keys(PERIODS);
    }
    $periods = array_values(array_unique(array_intersect($periods, array_keys(PERIODS))));
    if ($periods === []) {
        throw new RuntimeException('Choose Prelim, Midterm, Finals, or all periods.');
    }
    if (assignment_is_finalized($assignment)) {
        throw new RuntimeException('This class is already finalized.');
    }
    $current = (string) ($assignment['submission_status'] ?? 'draft');
    if (!in_array($current, ['draft', 'returned', 'submitted', 'chair_verified', 'oic_approved', 'osa_endorsed'], true)) {
        throw new RuntimeException('This class cannot be submitted from its current status.');
    }
    $already = submitted_period_keys($assignment);
    $fresh = array_values(array_diff($periods, $already));
    if ($fresh === []) {
        $label = period_list_label($periods);
        throw new RuntimeException($label . (count($periods) > 1 ? ' are' : ' is') . ' already submitted.');
    }
    $merged = [];
    foreach (array_keys(PERIODS) as $key) {
        if (in_array($key, $already, true) || in_array($key, $fresh, true)) {
            $merged[] = $key;
        }
    }
    $book = build_gradebook($assignmentId);
    $gaps = [];
    foreach ($fresh as $period) {
        $names = period_unfilled($book, $period);
        if ($names !== []) {
            $gaps[] = period_gap_message($period, $names);
        }
    }
    if ($gaps !== []) {
        throw new RuntimeException(implode(' ', $gaps));
    }
    $next = 'submitted';
    $periodLabel = period_list_label($fresh);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $version = (int) $assignment['version'] + 1;
        $pdo->prepare(
            'UPDATE grade_submissions
             SET status = ?, submitted_periods = ?, submitted_at = NOW(), return_note = NULL, flag_note = NULL, locked = 0, version = ?
             WHERE id = ?'
        )->execute([$next, implode(',', $merged), $version, (int) $assignment['submission_id']]);
        snapshot_grades((int) $assignment['submission_id'], $version, $book);
        require_once __DIR__ . '/recognition.php';
        foreach ($book['students'] as $student) {
            refresh_student_evaluation((int) $student['student_id'], (int) $assignment['semester_id'], (int) $user['id']);
            recognition_sync_candidate((int) $student['student_id'], (int) $assignment['semester_id']);
        }
        $note = 'Submitted ' . $periodLabel . '.';
        $pdo->prepare(
            'INSERT INTO submission_reviews (submission_id, reviewer_id, action, from_status, to_status, remarks)
             VALUES (?, ?, "submit", ?, ?, ?)'
        )->execute([
            (int) $assignment['submission_id'],
            (int) $user['id'],
            $current,
            $next,
            $note,
        ]);
        audit((int) $user['id'], 'submit', 'grade_submission', (string) $assignment['submission_id'], $assignment['subject_code'] . ' ' . $assignment['section_name'] . ' · ' . $periodLabel);
        $label = $assignment['subject_code'] . ' · ' . $assignment['section_name'];
        foreach (users_with_role('program_chair', (int) $assignment['program_id']) as $chair) {
            notify((int) $chair['id'], 'Grades submitted', display_name($user) . ' submitted ' . $periodLabel . ' for ' . $label . '.', 'admin/review.php?id=' . (int) $assignment['submission_id']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $periodLabel;
}

function review_submission(array $user, int $submissionId, string $action, string $remarks): void
{
    $stmt = db()->prepare(
        'SELECT gs.*, pa.id AS assignment_id
         FROM grade_submissions gs
         JOIN professor_assignments pa ON pa.id = gs.assignment_id
         WHERE gs.id = ?'
    );
    $stmt->execute([$submissionId]);
    $submission = $stmt->fetch();
    if (!$submission) {
        throw new RuntimeException('Submission not found.');
    }
    $assignment = fetch_assignment((int) $submission['assignment_id']);
    if (!$assignment) {
        throw new RuntimeException('Class not found.');
    }
    $remarks = trim($remarks);
    if (in_array($action, ['return', 'flag'], true) && $remarks === '') {
        throw new RuntimeException('A remark is required when you return or flag a submission.');
    }
    if ($action !== 'flag' && !user_can_review($user, $assignment, $submission['status'])) {
        throw new RuntimeException('Your office cannot take that action on this submission.');
    }
    if ($action === 'flag' && !user_can_review($user, $assignment, $submission['status'])) {
        throw new RuntimeException('Your office cannot flag this submission.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($action === 'flag') {
            $pdo->prepare('UPDATE grade_submissions SET flag_note = ? WHERE id = ?')->execute([$remarks, $submissionId]);
            $pdo->prepare(
                'INSERT INTO submission_reviews (submission_id, reviewer_id, action, from_status, to_status, remarks)
                 VALUES (?, ?, "flag", ?, ?, ?)'
            )->execute([$submissionId, (int) $user['id'], $submission['status'], $submission['status'], $remarks]);
            audit((int) $user['id'], 'flag', 'grade_submission', (string) $submissionId, $remarks);
            notify((int) $assignment['professor_user_id'], 'Submission flagged', $assignment['subject_code'] . ' was flagged: ' . $remarks, 'professor/class.php?id=' . (int) $assignment['id'] . '&view=final');
            $pdo->commit();
            return;
        }

        $next = next_status($submission['status'], $action);
        if ($next === null) {
            throw new RuntimeException('That action is not available for the current status.');
        }
        $locked = $next === 'registrar_finalized' ? 1 : 0;
        $pdo->prepare(
            'UPDATE grade_submissions SET status = ?, locked = ?, return_note = ?, flag_note = NULL, submitted_periods = IF(? = "return", "", submitted_periods) WHERE id = ?'
        )->execute([
            $next,
            $locked,
            $action === 'return' ? $remarks : null,
            $action,
            $submissionId,
        ]);
        $pdo->prepare(
            'INSERT INTO submission_reviews (submission_id, reviewer_id, action, from_status, to_status, remarks)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$submissionId, (int) $user['id'], $action, $submission['status'], $next, $remarks]);
        audit((int) $user['id'], $action, 'grade_submission', (string) $submissionId, $submission['status'] . ' -> ' . $next . ($remarks !== '' ? ' · ' . $remarks : ''));

        $label = $assignment['subject_code'] . ' · ' . $assignment['section_name'];
        if ($action === 'return') {
            notify((int) $assignment['professor_user_id'], 'Grades returned', $label . ' was returned. ' . $remarks, 'professor/class.php?id=' . (int) $assignment['id'] . '&view=final');
        } elseif ($next === 'registrar_finalized') {
            lock_official_grades($assignment, $user);
            notify((int) $assignment['professor_user_id'], 'Grades finalized', $label . ' is now an official record.', 'professor/class.php?id=' . (int) $assignment['id'] . '&view=final');
        } else {
            $nextRole = role_for_status($next);
            if ($nextRole) {
                $programId = $nextRole === 'program_chair' ? (int) $assignment['program_id'] : null;
                foreach (users_with_role($nextRole, $programId) as $recipient) {
                    notify((int) $recipient['id'], 'Grades waiting for review', $label . ' moved to your queue.', 'admin/review.php?id=' . $submissionId);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lock_official_grades(array $assignment, array $user): void
{
    require_once __DIR__ . '/recognition.php';
    $book = build_gradebook((int) $assignment['id']);
    $upsert = db()->prepare(
        'INSERT INTO official_grades
            (enrollment_id, prelim_percent, prelim_eqv, midterm_percent, midterm_eqv, finals_percent, finals_eqv, final_percent, final_eqv, remarks, finalized_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            prelim_percent = VALUES(prelim_percent), prelim_eqv = VALUES(prelim_eqv),
            midterm_percent = VALUES(midterm_percent), midterm_eqv = VALUES(midterm_eqv),
            finals_percent = VALUES(finals_percent), finals_eqv = VALUES(finals_eqv),
            final_percent = VALUES(final_percent), final_eqv = VALUES(final_eqv),
            remarks = VALUES(remarks), finalized_at = NOW()'
    );
    foreach ($book['students'] as $student) {
        $upsert->execute([
            $student['enrollment_id'],
            $student['periods']['prelim']['percent'],
            $student['periods']['prelim']['equivalent'],
            $student['periods']['midterm']['percent'],
            $student['periods']['midterm']['equivalent'],
            $student['periods']['finals']['percent'],
            $student['periods']['finals']['equivalent'],
            $student['final_percent'],
            $student['final_equivalent'],
            $student['remarks'],
        ]);
        refresh_student_evaluation((int) $student['student_id'], (int) $assignment['semester_id'], (int) $user['id']);
        recognition_sync_candidate((int) $student['student_id'], (int) $assignment['semester_id']);
    }
}

function semester_subject_results(int $studentId, int $semesterId, string $basis): array
{
    $stmt = db()->prepare(
        'SELECT e.id AS enrollment_id, pa.id AS assignment_id, s.code, gs.status, og.final_eqv
         FROM enrollments e
         JOIN professor_assignments pa ON pa.id = e.assignment_id
         JOIN subjects s ON s.id = pa.subject_id
         LEFT JOIN grade_submissions gs ON gs.assignment_id = pa.id
         LEFT JOIN official_grades og ON og.enrollment_id = e.id
         WHERE e.student_id = ? AND pa.semester_id = ?'
    );
    $stmt->execute([$studentId, $semesterId]);
    $rows = $stmt->fetchAll();
    $grades = [];
    $complete = $rows !== [];
    foreach ($rows as $row) {
        if ($basis === 'official') {
            
if (!in_array((string) $row['status'], ['chair_verified', 'oic_approved', 'osa_endorsed', 'registrar_finalized'], true)) {
    $complete = false;
    continue;
}

            if ($row['final_eqv'] !== null) {
                $grades[] = (float) $row['final_eqv'];
                continue;
            }
            $book = build_gradebook((int) $row['assignment_id']);
            $found = null;
            foreach ($book['students'] as $student) {
                if ((int) $student['student_id'] === $studentId) {
                    $found = $student;
                    break;
                }
            }
            if (!$found || $found['final_equivalent'] === null || $found['remarks'] === 'INC') {
                $complete = false;
                continue;
            }
            $grades[] = (float) $found['final_equivalent'];
        }
    }
    return ['grades' => $grades, 'complete' => $complete, 'count' => count($rows)];
}

function advisory_subject_grades(int $studentId, int $semesterId): array
{
    $stmt = db()->prepare(
        'SELECT pa.id
         FROM enrollments e
         JOIN professor_assignments pa ON pa.id = e.assignment_id
         WHERE e.student_id = ? AND pa.semester_id = ?'
    );
    $stmt->execute([$studentId, $semesterId]);
    $grades = [];
    $complete = true;
    $count = 0;
    foreach ($stmt as $row) {
        $count++;
        $book = build_gradebook((int) $row['id']);
        $found = null;
        foreach ($book['students'] as $student) {
            if ((int) $student['student_id'] === $studentId) {
                $found = $student;
                break;
            }
        }
        if (!$found || $found['predicted_equivalent'] === null) {
            $complete = false;
            continue;
        }
        $grades[] = (float) $found['predicted_equivalent'];
    }
    if ($count === 0) {
        $complete = false;
    }
    return ['grades' => $grades, 'complete' => $complete && count($grades) === $count, 'count' => $count];
}

function refresh_student_evaluation(int $studentId, int $semesterId, ?int $reviewerId = null): void
{
    $student = db()->prepare('SELECT * FROM students WHERE id = ?');
    $student->execute([$studentId]);
    $student = $student->fetch();
    if (!$student) {
        return;
    }
    $rules = load_rules($semesterId);
    foreach (['official', 'predicted'] as $basis) {
        if ($basis === 'official') {
            $pack = semester_subject_results($studentId, $semesterId, 'official');
        } else {
            $pack = advisory_subject_grades($studentId, $semesterId);
        }
        $result = dlpl_status($student['student_type'], $pack['grades'], $rules, $pack['complete']);
        $existing = db()->prepare('SELECT * FROM dl_pl_evaluations WHERE student_id = ? AND semester_id = ? AND basis = ?');
        $existing->execute([$studentId, $semesterId, $basis]);
        $existing = $existing->fetch();
        $status = $result['status'];
        $isFinal = 0;
        if ($existing && $existing['status'] === 'Final Approved') {
            if (in_array($result['status'], ['Possible DL', 'Possible PL'], true)) {
                $status = 'Final Approved';
                $isFinal = 1;
            }
        } elseif ($existing && $existing['status'] === 'Returned' && $result['status'] === 'Pending') {
            $status = 'Returned';
        }
        db()->prepare(
            'INSERT INTO dl_pl_evaluations (student_id, semester_id, basis, gwa, status, reason, is_final, reviewer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE gwa = VALUES(gwa), status = VALUES(status), reason = VALUES(reason), is_final = VALUES(is_final), reviewer_id = VALUES(reviewer_id)'
        )->execute([
            $studentId,
            $semesterId,
            $basis,
            $result['gwa'],
            $status,
            $result['reason'],
            $isFinal,
            $reviewerId,
        ]);
    }
}

function store_advisory_predictions(array $book): void
{
    db()->prepare(
        'DELETE FROM prediction_results WHERE semester_id = ? AND subject_id = ? AND run_label = "advisory-sheet"'
    )->execute([(int) $book['assignment']['semester_id'], (int) $book['assignment']['subject_id']]);
    $stmt = db()->prepare(
        'INSERT INTO prediction_results
            (student_id, semester_id, subject_id, model_name, predicted_percent, predicted_equivalent, run_label)
         VALUES (?, ?, ?, "Preserved Linear Regression", ?, ?, "advisory-sheet")'
    );
    foreach ($book['students'] as $student) {
        if ($student['predicted_percent'] === null) {
            continue;
        }
        $stmt->execute([
            $student['student_id'],
            (int) $book['assignment']['semester_id'],
            (int) $book['assignment']['subject_id'],
            $student['predicted_percent'],
            $student['predicted_equivalent'],
        ]);
    }
}
