<?php
declare(strict_types=1);
$current = $assignment['submission_status'] ?? 'draft';
$professorStage = match ($current) {
    'draft' => 'draft',
    'returned' => 'returned',
    'registrar_finalized' => 'final',
    default => 'review',
};

// Dynamic header title based on current active view
$pageHeading = match ($view) {
    'settings'   => 'Class Assessment & Category Settings',
    'summary'    => 'Grade Summary & Analytics',
    'final'      => 'Final Grades & Predictions',
    'equivalent' => 'Grade Equivalent Scale',
    default      => 'College grading sheet',
};
?>
<section class="sheet-top">
    <img src="<?= h(url('img/logo.png')) ?>" alt="">
    <div class="sheet-title">
        <p><?= h(SCHOOL_NAME) ?></p>
        <h1><?= h($pageHeading) ?></h1>
    </div>
    <div class="sheet-meta">
        <div>Section <strong><?= h($assignment['section_name']) ?></strong></div>
        <div>Teacher / Professor <strong><?= h(trim($assignment['professor_title'] . ' ' . $assignment['professor_name'])) ?></strong></div>
        <div><?= h($assignment['subject_code']) ?> · <?= h($assignment['subject_name']) ?></div>
        <div><?= h($assignment['program_code']) ?> · <?= h($assignment['year_label']) ?> <?= h($assignment['semester_name']) ?></div>
    </div>
</section>

<?php if (!in_array($view, ['settings', 'equivalent'], true)): ?>
<div class="tracker" aria-label="Submission progress">
    <span class="<?= $professorStage === 'draft' ? 'is-now' : 'is-done' ?>">Draft</span>
    <span class="<?= in_array($professorStage, ['review', 'returned'], true) ? 'is-now' : ($professorStage === 'final' ? 'is-done' : '') ?>">Submitted · Under review</span>
    <span class="<?= $professorStage === 'final' ? 'is-now' : '' ?>">Finalized</span>
    <?php if ($professorStage === 'returned'): ?><?= badge('Returned for correction', 'returned') ?><?php endif; ?>
</div>
<?php endif; ?>

<?php
$submittedNow = submitted_period_keys($assignment);
if (in_array($view, ['sheet', 'summary'], true) && isset(PERIODS[$period]) && in_array($period, $submittedNow, true)): ?>
    <div class="alert alert-info"><?= h(PERIODS[$period]) ?> is read-only because it was submitted. Other periods that are still open can be encoded.</div>
<?php elseif ($view === 'settings' && professor_owns_assignment($assignment, $user) && !$structureEditable && !assignment_is_finalized($assignment)): ?>
    <div class="alert alert-info">Column changes stay locked while a period is under review.</div>
<?php elseif (assignment_is_finalized($assignment)): ?>
    <div class="alert alert-info">This sheet is read-only. The record is finalized.</div>
<?php endif; ?>
<?php if ($submittedNow !== []): ?>
    <p class="hint">Submitted: <?= h(period_list_label($submittedNow)) ?>.</p>
<?php endif; ?>
<?php if (!empty($assignment['return_note'])): ?>
    <div class="alert alert-warn">Returned note: <?= h($assignment['return_note']) ?></div>
<?php endif; ?>
<?php if (!empty($assignment['flag_note'])): ?>
    <div class="alert alert-warn">Flag: <?= h($assignment['flag_note']) ?></div>
<?php endif; ?>

<?php if ($view === 'sheet'): ?>
    <p class="hint" style="margin-top:0;">A blank cell is not encoded yet and stays out of the total. Type 0 when the student scored zero. Offline scores stay on this device and sync to the college record when you reconnect.</p>
<?php endif; ?>

<?php if (in_array($view, ['sheet', 'summary'], true)): ?>
<nav class="period-switch" aria-label="Grading period">
    <?php foreach (PERIODS as $key => $label): ?>
        <a class="<?= $period === $key ? 'is-active' : '' ?>" href="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => $view, 'period' => $key]))) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php
$warning = weight_warning((float) ($book['students'][0]['periods'][$period]['weight_total'] ?? array_sum($book['weights'][$period] ?? [])), PERIODS[$period]);
if ($warning): ?>
    <div class="alert alert-warn"><?= h($warning) ?></div>
<?php endif; ?>
<?php endif; ?>