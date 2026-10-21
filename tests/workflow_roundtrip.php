<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/gradebook.php';
require dirname(__DIR__) . '/includes/workflow.php';

$professor = db()->query("SELECT * FROM users WHERE username = 'fquiza'")->fetch();
$professor['roles'] = ['professor'];
$professor['role_rows'] = [];
$professor['portal'] = 'professor';
try {
    submit_assignment($professor, 1, ['prelim']);
    fwrite(STDERR, "incomplete prelim was submitted\n");
    exit(1);
} catch (RuntimeException $e) {
    echo "incomplete prelim blocked\n";
}

$queued = db()->query("SELECT status FROM grade_submissions WHERE id = 2")->fetchColumn();
if ($queued !== 'submitted') {
    echo "submission 2 is " . $queued . ", review roundtrip skipped\n";
    exit(0);
}

$chair = db()->query("SELECT * FROM users WHERE username = 'chair.bsit'")->fetch();
$chair['roles'] = ['program_chair'];
$chair['role_rows'] = [['code' => 'program_chair', 'program_id' => 1, 'name' => 'Program Chair']];
$chair['portal'] = 'admin';
try {
    review_submission($chair, 2, 'return', '');
    fwrite(STDERR, "empty return was accepted\n");
    exit(1);
} catch (RuntimeException $e) {
    echo "empty return blocked\n";
}
review_submission($chair, 2, 'approve', 'Checked the sheet.');
$status = db()->query('SELECT status FROM grade_submissions WHERE id = 2')->fetchColumn();
if ($status !== 'chair_verified') {
    fwrite(STDERR, "unexpected status $status\n");
    exit(1);
}
db()->prepare("UPDATE grade_submissions SET status = 'submitted', flag_note = NULL WHERE id = 2")->execute();
db()->prepare("DELETE FROM submission_reviews WHERE submission_id = 2 AND action = 'approve'")->execute();
db()->prepare("DELETE FROM notifications WHERE title = 'Grades waiting for review'")->execute();
echo "workflow roundtrip ok\n";
