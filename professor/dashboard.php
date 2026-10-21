<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('professor');
$classes = professor_class_rows((int) $user['id']);

$students = 0;
$incompleteStudents = 0;
$drafts = 0;
$submitted = 0;
$returned = 0;

foreach ($classes as $class) {
    $students += (int) $class['student_count'];
    $status = $class['status'] ?? 'draft';
    if ($status === 'draft') $drafts++;
    elseif ($status === 'returned') $returned++;
    elseif ($status !== 'registrar_finalized') $submitted++;
    
    if (in_array($status, ['draft', 'returned'], true)) {
        $book = build_gradebook((int) $class['id']);
        foreach ($book['students'] as $student) {
            if ($student['remarks'] === 'INC') {
                $incompleteStudents++;
            }
        }
    }
}

$history = db()->prepare(
    'SELECT sr.action, sr.to_status, sr.remarks, sr.created_at,
            s.code AS subject_code, sec.name AS section_name
     FROM submission_reviews sr
     JOIN grade_submissions gs ON gs.id = sr.submission_id
     JOIN professor_assignments pa ON pa.id = gs.assignment_id
     JOIN professors pr ON pr.id = pa.professor_id
     JOIN subjects s ON s.id = pa.subject_id
     JOIN sections sec ON sec.id = pa.section_id
     WHERE pr.user_id = ?
       AND sr.action IN ("submit", "return")
     ORDER BY sr.id DESC
     LIMIT 12'
);
$history->execute([(int) $user['id']]);
$history = $history->fetchAll();

layout_start('Dashboard', 'dash', [['label' => 'Dashboard']]);
?>

<style>
/* Modern KPI Card Overrides */
.kpi-grid-container {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.modern-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.modern-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.kpi-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}

.kpi-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.kpi-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}

.kpi-subtext {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 4px;
    font-weight: 600;
}

/* Deadline Banner */
.deadline-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.deadline-pills {
    display: flex;
    gap: 12px;
}

.deadline-pill {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85rem;
    color: #334155;
    font-weight: 600;
}

.deadline-pill strong {
    color: #2563eb;
}
</style>

<div class="page-head" style="margin-bottom: 24px;">
    <div>
        <h1 style="margin: 0; font-size: 1.75rem; font-weight: 800; color: #0f172a;">
            Good day, <?= h(display_name($user)) ?>
        </h1>
        <p class="lede" style="margin-top: 4px; color: #64748b; font-size: 0.95rem;">
            Every assigned class is listed below. Open a class subject to manage settings, encode, or submit grades.
        </p>
    </div>
</div>

<!-- MODERN KPI CARDS -->
<div class="kpi-grid-container">
    <div class="modern-kpi-card">
        <div class="kpi-icon-box" style="background: #eff6ff; color: #2563eb;">
            <?= icon('classes') ?>
        </div>
        <div>
            <div class="kpi-title">Assigned Classes</div>
            <div class="kpi-value"><?= count($classes) ?></div>
            <div class="kpi-subtext">Active teaching load</div>
        </div>
    </div>

    <div class="modern-kpi-card">
        <div class="kpi-icon-box" style="background: #f0fdf4; color: #16a34a;">
            <?= icon('students') ?>
        </div>
        <div>
            <div class="kpi-title">Total Students</div>
            <div class="kpi-value"><?= $students ?></div>
            <div class="kpi-subtext">Enrolled across classes</div>
        </div>
    </div>

    <div class="modern-kpi-card">
        <div class="kpi-icon-box" style="background: #fffbeb; color: #d97706;">
            <?= icon('queue') ?>
        </div>
        <div>
            <div class="kpi-title">Incomplete Students</div>
            <div class="kpi-value"><?= $incompleteStudents ?></div>
            <div class="kpi-subtext">Students with missing grades</div>
        </div>
    </div>

    <div class="modern-kpi-card">
        <div class="kpi-icon-box" style="background: #f3e8ff; color: #9333ea;">
            <?= icon('reports') ?>
        </div>
        <div>
            <div class="kpi-title">Class Status</div>
            <div class="kpi-value"><?= $drafts + $returned ?></div>
            <div class="kpi-subtext">
                <?= $submitted > 0 ? "<strong>{$submitted}</strong> pending review" : "Drafts & returned" ?>
            </div>
        </div>
    </div>
</div>

<!-- DEADLINES BANNER -->
<div class="deadline-card">
    <div style="display: flex; align-items: center; gap: 10px; font-weight: 700; color: #0f172a;">
        <?= icon('calendar') ?> Grade Submission Deadlines
    </div>
    <?php if ($classes === []): ?>
        <span style="color: #94a3b8; font-size: 0.9rem;">No class assigned yet.</span>
    <?php else: ?>
        <div class="deadline-pills">
            <div class="deadline-pill">Prelim: <strong><?= h(fmt_date($classes[0]['prelim_deadline'])) ?></strong></div>
            <div class="deadline-pill">Midterm: <strong><?= h(fmt_date($classes[0]['midterm_deadline'])) ?></strong></div>
            <div class="deadline-pill">Finals: <strong><?= h(fmt_date($classes[0]['finals_deadline'])) ?></strong></div>
        </div>
    <?php endif; ?>
</div>

<!-- CLASS CARDS LIST -->
<section style="margin-bottom: 32px;">
    <?php if ($classes === []): ?>
        <div style="background: #ffffff; padding: 32px; text-align: center; border-radius: 12px; border: 1px solid #e2e8f0; color: #94a3b8;">
            No class is assigned to this account yet.
        </div>
    <?php else: ?>
        <?php require __DIR__ . '/views/class_cards.php'; ?>
    <?php endif; ?>
</section>

<!-- SUBMISSION HISTORY -->
<section style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;">
        <h2 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
            <?= icon('audit') ?> Grade Submission History
        </h2>
    </div>

    <?php if ($history === []): ?>
        <p style="margin: 0; color: #94a3b8; font-size: 0.9rem; text-align: center; padding: 16px 0;">
            No grades have been submitted yet.
        </p>
    <?php else: ?>
        <div class="table-wrap" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem; text-align: left;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0; color: #475569;">
                        <th style="padding: 10px; font-weight: 700;">Class</th>
                        <th style="padding: 10px; font-weight: 700;">Action</th>
                        <th style="padding: 10px; font-weight: 700;">Remarks</th>
                        <th style="padding: 10px; font-weight: 700; text-align: right;">When</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $item): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px 10px; font-weight: 700; color: #0f172a;">
                            <?= h($item['subject_code'] . ' · ' . $item['section_name']) ?>
                        </td>
                        <td style="padding: 12px 10px;">
                            <?php if ($item['action'] === 'return'): ?>
                                <span style="background: #fef2f2; color: #dc2626; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 0.8rem;">
                                    Returned for correction
                                </span>
                            <?php else: ?>
                                <span style="background: #f0fdf4; color: #16a34a; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 0.8rem;">
                                    Grades submitted
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 10px; color: #64748b;">
                            <?= h((string) $item['remarks']) ?: '—' ?>
                        </td>
                        <td style="padding: 12px 10px; text-align: right; color: #94a3b8; font-weight: 600;">
                            <?= fmt_when($item['created_at']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>