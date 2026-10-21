<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'manage_users');

$officeRoles = [
    'system_admin' => 'System Administrator',
    'program_chair' => 'Program Chair',
    'college_oic' => 'College Department OIC',
    'osa' => 'Office of Student Affairs',
    'registrar' => 'Registrar',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $programId = (int) ($_POST['program_id'] ?? 0);
            if ($username === '' || $name === '' || !isset($officeRoles[$role]) || strlen($password) < 8) {
                throw new RuntimeException('Complete the account form with a password of at least 8 characters.');
            }
            if ($role === 'program_chair' && $programId <= 0) {
                throw new RuntimeException('A program chair must be assigned to one program.');
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO users (username, password_hash, full_name, title, must_change_password) VALUES (?, ?, ?, "", 1)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name]);
            $userId = (int) db()->lastInsertId();
            $roleId = db()->prepare('SELECT id FROM roles WHERE code = ?');
            $roleId->execute([$role]);
            db()->prepare('INSERT INTO user_roles (user_id, role_id, program_id) VALUES (?, ?, ?)')
                ->execute([$userId, (int) $roleId->fetchColumn(), $role === 'program_chair' ? $programId : null]);
            audit((int) $user['id'], 'account_create', 'user', (string) $userId, $role . ' ' . $username);
            db()->commit();
            flash('ok', 'Office account created successfully.');
        } elseif ($action === 'toggle') {
            $target = (int) ($_POST['user_id'] ?? 0);
            if ($target === (int) $user['id']) {
                throw new RuntimeException('You cannot disable the account you are currently using.');
            }
            db()->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?')->execute([$target]);
            audit((int) $user['id'], 'account_status', 'user', (string) $target, 'Active flag toggled');
            flash('ok', 'Account status updated.');
        } elseif ($action === 'update') {
            $target = (int) ($_POST['user_id'] ?? 0);
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $programId = (int) ($_POST['program_id'] ?? 0);
            if ($target <= 0 || $name === '' || !isset($officeRoles[$role])) {
                throw new RuntimeException('Complete the account name and role.');
            }
            if ($target === (int) $user['id'] && $role !== 'system_admin') {
                throw new RuntimeException('You cannot remove the System Administrator role from the account you are using.');
            }
            if ($role === 'program_chair' && $programId <= 0) {
                throw new RuntimeException('A Program Chair must have an assigned program.');
            }
            $roleStmt = db()->prepare('SELECT id FROM roles WHERE code = ?');
            $roleStmt->execute([$role]);
            $roleId = (int) $roleStmt->fetchColumn();
            db()->beginTransaction();
            db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$name, $target]);
            db()->prepare('DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code <> "professor"')->execute([$target]);
            db()->prepare('INSERT INTO user_roles (user_id, role_id, program_id) VALUES (?, ?, ?)')
                ->execute([$target, $roleId, $role === 'program_chair' ? $programId : null]);
            audit((int) $user['id'], 'account_update', 'user', (string) $target, $role . ' ' . $name);
            db()->commit();
            flash('ok', 'Office account updated successfully.');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'That username already exists.' : $e->getMessage());
    }
    redirect('admin/users.php');
}

$programs = db()->query('SELECT id, code FROM programs ORDER BY code')->fetchAll();
$accounts = db()->query(
    "SELECT u.id, u.username, u.full_name, u.is_active, r.name AS role_name, r.code AS role_code,
            p.id AS program_id, p.code AS program_code
     FROM users u
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     LEFT JOIN programs p ON p.id = ur.program_id
     WHERE r.code <> 'professor'
     ORDER BY u.full_name"
)->fetchAll();

// Calculate Summary Statistics
$totalOffice = count($accounts);
$programChairs = 0;
$activeOffice = 0;
foreach ($accounts as $acc) {
    if ((int)$acc['is_active'] === 1) $activeOffice++;
    if ($acc['role_code'] === 'program_chair') $programChairs++;
}

layout_start('Office Accounts', 'users', [['label' => 'Office accounts']]);
?>
<div class="page-head">
    <div>
        <h1>Office Accounts Management</h1>
        <p class="lede">Manage administrative office accounts, role permissions, and department scope assignments.</p>
    </div>
</div>

<!-- Summary Metrics Header -->
<section class="kpis">
    <article class="kpi">
        <span>Total Office Accounts</span>
        <strong><?= $totalOffice ?></strong>
    </article>
    <article class="kpi">
        <span>Active Accounts</span>
        <strong><?= $activeOffice ?></strong>
    </article>
    <article class="kpi">
        <span>Program Chairs</span>
        <strong><?= $programChairs ?></strong>
    </article>
</section>

<!-- New Office Account Form Card -->
<section class="card">
    <h2><?= icon('users') ?> New Office Account</h2>
    <p class="hint">Professors are managed on the Faculty page. These accounts sign in through the college offices portal.</p>
    
    <form method="post" class="form-grid" style="margin-top: 14px;" data-offline-save data-offline-label="New office account">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        
        <div>
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" placeholder="e.g. Registrar Officer" required>
        </div>
        <div>
            <label for="username">System Username</label>
            <input id="username" name="username" placeholder="e.g. registrar" required>
        </div>
        <div>
            <label for="role">Assigned Role</label>
            <select id="role" name="role">
                <?php foreach ($officeRoles as $code => $label): ?>
                    <option value="<?= h($code) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="program_id">Program Scope (For Program Chairs)</label>
            <select id="program_id" name="program_id">
                <option value="0">Not a program chair</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= (int) $program['id'] ?>"><?= h($program['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wide">
            <label for="password">Temporary Password</label>
            <input id="password" name="password" type="text" minlength="8" placeholder="At least 8 characters" required>
        </div>
        <div class="wide" style="margin-top: 6px;">
            <button class="btn btn-gold" type="submit">Create Office Account</button>
        </div>
    </form>
</section>

<!-- Office Accounts Table -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('users') ?> Registered Office Accounts</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role Permission</th>
                    <th>Program Scope</th>
                    <th>Status & Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td>
                        <input form="account-<?= (int) $account['id'] ?>" name="full_name" value="<?= h($account['full_name']) ?>" aria-label="Full name" required style="min-width: 180px;">
                    </td>
                    <td class="mono"><strong><?= h($account['username']) ?></strong></td>
                    <td>
                        <select form="account-<?= (int) $account['id'] ?>" name="role" aria-label="Role" style="min-width: 160px;">
                            <?php foreach ($officeRoles as $code => $label): ?>
                                <option value="<?= h($code) ?>" <?= $account['role_code'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select form="account-<?= (int) $account['id'] ?>" name="program_id" aria-label="Program" style="min-width: 120px;">
                            <option value="0">Not assigned</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int) $program['id'] ?>" <?= (int) $account['program_id'] === (int) $program['id'] ? 'selected' : '' ?>><?= h($program['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?= (int) $account['is_active'] === 1 ? badge('Active', 'PASSED') : badge('Disabled', 'FAILED') ?>
                            
                            <form id="account-<?= (int) $account['id'] ?>" method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-sm" type="submit">Save</button>
                            </form>
                            
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">Toggle</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php layout_end(); ?>