<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

if (staff_current() !== null) {
    header('Location: index.php');
    exit;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$invite = staff_find_invite($token);
$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite !== null) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $result = staff_accept_invite($token, $username, $password);
    if ($result['ok']) {
        staff_login($result['account']);
        header('Location: index.php');
        exit;
    }
    $error = (string) $result['error'];
}

$clinic = preview_clinic();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create staff account — <?php echo h($clinic['short']); ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="login-body">
    <main class="login-card" id="main">
        <p class="login-mark" aria-hidden="true">G</p>
        <h1>Create your account</h1>
        <?php if ($invite === null): ?>
            <p class="login-error" role="alert">This invite is closed or already used.</p>
            <a class="side-link login-back" href="login.php">Staff login</a>
        <?php else: ?>
            <p class="muted"><?php echo h($invite['name']); ?> · <?php echo h(staff_role_label((string) $invite['role'])); ?></p>
            <?php if ($error !== ''): ?>
                <p class="login-error" role="alert"><?php echo h($error); ?></p>
            <?php endif; ?>
            <form method="post" action="invite.php">
                <input type="hidden" name="token" value="<?php echo h($token); ?>">
                <label>
                    Username
                    <input name="username" type="text" autocomplete="username" required maxlength="40" value="<?php echo h($username); ?>">
                </label>
                <label>
                    Password
                    <input name="password" type="password" autocomplete="new-password" required minlength="8">
                </label>
                <button class="btn btn-solid btn-wide" type="submit">Create account</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
