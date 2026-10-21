<?php
declare(strict_types=1);

function attendance_choices(): array
{
    return [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excuse',
    ];
}

function attendance_choice(?string $mark, $score, float $max): string
{
    $mark = (string) $mark;
    if (isset(attendance_choices()[$mark])) {
        return $mark;
    }
    if ($score === null || $score === '') {
        return '';
    }
    $score = (float) $score;
    if ($max > 0 && abs($score - $max) < 0.011) {
        return 'present';
    }
    if (abs($score) < 0.011) {
        return 'absent';
    }
    if ($max > 0 && abs($score - round($max / 2, 2)) < 0.011) {
        return 'late';
    }
    return '';
}

function attendance_score(string $choice, float $max): ?float
{
    return match ($choice) {
        'present' => round($max, 2),
        'late' => round($max / 2, 2),
        'absent' => 0.0,
        'excused', '' => null,
        default => throw new RuntimeException('Choose Present, Absent, Late, or Excuse.'),
    };
}

function category_percent(array $items): ?float
{
    $earned = 0.0;
    $possible = 0.0;
    foreach ($items as $item) {
        $max = (float) ($item['max_score'] ?? 0);
        if ($max <= 0) {
            continue;
        }
        if (!array_key_exists('score', $item) || $item['score'] === null || $item['score'] === '') {
            continue;
        }
        $earned += (float) $item['score'];
        $possible += $max;
    }
    if ($possible <= 0) {
        return null;
    }
    return round($earned / $possible * 100, 2);
}

function weighted_percent(array $parts, array $weights, float $adjustment = 0.0): array
{
    $weightTotal = 0.0;
    $acc = 0.0;
    $missing = [];
    foreach ($weights as $key => $weight) {
        $weight = (float) $weight;
        if ($weight <= 0) {
            continue;
        }
        $weightTotal += $weight;
        if (!array_key_exists($key, $parts) || $parts[$key] === null) {
            $missing[] = (string) $key;
            continue;
        }
        $acc += (float) $parts[$key] * $weight;
    }
    $weightTotal = round($weightTotal, 2);
    if ($weightTotal <= 0 || $missing !== []) {
        return [
            'percent' => null,
            'complete' => false,
            'missing' => $missing,
            'weight_total' => $weightTotal,
            'base' => null,
        ];
    }
    $base = $acc / 100;
    $percent = round(max(0, min(100, $base + $adjustment)), 2);
    return [
        'percent' => $percent,
        'complete' => true,
        'missing' => [],
        'weight_total' => $weightTotal,
        'base' => round($base, 2),
    ];
}

function lookup_equivalent(?float $percent, array $scale): ?float
{
    if ($percent === null) {
        return null;
    }
    foreach ($scale as $band) {
        $min = (float) $band['min_percent'];
        $max = (float) $band['max_percent'];
        if ($percent >= $min - 0.001 && $percent <= $max + 0.001) {
            return (float) $band['equivalent'];
        }
    }
    return 5.0;
}

function grade_remarks(?float $equivalent, bool $complete): string
{
    if (!$complete || $equivalent === null) {
        return 'INC';
    }
    return $equivalent <= 3.0 ? 'PASSED' : 'FAILED';
}

function gwa_of(array $equivalents): ?float
{
    $values = [];
    foreach ($equivalents as $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $values[] = (float) $value;
    }
    if ($values === []) {
        return null;
    }
    return round(array_sum($values) / count($values), 2);
}

function weight_warning(float $total, string $label): ?string
{
    if (abs($total - 100) <= 0.01) {
        return null;
    }
    return $label . ' weights total ' . number_format($total, 2) . '%. They are applied as shares of 100, so a total other than 100 changes the highest grade this block can produce.';
}

function dlpl_status(string $studentType, array $equivalents, array $rules, bool $recordComplete): array
{
    $gwa = gwa_of($equivalents);
    if (!$recordComplete || $gwa === null) {
        return ['status' => 'Pending', 'gwa' => $gwa, 'reason' => 'The record is still incomplete.'];
    }
    $line = (float) $rules['disqualify_at'];
    foreach ($equivalents as $grade) {
        if ($grade === null || $grade === '') {
            continue;
        }
        if ((float) $grade >= $line) {
            return ['status' => 'Not Eligible', 'gwa' => $gwa, 'reason' => 'A subject grade is at or past the disqualifying line of ' . number_format($line, 2) . '.'];
        }
    }
    if ($gwa >= (float) $rules['pl_min'] && $gwa <= (float) $rules['pl_max']) {
        return ['status' => 'Possible PL', 'gwa' => $gwa, 'reason' => 'GWA falls inside the configured President\'s List range.'];
    }
    if ($gwa >= (float) $rules['dl_min'] && $gwa <= (float) $rules['dl_max']) {
        return ['status' => 'Possible DL', 'gwa' => $gwa, 'reason' => 'GWA falls inside the configured Dean\'s List range.'];
    }
    return ['status' => 'Not Eligible', 'gwa' => $gwa, 'reason' => 'GWA is outside the configured DL/PL ranges.'];
}

