<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (file_exists(__DIR__ . '/../../includes/bootstrap.php')) {
        require_once __DIR__ . '/../../includes/bootstrap.php';
    }
}

// Ensure clean_pct helper function is available in settings view
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

$currentWeights = $book['weights'][$period] ?? [];
$periodData = $book['items'][$period] ?? [];
?>

<div class="settings-container" style="max-width: 950px; margin: 0 auto; padding: 20px 0; margin-top: 12px;">
    
    <!-- SECTION 1: CATEGORY WEIGHTS CONFIGURATION -->
    <form method="POST" action="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => 'settings', 'period' => $period]))) ?>" style="margin-bottom: 24px;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">

        <div style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
            <div style="background: #0f172a; padding: 16px 24px; color: #ffffff; display: flex; align-items: center; justify-content: space-between;">
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                    <?= icon('settings') ?> Category Weights Configuration
                </h3>
                <span style="background: #334155; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">
                    Active Period: <?= strtoupper(h($period)) ?>
                </span>
            </div>

            <div style="padding: 24px; background: #f8fafc;">
                <p style="margin-top: 0; margin-bottom: 20px; color: #64748b; font-size: 0.9rem;">
                    Adjust percentage weights for each grading category. Total combined weight across active categories must equal exactly 100%.
                </p>

                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px;">
                    <?php foreach (CATEGORIES as $catKey => $catLabel): ?>
                        <?php 
                        $isExam = ($catKey === 'exam');
                        $val = (float) ($currentWeights[$catKey] ?? 0);
                        ?>
                        <div style="background: #ffffff; padding: 16px; border-radius: 8px; border: 1px solid #cbd5e1;">
                            <label style="display: flex; justify-content: space-between; align-items: center; font-weight: 700; color: #0f172a; margin-bottom: 8px; font-size: 0.9rem;">
                                <span><?= h($catLabel) ?></span>
                                <?php if ($isExam): ?>
                                    <small style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem;">Fixed 40%</small>
                                <?php endif; ?>
                            </label>
                            <div style="display: flex; align-items: center;">
                                <input type="number" 
                                       name="weights[<?= h($catKey) ?>]" 
                                       value="<?= h((string) $val) ?>" 
                                       step="0.01" 
                                       min="0" 
                                       max="100" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #94a3b8; border-radius: 6px 0 0 6px; font-weight: 700; color: #0f172a; background: <?= $isExam ? '#f1f5f9' : '#ffffff' ?>;"
                                       <?= $isExam ? 'readonly' : '' ?>
                                       required>
                                <span style="background: #cbd5e1; padding: 8px 12px; border: 1px solid #94a3b8; border-left: none; border-radius: 0 6px 6px 0; font-weight: 700; color: #334155;">%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="background: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    <h4 style="margin-top: 0; margin-bottom: 12px; font-size: 0.95rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <?= icon('pin') ?> Apply Scope
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; color: #334155;">
                            <input type="radio" name="apply_scope" value="prelim" <?= $period === 'prelim' ? 'checked' : '' ?>>
                            Apply to <strong style="color: #2563eb;">PRELIM</strong> period only
                        </label>

                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; color: #334155;">
                            <input type="radio" name="apply_scope" value="midterm" <?= $period === 'midterm' ? 'checked' : '' ?>>
                            Apply to <strong style="color: #2563eb;">MIDTERM</strong> period only
                        </label>

                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; color: #334155;">
                            <input type="radio" name="apply_scope" value="finals" <?= $period === 'finals' ? 'checked' : '' ?>>
                            Apply to <strong style="color: #2563eb;">FINALS</strong> period only
                        </label>

                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; color: #334155;">
                            <input type="radio" name="apply_scope" value="all">
                            Apply across <strong style="color: #16a34a;">ALL PERIODS</strong> (Prelim, Midterm, & Finals)
                        </label>
                    </div>
                </div>
            </div>

            <div style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; align-items: center;">
                <button type="submit" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 24px; border-radius: 6px; border: none; background: #2563eb; color: #ffffff; font-weight: 700; cursor: pointer;">
                    <?= icon('check') ?> Save Changes
                </button>
            </div>
        </div>
    </form>

    <!-- SECTION 2: ADD ASSESSMENT COLUMN -->
    <form method="POST" action="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => 'settings', 'period' => $period]))) ?>" style="margin-bottom: 24px;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_component">
        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">

        <div style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
            <div style="background: #0f172a; padding: 16px 24px; color: #ffffff;">
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                    <?= icon('plus') ?> Add Assessment Column
                </h3>
            </div>

            <div style="padding: 24px; background: #f8fafc;">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
                    <div>
                        <label style="display: block; font-weight: 700; color: #0f172a; margin-bottom: 6px; font-size: 0.88rem;">Category</label>
                        <select name="new_category" style="width: 100%; padding: 9px 12px; border: 1px solid #94a3b8; border-radius: 6px; font-weight: 600; color: #0f172a; background: #ffffff;" required>
                            <?php foreach (CATEGORIES as $key => $label): ?>
                                <?php if ((float)($currentWeights[$key] ?? 0) > 0): ?>
                                    <option value="<?= h($key) ?>"><?= h($label) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #0f172a; margin-bottom: 6px; font-size: 0.88rem;">Apply Period</label>
                        <select name="new_period" style="width: 100%; padding: 9px 12px; border: 1px solid #94a3b8; border-radius: 6px; font-weight: 600; color: #0f172a; background: #ffffff;">
                            <option value="prelim" <?= $period === 'prelim' ? 'selected' : '' ?>>PRELIM only</option>
                            <option value="midterm" <?= $period === 'midterm' ? 'selected' : '' ?>>MIDTERM only</option>
                            <option value="finals" <?= $period === 'finals' ? 'selected' : '' ?>>FINALS only</option>
                            <option value="all">ALL PERIODS (Prelim, Midterm, & Finals)</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; color: #0f172a; margin-bottom: 6px; font-size: 0.88rem;">Max Score / Items</label>
                        <input type="number" name="new_max" placeholder="Default: 10 (e.g. 10, 20, 100)" min="1" max="1000" style="width: 100%; padding: 8px 12px; border: 1px solid #94a3b8; border-radius: 6px; font-weight: 600; color: #0f172a;">
                    </div>
                </div>
            </div>

            <div style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #e2e8f0; text-align: right;">
                <button type="submit" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 24px; border-radius: 6px; border: none; background: #16a34a; color: #ffffff; font-weight: 700; cursor: pointer;">
                    <?= icon('plus') ?> Add Column
                </button>
            </div>
        </div>
    </form>

    <!-- SECTION 3: MANAGE & DELETE EXISTING COLUMNS -->
    <div style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
        <div style="background: #0f172a; padding: 16px 24px; color: #ffffff;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                <?= icon('trash') ?> Manage & Delete Existing Columns (<?= strtoupper(h($period)) ?>)
            </h3>
        </div>

        <div style="padding: 24px; background: #ffffff;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                <thead>
                    <tr style="border-bottom: 2px solid #e2e8f0; color: #475569;">
                        <th style="padding: 10px; font-weight: 700;">Category</th>
                        <th style="padding: 10px; font-weight: 700;">Code</th>
                        <th style="padding: 10px; font-weight: 700;">Label</th>
                        <th style="padding: 10px; font-weight: 700;">Max Score</th>
                        <th style="padding: 10px; font-weight: 700; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $hasCols = false;
                    foreach (CATEGORIES as $catKey => $catLabel):
                        if ((float)($currentWeights[$catKey] ?? 0) <= 0) {
                            continue;
                        }
                        
                        foreach ($periodData[$catKey] ?? [] as $col):
                            $hasCols = true;
                    ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px 10px; font-weight: 600; color: #0f172a;"><?= h($catLabel) ?></td>
                            <td style="padding: 12px 10px; font-weight: 700; color: #2563eb;"><?= h($col['code']) ?></td>
                            <td style="padding: 12px 10px; color: #334155;"><?= h($col['label']) ?></td>
                            <td style="padding: 12px 10px; font-weight: 700; color: #0f172a;"><?= h(clean_pct($col['max_score'])) ?></td>
                            <td style="padding: 12px 10px; text-align: right;">
                                <form method="POST" action="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => 'settings', 'period' => $period]))) ?>" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete column <?= h($col['code']) ?>? All encoded scores in this column will be permanently removed.');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_component">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                                    <input type="hidden" name="component_id" value="<?= (int) $col['id'] ?>">
                                    <button type="submit" style="display: inline-flex; align-items: center; gap: 4px; background: #ef4444; color: #ffffff; border: none; padding: 6px 12px; border-radius: 4px; font-weight: 700; font-size: 0.8rem; cursor: pointer;">
                                        <?= icon('trash') ?> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php 
                        endforeach;
                    endforeach; 
                    if (!$hasCols):
                    ?>
                        <tr><td colspan="5" style="padding: 16px; text-align: center; color: #94a3b8;">No active assessment columns found for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>