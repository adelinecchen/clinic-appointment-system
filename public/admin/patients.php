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

function patients_push_id(string $key, string $id): void
{
    $already = preview_session_string_list($key);
    if (!in_array($id, $already, true)) {
        $already[] = $id;
        $_SESSION[$key] = $already;
    }
}

function patients_remove_id(string $key, string $id): void
{
    $_SESSION[$key] = array_values(array_filter(
        preview_session_string_list($key),
        static fn (string $item): bool => $item !== $id
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    $query = trim((string) ($_POST['q'] ?? $query));
    $view = (string) ($_POST['view'] ?? $view);
    $known = preview_upcoming_ids();
    $appointment = in_array($id, $known, true) ? preview_upcoming_by_id($id) : null;

    if ($action !== '' && $appointment === null) {
        $actionError = 'That visit is not on the upcoming list.';
    } elseif ($appointment !== null && $action === 'cancel' && $appointment['status'] !== 'cancelled') {
        patients_push_id('upcoming_cancelled', $id);
        preview_sync_live_booking(array_merge($appointment, ['status' => 'cancelled']));
        $notice = 'Cancelled ' . $appointment['patient'] . '’s visit at ' . $appointment['time'] . '.';
    } elseif ($appointment !== null && $action === 'restore' && $appointment['status'] === 'cancelled') {
        patients_remove_id('upcoming_cancelled', $id);
        preview_sync_live_booking(array_merge($appointment, ['status' => 'no_reply']));
        $notice = 'Put ' . $appointment['patient'] . '’s visit back on the schedule.';
    } elseif ($appointment !== null && $action === 'remind' && in_array($appointment['status'], ['no_reply', 'reminder_sent'], true)) {
        patients_push_id('upcoming_reminded', $id);
        $notice = 'Marked a reminder sent to ' . $appointment['patient'] . '.';
    } elseif ($appointment !== null && $action === 'double' && in_array($appointment['status'], ['no_reply', 'reminder_sent'], true)) {
        $person = (string) ($_POST['waitlist_id'] ?? '');
        $waiting = array_column(preview_waitlist(), 'id');
        if (in_array($person, $waiting, true)) {
            $doubles = preview_session_map('upcoming_doubles');
            $doubles[$id] = $person;
            $_SESSION['upcoming_doubles'] = $doubles;
            $notice = 'This unconfirmed time is also held for a waitlist patient.';
        } else {
            $actionError = 'Choose someone from the waitlist.';
        }
    } elseif ($appointment !== null && $action === 'move' && $appointment['status'] !== 'cancelled') {
        $date = (string) ($_POST['date'] ?? '');
        $time = (string) ($_POST['time'] ?? '');
        if (!preview_date_is_choosable($date) || preview_day_hours($date) === null || !preview_time_is_open($date, $time)) {
            $actionError = 'Choose an open day and time.';
        } elseif (preview_upcoming_slot_taken($date, $time, $id)) {
            $actionError = 'That time already has a visit. Pick another block.';
        } else {
            $moves = preview_session_map('upcoming_moves');
            $moves[$id] = ['date' => $date, 'time' => $time];
            $_SESSION['upcoming_moves'] = $moves;
            $moved = array_merge($appointment, [
                'date' => $date,
                'date_label' => preview_day_record($date)['full'] ?? $date,
                'time' => preview_slot_label($time),
                'time_value' => $time,
            ]);
            preview_sync_live_booking($moved);
            $notice = 'Moved ' . $appointment['patient'] . ' to ' . preview_slot_label($time) . ' on ' . ($moved['date_label']);
        }
    }

    if ($notice !== '' && $actionError === '') {
        $_SESSION['patients_notice'] = $notice;
        if ($action === 'cancel') {
            $view = 'cancelled';
        } elseif ($action === 'restore') {
            $view = 'upcoming';
        }
        header('Location: ' . patients_back($query, $view));
        exit;
    }
}

if ($notice === '' && isset($_SESSION['patients_notice']) && is_string($_SESSION['patients_notice'])) {
    $notice = $_SESSION['patients_notice'];
    unset($_SESSION['patients_notice']);
}

$all = preview_upcoming_appointments();
$counts = preview_upcoming_counts($all);
$waiting = preview_waitlist();
$openDays = preview_open_dates();
$slotMap = [];
foreach ($openDays as $day) {
    $slotMap[$day['value']] = array_map(
        static fn (array $slot): array => ['value' => $slot['value'], 'label' => $slot['label']],
        preview_open_slots($day['value'])
    );
}

$matched = array_values(array_filter(
    $all,
    static fn (array $appointment): bool => preview_upcoming_matches($appointment, $query)
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

admin_page_start('Schedule', 'schedule');
?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Schedule</p>
            <h1>Upcoming visits</h1>
        </div>
        <p class="head-note">When someone calls the desk, find the visit by name or phone. You can cancel it, change the time, send a reminder, or hold an unconfirmed block for a waitlist patient.</p>
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

    <?php if ($openDays === []): ?>
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
                        <div class="visit-person">
                            <h2><?php echo h($appointment['patient']); ?></h2>
                            <p><?php echo h($appointment['visit'] . ' · ' . $appointment['phone']); ?></p>
                            <?php if ($appointment['note'] !== ''): ?>
                                <p class="row-note<?php echo $appointment['status'] === 'cancelled' ? ' is-cancel' : ''; ?>"><?php echo h($appointment['note']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="visit-actions">
                            <?php admin_type_badge((string) $appointment['visit']); ?>
                            <?php admin_status($appointment['status'], $appointment['status_label']); ?>
                            <?php if ($appointment['status'] === 'cancelled'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="id" value="<?php echo h($appointment['id']); ?>">
                                    <input type="hidden" name="q" value="<?php echo h($query); ?>">
                                    <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                    <button class="btn btn-line" type="submit">Keep this visit</button>
                                </form>
                            <?php else: ?>
                                <?php if ($appointment['status'] === 'no_reply'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="remind">
                                        <input type="hidden" name="id" value="<?php echo h($appointment['id']); ?>">
                                        <input type="hidden" name="q" value="<?php echo h($query); ?>">
                                        <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                        <button class="btn btn-solid" type="submit">Send reminder</button>
                                    </form>
                                <?php elseif ($appointment['status'] === 'reminder_sent'): ?>
                                    <a class="btn btn-line" href="tel:<?php echo h($appointment['phone_digits']); ?>">Call patient</a>
                                <?php endif; ?>
                                <?php if (in_array($appointment['status'], ['no_reply', 'reminder_sent'], true) && $waiting !== [] && $appointment['held_for'] === ''): ?>
                                    <details class="fill">
                                        <summary>Hold for waitlist</summary>
                                        <form method="post">
                                            <input type="hidden" name="action" value="double">
                                            <input type="hidden" name="id" value="<?php echo h($appointment['id']); ?>">
                                            <input type="hidden" name="q" value="<?php echo h($query); ?>">
                                            <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                            <p>Keep this unconfirmed visit, and also hold the time for someone waiting.</p>
                                            <?php foreach ($waiting as $person): ?>
                                                <label>
                                                    <input type="radio" name="waitlist_id" value="<?php echo h($person['id']); ?>" required>
                                                    <span>
                                                        <strong><?php echo h($person['name']); ?></strong>
                                                        <?php echo h($person['visit'] . ' · ' . $person['phone']); ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                            <button class="btn btn-solid" type="submit">Hold this time</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($openDays !== []): ?>
                                    <details class="fill">
                                        <summary>Change time</summary>
                                        <form method="post" data-move-form>
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="id" value="<?php echo h($appointment['id']); ?>">
                                            <input type="hidden" name="q" value="<?php echo h($query); ?>">
                                            <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                            <label>
                                                Day
                                                <select name="date">
                                                    <?php foreach ($openDays as $dayOption): ?>
                                                        <option value="<?php echo h($dayOption['value']); ?>"<?php echo $dayOption['value'] === $appointment['date'] ? ' selected' : ''; ?>>
                                                            <?php echo h($dayOption['full']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>
                                                Time
                                                <select name="time" data-current="<?php echo h($appointment['time_value']); ?>">
                                                    <?php foreach ($slotMap[$appointment['date']] ?? [['value' => $appointment['time_value'], 'label' => $appointment['time']]] as $slot): ?>
                                                        <option value="<?php echo h($slot['value']); ?>"<?php echo $slot['value'] === $appointment['time_value'] ? ' selected' : ''; ?>>
                                                            <?php echo h($slot['label']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <button class="btn btn-solid" type="submit">Save new time</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?php echo h($appointment['id']); ?>">
                                    <input type="hidden" name="q" value="<?php echo h($query); ?>">
                                    <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                    <button class="btn btn-line" type="submit">Cancel visit</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
    <script type="application/json" id="upcoming-slots"><?php echo json_encode($slotMap, JSON_UNESCAPED_SLASHES); ?></script>
    <script src="../assets/js/patients.js"></script>
<?php
admin_page_end();
