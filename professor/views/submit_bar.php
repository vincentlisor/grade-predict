<?php
declare(strict_types=1);

$ownsSheet = ($user['portal'] ?? '') === 'professor'
    && in_array('professor', $user['roles'] ?? [], true)
    && (int) ($assignment['professor_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
if (!$ownsSheet || assignment_is_finalized($assignment)) {
    return;
}

$submittedPeriods = submitted_period_keys($assignment);
$openPeriods = array_values(array_diff(array_keys(PERIODS), $submittedPeriods));
$readyPeriods = [];
foreach (array_keys(PERIODS) as $key) {
    $readyPeriods[$key] = period_unfilled($book, $key) === [];
}
$remainingReady = $openPeriods !== [] && array_reduce($openPeriods, static fn (bool $ok, string $key): bool => $ok && $readyPeriods[$key], true);
?>
<section class="card submit-panel">
    <div class="card-head">
        <h2>Submit grades</h2>
    </div>
    <?php if ($submittedPeriods !== []): ?>
        <p class="hint">Already submitted: <?= h(period_list_label($submittedPeriods)) ?>.</p>
    <?php endif; ?>
    <?php if ($openPeriods === []): ?>
        <p class="hint">Every period is with the Program Chair.</p>
    <?php else: ?>
        <div class="submit-choices">
            <?php foreach (PERIODS as $key => $label): ?>
                <?php
                $sent = in_array($key, $submittedPeriods, true);
                $ready = $readyPeriods[$key];
                $disabled = $sent || !$ready;
                ?>
                <button class="btn <?= $disabled ? 'btn-ghost' : '' ?>" type="button" data-submit-scope="<?= h($key) ?>" <?= $disabled ? 'disabled' : '' ?>>
                    <?php if ($sent): ?>
                        <?= h($label) ?> submitted
                    <?php elseif (!$ready): ?>
                        <?= h($label) ?> not finished
                    <?php else: ?>
                        Submit <?= h($label) ?>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
            <button class="btn <?= $remainingReady ? 'btn-gold' : 'btn-ghost' ?>" type="button" data-submit-scope="all" <?= $remainingReady ? '' : 'disabled' ?>>
                <?= $submittedPeriods === [] ? 'Submit all periods' : 'Submit the remaining periods' ?>
            </button>
        </div>
        <dialog id="submitDialog">
            <h3 style="margin-top:0;">Submit these grades?</h3>
            <p id="submitDialogText" style="color:#5C6B7A;">The Program Chair will receive the periods you chose.</p>
            <form method="post" action="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => $view, 'period' => $period]))) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="submit_scope" id="submitScope" value="">
                <div class="dialog-actions">
                    <button class="btn btn-ghost" type="button" id="submitCancel">Cancel</button>
                    <button class="btn btn-gold" type="submit">Submit</button>
                </div>
            </form>
        </dialog>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const dialog = document.getElementById('submitDialog');
            const scope = document.getElementById('submitScope');
            const text = document.getElementById('submitDialogText');
            const names = <?= json_encode(PERIODS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            document.querySelectorAll('[data-submit-scope]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const chosen = button.getAttribute('data-submit-scope') || '';
                    scope.value = chosen;
                    const label = chosen === 'all' ? 'all remaining periods' : (names[chosen] || 'this period');
                    text.textContent = 'Submit ' + label + '? Those periods stay locked until they are returned.';
                    dialog.showModal();
                });
            });
            document.getElementById('submitCancel').addEventListener('click', function () {
                dialog.close();
            });
        });
        </script>
    <?php endif; ?>
</section>
