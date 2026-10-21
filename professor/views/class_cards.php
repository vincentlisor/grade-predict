<?php
declare(strict_types=1);
?>

<style>
/* Class Cards Grid Layout */
.class-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 20px;
}

/* Modernized Card Item */
.modern-class-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    text-decoration: none !important;
    color: inherit;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}

.modern-class-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    border-color: #cbd5e1;
}

/* Top Section (Badge & Icon) */
.class-card-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.class-icon-wrapper {
    width: 36px;
    height: 36px;
    background: #f1f5f9;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0f172a;
}

/* Code & Subject Titles */
.class-card-heading {
    margin: 0 0 4px 0;
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.2px;
}

.class-subject-title {
    margin: 0 0 12px 0;
    font-size: 0.92rem;
    font-weight: 600;
    color: #334155;
}

.class-meta-info {
    margin: 0 0 16px 0;
    font-size: 0.82rem;
    color: #64748b;
    line-height: 1.4;
}

/* Footer Section */
.class-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
}

.open-link-action {
    color: #2563eb;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 4px;
}
</style>

<div class="class-grid">
    <?php foreach ($classes as $class): ?>
        <?php
        $status = (string) ($class['status'] ?? 'draft');
        $professorStatus = match ($status) {
            'draft' => 'Draft',
            'returned' => 'Returned for correction',
            'registrar_finalized' => 'Finalized',
            default => 'Submitted · Under review',
        };
        ?>
        <a class="modern-class-card" href="<?= h(url('professor/class.php', ['id' => $class['id']])) ?>">
            <div>
                <div class="class-card-top-bar">
                    <div class="class-icon-wrapper">
                        <?= icon('classes') ?>
                    </div>
                    <div>
                        <?= badge($professorStatus, $status) ?>
                    </div>
                </div>

                <h3 class="class-card-heading">
                    <?= h($class['subject_code']) ?> · <?= h($class['section_name']) ?>
                </h3>

                <p class="class-subject-title">
                    <?= h($class['subject_name']) ?>
                </p>

                <p class="class-meta-info">
                    <?= h($class['program_code']) ?> · <?= h($class['year_level']) ?> · <?= h($class['year_label']) ?> <?= h($class['semester_name']) ?>
                </p>
            </div>

            <div class="class-card-bottom">
                <span style="display: inline-flex; align-items: center; gap: 6px;"><?= icon('students') ?> <?= (int) $class['student_count'] ?> students</span>
                <span class="open-link-action">Open Gradebook <?= icon('open') ?></span>
            </div>
        </a>
    <?php endforeach; ?>
</div>