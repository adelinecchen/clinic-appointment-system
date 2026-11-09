<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/csrf.php';
require_once __DIR__ . '/../../src/admin_layout.php';

staff_require_super();
csrf_ensure_session();

$notice = '';
$error = '';
$editId = (string) ($_GET['edit'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $error = 'Your form expired. Please try again.';
    } elseif (!staff_is_super()) {
        $error = 'Only a super admin can change staff accounts.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $role = (string) ($_POST['role'] ?? 'admin');

        if ($action === 'create') {
            $result = staff_create_account(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['phone'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                $role
            );
            $error = $result['ok'] ? '' : (string) $result['error'];
            $notice = $result['ok'] ? 'The account is ready. Share the email and password with that person.' : '';
        } elseif ($action === 'update') {
            $result = staff_update_account(
                (string) ($_POST['id'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['phone'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                $role,
                (string) ($_POST['password'] ?? '')
            );
            $error = $result['ok'] ? '' : (string) $result['error'];
            $notice = $result['ok'] ? 'Account updated.' : '';
            $editId = $result['ok'] ? '' : (string) ($_POST['id'] ?? '');
        } elseif ($action === 'delete') {
            $result = staff_delete_account((string) ($_POST['id'] ?? ''));
            $error = $result['ok'] ? '' : (string) $result['error'];
            $notice = $result['ok'] ? 'Account deleted.' : '';
        }
    }
}

$editing = $editId !== '' ? staff_find_account($editId) : null;

admin_page_start('Staff', 'staff');
?>
    <header class="page-head">
        <div>
            <h1>Staff</h1>
        </div>
        <p class="head-note">Create and manage desk accounts. Everyone signs in with email. Only super admins can change roles or delete accounts.</p>
    </header>
    <?php if ($notice !== ''): ?>
        <p class="desk-note"><?php echo h($notice); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="login-error" role="alert"><?php echo h($error); ?></p>
    <?php endif; ?>

    <div class="day-layout">
        <section class="panel">
            <h2>Accounts</h2>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Staff accounts</caption>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (staff_accounts() as $account): ?>
                            <tr>
                                <td>
                                    <?php echo h((string) $account['name']); ?>
                                    <div class="muted"><?php echo h((string) $account['phone']); ?></div>
                                </td>
                                <td><?php echo h((string) $account['email']); ?></td>
                                <td><?php echo h(staff_role_label((string) $account['role'])); ?></td>
                                <td>
                                    <a class="btn btn-line" href="staff.php?edit=<?php echo h((string) $account['id']); ?>">Edit</a>
                                    <form method="post" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo h((string) $account['id']); ?>">
                                        <button class="btn btn-line" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="stack">
            <?php if ($editing !== null): ?>
                <section class="panel">
                    <h2>Edit <?php echo h((string) $editing['name']); ?></h2>
                    <form method="post" class="staff-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo h((string) $editing['id']); ?>">
                        <label>Name <input name="name" required maxlength="50" value="<?php echo h((string) $editing['name']); ?>"></label>
                        <label>Phone <input name="phone" required maxlength="15" value="<?php echo h((string) $editing['phone']); ?>"></label>
                        <label>Email <input name="email" type="email" required maxlength="80" value="<?php echo h((string) $editing['email']); ?>"></label>
                        <label>Role
                            <select name="role">
                                <option value="admin"<?php echo $editing['role'] === 'admin' ? ' selected' : ''; ?>>Admin</option>
                                <option value="super_admin"<?php echo $editing['role'] === 'super_admin' ? ' selected' : ''; ?>>Super admin</option>
                            </select>
                        </label>
                        <label>New password <input name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep it"></label>
                        <button class="btn btn-solid" type="submit">Save account</button>
                        <a class="side-link" href="staff.php">Cancel</a>
                    </form>
                </section>
            <?php endif; ?>

            <section class="panel">
                <h2>Create an account</h2>
                <p class="muted">Share the email and password with that person. They sign in on the staff login page.</p>
                <form method="post" class="staff-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">
                    <label>Name <input name="name" required maxlength="50"></label>
                    <label>Phone <input name="phone" required maxlength="15" placeholder="5550101234"></label>
                    <label>Email <input name="email" type="email" required maxlength="80"></label>
                    <label>Password <input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
                    <label>Role
                        <select name="role">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super admin</option>
                        </select>
                    </label>
                    <button class="btn btn-solid" type="submit">Create account</button>
                </form>
            </section>
        </div>
    </div>
<?php
admin_page_end();
