<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

$people = preview_patients();

admin_page_start('Patients', 'patients');
?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Patients</p>
            <h1>Patient list</h1>
        </div>
        <p class="head-note">People with an upcoming visit. Open the schedule to change a time, send a reminder, or cancel.</p>
    </header>

    <?php if ($people === []): ?>
        <section class="panel">
            <?php admin_empty_state(
                'No patients on the list yet',
                'When visits are booked, names from those appointments will show here.',
                'patients.php',
                'Open the schedule'
            ); ?>
        </section>
    <?php else: ?>
        <section class="people-grid" aria-label="Patient list">
            <?php foreach ($people as $person): ?>
                <article class="panel person-card">
                    <span class="avatar" aria-hidden="true"><?php echo h(admin_initials((string) $person['name'])); ?></span>
                    <div>
                        <h2><?php echo h($person['name']); ?></h2>
                        <?php admin_type_badge((string) $person['visit']); ?>
                        <p class="muted"><?php echo h($person['visit']); ?></p>
                    </div>
                    <p><?php echo h($person['household']); ?></p>
                    <a class="btn btn-line" href="patients.php">Open schedule</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php
admin_page_end();
