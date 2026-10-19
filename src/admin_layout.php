<?php

declare(strict_types=1);

function admin_credentials_match(string $username, string $password): bool
{
    $expectedUser = env_value('ADMIN_USERNAME', 'staff');
    $expectedPassword = env_value('ADMIN_PASSWORD', 'clinic-desk');
    if (strlen($username) !== strlen($expectedUser) || strlen($password) !== strlen($expectedPassword)) {
        return false;
    }

    return hash_equals($expectedUser, $username) && hash_equals($expectedPassword, $password);
}

function admin_require_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'login.php') {
        return;
    }

    if (empty($_SESSION['admin_user'])) {
        header('Location: login.php');
        exit;
    }
}

function admin_page_start(string $title, string $active): void
{
    admin_require_login();

    $clinic = preview_clinic();
    $badges = [
        'notifications' => count(preview_unconfirmed(preview_appointments())),
        'waitlist' => count(preview_waitlist()),
    ];
    $items = [
        'dashboard' => ['Appointments', 'index.php'],
        'patients' => ['Patients', 'patients.php'],
        'notifications' => ['Messages', 'notifications.php'],
        'waitlist' => ['Waitlist', 'waitlist.php'],
        'availability' => ['Availability', 'availability.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title . ' — ' . $clinic['short'] . ' staff'); ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    <div class="shell">
        <aside class="sidebar">
            <a class="side-brand" href="index.php">
                <span class="mark" aria-hidden="true">G</span>
                <span>
                    <strong><?php echo h($clinic['short']); ?></strong>
                    <span><?php echo h($clinic['doctor']); ?></span>
                </span>
            </a>
            <nav aria-label="Staff">
                <?php foreach ($items as $key => $item): ?>
                    <a href="<?php echo h($item[1]); ?>"<?php echo $active === $key ? ' aria-current="page"' : ''; ?>>
                        <span><?php echo h($item[0]); ?></span>
                        <?php if (($badges[$key] ?? 0) > 0): ?>
                            <span class="nav-count"><?php echo h((string) $badges[$key]); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="side-foot">
                <a class="side-link" href="../index.php">Patient website</a>
                <a class="side-link" href="login.php?logout=1">Log out</a>
            </div>
        </aside>
        <div class="workspace">
            <main id="main">
    <?php
}

function admin_page_end(): void
{
    ?>
            </main>
        </div>
    </div>
</body>
</html>
    <?php
}

function admin_status(string $status, string $label): void
{
    $known = ['confirmed', 'no_reply', 'reminder_sent', 'cancelled', 'pending'];
    $class = in_array($status, $known, true) ? $status : 'no_reply';
    if ($class === 'pending') {
        $class = 'no_reply';
    }
    echo '<span class="status status-' . h($class) . '">' . h($label) . '</span>';
}

admin_require_login();
