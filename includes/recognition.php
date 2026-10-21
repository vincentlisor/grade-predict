<?php

declare(strict_types=1);

require_once __DIR__ . '/gradebook.php';
require_once __DIR__ . '/workflow.php';

function recognition_actor(array $user): string
{
    foreach (['program_chair', 'college_oic', 'osa', 'registrar'] as $role) {
        if (in_array($role, $user['roles'], true)) {
            return $role;
        }
    }
    if (in_array('system_admin', $user['roles'], true)) {
        return 'viewer';
    }
    return '';
}

function recognition_stage_label(string $stage): string
{
    return [
        'chair_review' => 'For Program Chair Review',
        'chair_endorsed' => 'Endorsed & Approved (Final)',
        'chair_returned' => 'Returned to Program Chair',
        'chair_rejected' => 'Rejected by Program Chair',
        'oic_approved' => 'Approved by OIC (Oversight)',
        'oic_returned' => 'Returned by OIC',
        'oic_rejected' => 'Rejected by OIC',
        'osa_approved' => 'Qualified by Committee (Oversight)',
        'osa_rejected' => 'Rejected by Committee',
        'osa_deferred' => 'Deferred by Committee',
        'registrar_returned' => 'Returned by Registrar',
        'registrar_verified' => 'Officially Recorded',
        'approved' => 'Approved & Finalized',
        'final' => 'Approved & Finalized',
    ][$stage] ?? $stage;
}

function recognition_stage_group(string $actor, string $stage): string
{
    $groups = [
        'program_chair' => [
            'chair_review' => 'review',
            'chair_endorsed' => 'endorsed',
            'approved' => 'endorsed',
            'final' => 'endorsed',
            'chair_returned' => 'returned',
            'chair_rejected' => 'rejected',
        ],
        'college_oic' => [
            'chair_endorsed' => 'approved',
            'approved' => 'approved',
            'final' => 'approved',
            'chair_review' => 'review',
        ],
        'osa' => [
            'chair_endorsed' => 'approved',
            'approved' => 'approved',
            'final' => 'approved',
            'chair_review' => 'review',
        ],
        'registrar' => [
            'chair_endorsed' => 'verified',
            'approved' => 'verified',
            'final' => 'verified',
            'registrar_verified' => 'verified',
            'chair_review' => 'review',
        ],
    ];
    return $groups[$actor][$stage] ?? 'history';
}

function recognition_list_label(string $listType): string
{
    return $listType === 'PL' ? "President's List" : "Dean's List";
}

function recognition_when(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $time = strtotime($value);
    return $time ? date('M j, Y g:i A', $time) : '';
}

function recognition_list_for_standing(string $standing): ?string
{
    if ($standing === 'Possible PL' || $standing === "President's List" || $standing === 'PL') {
        return 'PL';
    }
    if ($standing === 'Possible DL' || $standing === "Dean's List" || $standing === 'DL') {
        return 'DL';
    }
    return null;
}

function recognition_notify_role(string $role, string $title, string $body, ?int $programId = null): void
{
    $sql = 'SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = ?';
    $params = [$role];
    if ($programId !== null) {
        $sql .= ' AND ur.program_id = ?';
        $params[] = $programId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        notify((int) $row['user_id'], $title, $body, 'admin/recognition.php');
    }
}

function recognition_notify_professors(int $studentId, int $semesterId, string $title, string $body): void
{
    $stmt = db()->prepare(
        'SELECT DISTINCT u.id
         FROM enrollments e
         JOIN professor_assignments pa ON pa.id = e.assignment_id
         JOIN professors pr ON pr.id = pa.professor_id
         JOIN users u ON u.id = pr.user_id
         WHERE e.student_id = ? AND pa.semester_id = ?'
    );
    $stmt->execute([$studentId, $semesterId]);
    foreach ($stmt as $row) {
        notify((int) $row['id'], $title, $body, 'professor/dashboard.php');
    }
}

