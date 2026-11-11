<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/csrf.php';
require_once __DIR__ . '/../../src/admin_desk.php';
require_once __DIR__ . '/../../src/admin_layout.php';

csrf_ensure_session();

$deskDate = admin_desk_today()->format('Y-m-d');
$notice = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $actionError = 'Your form expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'remind') {
            $chosen = $_POST['patient_id'] ?? [];
            if (!is_array($chosen)) {
                $chosen = [];
            }
            $result = admin_mark_reminders_sent($chosen);
            $sent = count($result['marked']);
            $failed = count($result['failed']);
            if ($sent > 0 && $failed === 0) {
                $notice = $sent === 1
                    ? 'Reminder text accepted by the provider (not proof of delivery).'
                    : 'Reminder texts accepted by the provider for ' . $sent . ' visits (not proof of delivery).';
            } elseif ($sent > 0) {
                $notice = 'Accepted ' . $sent . ' reminder(s); ' . $failed . ' could not be sent.';
                $firstError = (string) ($result['failed'][0]['error'] ?? '');
                if ($firstError !== '') {
                    $actionError = $firstError;
                }
            } else {
                $actionError = (string) ($result['failed'][0]['error'] ?? 'No reminder texts were sent.');
            }
        }

        if ($action === 'fill') {
            $slot = (int) ($_POST['slot_id'] ?? 0);
            $person = (int) ($_POST['waitlist_id'] ?? 0);
            $result = admin_fill_cancelled_from_waitlist($slot, $person);
            if ($result['ok']) {
                $notice = 'Filled that time from the waitlist.';
            } else {
                $actionError = (string) ($result['error'] ?? 'Could not fill that time.');
            }
        }
    }

    if ($actionError === '') {
        $_SESSION['desk_notice'] = $notice;
        header('Location: index.php');
        exit;
    }
}

if ($notice === '' && isset($_SESSION['desk_notice']) && is_string($_SESSION['desk_notice'])) {
    $notice = $_SESSION['desk_notice'];
    unset($_SESSION['desk_notice']);
}

$appointments = admin_desk_appointments_for_date($deskDate);
$counts = admin_appointment_counts(array_map(static function (array $row): array {
    return [
        'DeskStatus' => $row['status'],
    ];
}, $appointments));
// Recompute properly from desk rows
$counts = [
    'booked' => count($appointments),
    'confirmed' => 0,
    'not_confirmed' => 0,
    'cancelled' => 0,
];
foreach ($appointments as $appointment) {
    if ($appointment['status'] === 'confirmed') {
        $counts['confirmed']++;
    } elseif ($appointment['status'] === 'cancelled') {
        $counts['cancelled']++;
    } else {
        $counts['not_confirmed']++;
    }
}
$unconfirmed = array_values(array_filter(
    $appointments,
    static fn (array $a): bool => in_array($a['status'], ['no_reply', 'reminder_sent'], true)
));
$waiting = waitlist_desk_entries();
$cards = [
    ['booked', 'Booked', $counts['booked']],
    ['confirmed', 'Confirmed', $counts['confirmed']],
    ['not_confirmed', 'Not confirmed', $counts['not_confirmed']],
    ['cancelled', 'Cancelled', $counts['cancelled']],
];
$messages = [];
foreach ($unconfirmed as $appointment) {
    $messages[] = [
        'id' => $appointment['id'],
        'text' => admin_reminder_preview_text($appointment),
    ];
}

admin_page_start('Appointments', 'dashboard');
?>
    <header class="day-head">
        <h1><?php echo h(admin_desk_today()->format('l, F j')); ?></h1>
        <a class="btn btn-solid" href="../index.php">+ Add appointment</a>
    </header>
    <?php if ($notice !== ''): ?>
        <p class="desk-note"><?php echo h($notice); ?></p>
    <?php endif; ?>
    <?php if ($actionError !== ''): ?>
        <p class="login-error" role="alert"><?php echo h($actionError); ?></p>
    <?php endif; ?>

    <section class="cards" aria-label="Appointment summary">
        <?php foreach ($cards as $card): ?>
            <article class="card card-<?php echo h($card[0]); ?>">
                <h2><?php echo h($card[1]); ?></h2>
                <p class="count"><?php echo h((string) $card[2]); ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="day-layout">
        <section class="panel schedule" aria-label="Appointments">
            <?php if ($appointments === []): ?>
                <p class="muted">No appointments on the schedule for today.</p>
            <?php endif; ?>
            <?php foreach ($appointments as $appointment): ?>
                <article class="visit-row">
                    <p class="visit-time"><?php echo h($appointment['time']); ?></p>
                    <div class="visit-person">
                        <h2><?php echo h($appointment['patient']); ?></h2>
                        <p><?php echo h($appointment['visit'] . ', ' . patient_format_phone($appointment['phone'])); ?></p>
                        <?php if ($appointment['note'] !== ''): ?>
                            <p class="row-note<?php echo $appointment['status'] === 'cancelled' ? ' is-cancel' : ''; ?>"><?php echo h($appointment['note']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="visit-actions">
                        <?php admin_status($appointment['status'], $appointment['status_label']); ?>
                        <?php if ($appointment['status'] === 'no_reply'): ?>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="remind">
                                <input type="hidden" name="patient_id[]" value="<?php echo h($appointment['id']); ?>">
                                <button class="btn btn-solid" type="submit">Mark reminder sent</button>
                            </form>
                        <?php elseif ($appointment['status'] === 'reminder_sent'): ?>
                            <a class="btn btn-line" href="tel:<?php echo h($appointment['phone_digits']); ?>">Call patient</a>
                        <?php elseif ($appointment['status'] === 'cancelled' && $waiting !== []): ?>
                            <details class="fill">
                                <summary>Fill from waitlist</summary>
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="fill">
                                    <input type="hidden" name="slot_id" value="<?php echo h($appointment['id']); ?>">
                                    <p>This opening is one visit block. No SMS will be sent.</p>
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
                </article>
            <?php endforeach; ?>
        </section>

        <aside class="panel follow-up">
            <h2>Follow up</h2>
            <?php if ($unconfirmed === []): ?>
                <p class="muted">Everyone on this day has confirmed, or the open blocks are cancelled.</p>
            <?php else: ?>
                <p class="muted" id="follow-count"><?php echo h((string) count($unconfirmed)); ?> patients have not confirmed.</p>
                <form method="post" id="follow-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="remind">
                    <ul class="follow-list">
                        <?php foreach ($unconfirmed as $appointment): ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="patient_id[]" value="<?php echo h($appointment['id']); ?>" checked>
                                    <span><?php echo h($appointment['patient']); ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="sms" id="sms-preview"><?php echo h(admin_reminder_preview_text($unconfirmed[0])); ?></p>
                    <p class="muted">This marks ConfirmationStatus as Pending and NotificationStatus as Sent. It does not send an SMS.</p>
                    <button class="btn btn-solid btn-wide" type="submit" id="send-texts">Mark reminder for <?php echo h((string) count($unconfirmed)); ?> patients</button>
                </form>
                <script type="application/json" id="follow-messages"><?php echo json_encode($messages, JSON_UNESCAPED_SLASHES); ?></script>
            <?php endif; ?>
        </aside>
    </div>
    <script src="../assets/js/desk.js"></script>
<?php
admin_page_end();
