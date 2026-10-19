<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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
        foreach ($chosen as $id) {
            $id = (string) $id;
            if (in_array($id, $knownSlots, true) && !in_array($id, $already, true)) {
                $already[] = $id;
            }
        }
        $_SESSION['desk_reminded'] = $already;
    }

    if ($action === 'fill') {
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
        }
    }

    header('Location: index.php');
    exit;
}

$appointments = preview_appointments();
$counts = preview_appointment_counts($appointments);
$unconfirmed = preview_unconfirmed($appointments);
$waiting = preview_waitlist();
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
        'text' => preview_reminder_text($appointment),
    ];
}

admin_page_start('Appointments', 'dashboard');
?>
    <header class="day-head">
        <h1><?php echo h(preview_desk_date()->format('l, F j')); ?></h1>
        <a class="btn btn-solid" href="../index.php">+ Add appointment</a>
    </header>

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
            <?php foreach ($appointments as $appointment): ?>
                <article class="visit-row">
                    <p class="visit-time"><?php echo h($appointment['time']); ?></p>
                    <div class="visit-person">
                        <h2><?php echo h($appointment['patient']); ?></h2>
                        <p><?php echo h($appointment['visit'] . ', ' . $appointment['phone']); ?></p>
                        <?php if ($appointment['note'] !== ''): ?>
                            <p class="row-note<?php echo $appointment['status'] === 'cancelled' ? ' is-cancel' : ''; ?>"><?php echo h($appointment['note']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="visit-actions">
                        <?php admin_status($appointment['status'], $appointment['status_label']); ?>
                        <?php if ($appointment['status'] === 'confirmed'): ?>
                            <a class="btn btn-line" href="notifications.php">Message</a>
                        <?php elseif ($appointment['status'] === 'no_reply'): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="remind">
                                <input type="hidden" name="patient_id[]" value="<?php echo h($appointment['id']); ?>">
                                <button class="btn btn-solid" type="submit">Send reminder</button>
                            </form>
                        <?php elseif ($appointment['status'] === 'reminder_sent'): ?>
                            <a class="btn btn-line" href="tel:<?php echo h($appointment['phone_digits']); ?>">Call patient</a>
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
                    <p class="sms" id="sms-preview"><?php echo h(preview_reminder_text($unconfirmed[0])); ?></p>
                    <button class="btn btn-solid btn-wide" type="submit" id="send-texts">Send text to <?php echo h((string) count($unconfirmed)); ?> patients</button>
                </form>
                <script type="application/json" id="follow-messages"><?php echo json_encode($messages, JSON_UNESCAPED_SLASHES); ?></script>
            <?php endif; ?>
        </aside>
    </div>
    <script src="../assets/js/desk.js"></script>
<?php
admin_page_end();
