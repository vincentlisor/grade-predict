<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/grades.php';

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    if (!$ok) {
        $failed++;
        fwrite(STDERR, "FAIL $label\n");
    }
}

$scale = default_scale_rows();
$weights = ['attendance' => 10, 'recitation' => 10, 'activities' => 10, 'quizzes' => 15, 'project' => 15, 'exam' => 40];
$full = array_fill_keys(array_keys($weights), 100.0);
$period = weighted_percent($full, $weights, 0);
check($period['percent'] === 100.0, 'perfect period');
check(lookup_equivalent($period['percent'], $scale) === 1.0, '100 is 1.00');

$mid = array_fill_keys(array_keys($weights), 85.0);
$period = weighted_percent($mid, $weights, 0);
check($period['percent'] === 85.0, '85 period');
check(lookup_equivalent(85.0, $scale) === 2.25, '85 is 2.25');
check(grade_remarks(2.25, true) === 'PASSED', '2.25 still passes the subject');

$partial = $full;
$partial['exam'] = null;
$period = weighted_percent($partial, $weights, 0);
check($period['percent'] === null && $period['complete'] === false, 'missing exam is incomplete');

$sparse = ['project' => 100.0, 'exam' => 100.0];
$sparseWeights = ['attendance' => 0, 'project' => 40, 'exam' => 40];
$period = weighted_percent($sparse, $sparseWeights, 0);
check($period['percent'] === 80.0, '40 plus 40 stays 80');

$adjusted = weighted_percent($full, $weights, 5);
check($adjusted['percent'] === 100.0, 'adjustment clamps at 100');
$cut = weighted_percent(array_fill_keys(array_keys($weights), 90.0), $weights, -20);
check($cut['percent'] === 70.0 && lookup_equivalent($cut['percent'], $scale) === 5.0, 'adjustment can fail a period');

$items = [
    ['score' => 10, 'max_score' => 10],
    ['score' => null, 'max_score' => 10],
];
check(category_percent($items) === 100.0, 'blank score is excluded');
check(category_percent([['score' => 0, 'max_score' => 10]]) === 0.0, 'zero counts');

$final = weighted_percent(['prelim' => 100, 'midterm' => 100, 'finals' => 90], ['prelim' => 30, 'midterm' => 30, 'finals' => 40], 0);
check($final['percent'] === 96.0 && lookup_equivalent($final['percent'], $scale) === 1.25, '30/30/40 final');
$inc = weighted_percent(['prelim' => 100, 'midterm' => null, 'finals' => 90], ['prelim' => 30, 'midterm' => 30, 'finals' => 40], 0);
check($inc['complete'] === false && grade_remarks(null, false) === 'INC', 'missing term is INC');

check(gwa_of([1.50, 1.25, 1.75, 1.50]) === 1.50, 'unweighted GWA');
$rules = ['irregular_ineligible' => 1, 'disqualify_at' => 2, 'pl_min' => 1, 'pl_max' => 1.25, 'dl_min' => 1.26, 'dl_max' => 1.75];
check(dlpl_status('Irregular', [1.0, 1.25], $rules, true)['status'] === 'Possible PL', 'irregular uses the same ranges');
check(dlpl_status('Regular', [1.0, 2.25], $rules, true)['status'] === 'Not Eligible', 'disqualifying grade');
check(dlpl_status('Regular', [1.0, 1.25], $rules, true)['status'] === 'Possible PL', 'possible PL');
check(dlpl_status('Regular', [1.50, 1.50], $rules, true)['status'] === 'Possible DL', 'possible DL');
check(dlpl_status('Regular', [], $rules, false)['status'] === 'Pending', 'pending');

$predicted = preserved_linear_prediction(['attendance' => 90, 'recitation' => 85, 'activities' => 88, 'quizzes' => 80, 'project' => 92, 'exam' => 84]);
check($predicted === null || ($predicted >= 0 && $predicted <= 100), 'trained linear model prediction stays within 0-100 when model exists');

if ($failed > 0) {
    fwrite(STDERR, "$failed checks failed\n");
    exit(1);
}
echo "grade math ok\n";
