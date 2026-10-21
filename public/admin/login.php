<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

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
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $account = staff_authenticate($username, $password);
    if ($account !== null) {
        staff_login($account);
        header('Location: index.php');
        exit;
    }
    $error = true;
}

$clinic = preview_clinic();
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
            <p class="login-error" role="alert">That username or password is not right.</p>
        <?php endif; ?>
        <form method="post" action="login.php">
            <label>
                Username
                <input name="username" type="text" autocomplete="username" required maxlength="80" value="<?php echo h($username); ?>">
            </label>
            <label>
                Password
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            <button class="btn btn-solid btn-wide" type="submit">Log in</button>
        </form>
        <p class="login-hint">Local demo: super admin is <strong>staff</strong> / <strong>clinic-desk</strong>. A regular admin is <strong>desk</strong> / <strong>clinic-desk</strong>.</p>
        <a class="side-link login-back" href="../index.php">Patient website</a>
    </main>
</body>
</html>
