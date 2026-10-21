<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

$saved = false;
$saveError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $templates = [
        'booking' => (string) ($_POST['booking'] ?? ''),
        'two_day' => (string) ($_POST['two_day'] ?? ''),
        'one_day' => (string) ($_POST['one_day'] ?? ''),
        'follow_up' => (string) ($_POST['follow_up'] ?? ''),
    ];
    if (preview_save_message_templates($templates)) {
        $saved = true;
    } else {
        $saveError = true;
    }
}

$templates = preview_message_templates();
$notices = preview_notifications();

admin_page_start('Messages', 'notifications');
?>
    <header class="page-head">
        <div>
            <h1>Messages</h1>
        </div>
        <p class="head-note">Edit the texts patients get. Use {name}, {time}, {date}, {clinic}, and {phone}. Follow-up texts on Appointments use the last template.</p>
    </header>
    <?php if ($saved): ?>
        <p class="desk-note">The texts are saved on this computer.</p>
    <?php endif; ?>
    <?php if ($saveError): ?>
        <p class="login-error" role="alert">The texts could not be saved on this computer.</p>
    <?php endif; ?>

    <form method="post" class="stack">
        <?php foreach ($notices as $notice): ?>
            <article class="panel notice">
                <header>
                    <h2><?php echo h($notice['title']); ?></h2>
                    <p><?php echo h($notice['when']); ?></p>
                </header>
                <label class="sr-only" for="msg-<?php echo h($notice['key']); ?>">Message</label>
                <textarea id="msg-<?php echo h($notice['key']); ?>" name="<?php echo h($notice['key']); ?>" rows="4" required><?php echo h($templates[$notice['key']]); ?></textarea>
                <p class="muted">Preview: <?php echo h($notice['message']); ?></p>
            </article>
        <?php endforeach; ?>
        <button class="btn btn-solid" type="submit">Save texts</button>
    </form>
<?php
admin_page_end();
