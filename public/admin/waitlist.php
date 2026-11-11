<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/csrf.php';
require_once __DIR__ . '/../../src/admin_desk.php';
require_once __DIR__ . '/../../src/admin_layout.php';

csrf_ensure_session();

$notice = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $actionError = 'Your form expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $visit = (string) ($_POST['visit_type'] ?? 'wellness');
            $digits = patient_phone_digits($phone);
            if (strlen($digits) !== 10) {
                $actionError = 'Enter a 10-digit phone number.';
            } else {
                $created = patient_create($name, patient_booking_format_phone($digits));
                if (!$created['ok']) {
                    $actionError = (string) $created['error'];
                } else {
                    $added = waitlist_add((int) $created['patient']['Patient_ID'], $visit);
                    if ($added['ok']) {
                        $notice = 'Added to the waitlist.';
                    } else {
                        $actionError = (string) $added['error'];
                    }
                }
            }
        } elseif ($action === 'remove') {
            $id = (int) ($_POST['waitlist_id'] ?? 0);
            $result = waitlist_set_status($id, 'Removed');
            if ($result['ok']) {
                $notice = 'Removed from the waitlist.';
            } else {
                $actionError = (string) $result['error'];
            }
        }
    }

    if ($actionError === '' && $notice !== '') {
        $_SESSION['waitlist_notice'] = $notice;
        header('Location: waitlist.php');
        exit;
    }
}

if ($notice === '' && isset($_SESSION['waitlist_notice']) && is_string($_SESSION['waitlist_notice'])) {
    $notice = $_SESSION['waitlist_notice'];
    unset($_SESSION['waitlist_notice']);
}

admin_page_start('Waitlist', 'waitlist');
$people = waitlist_desk_entries();
?>
    <header class="page-head">
        <div>
            <h1>Waitlist</h1>
        </div>
    </header>
    <?php if ($notice !== ''): ?>
        <p class="desk-note"><?php echo h($notice); ?></p>
    <?php endif; ?>
    <?php if ($actionError !== ''): ?>
        <p class="login-error" role="alert"><?php echo h($actionError); ?></p>
    <?php endif; ?>

    <section class="panel">
        <h2>Add someone</h2>
        <form method="post" class="hours-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <div class="hours-grid">
                <label>
                    Name
                    <input type="text" name="name" maxlength="50" required>
                </label>
                <label>
                    Phone
                    <input type="tel" name="phone" maxlength="15" required>
                </label>
                <label>
                    Visit type
                    <select name="visit_type">
                        <option value="wellness">First Visit</option>
                        <option value="follow_up">Follow-up</option>
                    </select>
                </label>
            </div>
            <button class="btn btn-solid" type="submit">Add to waitlist</button>
        </form>
    </section>

    <?php if ($people === []): ?>
        <section class="panel">
            <p>No one is waiting right now. Open appointments to place someone when a block opens.</p>
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
                    <div class="visit-actions">
                        <a class="btn btn-line" href="index.php">Place in an open block</a>
                        <form method="post">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="waitlist_id" value="<?php echo h($entry['id']); ?>">
                            <button class="btn btn-line" type="submit">Remove</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php
admin_page_end();
