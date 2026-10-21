<?php
declare(strict_types=1);

$tone = [
    'attendance' => 'att',
    'recitation' => 'rec',
    'activities' => 'act',
    'quizzes'    => 'quiz',
    'project'    => 'proj',
    'exam'       => 'exam',
];

if (!function_exists('clean_pct')) {
    function clean_pct(mixed $val): string {
        if ($val === null || $val === '') return '—';
        $f = (float) $val;
        if (floor($f) == $f) {
            return (string) ((int) $f);
        }
        return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
    }
}

$participation = [];
foreach (['attendance' => 'Attendance', 'recitation' => 'Recitation', 'activities' => 'Activities'] as $cat => $label) {
    $w = (float) ($book['weights'][$period][$cat] ?? 0);
    if ($w > 0 && category_visible($book, $period, $cat)) {
        $participation[$cat] = $label;
    }
}

$major = [];
foreach (['quizzes' => 'Quizzes', 'project' => 'Project', 'exam' => 'Exam'] as $cat => $label) {
    $w = (float) ($book['weights'][$period][$cat] ?? 0);
    if ($w > 0 && category_visible($book, $period, $cat)) {
        $major[$cat] = $label;
    }
}
?>

<style>
/* Full Width Table Layout Overrides */
.sheet-body {
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    padding-right: 0 !important;
    margin-right: 0 !important;
}
.sheet-scroll {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
}
.grade-grid {
    width: 100% !important;
    min-width: 100% !important;
}

td.tone-att, td.tone-rec, td.tone-act, td.tone-quiz, td.tone-proj, td.tone-exam {
    font-weight: 700;
    text-align: center;
}
</style>

<div class="sheet-body w-100">
    <div class="sheet-scroll w-100">
        <table class="grade-grid w-100">
            <thead>
                <tr>
                    <th class="sticky col-num">#</th>
                    <th class="sticky col-sn">Student number</th>
                    <th class="sticky col-name">Student name</th>
                    <?php if ($participation !== []): ?>
                        <th class="cat-att" colspan="<?= count($participation) ?>">Class participation</th>
                    <?php endif; ?>
                    <?php foreach ($major as $cat => $label): ?>
                        <th class="cat-<?= h($tone[$cat]) ?>" colspan="2">
                            <?= h($label) ?> <?= clean_pct($book['weights'][$period][$cat] ?? 0) ?>%
                        </th>
                    <?php endforeach; ?>
                    <th class="cat-grade" colspan="2">Grade</th>
                </tr>
                <tr>
                    <th class="sticky col-num"></th>
                    <th class="sticky col-sn"></th>
                    <th class="sticky col-name"></th>
                    <?php foreach ($participation as $cat => $label): ?>
                        <th class="cat-<?= h($tone[$cat]) ?>">
                            <?= h($label) ?> <?= clean_pct($book['weights'][$period][$cat] ?? 0) ?>%
                        </th>
                    <?php endforeach; ?>
                    <?php foreach ($major as $cat => $label): ?>
                        <th class="item-sub-head">%</th>
                        <th class="item-sub-head">Eqv</th>
                    <?php endforeach; ?>
                    <th class="item-sub-head">%</th>
                    <th class="item-sub-head">Eqv</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($book['students'] as $student): ?>
                <tr>
                    <td class="sticky col-num"><?= (int) $student['number'] ?></td>
                    <td class="sticky col-sn mono"><?= h($student['student_number']) ?></td>
                    <td class="sticky col-name">
                        <?= h($student['full_name']) ?>
                        <small class="sn-mobile"><?= h($student['student_number']) ?></small>
                    </td>
                    <?php foreach ($participation as $cat => $label): ?>
                        <td class="tone-<?= h($tone[$cat]) ?>">
                            <?= clean_pct($student['periods'][$period]['categories'][$cat]['percent']) ?>
                        </td>
                    <?php endforeach; ?>
                    <?php foreach ($major as $cat => $label): ?>
                        <td class="tone-<?= h($tone[$cat]) ?>">
                            <?= clean_pct($student['periods'][$period]['categories'][$cat]['percent']) ?>
                        </td>
                        <td class="tone-<?= h($tone[$cat]) ?>">
                            <?= fmt_eqv($student['periods'][$period]['categories'][$cat]['equivalent']) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="grade-total"><?= clean_pct($student['periods'][$period]['percent']) ?></td>
                    <td class="grade-eqv"><?= fmt_eqv($student['periods'][$period]['equivalent']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>