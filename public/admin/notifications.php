<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

admin_page_start('Messages', 'notifications');
?>
    <header class="page-head">
        <div>
            <h1>Messages</h1>
        </div>
        <p class="head-note">Drafts for the texts a patient gets after booking. Today’s unconfirmed visits are on Appointments, in Follow up.</p>
    </header>
    <section class="stack">
        <?php foreach (preview_notifications() as $notice): ?>
            <article class="panel notice">
                <header>
                    <h2><?php echo h($notice['title']); ?></h2>
                    <p><?php echo h($notice['audience']); ?></p>
                </header>
                <blockquote><?php echo h($notice['message']); ?></blockquote>
                <button type="button" disabled>Send is not connected</button>
            </article>
        <?php endforeach; ?>
    </section>
<?php
admin_page_end();
