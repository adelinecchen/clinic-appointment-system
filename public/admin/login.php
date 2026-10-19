<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_user']);
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['admin_user'])) {
    header('Location: index.php');
    exit;
}

$error = false;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (admin_credentials_match($username, $password)) {
        session_regenerate_id(true);
        $_SESSION['admin_user'] = $username;
        header('Location: index.php');
        exit;
    }
    $error = true;
}

$clinic = preview_clinic();
$usingDefault = env_value('ADMIN_PASSWORD', 'clinic-desk') === 'clinic-desk' && env_value('ADMIN_USERNAME', 'staff') === 'staff';
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
        <?php if ($usingDefault): ?>
            <p class="login-hint">For this local demo, sign in with <strong>staff</strong> and <strong>clinic-desk</strong>.</p>
        <?php endif; ?>
        <a class="side-link login-back" href="../index.php">Patient website</a>
    </main>
</body>
</html>
