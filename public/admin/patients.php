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
$query = trim((string) ($_GET['q'] ?? ''));
$view = (string) ($_GET['view'] ?? 'upcoming');
if (!in_array($view, ['upcoming', 'unconfirmed', 'cancelled', 'all'], true)) {
    $view = 'upcoming';
}

function patients_back(string $query, string $view): string
{
    $params = ['view' => $view];
    if ($query !== '') {
        $params['q'] = $query;
    }

    return 'patients.php?' . http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $actionError = 'Your form expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $query = trim((string) ($_POST['q'] ?? $query));
        $view = (string) ($_POST['view'] ?? $view);
        $appointment = appointment_find_by_id($id);

        if ($action !== '' && $appointment === null) {
            $actionError = 'That visit is not on the upcoming list.';
        } elseif ($appointment !== null && $action === 'cancel' && $appointment['AppointmentStatus'] !== 'Cancelled') {
            $result = appointment_cancel($id);
            if ($result['ok']) {
                require_once __DIR__ . '/../../src/sms_notifications.php';
                sms_notify_cancel($id);
                $notice = 'Cancelled the visit at ' . $appointment['ScheduleTimeLabel'] . '.';
                $view = 'cancelled';
            } else {
                $actionError = (string) $result['error'];
            }
        } elseif ($appointment !== null && $action === 'restore' && $appointment['AppointmentStatus'] === 'Cancelled') {
            $result = admin_restore_appointment($id);
            if ($result['ok']) {
                $notice = 'Put the visit back on the schedule.';
                $view = 'upcoming';
            } else {
                $actionError = (string) $result['error'];
            }
        } elseif ($appointment !== null && $action === 'remind') {
            $result = admin_mark_reminder_sent($id);
            if ($result['ok']) {
                $notice = 'Reminder text accepted by the provider (not proof of delivery).';
            } else {
                $actionError = (string) $result['error'];
            }
        } elseif ($appointment !== null && $action === 'double') {
            $person = (int) ($_POST['waitlist_id'] ?? 0);
            $result = admin_double_book_from_waitlist($id, $person);
            if ($result['ok']) {
                $notice = 'This unconfirmed time is also held for a waitlist patient.';
            } else {
                $actionError = (string) $result['error'];
            }
        } elseif ($appointment !== null && $action === 'move' && $appointment['AppointmentStatus'] !== 'Cancelled') {
            $date = (string) ($_POST['date'] ?? '');
            $time = (string) ($_POST['time'] ?? '');
            $result = appointment_reschedule($id, $date, $time);
            if ($result['ok']) {
                require_once __DIR__ . '/../../src/sms_notifications.php';
                sms_notify_reschedule($id);
                $notice = 'Moved the visit to ' . $result['appointment']['ScheduleTimeLabel'] . ' on ' . $result['appointment']['ScheduleDate'] . '.';
            } else {
                $actionError = (string) $result['error'];
            }
        }
    }

    if ($notice !== '' && $actionError === '') {
        $_SESSION['patients_notice'] = $notice;
        header('Location: ' . patients_back($query, $view));
        exit;
    }
}

if ($notice === '' && isset($_SESSION['patients_notice']) && is_string($_SESSION['patients_notice'])) {
    $notice = $_SESSION['patients_notice'];
    unset($_SESSION['patients_notice']);
}

$all = admin_upcoming_appointments();
$counts = admin_upcoming_counts($all);
$waiting = waitlist_desk_entries();
$openDays = admin_schedule_open_dates();
$slotMap = [];
foreach ($openDays as $day) {
    $slotMap[$day['value']] = array_map(
        static fn (array $slot): array => ['value' => $slot['value'], 'label' => $slot['label']],
        patient_booking_open_slots($day['value'])
    );
}

$matched = array_values(array_filter(
    $all,
    static fn (array $appointment): bool => admin_upcoming_matches($appointment, $query)
));
$shown = array_values(array_filter($matched, static function (array $appointment) use ($view): bool {
    if ($view === 'unconfirmed') {
        return in_array($appointment['status'], ['no_reply', 'reminder_sent'], true);
    }
    if ($view === 'cancelled') {
        return $appointment['status'] === 'cancelled';
    }
    if ($view === 'upcoming') {
        return $appointment['status'] !== 'cancelled';
    }

    return true;
}));

$groups = [];
foreach ($shown as $appointment) {
    $groups[$appointment['date']][] = $appointment;
}

$filters = [
    'upcoming' => 'Upcoming',
    'unconfirmed' => 'Not confirmed',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];

