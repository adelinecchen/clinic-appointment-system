<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

staff_require_super();

$notice = '';
$error = '';
$invitePreview = null;
$editId = (string) ($_GET['edit'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $role = (string) ($_POST['role'] ?? 'admin');

    if ($action === 'invite') {
        $result = staff_create_invite(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            $role
        );
        if ($result['ok']) {
            $invitePreview = $result['invite'];
            $notice = 'Invite is ready. Copy the email below. Sending is not connected yet.';
        } else {
            $error = (string) $result['error'];
        }
    } elseif ($action === 'create') {
        $result = staff_create_account(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            $role
        );
        $error = $result['ok'] ? '' : (string) $result['error'];
        $notice = $result['ok'] ? 'The account is ready. Share the username and password with that person.' : '';
    } elseif ($action === 'update') {
        $result = staff_update_account(
            (string) ($_POST['id'] ?? ''),
            (string) ($_POST['name'] ?? ''),
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
    } elseif ($action === 'revoke') {
        $result = staff_revoke_invite((string) ($_POST['id'] ?? ''));
        $error = $result['ok'] ? '' : (string) $result['error'];
        $notice = $result['ok'] ? 'Invite closed.' : '';
    }
}

$editing = $editId !== '' ? staff_find_account($editId) : null;
$pending = array_values(array_filter(
    staff_invites(),
    static fn (array $invite): bool => ($invite['status'] ?? '') === 'pending'
));

admin_page_start('Staff', 'settings');
?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Settings</p>
            <h1>Staff</h1>
        </div>
        <p class="head-note">Create an account for someone, or email them a link so they can choose a password. Super admins can change roles and delete accounts.</p>
    </header>
    <?php if ($notice !== ''): ?>
        <p class="desk-note"><?php echo h($notice); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="login-error" role="alert"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php if (is_array($invitePreview)): ?>
        <article class="panel notice">
            <h2>Invite email</h2>
            <p class="muted">To <?php echo h((string) $invitePreview['email']); ?></p>
            <blockquote>
                Hi <?php echo h((string) $invitePreview['name']); ?>, you have a <?php echo h(staff_role_label((string) $invitePreview['role'])); ?> account at <?php echo h(preview_clinic()['name']); ?>. Open this link to choose a username and password: <?php echo h(staff_invite_url((string) $invitePreview['token'])); ?>
            </blockquote>
        </article>
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
                            <th scope="col">Username</th>
                            <th scope="col">Role</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (staff_accounts() as $account): ?>
                            <tr>
                                <td>
                                    <?php echo h((string) $account['name']); ?>
                                    <div class="muted"><?php echo h((string) $account['email']); ?></div>
                                </td>
                                <td><?php echo h((string) $account['username']); ?></td>
                                <td><?php echo h(staff_role_label((string) $account['role'])); ?></td>
                                <td>
                                    <a class="btn btn-line" href="staff.php?edit=<?php echo h((string) $account['id']); ?>">Edit</a>
                                    <form method="post" class="inline-form">
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
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo h((string) $editing['id']); ?>">
                        <label>Name <input name="name" required value="<?php echo h((string) $editing['name']); ?>"></label>
                        <label>Email <input name="email" type="email" required value="<?php echo h((string) $editing['email']); ?>"></label>
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
                <h2>Email an invite</h2>
                <p class="muted">They open the link and create their own username and password.</p>
                <form method="post" class="staff-form">
                    <input type="hidden" name="action" value="invite">
                    <label>Name <input name="name" required></label>
                    <label>Email <input name="email" type="email" required></label>
                    <label>Role
                        <select name="role">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super admin</option>
                        </select>
                    </label>
                    <button class="btn btn-solid" type="submit">Prepare invite</button>
                </form>
            </section>

            <section class="panel">
                <h2>Create an account now</h2>
                <p class="muted">Use this when you want to hand someone a username and password today.</p>
                <form method="post" class="staff-form">
                    <input type="hidden" name="action" value="create">
                    <label>Name <input name="name" required></label>
                    <label>Email <input name="email" type="email" required></label>
                    <label>Username <input name="username" required maxlength="40"></label>
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

    <?php if ($pending !== []): ?>
        <section class="panel">
            <h2>Open invites</h2>
            <?php foreach ($pending as $invite): ?>
                <article class="date-row">
                    <div>
                        <h2><?php echo h((string) $invite['name']); ?></h2>
                        <p class="muted"><?php echo h((string) $invite['email']); ?> · <?php echo h(staff_role_label((string) $invite['role'])); ?></p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="id" value="<?php echo h((string) $invite['id']); ?>">
                        <button class="btn btn-line" type="submit">Close invite</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php
admin_page_end();
