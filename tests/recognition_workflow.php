<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/recognition.php';

$failed = 0;
function recognition_check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO students (student_number, full_name, student_type, program_id, year_level)
         VALUES ("WF-TEMP", "Workflow Test Student", "Regular", 1, "4th Year")'
    )->execute();
    $studentId = (int) $pdo->lastInsertId();
    $semesterId = (int) $pdo->query('SELECT id FROM semesters ORDER BY is_current DESC, id DESC LIMIT 1')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO dl_pl_evaluations (student_id, semester_id, basis, gwa, status, reason)
         VALUES (?, ?, "official", 1.50, "Possible DL", "Workflow test")'
    )->execute([$studentId, $semesterId]);
    $pdo->prepare(
        'INSERT INTO recognition_cases
            (student_id, semester_id, program_id, list_type, gwa, standing, stage)
         VALUES (?, ?, 1, "DL", 1.50, "Possible DL", "chair_review")'
    )->execute([$studentId, $semesterId]);
    $caseId = (int) $pdo->lastInsertId();

    $oic = ['id' => 6, 'roles' => ['college_oic'], 'role_rows' => [['code' => 'college_oic', 'program_id' => null]]];
    $osa = ['id' => 7, 'roles' => ['osa'], 'role_rows' => [['code' => 'osa', 'program_id' => null]]];
    $registrar = ['id' => 8, 'roles' => ['registrar'], 'role_rows' => [['code' => 'registrar', 'program_id' => null]]];
    $admin = ['id' => 1, 'roles' => ['system_admin'], 'role_rows' => [['code' => 'system_admin', 'program_id' => null]]];
    $wrongChair = ['id' => 5, 'roles' => ['program_chair'], 'role_rows' => [['code' => 'program_chair', 'program_id' => 2]]];

    $blocked = false;
    try {
        recognition_act($wrongChair, $semesterId, [$caseId], 'chair_reject', 'Outside program.');
    } catch (RuntimeException) {
        $blocked = true;
    }
    recognition_check($blocked, 'Program Chair cannot act on another program');
    $pdo->prepare('UPDATE recognition_cases SET stage = "chair_endorsed" WHERE id = ?')->execute([$caseId]);

    recognition_act($oic, $semesterId, [$caseId], 'oic_approve', 'Administrative requirements checked.');
    $stage = $pdo->query("SELECT stage FROM recognition_cases WHERE id = $caseId")->fetchColumn();
    recognition_check($stage === 'oic_approved', 'OIC can approve only a Chair endorsement');

    recognition_act($osa, $semesterId, [$caseId], 'osa_approve', 'Committee qualified the student.');
    $stage = $pdo->query("SELECT stage FROM recognition_cases WHERE id = $caseId")->fetchColumn();
    recognition_check($stage === 'osa_approved', 'Committee approval reaches Registrar stage');

    recognition_act($registrar, $semesterId, [$caseId], 'registrar_verify', 'Official grades verified.');
    $stage = $pdo->query("SELECT stage FROM recognition_cases WHERE id = $caseId")->fetchColumn();
    recognition_check($stage === 'registrar_verified', 'Registrar creates the verified final record');
    $evaluation = $pdo->query("SELECT status, is_final FROM dl_pl_evaluations WHERE student_id = $studentId AND semester_id = $semesterId AND basis = 'official'")->fetch();
    recognition_check($evaluation['status'] === 'Final Approved' && (int) $evaluation['is_final'] === 1, 'Registrar verification finalizes the DL/PL evaluation');

    $blocked = false;
    try {
        recognition_act($admin, $semesterId, [$caseId], 'registrar_verify', '');
    } catch (RuntimeException) {
        $blocked = true;
    }
    recognition_check($blocked, 'System Admin cannot perform approval decisions');

    $events = (int) $pdo->query("SELECT COUNT(*) FROM recognition_events WHERE case_id = $caseId")->fetchColumn();
    recognition_check($events === 3, 'Every workflow decision has an immutable history event');
} finally {
    $pdo->rollBack();
}

if ($failed > 0) exit(1);
echo "recognition workflow ok" . PHP_EOL;