function recognition_log(int $caseId, ?int $userId, string $action, ?string $from, string $to, string $note): void
{
    db()->prepare(
        'INSERT INTO recognition_events (case_id, user_id, action, from_stage, to_stage, note) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$caseId, $userId, $action, $from, $to, $note !== '' ? $note : null]);
    audit($userId, 'recognition_' . $action, 'recognition_case', (string) $caseId, trim((string) $from . ' -> ' . $to . ' ' . $note));
}

function recognition_rows(array $user, int $semesterId, string $search, string $stage, string $sort, string $dir): array
{
    $scope = scoped_program_ids($user);
    $where = ['1 = 1'];
    $params = [$semesterId, $semesterId];
    if ($scope !== null) {
        if ($scope === []) {
            return [];
        }
        $where[] = 'st.program_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        foreach ($scope as $programId) {
            $params[] = $programId;
        }
    }
    if ($search !== '') {
        $where[] = '(st.full_name LIKE ? OR st.student_number LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    
    $actor = recognition_actor($user);
    
    // Read-only monitoring roles see all cases
    if (in_array($actor, ['college_oic', 'osa', 'registrar', 'viewer'], true)) {
        $where[] = 'rc.id IS NOT NULL';
    } elseif ($actor === 'program_chair') {
        $where[] = 'rc.id IS NOT NULL';
    }

    $order = $sort === 'gwa' ? 'e.gwa' : 'st.full_name';
    $direction = $dir === 'desc' ? 'DESC' : 'ASC';
    $sql = 'SELECT st.id AS student_id, st.student_number, st.full_name, st.student_type, st.year_level,
            p.code AS program_code, p.name AS program_name,
            e.gwa, e.status AS standing, e.reason,
            rc.id AS case_id, rc.list_type, rc.stage, rc.gwa AS case_gwa, rc.standing AS case_standing,
            rc.criteria_id, rc.last_note, rc.required_action,
            rc.endorsed_at, rc.committee_at, rc.osa_at, rc.verified_at,
            chair.full_name AS chair_name, chair.title AS chair_title,
            oic.full_name AS oic_name, oic.title AS oic_title,
            osa.full_name AS osa_name, osa.title AS osa_title,
            reg.full_name AS registrar_name, reg.title AS registrar_title
        FROM students st
        JOIN programs p ON p.id = st.program_id
        LEFT JOIN dl_pl_evaluations e ON e.student_id = st.id AND e.semester_id = ? AND e.basis = "official"
        LEFT JOIN recognition_cases rc ON rc.student_id = st.id AND rc.semester_id = ?
        LEFT JOIN users chair ON chair.id = rc.chair_user_id
        LEFT JOIN users oic ON oic.id = rc.oic_user_id
        LEFT JOIN users osa ON osa.id = rc.osa_user_id
        LEFT JOIN users reg ON reg.id = rc.registrar_user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . $order . ' ' . $direction . ', st.full_name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function recognition_history(array $caseIds): array
{
    if ($caseIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
    $stmt = db()->prepare(
        "SELECT ev.case_id, ev.action, ev.from_stage, ev.to_stage, ev.note, ev.created_at,
                u.full_name, u.title
         FROM recognition_events ev
         LEFT JOIN users u ON u.id = ev.user_id
         WHERE ev.case_id IN ($placeholders)
         ORDER BY ev.id ASC"
    );
    $stmt->execute(array_values($caseIds));
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['case_id']][] = $row;
    }
    return $grouped;
}

function recognition_sync_candidate(int $studentId, int $semesterId): ?int
{
    $stmt = db()->prepare(
        'SELECT st.program_id, e.gwa, e.status
         FROM students st
         JOIN dl_pl_evaluations e ON e.student_id = st.id AND e.semester_id = ? AND e.basis = "official"
         WHERE st.id = ?'
    );
    $stmt->execute([$semesterId, $studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $rules = load_rules($semesterId);
    $list = recognition_list_for_standing((string) $row['status']);
    if ($list === null && ($row['status'] === 'Final Approved' || $row['status'] === 'Approved') && $row['gwa'] !== null) {
        $gwa = (float) $row['gwa'];
        if ($gwa >= (float) $rules['pl_min'] && $gwa <= (float) $rules['pl_max']) {
            $list = 'PL';
        } elseif ($gwa >= (float) $rules['dl_min'] && $gwa <= (float) $rules['dl_max']) {
            $list = 'DL';
        }
    }
    if ($list === null) {
        return null;
    }
    $existing = db()->prepare('SELECT id FROM recognition_cases WHERE student_id = ? AND semester_id = ?');
    $existing->execute([$studentId, $semesterId]);
    $caseId = (int) $existing->fetchColumn();
    if ($caseId > 0) {
        db()->prepare(
            'UPDATE recognition_cases
             SET list_type = ?, gwa = ?, standing = ?, criteria_id = ?
             WHERE id = ? AND stage = "chair_review"'
        )->execute([$list, $row['gwa'], $row['status'], isset($rules['id']) ? (int) $rules['id'] : null, $caseId]);
        return $caseId;
    }
    db()->prepare(
        'INSERT INTO recognition_cases
            (student_id, semester_id, program_id, list_type, gwa, standing, stage, criteria_id, last_note)
         VALUES (?, ?, ?, ?, ?, ?, "chair_review", ?, "System qualification check completed.")'
    )->execute([
        $studentId,
        $semesterId,
        (int) $row['program_id'],
        $list,
        $row['gwa'],
        $row['status'],
        isset($rules['id']) ? (int) $rules['id'] : null,
    ]);
    $caseId = (int) db()->lastInsertId();
    recognition_log($caseId, null, 'system_check', null, 'chair_review', 'System calculated ' . recognition_list_label($list) . ' eligibility from official grades.');
    recognition_notify_role('program_chair', 'DL/PL candidate ready', 'A qualified student is ready for Program Chair review.', (int) $row['program_id']);
    return $caseId;
}

function recognition_endorse(array $user, int $semesterId, array $studentIds, string $note): array
{
    if (recognition_actor($user) !== 'program_chair') {
        throw new RuntimeException('Only a Program Chair can endorse students.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if ($ids === []) {
        throw new RuntimeException('Select at least one student.');
    }
    $scope = scoped_program_ids($user) ?? [];
    $endorsed = 0;
    $skipped = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($ids as $studentId) {
            $stmt = $pdo->prepare('SELECT id, full_name, program_id, student_type FROM students WHERE id = ? FOR UPDATE');
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            if (!$student || !in_array((int) $student['program_id'], $scope, true)) {
                $skipped[] = 'A selected student is outside your department.';
                continue;
            }
            refresh_student_evaluation($studentId, $semesterId, (int) $user['id']);
            $eval = $pdo->prepare(
                'SELECT gwa, status FROM dl_pl_evaluations WHERE student_id = ? AND semester_id = ? AND basis = "official"'
            );
            $eval->execute([$studentId, $semesterId]);
            $eval = $eval->fetch() ?: ['gwa' => null, 'status' => ''];
            $student['gwa'] = $eval['gwa'];
            $student['status'] = $eval['status'];
            $list = recognition_list_for_standing((string) ($student['status'] ?? ''));
            if ($list === null) {
                $skipped[] = $student['full_name'] . ' is not academically qualified for DL or PL.';
                continue;
            }
            $caseId = recognition_sync_candidate($studentId, $semesterId);
            $existing = $pdo->prepare('SELECT id, stage FROM recognition_cases WHERE student_id = ? AND semester_id = ? FOR UPDATE');
            $existing->execute([$studentId, $semesterId]);
            $case = $existing->fetch();
            if (!$case || !in_array((string) $case['stage'], ['chair_review', 'chair_returned', 'oic_returned'], true)) {
                $skipped[] = $student['full_name'] . ' is already ' . recognition_stage_label((string) ($case['stage'] ?? '')) . '.';
                continue;
            }
            $from = (string) $case['stage'];
            $caseId = (int) $case['id'];
            
            // Program Chair endorsement directly sets final approval
            $pdo->prepare(
                'UPDATE recognition_cases
                 SET stage = "chair_endorsed", list_type = ?, gwa = ?, standing = ?,
                     chair_user_id = ?, endorsed_at = NOW(), last_note = ?, required_action = NULL
                 WHERE id = ? AND stage = ?'
            )->execute([$list, $student['gwa'], $student['status'], (int) $user['id'], $note !== '' ? $note : null, $caseId, $from]);
            
            // Sync status directly to dl_pl_evaluations
            $pdo->prepare(
                'UPDATE dl_pl_evaluations
                 SET status = "Final Approved", is_final = 1, reviewer_id = ?
                 WHERE student_id = ? AND semester_id = ? AND basis = "official"'
            )->execute([(int) $user['id'], $studentId, $semesterId]);

            recognition_log($caseId, (int) $user['id'], 'endorse', $from, 'chair_endorsed', $note);
            $endorsed++;
        }
        if ($endorsed === 0) {
            throw new RuntimeException($skipped[0] ?? 'No students were endorsed.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['endorsed' => $endorsed, 'skipped' => $skipped];
}

function recognition_act(array $user, int $semesterId, array $caseIds, string $action, string $note): int
{
    $actor = recognition_actor($user);
    $map = [
        'chair_return' => ['program_chair', ['chair_review'], 'chair_returned', null, 'chair_user_id', '', 'Program Chair returned'],
        'chair_reject' => ['program_chair', ['chair_review', 'chair_returned', 'oic_returned'], 'chair_rejected', null, 'chair_user_id', '', 'Program Chair rejected'],
    ];
    if (!isset($map[$action]) || $actor !== $map[$action][0]) {
        throw new RuntimeException('This step is not available for your role.');
    }
    [$role, $fromStages, $to, $stamp, $owner, $notifyRole, $label] = $map[$action];
    unset($role);
    if (in_array($action, ['chair_return', 'chair_reject'], true) && $note === '') {
        throw new RuntimeException('Add a remark explaining this decision.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $caseIds))));
    if ($ids === []) {
        throw new RuntimeException('Select at least one student.');
    }
    $pdo = db();
    $moved = 0;
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $select = $pdo->prepare('SELECT id, stage, student_id, program_id FROM recognition_cases WHERE id = ? AND semester_id = ? FOR UPDATE');
        foreach ($ids as $caseId) {
            $select->execute([$caseId, $semesterId]);
            $case = $select->fetch();
            if (!$case || !in_array((string) $case['stage'], $fromStages, true)) {
                throw new RuntimeException('A selected student is not at the stage this action can change.');
            }
            if ($actor === 'program_chair' && !in_array((int) $case['program_id'], chair_program_ids($user), true)) {
                throw new RuntimeException('This student is outside your assigned program.');
            }
            $from = (string) $case['stage'];
            $sets = ['stage = ?', "{$owner} = ?", 'last_note = ?', 'required_action = ?'];
            $values = [
                $to,
                (int) $user['id'],
                $note !== '' ? $note : null,
                str_contains($action, 'return') ? $note : null,
            ];
            if ($stamp !== null) {
                $sets[] = "{$stamp} = NOW()";
            }
            $values[] = $caseId;
            $values[] = $from;
            $update = $pdo->prepare('UPDATE recognition_cases SET ' . implode(', ', $sets) . ' WHERE id = ? AND stage = ?');
            $update->execute($values);
            
            recognition_log($caseId, (int) $user['id'], $action, $from, $to, $note);
            $moved++;
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $moved;
}

function recognition_add_note(array $user, int $caseId, string $note): void
{
    if ($note === '') {
        throw new RuntimeException('Enter a remark before saving.');
    }
    $case = recognition_case($caseId, $user);
    $actor = recognition_actor($user);
    if (!$case || !in_array($actor, ['program_chair', 'college_oic', 'osa', 'registrar'], true)) {
        throw new RuntimeException('This record is not available for your role.');
    }
    db()->prepare('UPDATE recognition_cases SET last_note = ? WHERE id = ?')->execute([$note, $caseId]);
    recognition_log($caseId, (int) $user['id'], 'remark', (string) $case['stage'], (string) $case['stage'], $note);
}

// Updated to pull all Program Chair endorsed & finalized candidates
function recognition_official(int $semesterId): array
{
    $stmt = db()->prepare(
        'SELECT st.student_number, st.full_name, st.year_level, p.code AS program_code, p.name AS program_name,
                rc.id, rc.list_type, rc.gwa, rc.standing, rc.endorsed_at, rc.committee_at, rc.osa_at, rc.verified_at,
                chair.full_name AS chair_name, chair.title AS chair_title,
                oic.full_name AS oic_name, oic.title AS oic_title,
                osa.full_name AS osa_name, osa.title AS osa_title,
                reg.full_name AS registrar_name, reg.title AS registrar_title,
                sem.name AS semester_name, ay.label AS year_label
         FROM recognition_cases rc
         JOIN students st ON st.id = rc.student_id
         JOIN programs p ON p.id = rc.program_id
         JOIN semesters sem ON sem.id = rc.semester_id
         JOIN academic_years ay ON ay.id = sem.academic_year_id
         LEFT JOIN users chair ON chair.id = rc.chair_user_id
         LEFT JOIN users oic ON oic.id = rc.oic_user_id
         LEFT JOIN users osa ON osa.id = rc.osa_user_id
         LEFT JOIN users reg ON reg.id = rc.registrar_user_id
         WHERE rc.semester_id = ? AND rc.stage IN ("chair_endorsed", "oic_approved", "osa_approved", "registrar_verified", "approved", "final")
         ORDER BY rc.list_type DESC, p.code, st.full_name'
    );
    $stmt->execute([$semesterId]);
    return $stmt->fetchAll();
}

function recognition_case(int $caseId, array $user): ?array
{
    $scope = scoped_program_ids($user);
    $stmt = db()->prepare(
        'SELECT rc.*, st.student_number, st.full_name, st.student_type, st.year_level,
                p.code AS program_code, p.name AS program_name,
                sem.name AS semester_name, ay.label AS year_label,
                chair.full_name AS chair_name, oic.full_name AS oic_name,
                osa.full_name AS osa_name, reg.full_name AS registrar_name
         FROM recognition_cases rc
         JOIN students st ON st.id = rc.student_id
         JOIN programs p ON p.id = rc.program_id
         JOIN semesters sem ON sem.id = rc.semester_id
         JOIN academic_years ay ON ay.id = sem.academic_year_id
         LEFT JOIN users chair ON chair.id = rc.chair_user_id
         LEFT JOIN users oic ON oic.id = rc.oic_user_id
         LEFT JOIN users osa ON osa.id = rc.osa_user_id
         LEFT JOIN users reg ON reg.id = rc.registrar_user_id
         WHERE rc.id = ?'
    );
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case || (is_array($scope) && !in_array((int) $case['program_id'], $scope, true))) {
        return null;
    }
    return $case;
}

function recognition_subject_grades(int $studentId, int $semesterId): array
{
    $stmt = db()->prepare(
        'SELECT pa.id AS assignment_id, s.code, s.name, og.final_percent, og.final_eqv, og.remarks,
                u.full_name AS professor_name
         FROM enrollments e
         JOIN professor_assignments pa ON pa.id = e.assignment_id
         JOIN subjects s ON s.id = pa.subject_id
         JOIN professors pr ON pr.id = pa.professor_id
         JOIN users u ON u.id = pr.user_id
         LEFT JOIN official_grades og ON og.enrollment_id = e.id
         WHERE e.student_id = ? AND pa.semester_id = ?
         ORDER BY s.code'
    );
    $stmt->execute([$studentId, $semesterId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if ($row['final_eqv'] !== null) {
            continue;
        }
        $book = build_gradebook((int) $row['assignment_id']);
        foreach ($book['students'] as $student) {
            if ((int) $student['student_id'] !== $studentId) {
                continue;
            }
            $row['final_percent'] = $student['final_percent'];
            $row['final_eqv'] = $student['final_equivalent'];
            $row['remarks'] = $student['remarks'];
            break;
        }
    }
    unset($row);
    return $rows;
}