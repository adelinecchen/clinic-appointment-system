<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

$clinic = preview_clinic();
$staff = staff_current();

admin_page_start('Settings', 'settings');
?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Settings</p>
            <h1>Clinic settings</h1>
        </div>
        <p class="head-note">Hours, staff accounts, and message templates. Appointment data still comes from the staff desk, not a direct database connection.</p>
    </header>

    <section class="cards settings-cards" aria-label="Settings shortcuts">
        <a class="panel setting-link" href="availability.php">
            <?php admin_icon('schedule'); ?>
            <span>
                <strong>Clinic hours</strong>
                Open days, visit length, and blocked times
            </span>
        </a>
        <a class="panel setting-link" href="notifications.php">
            <?php admin_icon('messages'); ?>
            <span>
                <strong>Message templates</strong>
                Texts used for booking and follow-up
            </span>
        </a>
        <?php if (staff_is_super()): ?>
            <a class="panel setting-link" href="staff.php">
                <?php admin_icon('patients'); ?>
                <span>
                    <strong>Staff accounts</strong>
                    Invite, edit, or remove desk users
                </span>
            </a>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Clinic profile</h2>
        <dl class="meta-list">
            <div>
                <dt>Clinic</dt>
                <dd><?php echo h($clinic['name']); ?></dd>
            </div>
            <div>
                <dt>Doctor</dt>
                <dd><?php echo h($clinic['doctor']); ?></dd>
            </div>
            <div>
                <dt>Signed in as</dt>
                <dd><?php echo h($staff !== null ? $staff['name'] . ' · ' . staff_role_label((string) $staff['role']) : 'Staff'); ?></dd>
            </div>
            <div>
                <dt>Address</dt>
                <dd><?php echo h($clinic['address']); ?></dd>
            </div>
        </dl>
    </section>
<?php
admin_page_end();
