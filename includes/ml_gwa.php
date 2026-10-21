<?php
declare(strict_types=1);

function ml_gwa_predict(array $features, string $stage): ?float
{
    $files = [
        'prelim' => 'prelim_model.json',
        'midterm' => 'midterm_model.json',
    ];

    if (!isset($files[$stage])) {
        return null;
    }

    $path = dirname(__DIR__) . '/ml/' . $files[$stage];

    if (!is_file($path)) {
        return null;
    }

    $model = json_decode((string) file_get_contents($path), true);

    if (
        !is_array($model) ||
        !isset($model['intercept'], $model['coefficients']) ||
        !is_array($model['coefficients'])
    ) {
        return null;
    }

    $prediction = (float) $model['intercept'];

    foreach (($model['features'] ?? []) as $feature) {
        if (
            !array_key_exists($feature, $features) ||
            !is_numeric($features[$feature]) ||
            !isset($model['coefficients'][$feature])
        ) {
            return null;
        }

        $prediction +=
            (float) $model['coefficients'][$feature] *
            (float) $features[$feature];
    }

    return round(max(1.00, min(5.00, $prediction)), 2);
}

function ml_candidate_status(?float $gwa): string
{
    if ($gwa === null) {
        return 'Pending';
    }

    if ($gwa >= 1.00 && $gwa <= 1.25) {
        return 'Possible PL Candidate';
    }

    if ($gwa >= 1.26 && $gwa <= 1.75) {
        return 'Possible DL Candidate';
    }

    return 'Not a predicted DL/PL candidate';
}

function ml_student_semester_prediction(
    int $studentId,
    int $semesterId
): array {
    require_once __DIR__ . '/gradebook.php';

    $stmt = db()->prepare(
        'SELECT pa.id AS assignment_id
         FROM enrollments e
         JOIN professor_assignments pa ON pa.id = e.assignment_id
         WHERE e.student_id = ? AND pa.semester_id = ?
         ORDER BY pa.id'
    );

    $stmt->execute([$studentId, $semesterId]);

    $prelimGrades = [];
    $midtermGrades = [];
    $finalGrades = [];

    $subjectCount = 0;
    $prelimCompleteCount = 0;
    $midtermCompleteCount = 0;
    $finalCompleteCount = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $book = build_gradebook((int) $row['assignment_id']);
        $found = false;

        foreach ($book['students'] ?? [] as $subjectStudent) {
            if ((int) $subjectStudent['student_id'] !== $studentId) {
                continue;
            }

            $found = true;
            $subjectCount++;

            $prelim = $subjectStudent['periods']['prelim'] ?? [];
            $midterm = $subjectStudent['periods']['midterm'] ?? [];
            $finals = $subjectStudent['periods']['finals'] ?? [];

            if (
                !empty($prelim['complete']) &&
                isset($prelim['equivalent']) &&
                is_numeric($prelim['equivalent'])
            ) {
                $prelimGrades[] = (float) $prelim['equivalent'];
                $prelimCompleteCount++;
            }

            if (
                !empty($midterm['complete']) &&
                isset($midterm['equivalent']) &&
                is_numeric($midterm['equivalent'])
            ) {
                $midtermGrades[] = (float) $midterm['equivalent'];
                $midtermCompleteCount++;
            }

            $finalEquivalent = $subjectStudent['final_equivalent'] ?? null;
            $remarks = strtoupper((string) ($subjectStudent['remarks'] ?? ''));

            if (
                $remarks !== 'INC' &&
                $finalEquivalent !== null &&
                $finalEquivalent !== '' &&
                is_numeric($finalEquivalent)
            ) {
                $finalGrades[] = (float) $finalEquivalent;
                $finalCompleteCount++;
            }

            break;
        }

        if (!$found) {
            // Missing subject record: do not claim all grades are complete.
            $subjectCount++;
        }
    }

    $base = [
        'gwa' => null,
        'stage' => null,
        'subjects' => $subjectCount,
        'prelim_complete' => $prelimCompleteCount,
        'midterm_complete' => $midtermCompleteCount,
        'final_complete' => $finalCompleteCount,
        'mode' => 'pending',
        'candidate_status' => 'Pending',
    ];

    if ($subjectCount === 0) {
        return $base;
    }

    // All subject final grades are complete: use actual grades, not ML.
    if ($finalCompleteCount === $subjectCount) {
        $actualGwa = round(
            array_sum($finalGrades) / $subjectCount,
            2
        );

        $disqualified = false;

        foreach ($finalGrades as $grade) {
            if ($grade >= 2.00) {
                $disqualified = true;
                break;
            }
        }

        if ($disqualified) {
            $status = 'Not Eligible';
        } elseif ($actualGwa >= 1.00 && $actualGwa <= 1.25) {
            $status = 'PL Candidate';
        } elseif ($actualGwa >= 1.26 && $actualGwa <= 1.75) {
            $status = 'DL Candidate';
        } else {
            $status = 'Not Eligible';
        }

        return [
            ...$base,
            'gwa' => $actualGwa,
            'stage' => 'Actual Final Grades',
            'mode' => 'actual',
            'candidate_status' => $status,
        ];
    }

    // Until Finals are complete, retain the existing ML prediction flow.
    if ($prelimCompleteCount !== $subjectCount) {
        return $base;
    }

    $prelimGwa = array_sum($prelimGrades) / $subjectCount;

    if ($midtermCompleteCount === $subjectCount) {
        $midtermGwa = array_sum($midtermGrades) / $subjectCount;

        $gwa = ml_gwa_predict([
            'prelim_gwa' => $prelimGwa,
            'midterm_gwa' => $midtermGwa,
        ], 'midterm');

        return [
            ...$base,
            'gwa' => $gwa,
            'stage' => 'Prelim + Midterm',
            'mode' => 'predicted',
            'candidate_status' => ml_candidate_status($gwa),
        ];
    }

    $gwa = ml_gwa_predict([
        'prelim_gwa' => $prelimGwa,
    ], 'prelim');

    return [
        ...$base,
        'gwa' => $gwa,
        'stage' => 'Prelim-only',
        'mode' => 'predicted',
        'candidate_status' => ml_candidate_status($gwa),
    ];
}
