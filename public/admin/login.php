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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="login-body">
    <main class="login-card" id="main">
        <div class="login-brand" aria-hidden="true">
            <svg class="login-cross" viewBox="0 0 48 48" focusable="false">
                <rect x="18" y="6" width="12" height="36" rx="3" fill="currentColor"/>
                <rect x="6" y="18" width="36" height="12" rx="3" fill="currentColor"/>
            </svg>
        </div>
        <h1>Welcome Back!</h1>
        <p class="login-subtitle"><?php echo h($clinic['name']); ?></p>
        <?php if ($error): ?>
            <p class="login-error" role="alert">That email or password is not right.</p>
        <?php endif; ?>
        <form method="post" action="login.php">
            <?php echo csrf_field(); ?>
            <label class="login-field">
                <span class="login-label">Email</span>
                <span class="login-input-wrap">
                    <input name="email" type="email" autocomplete="username" required maxlength="80" placeholder="Email" value="<?php echo h($email); ?>">
                    <span class="login-field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M20 21a8 8 0 0 0-16 0"/>
                            <circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                    </span>
                </span>
            </label>
            <label class="login-field">
                <span class="login-label">Password</span>
                <span class="login-input-wrap password-field">
                    <input id="login-password" name="password" type="password" autocomplete="current-password" required placeholder="Password">
                    <button
                        class="password-toggle"
                        type="button"
                        aria-label="Show password"
                        aria-controls="login-password"
                        aria-pressed="false"
                        data-show-label="Show password"
                        data-hide-label="Hide password"
                    >
                        <svg class="password-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <path class="password-toggle-slash" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M4 4l16 16"/>
                        </svg>
                    </button>
                </span>
            </label>
            <button class="btn btn-solid btn-wide login-submit" type="submit">Sign in</button>
        </form>
        <?php if ($showLocalHint): ?>
            <p class="login-hint">Local demo seeding is enabled. Use the email and password from your local <code>.env</code> (<code>ADMIN_EMAIL</code> / <code>ADMIN_PASSWORD</code>) when the Admin table is empty.</p>
        <?php endif; ?>
        <p class="login-footer">
            <a class="login-back" href="../index.php">Patient website</a>
        </p>
    </main>
    <script>
        (function () {
            var toggle = document.querySelector('.password-toggle');
            var input = document.getElementById('login-password');
            if (!toggle || !input) return;

            toggle.addEventListener('click', function () {
                var revealing = input.type === 'password';
                input.type = revealing ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', revealing ? 'true' : 'false');
                toggle.setAttribute(
                    'aria-label',
                    revealing ? toggle.getAttribute('data-hide-label') : toggle.getAttribute('data-show-label')
                );
            });
        })();
    </script>
</body>
</html>
