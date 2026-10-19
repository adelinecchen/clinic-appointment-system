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
            <h1>Waitlist</h1>
        </div>
        <p class="head-note">When someone cancels, give that 15-minute block to one person here. Each person still needs their own time.</p>
    </header>
    <?php if ($people === []): ?>
        <section class="panel">
            <p>Everyone waiting has a time on the schedule. Open appointments to see the day.</p>
        </section>
    <?php else: ?>
        <section class="stack">
            <?php foreach ($people as $entry): ?>
                <article class="panel wait-card">
                    <div>
                        <h2><?php echo h($entry['name']); ?></h2>
                        <p><?php echo h($entry['phone']); ?> · <?php echo h($entry['visit']); ?></p>
                    </div>
                    <p><?php echo h($entry['note']); ?></p>
                    <a class="btn btn-line" href="index.php">Place in an open block</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php
admin_page_end();
