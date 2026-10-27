<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$notice = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $knownSlots = array_column(preview_appointment_seed(), 'id');
    $knownWaiting = array_column(preview_waitlist_seed(), 'id');

    if ($action === 'remind') {
        $chosen = $_POST['patient_id'] ?? [];
        if (!is_array($chosen)) {
            $chosen = [];
        }
        $already = $_SESSION['desk_reminded'] ?? [];
        $added = 0;
        foreach ($chosen as $id) {
            $id = (string) $id;
            if (in_array($id, $knownSlots, true) && !in_array($id, $already, true)) {
                $already[] = $id;
                $added++;
            }
        }
        $_SESSION['desk_reminded'] = $already;
        if ($added > 0) {
            $_SESSION['desk_notice'] = $added === 1
                ? 'Marked a reminder as sent for this sample day.'
                : 'Marked reminders as sent for this sample day.';
        } else {
            $_SESSION['desk_error'] = 'Choose at least one patient who still needs a reminder.';
        }
    } elseif ($action === 'fill') {
        $slot = (string) ($_POST['slot_id'] ?? '');
        $person = (string) ($_POST['waitlist_id'] ?? '');
        $used = array_values($_SESSION['desk_fills'] ?? []);
        if (
            in_array($slot, $knownSlots, true)
            && in_array($person, $knownWaiting, true)
            && !in_array($person, $used, true)
            && !isset($_SESSION['desk_fills'][$slot])
        ) {
            $_SESSION['desk_fills'][$slot] = $person;
            $_SESSION['desk_notice'] = 'Filled that opening from the waitlist.';
        } else {
            $_SESSION['desk_error'] = 'That waitlist opening could not be filled.';
        }
    } else {
        $_SESSION['desk_error'] = 'That action is not available.';
    }

    header('Location: index.php');
    exit;
}

if (isset($_SESSION['desk_notice']) && is_string($_SESSION['desk_notice'])) {
    $notice = $_SESSION['desk_notice'];
    unset($_SESSION['desk_notice']);
}
if (isset($_SESSION['desk_error']) && is_string($_SESSION['desk_error'])) {
    $actionError = $_SESSION['desk_error'];
    unset($_SESSION['desk_error']);
}

$appointments = preview_appointments();
$counts = preview_appointment_counts($appointments);
$unconfirmed = preview_unconfirmed($appointments);
$waiting = preview_waitlist();
$cards = [
    ['today', "Today's appointments", $counts['booked'], 'clock'],
    ['confirmed', 'Confirmed', $counts['confirmed'], 'check'],
    ['not_confirmed', 'Awaiting confirmation', $counts['not_confirmed'], 'alert'],
    ['cancelled', 'Cancelled', $counts['cancelled'], 'cancel'],
];
$messages = [];
foreach ($unconfirmed as $appointment) {
    $messages[] = [
        'id' => $appointment['id'],
        'text' => preview_reminder_text($appointment),
    ];
}

admin_page_start('Dashboard', 'dashboard');
?>
    <header class="day-head">
        <div>
            <p class="eyebrow">Dashboard</p>
            <h1><?php echo h(preview_desk_date()->format('l, F j')); ?></h1>
            <p class="muted">Today’s clinic board. Counts come from the current appointment list.</p>
        </div>
        <a class="btn btn-solid" href="../index.php">Add appointment</a>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="desk-note"><?php echo h($notice); ?></p>
    <?php endif; ?>
    <?php if ($actionError !== ''): ?>
        <p class="login-error" role="alert"><?php echo h($actionError); ?></p>
    <?php endif; ?>

    <section class="cards" aria-label="Appointment summary">
        <?php foreach ($cards as $card): ?>
            <?php admin_summary_card($card[0], $card[1], $card[2], $card[3]); ?>
        <?php endforeach; ?>
    </section>

    <div class="day-layout">
        <section class="panel schedule-panel" aria-label="Today's appointments">
            <div class="panel-head-row">
                <div>
                    <h2>Today’s schedule</h2>
                    <p class="muted">Chronological visits for this sample clinic day.</p>
                </div>
                <a class="btn btn-line" href="patients.php">Full schedule</a>
            </div>
            <?php if ($appointments === []): ?>
                <?php admin_empty_state(
                    'No visits on the board',
                    'When patients book, today’s appointments will show here.',
                    'patients.php',
                    'Open the schedule'
                ); ?>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($appointments as $appointment): ?>
                        <?php admin_desk_appointment($appointment, $waiting); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="desk-side">
            <aside class="panel follow-up">
                <h2>Follow up</h2>
                <?php if ($unconfirmed === []): ?>
                    <?php admin_empty_state(
                        'Caught up for today',
                        'Everyone on this day has confirmed, or the open blocks are cancelled.'
                    ); ?>
                <?php else: ?>
                    <p class="muted" id="follow-count"><?php echo h((string) count($unconfirmed)); ?> patients have not confirmed.</p>
                    <form method="post" id="follow-form">
                        <input type="hidden" name="action" value="remind">
                        <ul class="follow-list">
                            <?php foreach ($unconfirmed as $appointment): ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="patient_id[]" value="<?php echo h($appointment['id']); ?>" checked>
                                        <span class="avatar" aria-hidden="true"><?php echo h(admin_initials((string) $appointment['patient'])); ?></span>
                                        <span>
                                            <strong><?php echo h($appointment['patient']); ?></strong>
                                            <span><?php echo h($appointment['time'] . ' · ' . $appointment['status_label']); ?></span>
                                        </span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="sms" id="sms-preview"><?php echo h(preview_reminder_text($unconfirmed[0])); ?></p>
                        <button class="btn btn-solid btn-wide" type="submit" id="send-texts">Send text to <?php echo h((string) count($unconfirmed)); ?> patients</button>
                    </form>
                    <script type="application/json" id="follow-messages"><?php echo json_encode($messages, JSON_UNESCAPED_SLASHES); ?></script>
                <?php endif; ?>
            </aside>

            <nav class="panel quick-nav" aria-label="Quick navigation">
                <h2>Quick navigation</h2>
                <p class="muted">Jump to the lists used during a clinic day.</p>
                <a href="patients.php">
                    <?php admin_icon('schedule'); ?>
                    <span>
                        <strong>Schedule</strong>
                        Upcoming visits, reschedules, and cancellations
                    </span>
                </a>
                <a href="directory.php">
                    <?php admin_icon('patients'); ?>
                    <span>
                        <strong>Patients</strong>
                        Names from the current appointment list
                    </span>
                </a>
                <a href="waitlist.php">
                    <?php admin_icon('waitlist'); ?>
                    <span>
                        <strong>Waitlist</strong>
                        People waiting for an open 15-minute block
                    </span>
                </a>
            </nav>
        </div>
    </div>
    <script src="../assets/js/desk.js"></script>
<?php
admin_page_end();
