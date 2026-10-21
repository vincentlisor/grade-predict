<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

db_or_setup();
$user = require_user();
$userId = (int) $user['id'];

// Handle POST actions for Mark as Read / Mark All as Read / Click-to-Open
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $notifId = (int) ($_POST['notification_id'] ?? 0);

    if ($action === 'open_notif' && $notifId > 0) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
            ->execute([$notifId, $userId]);
        
        $targetUrl = trim((string) ($_POST['target_url'] ?? ''));
        if ($targetUrl !== '') {
            redirect($targetUrl);
        }
    } elseif ($action === 'mark_read' && $notifId > 0) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
            ->execute([$notifId, $userId]);
        flash('ok', 'Notification marked as read.');
    } elseif ($action === 'mark_all_read') {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
            ->execute([$userId]);
        flash('ok', 'All notifications marked as read.');
    }

    $side = trim((string) ($_GET['side'] ?? ''));
    redirect('notifications.php' . ($side !== '' ? '?side=' . urlencode($side) : ''));
}

$rows = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$rows->execute([$userId]);
$rows = $rows->fetchAll();

$unreadCount = 0;
foreach ($rows as $r) {
    if ((int) $r['is_read'] === 0) {
        $unreadCount++;
    }
}

layout_start('Notifications', 'dash', [['label' => 'Notifications']]);
?>
<div style="max-width: 720px; margin: 24px auto 0;">
    <section class="card" style="margin: 0;">
        <div class="card-head" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--line, #e2e8f0); padding-bottom: 14px; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <h2 style="margin: 0; font-size: 1.25rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <?= icon('bell') ?> Notifications
            </h2>
            <div style="display: flex; align-items: center; gap: 10px;">
                <?php if ($unreadCount > 0): ?>
                    <form method="post" style="margin: 0;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button class="btn btn-ghost btn-sm" type="submit" style="font-size: 0.8rem; padding: 4px 10px;">
                            Mark all as read
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($rows !== []): ?>
                    <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 600; padding: 2px 10px; border-radius: 12px; font-size: 0.8rem;">
                        <?= count($rows) ?> total
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--muted, #64748b);">
                <div class="empty-icon"><?= icon('bell') ?></div>
                <p class="empty" style="margin: 0; font-weight: 500;">No notifications yet.</p>
                <small>Updates about your classes and grade submissions will appear here.</small>
            </div>
        <?php else: ?>
            <ul class="timeline" style="list-style: none; padding: 0; margin: 0;">
                <?php foreach ($rows as $row): ?>
                    <?php 
                    $isUnread = ((int)$row['is_read'] === 0); 
                    $linkUrl = $row['link_url'] ? url($row['link_url']) : '';
                    ?>
                    <li 
                        onclick="<?= $linkUrl !== '' ? 'document.getElementById(\'notif-open-' . (int)$row['id'] . '\').submit();' : '' ?>"
                        style="padding: 14px 16px; border-bottom: 1px solid var(--line, #f1f5f9); border-radius: 8px; margin-bottom: 6px; transition: background 0.15s ease; <?= $isUnread ? 'background: #f0f9ff;' : 'background: #ffffff;' ?> <?= $linkUrl !== '' ? 'cursor: pointer;' : '' ?>"
                        onmouseover="this.style.background='<?= $isUnread ? '#e0f2fe' : '#f8fafc' ?>'"
                        onmouseout="this.style.background='<?= $isUnread ? '#f0f9ff' : '#ffffff' ?>'"
                    >
                        <?php if ($linkUrl !== ''): ?>
                            <form id="notif-open-<?= (int)$row['id'] ?>" method="post" style="display: none;">
                                <input type="hidden" name="action" value="open_notif">
                                <input type="hidden" name="notification_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="target_url" value="<?= h($row['link_url']) ?>">
                            </form>
                        <?php endif; ?>

                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px; flex: 1;">
                                <strong style="font-size: 0.95rem; color: var(--fg, #0f172a);"><?= h($row['title']) ?></strong>
                                <?php if ($isUnread): ?>
                                    <span class="badge" style="background: #0284c7; color: #ffffff; font-size: 0.7rem; padding: 1px 6px; border-radius: 8px;">Unread</span>
                                <?php endif; ?>
                            </div>

                            <div style="display: flex; align-items: center; gap: 10px;" onclick="event.stopPropagation();">
                                <small style="color: var(--muted, #64748b); white-space: nowrap; font-size: 0.8rem;"><?= fmt_when($row['created_at']) ?></small>
                                <?php if ($isUnread): ?>
                                    <form method="post" style="margin: 0;">
                                        <input type="hidden" name="notification_id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="mark_read">
                                        <button class="btn btn-ghost btn-sm" type="submit" style="font-size: 0.78rem; padding: 2px 8px; color: #475569;">
                                            Mark as read
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="margin-top: 6px; font-size: 0.9rem; color: var(--body, #334155); line-height: 1.4;">
                            <?= h($row['body']) ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php layout_end(); ?>