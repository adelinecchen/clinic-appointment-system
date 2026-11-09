<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/csrf.php';
require_once __DIR__ . '/../../src/admin_layout.php';

csrf_ensure_session();

if (isset($_GET['logout'])) {
    staff_logout();
    header('Location: login.php');
    exit;
}

if (staff_current() !== null) {
    header('Location: index.php');
    exit;
}

$error = false;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $error = true;
    } else {
        $email = trim((string) ($_POST['email'] ?? $_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $account = staff_authenticate($email, $password);
        if ($account !== null) {
            staff_login($account);
            header('Location: index.php');
            exit;
        }
        $error = true;
    }
}

$clinic = preview_clinic();
$showLocalHint = admin_seed_enabled() && admin_is_local_env();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff login — <?php echo h($clinic['short']); ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="login-body">
    <main class="login-card" id="main">
        <p class="login-mark" aria-hidden="true">G</p>
        <h1>Staff login</h1>
        <p class="muted"><?php echo h($clinic['name']); ?></p>
        <?php if ($error): ?>
            <p class="login-error" role="alert">That email or password is not right.</p>
        <?php endif; ?>
        <form method="post" action="login.php">
            <?php echo csrf_field(); ?>
            <label>
                Email
                <input name="email" type="email" autocomplete="username" required maxlength="80" value="<?php echo h($email); ?>">
            </label>
            <label>
                Password
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            <button class="btn btn-solid btn-wide" type="submit">Log in</button>
        </form>
        <?php if ($showLocalHint): ?>
            <p class="login-hint">Local demo seeding is enabled. Use the email and password from your local <code>.env</code> (<code>ADMIN_EMAIL</code> / <code>ADMIN_PASSWORD</code>) when the Admin table is empty.</p>
        <?php endif; ?>
        <a class="side-link login-back" href="../index.php">Patient website</a>
    </main>
</body>
</html>
