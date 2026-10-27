<?php

declare(strict_types=1);

require_once __DIR__ . '/staff_accounts.php';

function admin_require_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['login.php', 'invite.php'], true)) {
        return;
    }

    if (staff_current() === null) {
        unset($_SESSION['admin_user'], $_SESSION['admin_id'], $_SESSION['admin_role']);
        header('Location: login.php');
        exit;
    }
}

function admin_icon(string $name): void
{
    $icons = [
        'dashboard' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/>',
        'schedule' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M8 3v4M16 3v4M3.5 11h17"/>',
        'patients' => '<path d="M15.5 19.5v-.8a3.7 3.7 0 0 0-3.7-3.7H8.2A3.7 3.7 0 0 0 4.5 18.7v.8"/><circle cx="10" cy="7.5" r="2.7"/><path d="M19.5 19.5v-.7a3.2 3.2 0 0 0-2.3-3.05"/><circle cx="17.2" cy="8.2" r="2.1"/>',
        'waitlist' => '<path d="M9 4.5H7.2A2.2 2.2 0 0 0 5 6.7v12.1A2.2 2.2 0 0 0 7.2 21h9.6a2.2 2.2 0 0 0 2.2-2.2V6.7A2.2 2.2 0 0 0 16.8 4.5H15"/><rect x="9" y="3" width="6" height="3.4" rx="1"/><path d="M9 12h6M9 16h4"/>',
        'messages' => '<path d="M20.5 12a8.2 8.2 0 0 1-8.1 8.2H4.5l2.1-3A8.2 8.2 0 1 1 20.5 12z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 4.2v2.1M12 17.7v2.1M6.1 6.1l1.5 1.5M16.4 16.4l1.5 1.5M4.2 12h2.1M17.7 12h2.1M6.1 17.9l1.5-1.5M16.4 7.6l1.5-1.5"/>',
        'website' => '<circle cx="12" cy="12" r="8"/><path d="M3.6 12h16.8M12 4c2.6 2.5 4 5.2 4 8s-1.4 5.5-4 8c-2.6-2.5-4-5.2-4-8s1.4-5.5 4-8z"/>',
        'logout' => '<path d="M9.5 20.5H6.2A2.2 2.2 0 0 1 4 18.3V5.7A2.2 2.2 0 0 1 6.2 3.5h3.3M15.5 16.5 20 12l-4.5-4.5M20 12H9.5"/>',
        'search' => '<circle cx="11" cy="11" r="6.2"/><path d="M20 20l-3.4-3.4"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4.5l3 1.7"/>',
        'check' => '<path d="M20 6.5 9.5 17 4 11.5"/>',
        'alert' => '<path d="M12 8.5v4.2M12 16.6h.01"/><path d="M10.2 4.8 2.7 18a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.8 4.8a2 2 0 0 0-3.6 0z"/>',
        'cancel' => '<circle cx="12" cy="12" r="8"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'phone' => '<path d="M6.8 3.8h3.1l1.3 3.2-2 1.2a12 12 0 0 0 6.6 6.6l1.2-2 3.2 1.3v3.1a1.6 1.6 0 0 1-1.7 1.6A16.2 16.2 0 0 1 5.2 5.5 1.6 1.6 0 0 1 6.8 3.8z"/>',
    ];
    if (!isset($icons[$name])) {
        return;
    }

    echo '<svg class="ui-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
}

function admin_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part === '') {
            continue;
        }
        $letters .= strtoupper(function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1));
    }

    return $letters !== '' ? $letters : 'S';
}

function admin_visit_is_new(string $visit): bool
{
    return !str_contains(strtolower($visit), 'follow');
}

function admin_type_badge(string $visit): void
{
    $isNew = admin_visit_is_new($visit);
    echo '<span class="type-pill' . ($isNew ? ' type-new' : ' type-follow') . '">' . h($isNew ? 'New patient' : 'Follow-up') . '</span>';
}

function admin_summary_card(string $key, string $label, int $count, string $icon): void
{
    ?>
    <article class="card card-<?php echo h($key); ?>">
        <div class="card-top">
            <h2><?php echo h($label); ?></h2>
            <span class="card-icon" aria-hidden="true"><?php admin_icon($icon); ?></span>
        </div>
        <p class="count"><?php echo h((string) $count); ?></p>
    </article>
    <?php
}

function admin_empty_state(string $title, string $body, string $href = '', string $cta = ''): void
{
    ?>
    <div class="empty-state" data-state="empty">
        <h2><?php echo h($title); ?></h2>
        <p><?php echo h($body); ?></p>
        <?php if ($href !== '' && $cta !== ''): ?>
            <a class="btn btn-solid" href="<?php echo h($href); ?>"><?php echo h($cta); ?></a>
        <?php endif; ?>
    </div>
    <?php
}

