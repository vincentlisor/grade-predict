<?php
declare(strict_types=1);
$addLabels = [
    'attendance' => 'Week',
    'recitation' => 'Recitation',
    'activities' => 'Activity',
    'quizzes' => 'Quiz',
    'project' => 'Project',
    'exam' => 'Exam',
];
$tones = [
    'attendance' => 'tone-att',
    'recitation' => 'tone-rec',
    'activities' => 'tone-act',
    'quizzes' => 'tone-quiz',
    'project' => 'tone-proj',
    'exam' => 'tone-exam',
];
$askLabels = [
    'attendance' => 'Add a week',
    'recitation' => 'Add a recitation',
    'activities' => 'Add an activity',
    'quizzes' => 'Add a quiz',
    'project' => 'Add a project',
    'exam' => 'Add an exam',
];
$nouns = [
    'attendance' => 'week',
    'recitation' => 'recitation',
    'activities' => 'activity',
    'quizzes' => 'quiz',
    'project' => 'project',
    'exam' => 'exam',
];
$columnMode = $columnMode ?? 'sheet';
$columnDefaultScope = $columnDefaultScope ?? ($columnMode === 'settings' ? 'all' : $period);
$formAction = url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => $view, 'period' => $period]));
$periodName = PERIODS[$period] ?? 'This period';
?>
<div class="column-tools">
    <?php if ($columnMode === 'settings'): ?><h2 class="column-title">Add or clear columns</h2><?php endif; ?>
    <form class="add-bar" method="post" action="<?= h($formAction) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
        <input type="hidden" name="action" value="add_component">
        <div class="add-row">
            <?php foreach ($addLabels as $key => $label): ?>
                <button class="btn btn-sm btn-cat <?= h($tones[$key]) ?>" type="submit" name="new_category" value="<?= h($key) ?>" data-ask="<?= h($askLabels[$key]) ?>" data-noun="<?= h($nouns[$key]) ?>" data-ok="<?= h('Add ' . strtolower($label)) ?>">+ <?= h($label) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="add-options">
            <label for="new_period">Add to</label>
            <select id="new_period" name="new_period">
                <?php if ($columnMode === 'sheet'): ?>
                    <option value="<?= h($period) ?>" <?= $columnDefaultScope === $period ? 'selected' : '' ?>><?= h($periodName) ?> only</option>
                <?php else: ?>
                    <?php foreach (PERIODS as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $columnDefaultScope === $key ? 'selected' : '' ?>><?= h($label) ?> only</option>
                    <?php endforeach; ?>
                <?php endif; ?>
                <option value="all" <?= $columnDefaultScope === 'all' ? 'selected' : '' ?>>All periods</option>
            </select>
        </div>
        <?php if ($columnMode === 'settings'): ?>
            <details class="add-custom">
                <summary>Custom name or number of items</summary>
                <div class="column-add">
                    <div>
                        <label for="new_label">Name</label>
                        <input id="new_label" name="new_label" type="text" placeholder="Leave blank to number it automatically">
                    </div>
                    <div>
                        <label for="new_max"># of items</label>
                        <input id="new_max" name="new_max" type="number" min="1" max="1000" step="0.01" placeholder="Default">
                    </div>
                </div>
            </details>
        <?php endif; ?>
    </form>
    <?php
    $missing = [];
    $checkPeriods = $columnMode === 'sheet' ? [$period] : array_keys(PERIODS);
    foreach (CATEGORIES as $cat => $catLabel) {
        foreach ($checkPeriods as $key) {
            if (!category_visible($book, $key, $cat)) {
                $missing[$cat] = $catLabel;
                break;
            }
        }
    }
    ?>
    <?php if ($missing !== []): ?>
        <?php
        $toneKey = ['attendance' => 'att', 'recitation' => 'rec', 'activities' => 'act', 'quizzes' => 'quiz', 'project' => 'proj', 'exam' => 'exam'];
        $presentShares = [];
        foreach (CATEGORIES as $cat => $catLabel) {
            if (!category_visible($book, $period, $cat)) {
                continue;
            }
            $presentShares[] = [
                'key' => $cat,
                'label' => $catLabel,
                'percent' => (float) ($book['weights'][$period][$cat] ?? 0),
                'tone' => $toneKey[$cat] ?? 'att',
            ];
        }
        ?>
        <form class="add-back" method="post" action="<?= h($formAction) ?>" data-confirm-kind="restore" data-shares="<?= h(json_encode($presentShares, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
            <input type="hidden" name="action" value="restore_category">
            <p class="hint">Add a category back</p>
            <div class="add-row">
                <?php foreach ($missing as $key => $label): ?>
                    <?php $share = rtrim(rtrim(number_format(default_category_percent($key), 2), '0'), '.'); ?>
                    <button class="btn btn-sm btn-cat <?= h($tones[$key]) ?>" type="submit" name="new_category" value="<?= h($key) ?>" data-ask="<?= h('Add ' . strtolower($label) . ' back') ?>" data-add-label="<?= h($label) ?>" data-add-share="<?= h($share) ?>" data-add-tone="<?= h($toneKey[$key] ?? 'att') ?>">+ <?= h($label) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="add-options">
                <label for="restore_period">Add back to</label>
                <select id="restore_period" name="new_period">
                    <?php if ($columnMode === 'sheet'): ?>
                        <option value="<?= h($period) ?>"><?= h($periodName) ?> only</option>
                    <?php else: ?>
                        <?php foreach (PERIODS as $key => $label): ?>
                            <option value="<?= h($key) ?>"><?= h($label) ?> only</option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <option value="all" <?= $columnMode === 'settings' ? 'selected' : '' ?>>All periods</option>
                </select>
            </div>
        </form>
    <?php endif; ?>
    <?php if ($columnMode === 'sheet'): ?>
        <p class="hint">The × on a heading such as Attendance 10% removes that whole category after the other percents are set to 100%.<?= $missing !== [] ? ' Add it back with the button above, then set every percent so the period adds up to 100%.' : ' After you remove one, an add-back button appears here.' ?> The × on one column removes only that column.</p>
    <?php else: ?>
        <form class="clear-box" method="post" action="<?= h($formAction) ?>" data-confirm-kind="clear">
            <?= csrf_field() ?>
            <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
            <input type="hidden" name="action" value="delete_category">
            <div class="clear-row">
                <label for="clear_category">Remove</label>
                <select id="clear_category" name="category">
                    <?php foreach (CATEGORIES as $cat => $catLabel): ?>
                        <option value="<?= h($cat) ?>"><?= h($catLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="clear_period">in</label>
                <select id="clear_period" name="column_period">
                    <?php foreach (PERIODS as $key => $label): ?>
                        <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                    <option value="all" selected>All periods</option>
                </select>
                <button class="btn btn-sm btn-clear" type="submit">Remove category</button>
            </div>
            <p class="hint">This removes the category and sets its percent to 0. To remove one column, use Remove beside it in the table below.</p>
        </form>
    <?php endif; ?>
</div>