admin_page_start('Patients', 'patients');
?>
    <header class="page-head">
        <div>
            <h1>Upcoming visits</h1>
        </div>
    </header>
    <?php if ($notice !== ''): ?>
        <p class="desk-note"><?php echo h($notice); ?></p>
    <?php endif; ?>
    <?php if ($actionError !== ''): ?>
        <p class="login-error" role="alert"><?php echo h($actionError); ?></p>
    <?php endif; ?>

    <form method="get" class="find-form" role="search">
        <input type="hidden" name="view" value="<?php echo h($view); ?>">
        <label class="sr-only" for="visit-search">Name or phone number</label>
        <input id="visit-search" type="search" name="q" value="<?php echo h($query); ?>" placeholder="Name or phone number" autocomplete="off">
        <button class="btn btn-solid" type="submit">Find visit</button>
    </form>

    <nav class="view-tabs" aria-label="Visit list">
        <?php foreach ($filters as $key => $label): ?>
            <a href="<?php echo h(patients_back($query, $key)); ?>"<?php echo $view === $key ? ' aria-current="page"' : ''; ?>>
                <?php echo h($label); ?>
                <span><?php echo h((string) $counts[$key]); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($openDays === [] && $shown === []): ?>
        <section class="panel">
            <p class="muted">There are no open days yet. Open a day on Availability first.</p>
        </section>
    <?php elseif ($shown === []): ?>
        <section class="panel">
            <p class="muted"><?php echo $query !== '' ? 'No visit matches that name or phone.' : 'No visits in this list.'; ?></p>
        </section>
    <?php else: ?>
        <?php foreach ($groups as $date => $visits): ?>
            <?php
            $heading = $visits[0]['date_label'];
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/New_York'));
            if ($day !== false) {
                $heading = $day->format('l, F j');
            }
            ?>
            <section class="panel schedule">
                <h2 class="visit-day"><?php echo h($heading); ?></h2>
                <?php foreach ($visits as $appointment): ?>
                    <article class="visit-row visit-manage">
                        <p class="visit-time"><?php echo h($appointment['time']); ?></p>
                        <div class="visit-status">
                            <?php admin_status($appointment['status'], $appointment['status_label']); ?>
                        </div>
                        <div class="visit-main">
                        <div class="visit-person">
                            <h2><?php echo h($appointment['patient']); ?></h2>
                            <p><?php echo h($appointment['visit'] . ' · ' . patient_format_phone($appointment['phone'])); ?></p>
                            <?php if ($appointment['note'] !== ''): ?>
                                <p class="row-note<?php echo $appointment['status'] === 'cancelled' ? ' is-cancel' : ''; ?>"><?php echo h($appointment['note']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="visit-actions">
                            <?php if ($appointment['status'] === 'cancelled'): ?>
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="id" value="<?php echo h($appointment['id']); ?>">
                                    <input type="hidden" name="q" value="<?php echo h($query); ?>">
                                    <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                    <button class="btn btn-line" type="submit">Keep this visit</button>
                                </form>
                            <?php else: ?>
                                <?php if ($appointment['status'] === 'no_reply'): ?>
                                    <form method="post">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="remind">
                                        <input type="hidden" name="id" value="<?php echo h($appointment['id']); ?>">
                                        <input type="hidden" name="q" value="<?php echo h($query); ?>">
                                        <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                        <button class="btn btn-solid" type="submit">Send reminder text</button>
                                    </form>
                                <?php elseif ($appointment['status'] === 'reminder_sent'): ?>
                                    <a class="btn btn-line" href="tel:<?php echo h($appointment['phone_digits']); ?>">Call patient</a>
                                <?php endif; ?>
                                <?php if (in_array($appointment['status'], ['no_reply', 'reminder_sent'], true) && $waiting !== []): ?>
                                    <button
                                        class="btn btn-line"
                                        type="button"
                                        data-open-hold
                                        data-id="<?php echo h($appointment['id']); ?>"
                                        data-patient="<?php echo h($appointment['patient']); ?>"
                                        data-when="<?php echo h($appointment['date_label'] . ' · ' . $appointment['time']); ?>"
                                    >Hold for waitlist</button>
                                <?php endif; ?>
                                <?php if ($openDays !== []): ?>
                                    <button
                                        class="btn btn-line"
                                        type="button"
                                        data-open-move
                                        data-id="<?php echo h($appointment['id']); ?>"
                                        data-patient="<?php echo h($appointment['patient']); ?>"
                                        data-date="<?php echo h($appointment['date']); ?>"
                                        data-time="<?php echo h($appointment['time_value']); ?>"
                                        data-time-label="<?php echo h($appointment['time']); ?>"
                                    >Change time</button>
                                <?php endif; ?>
                                <button
                                    class="btn btn-line"
                                    type="button"
                                    data-open-cancel
                                    data-id="<?php echo h($appointment['id']); ?>"
                                    data-patient="<?php echo h($appointment['patient']); ?>"
                                    data-when="<?php echo h($appointment['date_label'] . ' · ' . $appointment['time']); ?>"
                                >Cancel visit</button>
                            <?php endif; ?>
                        </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
    <dialog class="desk-modal" id="move-modal" aria-labelledby="move-modal-title">
        <form method="post" class="desk-modal-card" id="move-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="id" id="move-id" value="">
            <input type="hidden" name="q" value="<?php echo h($query); ?>">
            <input type="hidden" name="view" value="<?php echo h($view); ?>">
            <input type="hidden" name="date" id="move-date" value="" required>
            <input type="hidden" name="time" id="move-time" value="" required>
            <header class="desk-modal-head">
                <h2 id="move-modal-title">Change time</h2>
                <p class="muted" id="move-modal-note">Pick a new day and time.</p>
                <button class="desk-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
            </header>
            <div class="move-cal" aria-label="Choose a day">
                <div class="move-cal-nav">
                    <button class="btn btn-line" type="button" id="move-prev-month">Previous</button>
                    <h3 id="move-month-label">Month</h3>
                    <button class="btn btn-line" type="button" id="move-next-month">Next</button>
                </div>
                <div class="move-cal-grid" id="move-cal-grid" role="grid"></div>
            </div>
            <p class="picker-label">Time</p>
            <div class="move-times" id="move-times" role="group" aria-label="Choose a time"></div>
            <div class="desk-modal-actions">
                <button class="btn btn-line" type="button" data-close-modal>Back</button>
                <button class="btn btn-solid" type="submit" id="move-submit" disabled>Save new time</button>
            </div>
        </form>
    </dialog>

    <dialog class="desk-modal" id="cancel-modal" aria-labelledby="cancel-modal-title">
        <form method="post" class="desk-modal-card desk-modal-card--narrow" id="cancel-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" id="cancel-id" value="">
            <input type="hidden" name="q" value="<?php echo h($query); ?>">
            <input type="hidden" name="view" value="<?php echo h($view); ?>">
            <header class="desk-modal-head">
                <h2 id="cancel-modal-title">Cancel visit?</h2>
                <button class="desk-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
            </header>
            <p id="cancel-modal-copy">Are you sure you want to cancel this appointment?</p>
            <div class="desk-modal-actions">
                <button class="btn btn-line" type="button" data-close-modal>No</button>
                <button class="btn btn-remove" type="submit">Yes, cancel</button>
            </div>
        </form>
    </dialog>

    <?php if ($waiting !== []): ?>
        <dialog class="desk-modal" id="hold-modal" aria-labelledby="hold-modal-title">
            <form method="post" class="desk-modal-card" id="hold-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="double">
                <input type="hidden" name="id" id="hold-id" value="">
                <input type="hidden" name="q" value="<?php echo h($query); ?>">
                <input type="hidden" name="view" value="<?php echo h($view); ?>">
                <header class="desk-modal-head">
                    <h2 id="hold-modal-title">Hold for waitlist</h2>
                    <button class="desk-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
                </header>
                <p id="hold-modal-copy">This keeps the original unconfirmed visit, and also books the same time for someone on the waitlist (intentional double-book). If the first patient confirms, you’ll still have both on that slot.</p>
                <p class="muted" id="hold-modal-note"></p>
                <p class="picker-label">Choose who to hold this time for</p>
                <div class="hold-people">
                    <?php foreach ($waiting as $index => $person): ?>
                        <label class="hold-person">
                            <input type="radio" name="waitlist_id" value="<?php echo h($person['id']); ?>"<?php echo $index === 0 ? ' required' : ''; ?>>
                            <span>
                                <strong><?php echo h($person['name']); ?></strong>
                                <small><?php echo h($person['visit'] . ' · ' . $person['phone']); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="desk-modal-actions">
                    <button class="btn btn-line" type="button" data-close-modal>Back</button>
                    <button class="btn btn-solid" type="submit">Hold this time</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>

    <script type="application/json" id="patients-move-data"><?php echo json_encode([
        'slots' => $slotMap,
        'days' => $openDays,
    ], JSON_UNESCAPED_SLASHES); ?></script>
    <script src="../assets/js/patients.js"></script>
<?php
admin_page_end();