function default_scale_rows(): array
{
    return [
        ['min_percent' => 98, 'max_percent' => 100, 'equivalent' => 1.00, 'description' => 'Excellent'],
        ['min_percent' => 95, 'max_percent' => 97.99, 'equivalent' => 1.25, 'description' => 'Very Superior'],
        ['min_percent' => 92, 'max_percent' => 94.99, 'equivalent' => 1.50, 'description' => 'Superior'],
        ['min_percent' => 89, 'max_percent' => 91.99, 'equivalent' => 1.75, 'description' => 'Very Good'],
        ['min_percent' => 86, 'max_percent' => 88.99, 'equivalent' => 2.00, 'description' => 'Very Satisfactory'],
        ['min_percent' => 83, 'max_percent' => 85.99, 'equivalent' => 2.25, 'description' => 'Good'],
        ['min_percent' => 80, 'max_percent' => 82.99, 'equivalent' => 2.50, 'description' => 'Satisfactory'],
        ['min_percent' => 77, 'max_percent' => 79.99, 'equivalent' => 2.75, 'description' => 'Fair'],
        ['min_percent' => 75, 'max_percent' => 76.99, 'equivalent' => 3.00, 'description' => 'Passed'],
        ['min_percent' => 0, 'max_percent' => 74.99, 'equivalent' => 5.00, 'description' => 'Failed'],
    ];
}

function default_component_template(): array
{
    $rows = [];
    $sort = 1;
    foreach (['WK1', 'WK2', 'WK3', 'WK4', 'WK5'] as $i => $code) {
        $rows[] = ['category' => 'attendance', 'code' => $code, 'label' => 'Attendance ' . ($i + 1), 'max_score' => 10, 'sort_order' => $sort++];
    }
    foreach (['REC#1', 'REC#2', 'REC#3', 'REC#4', 'REC#5'] as $i => $code) {
        $rows[] = ['category' => 'recitation', 'code' => $code, 'label' => 'Recitation ' . ($i + 1), 'max_score' => 10, 'sort_order' => $sort++];
    }
    foreach (['ACT#1', 'ACT#2', 'ACT#3', 'ACT#4', 'ACT#5'] as $i => $code) {
        $rows[] = ['category' => 'activities', 'code' => $code, 'label' => 'Activity ' . ($i + 1), 'max_score' => 10, 'sort_order' => $sort++];
    }
    foreach (['Q1', 'Q2', 'Q3', 'Q4', 'Q5'] as $i => $code) {
        $rows[] = ['category' => 'quizzes', 'code' => $code, 'label' => 'Quiz ' . ($i + 1), 'max_score' => 20, 'sort_order' => $sort++];
    }
    $rows[] = ['category' => 'project', 'code' => 'PR', 'label' => 'Project', 'max_score' => 100, 'sort_order' => $sort++];
    $rows[] = ['category' => 'exam', 'code' => 'EX', 'label' => 'Exam', 'max_score' => 100, 'sort_order' => $sort++];
    return $rows;
}

function preserved_linear_prediction(array $features): ?float
{
    $modelPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'linear_model.json';
    if (!is_file($modelPath)) {
        return null;
    }
    $model = json_decode((string) file_get_contents($modelPath), true);
    if (!is_array($model) || !isset($model['intercept'], $model['coefficients']) || !is_array($model['coefficients'])) {
        return null;
    }
    $prediction = (float) $model['intercept'];
    foreach (($model['features'] ?? []) as $feature) {
        if (!array_key_exists($feature, $features) || !is_numeric($features[$feature]) || !isset($model['coefficients'][$feature])) {
            return null;
        }
        $prediction += (float) $model['coefficients'][$feature] * (float) $features[$feature];
    }
    return round(max(0, min(100, $prediction)), 2);
}
