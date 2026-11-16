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
        } elseif ($action === 'place') {
            $id = (int) ($_POST['waitlist_id'] ?? 0);
            $date = (string) ($_POST['date'] ?? '');
            $placement = (string) ($_POST['placement'] ?? '');
            $result = admin_waitlist_place($id, $date, $placement);
            if ($result['ok']) {
                $notice = ($result['mode'] ?? '') === 'hold'
                    ? 'Holding that unconfirmed time for this waitlist patient.'
                    : 'Booked them into that open time.';
            } else {
                $actionError = (string) ($result['error'] ?? 'Could not place that person.');
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
$placeable = admin_waitlist_placeable_options();
$placeDays = $placeable['days'];
$placeSlots = $placeable['slots'];
$hasPlaceable = $placeDays !== [];
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
                    <input type="tel" name="phone" inputmode="tel" maxlength="14" required placeholder="(757) 555-0142">
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
            <p>No one is waiting right now. When someone cancels, or a visit stays unconfirmed, place the next person from here.</p>
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
                    <div class="wait-actions">
                        <?php if ($entry['status'] === 'Held'): ?>
                            <p class="muted wait-actions-note">Already holding an unconfirmed time.</p>
                        <?php elseif ($hasPlaceable): ?>
                            <button
                                class="btn btn-line"
                                type="button"
                                data-open-place
                                data-id="<?php echo h($entry['id']); ?>"
                                data-patient="<?php echo h($entry['name']); ?>"
                                data-visit="<?php echo h($entry['visit']); ?>"
                            >Place into a time</button>
                        <?php else: ?>
                            <p class="muted wait-actions-note">No open or unconfirmed times right now.</p>
                        <?php endif; ?>
                        <button
                            class="btn btn-remove"
                            type="button"
                            data-open-remove
                            data-id="<?php echo h($entry['id']); ?>"
                            data-patient="<?php echo h($entry['name']); ?>"
                        >Remove</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($hasPlaceable): ?>
            <dialog class="desk-modal" id="place-modal" aria-labelledby="place-modal-title">
                <form method="post" class="desk-modal-card" id="place-form" data-waitlist-place>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="place">
                    <input type="hidden" name="waitlist_id" id="place-id" value="">
                    <header class="desk-modal-head">
                        <h2 id="place-modal-title">Place into a time</h2>
                        <p class="muted" id="place-modal-note">Pick a day and time for this person.</p>
                        <button class="desk-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
                    </header>
                    <p>Choose a day, then a time. <strong>Open slot</strong> books them into a free time (including cancellations). <strong>Hold</strong> keeps the original unconfirmed visit and also books this waitlist person onto the same time (intentional double-book).</p>
                    <label class="wait-place-field">
                        Day
                        <select name="date" id="place-date" required>
                            <?php foreach ($placeDays as $dayOption): ?>
                                <option value="<?php echo h($dayOption['value']); ?>">
                                    <?php echo h($dayOption['full']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="wait-place-field">
                        Time
                        <select name="placement" id="place-placement" required>
                            <?php
                            $firstDate = (string) $placeDays[0]['value'];
                            foreach ($placeSlots[$firstDate] ?? [] as $slotOption):
                            ?>
                                <option value="<?php echo h($slotOption['value']); ?>">
                                    <?php echo h($slotOption['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="desk-modal-actions">
                        <button class="btn btn-line" type="button" data-close-modal>Back</button>
                        <button class="btn btn-solid" type="submit">Place this person</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>

        <dialog class="desk-modal" id="remove-modal" aria-labelledby="remove-modal-title">
            <form method="post" class="desk-modal-card desk-modal-card--narrow" id="remove-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="waitlist_id" id="remove-id" value="">
                <header class="desk-modal-head">
                    <h2 id="remove-modal-title">Remove from waitlist?</h2>
                    <button class="desk-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
                </header>
                <p id="remove-modal-copy">Are you sure you want to remove this person from the waitlist?</p>
                <div class="desk-modal-actions">
                    <button class="btn btn-line" type="button" data-close-modal>No</button>
                    <button class="btn btn-remove" type="submit">Yes, remove</button>
                </div>
            </form>
        </dialog>

        <script type="application/json" id="waitlist-slots"><?php echo json_encode($placeSlots, JSON_UNESCAPED_SLASHES); ?></script>
        <script src="../assets/js/waitlist.js"></script>
    <?php endif; ?>
<?php
admin_page_end();
