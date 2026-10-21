<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('professor');
$classes = professor_class_rows((int) $user['id']);

layout_start('My classes', 'classes', [['label' => 'My classes']]);
?>
<div class="page-head">
    <div>
        <h1>My classes</h1>
        <p class="lede">Every class assigned to this account. Open a subject to use its grading sheet.</p>
    </div>
</div>
<?php if ($classes === []): ?>
    <p class="empty">No class is assigned to this account yet.</p>
<?php else: ?>
    <?php require __DIR__ . '/views/class_cards.php'; ?>
<?php endif; ?>
<?php layout_end(); ?>
