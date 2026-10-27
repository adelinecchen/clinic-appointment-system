<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

admin_page_start('Waitlist', 'waitlist');
$people = preview_waitlist();
?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Waitlist</p>
            <h1>Waitlist</h1>
        </div>
        <p class="head-note">When someone cancels, give that 15-minute block to one person here. Each person still needs their own time.</p>
    </header>
    <?php if ($people === []): ?>
        <section class="panel">
            <?php admin_empty_state(
                'The waitlist is clear',
                'Everyone waiting has a time on the schedule. Open today’s board to see the day.',
                'index.php',
                'Open the dashboard'
            ); ?>
        </section>
    <?php else: ?>
        <section class="people-grid">
            <?php foreach ($people as $entry): ?>
                <article class="panel wait-card person-card">
                    <span class="avatar" aria-hidden="true"><?php echo h(admin_initials((string) $entry['name'])); ?></span>
                    <div>
                        <h2><?php echo h($entry['name']); ?></h2>
                        <?php admin_type_badge((string) $entry['visit']); ?>
                        <p><?php echo h($entry['visit']); ?></p>
                    </div>
                    <p><?php echo h($entry['note']); ?></p>
                    <div class="visit-actions">
                        <a class="btn btn-line" href="tel:<?php echo h($entry['phone_digits']); ?>">Call</a>
                        <a class="btn btn-line" href="index.php">Place in an open block</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php
admin_page_end();
