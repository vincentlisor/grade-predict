<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/gradebook.php';
require dirname(__DIR__) . '/includes/workflow.php';

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

$book = build_gradebook(1);
$byNumber = [];
foreach ($book['students'] as $student) {
    $byNumber[$student['student_number']] = $student;
}
check(count($book['students']) === 12, 'IT Project class has 12 students');
check($byNumber['04-24-32733']['final_equivalent'] === 1.0, 'Reena final equivalent 1.00');
check($byNumber['04-24-32733']['remarks'] === 'PASSED', 'Reena passed');
check($byNumber['06-21-40517']['final_equivalent'] === 2.25, 'Domingo final equivalent 2.25');
check($byNumber['06-21-40517']['remarks'] === 'PASSED', 'Domingo passed the subject');
check($byNumber['08-15-41258']['final_equivalent'] === 1.0, 'Agpoon computed 1.00');
check($byNumber['09-18-43826']['remarks'] === 'INC', 'Villajuan is INC');
check($byNumber['01-26-10001']['remarks'] === 'INC', 'blank demo student is INC');
check(!isset($byNumber['11-03-38192']), 'Jed is not in 401-C');

$sam = build_gradebook(2);
check($sam['assignment']['submission_status'] === 'draft', 'SAM starts as a blank draft');
check($sam['assignment']['professor_user_id'] === 2, 'SAM belongs to Febrick');

$bshm = build_gradebook(3);
check((int) $bshm['assignment']['professor_user_id'] === 3, 'BSHM belongs to the other professor');

$chair = ['id' => 4, 'roles' => ['program_chair'], 'role_rows' => [['code' => 'program_chair', 'program_id' => 1]]];
$bshmChair = ['id' => 5, 'roles' => ['program_chair'], 'role_rows' => [['code' => 'program_chair', 'program_id' => 2]]];
check(user_can_review($chair, $sam['assignment'], 'submitted'), 'BSIT chair can review SAM');
check(!user_can_review($bshmChair, $sam['assignment'], 'submitted'), 'BSHM chair cannot review SAM');
check(!user_can_review($chair, $bshm['assignment'], 'submitted'), 'BSIT chair cannot review BSHM');

if ($failed > 0) {
    exit(1);
}
echo "integration ok\n";