function admin_page_start(string $title, string $active): void
{
    admin_require_login();

    $clinic = preview_clinic();
    $staff = staff_current();
    $todayUnconfirmed = count(preview_unconfirmed(preview_appointments()));
    $upcomingUnconfirmed = preview_upcoming_counts(preview_upcoming_appointments())['unconfirmed'];
    $waitCount = count(preview_waitlist());
    $items = [
        'dashboard' => ['Dashboard', 'index.php', 'dashboard', 0],
        'schedule' => ['Schedule', 'patients.php', 'schedule', $upcomingUnconfirmed],
        'patients' => ['Patients', 'directory.php', 'patients', 0],
        'waitlist' => ['Waitlist', 'waitlist.php', 'waitlist', $waitCount],
        'notifications' => ['Messages', 'notifications.php', 'messages', $todayUnconfirmed],
        'settings' => ['Settings', 'settings.php', 'settings', 0],
    ];
    $settingsPages = ['settings', 'availability', 'staff'];
    $staffName = $staff !== null ? (string) $staff['name'] : $clinic['doctor'];
    $staffRole = $staff !== null ? staff_role_label((string) $staff['role']) : 'Staff';
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
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
                    <span>Staff desk</span>
                </span>
            </a>
            <nav aria-label="Staff">
                <?php foreach ($items as $key => $item): ?>
                    <?php
                    $isCurrent = $active === $key || ($key === 'settings' && in_array($active, $settingsPages, true));
                    ?>
                    <a href="<?php echo h($item[1]); ?>"<?php echo $isCurrent ? ' aria-current="page"' : ''; ?>>
                        <?php admin_icon((string) $item[2]); ?>
                        <span><?php echo h($item[0]); ?></span>
                        <?php if ((int) $item[3] > 0): ?>
                            <span class="nav-count"><?php echo h((string) $item[3]); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="side-foot">
                <a class="side-link" href="../index.php"><?php admin_icon('website'); ?><span>Patient website</span></a>
                <a class="side-link side-logout" href="login.php?logout=1"><?php admin_icon('logout'); ?><span>Log out</span></a>
            </div>
        </aside>
        <div class="workspace">
            <header class="topbar">
                <form class="desk-search" method="get" action="patients.php" role="search">
                    <label class="sr-only" for="desk-search">Find a visit by name or phone</label>
                    <input id="desk-search" type="search" name="q" value="<?php echo h($searchQuery); ?>" placeholder="Find a visit by name or phone" autocomplete="off">
                    <button class="search-submit" type="submit" aria-label="Find visit"><?php admin_icon('search'); ?></button>
                </form>
                <details class="profile-menu">
                    <summary>
                        <span class="avatar" aria-hidden="true"><?php echo h(admin_initials($staffName)); ?></span>
                        <span class="profile-copy">
                            <strong><?php echo h($staffName); ?></strong>
                            <span><?php echo h($staffRole); ?></span>
                        </span>
                    </summary>
                    <div class="profile-pop">
                        <a href="settings.php">Settings</a>
                        <?php if (staff_is_super()): ?>
                            <a href="staff.php">Staff accounts</a>
                        <?php endif; ?>
                        <a href="../index.php">Patient website</a>
                        <a href="login.php?logout=1">Log out</a>
                    </div>
                </details>
            </header>
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

function admin_desk_appointment(array $appointment, array $waiting): void
{
    $isNew = admin_visit_is_new((string) $appointment['visit']);
    $classes = 'appt-card';
    if ($appointment['status'] === 'cancelled') {
        $classes .= ' is-cancelled';
    } elseif ($isNew) {
        $classes .= ' is-new';
    }
    ?>
    <article class="<?php echo h($classes); ?>">
        <p class="visit-time"><?php echo h($appointment['time']); ?></p>
        <div class="appt-body">
            <div class="appt-main">
                <span class="avatar" aria-hidden="true"><?php echo h(admin_initials((string) $appointment['patient'])); ?></span>
                <div class="visit-person">
                    <h2><?php echo h($appointment['patient']); ?></h2>
                    <p><?php echo h($appointment['visit']); ?></p>
                    <?php if ($appointment['note'] !== ''): ?>
                        <p class="row-note<?php echo $appointment['status'] === 'cancelled' ? ' is-cancel' : ''; ?>"><?php echo h($appointment['note']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="appt-flags">
                    <?php admin_type_badge((string) $appointment['visit']); ?>
                    <?php admin_status($appointment['status'], $appointment['status_label']); ?>
                </div>
            </div>
            <div class="visit-actions">
                <details class="fill">
                    <summary>Details</summary>
                    <div class="appt-detail">
                        <p><strong>Patient</strong> <?php echo h($appointment['patient']); ?></p>
                        <p><strong>Type</strong> <?php echo h($appointment['visit']); ?></p>
                        <p><strong>Status</strong> <?php echo h($appointment['status_label']); ?></p>
                        <p><strong>Time</strong> <?php echo h($appointment['time']); ?></p>
                    </div>
                </details>
                <?php if ($appointment['status'] === 'confirmed'): ?>
                    <a class="btn btn-line" href="notifications.php">Message</a>
                    <a class="btn btn-line" href="tel:<?php echo h($appointment['phone_digits']); ?>">Call</a>
                <?php elseif ($appointment['status'] === 'no_reply'): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="remind">
                        <input type="hidden" name="patient_id[]" value="<?php echo h($appointment['id']); ?>">
                        <button class="btn btn-solid" type="submit">Send reminder</button>
                    </form>
                    <a class="btn btn-line" href="tel:<?php echo h($appointment['phone_digits']); ?>">Call</a>
                <?php elseif ($appointment['status'] === 'reminder_sent'): ?>
                    <a class="btn btn-line" href="tel:<?php echo h($appointment['phone_digits']); ?>">Call</a>
                    <a class="btn btn-line" href="notifications.php">Message</a>
                <?php elseif ($waiting !== []): ?>
                    <details class="fill">
                        <summary>Fill from waitlist</summary>
                        <form method="post">
                            <input type="hidden" name="action" value="fill">
                            <input type="hidden" name="slot_id" value="<?php echo h($appointment['id']); ?>">
                            <p>This opening is one 15-minute block.</p>
                            <?php foreach ($waiting as $person): ?>
                                <label>
                                    <input type="radio" name="waitlist_id" value="<?php echo h($person['id']); ?>" required>
                                    <span>
                                        <strong><?php echo h($person['name']); ?></strong>
                                        <?php echo h($person['visit'] . ' · ' . $person['phone']); ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <button class="btn btn-solid" type="submit">Give them this time</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}

admin_require_login();
