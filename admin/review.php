<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'queue');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$submission = db()->prepare('SELECT * FROM grade_submissions WHERE id = ?');
$submission->execute([$id]);
$submission = $submission->fetch();

if (!$submission) {
    http_response_code(404);
    exit('Submission not found.');
}

$assignment = fetch_assignment((int) $submission['assignment_id']);
$scope = scoped_program_ids($user);

if (!$assignment || (is_array($scope) && !in_array((int) $assignment['program_id'], $scope, true))) {
    http_response_code(403);
    exit('This submission is outside your program.');
}

$isOicOrOsa = in_array('college_oic', $user['roles'], true) || in_array('osa', $user['roles'], true);

$canViewCurrent = user_can_review($user, $assignment, (string) $submission['status']);
if (!$canViewCurrent && !in_array('system_admin', $user['roles'], true) && !$isOicOrOsa) {
    $handled = db()->prepare('SELECT 1 FROM submission_reviews WHERE submission_id = ? AND reviewer_id = ? LIMIT 1');
    $handled->execute([$id, (int) $user['id']]);
    if (!$handled->fetchColumn()) {
        http_response_code(403);
        exit('This submission is not assigned to your office.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($isOicOrOsa) {
        flash('error', 'OIC and OSA roles have monitoring-only access.');
        redirect('admin/review.php', ['id' => $id]);
    }
    try {
        review_submission($user, $id, (string) ($_POST['review_action'] ?? ''), (string) ($_POST['remarks'] ?? ''));
        flash('ok', 'Review action recorded successfully.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/review.php', ['id' => $id]);
}

$book = build_gradebook((int) $assignment['id']);
$history = db()->prepare(
    'SELECT r.*, u.full_name, u.title FROM submission_reviews r LEFT JOIN users u ON u.id = r.reviewer_id WHERE r.submission_id = ? ORDER BY r.id ASC'
);
$history->execute([$id]);
$history = $history->fetchAll();

// OIC & OSA accounts are strictly monitoring-only
$canAct = $isOicOrOsa ? false : user_can_review($user, $assignment, $submission['status']);

// Calculate quick stats
$totalStudents = count($book['students']);
$passedCount = 0;
foreach ($book['students'] as $st) {
    if (strtoupper((string)($st['remarks'] ?? '')) === 'PASSED') {
        $passedCount++;
    }
}
$passRate = $totalStudents > 0 ? round(($passedCount / $totalStudents) * 100, 1) : 0;

layout_start('Review ' . $assignment['subject_code'], 'queue', [
    ['label' => 'Review queue', 'href' => 'admin/queue.php'],
    ['label' => $assignment['subject_code'] . ' ' . $assignment['section_name']],
]);
?>
<div class="page-head">
    <div>
        <h1><?= h($assignment['subject_code']) ?> · <?= h($assignment['section_name']) ?></h1>
        <p class="lede"><?= h($assignment['subject_name']) ?> · <?= h(trim(($assignment['professor_title'] ?? '') . ' ' . $assignment['professor_name'])) ?><?php $sentPeriods = submitted_period_keys($assignment); if ($sentPeriods !== []): ?> · Submitted: <?= h(period_list_label($sentPeriods)) ?><?php endif; ?></p>
    </div>
    <div>
        <?= badge(submission_label($submission['status']), $submission['status']) ?>
    </div>
</div>

<!-- Class Overview Metrics -->
<section class="kpis">
    <article class="kpi">
        <span>Enrolled Students</span>
        <strong><?= $totalStudents ?></strong>
    </article>
    <article class="kpi">
        <span>Class Passing Rate</span>
        <strong><?= $passRate ?>%</strong>
    </article>
    <article class="kpi">
        <span>Current Workflow Stage</span>
        <strong style="font-size:16px;"><?= h(submission_label($submission['status'])) ?></strong>
    </article>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= icon('sheet') ?> Computed Grades Sheet</h2>
        <a class="btn btn-ghost btn-sm" href="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => 'final']))) ?>" target="_blank">
            <?= icon('sheet') ?> View Full Sheet
        </a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Type</th>
                    <th>Final %</th>
                    <th>Equivalent</th>
                    <th>Remarks</th>
                    <th>Predicted Eqv</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($book['students'] as $student): ?>
                <tr>
                    <td>
                        <strong><?= h($student['full_name']) ?></strong>
                        <br><span class="mono hint"><?= h($student['student_number']) ?></span>
                    </td>
                    <td><?= badge((string) $student['student_type'], (string) $student['student_type']) ?></td>
                    <td><?= fmt_pct($student['final_percent']) ?></td>
                    <td><strong><?= fmt_eqv($student['final_equivalent']) ?></strong></td>
                    <td><?= badge((string) $student['remarks'], (string) $student['remarks']) ?></td>
                    <td><?= fmt_eqv($student['predicted_equivalent']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($canAct): ?>
<section class="card">
    <h2><?= icon('queue') ?> Action for Your Office</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <label for="remarks">Review Remarks / Notes</label>
        <textarea id="remarks" name="remarks" rows="3" placeholder="Required when returning or flagging for corrections"></textarea>
        <div class="dialog-actions">
            <button class="btn btn-gold" name="review_action" value="approve" type="submit">
                <?= $submission['status'] === 'osa_endorsed' ? 'Finalize and Lock Class Grades' : 'Approve & Forward to Next Office' ?>
            </button>
            <button class="btn btn-ghost" name="review_action" value="return" type="submit">Return to Previous Stage</button>
            <button class="btn btn-danger" name="review_action" value="flag" type="submit">Flag Discrepancy</button>
        </div>
    </form>
</section>
<?php else: ?>
    <div class="alert alert-info">
        <strong>Workflow Note:</strong> <?= $isOicOrOsa ? 'Your account has monitoring-only access. Primary verification and approval actions are assigned to the Program Chair.' : 'Review actions for this status belong to another office. You can inspect the computed grades and full audit timeline above.' ?>
    </div>
<?php endif; ?>

<section class="card">
    <h2><?= icon('audit') ?> Class Verification History</h2>
    <?php if ($history === []): ?>
        <p class="empty">No review notes recorded yet.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($history as $item): ?>
                <li>
                    <strong><?= h(ucfirst((string) $item['action'])) ?></strong> by 
                    <?= h(!empty($item['full_name']) ? trim((string)($item['title'] ?? '') . ' ' . (string)$item['full_name']) : 'System') ?>
                    · <small><?= h((string) $item['from_status']) ?> → <?= h((string) $item['to_status']) ?></small>
                    <?php if (!empty($item['remarks'])): ?>
                        <div style="margin-top:4px;"><?= h((string) $item['remarks']) ?></div>
                    <?php endif; ?>
                    <small class="hint"><?= fmt_when($item['created_at']) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php layout_end(); ?>