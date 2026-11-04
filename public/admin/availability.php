<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/csrf.php';
require_once __DIR__ . '/../../src/admin_desk.php';
require_once __DIR__ . '/../../src/admin_layout.php';

$saveError = false;
$hoursError = '';
$draftHours = null;
$postedDate = '';
$postedMonth = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $actionError = 'Your form expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $date = (string) ($_POST['date'] ?? '');
        $time = (string) ($_POST['time'] ?? '');
        $month = (string) ($_POST['month'] ?? '');
        $postedDate = $date;
        $postedMonth = $month;

        $back = 'availability.php?date=' . rawurlencode($date);
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $back .= '&month=' . rawurlencode($month);
        }

        if (admin_date_is_choosable($date) && in_array($action, ['open_date', 'update_hours'], true)) {
            $parsed = schedule_normalize_hours(
                (string) ($_POST['start'] ?? ''),
                (string) ($_POST['end'] ?? ''),
                (int) ($_POST['block'] ?? 15)
            );
            if (!$parsed['ok']) {
                $hoursError = str_contains((string) $parsed['error'], 'block') || str_contains((string) $parsed['error'], 'visit')
                    ? 'blocks'
                    : 'hours';
                $draftHours = [
                    'start' => (string) ($_POST['start'] ?? ''),
                    'end' => (string) ($_POST['end'] ?? ''),
                    'block' => (int) ($_POST['block'] ?? 15),
                ];
            } elseif ($action === 'open_date') {
                $result = schedule_open_day($date, $parsed['hours']['start'], $parsed['hours']['end'], $parsed['hours']['block']);
                if (!$result['ok']) {
                    $saveError = true;
                } else {
                    header('Location: ' . $back);
                    exit;
                }
            } else {
                $result = schedule_update_day_hours($date, $parsed['hours']['start'], $parsed['hours']['end'], $parsed['hours']['block']);
                if (!$result['ok']) {
                    $saveError = true;
                } else {
                    header('Location: ' . $back);
                    exit;
                }
            }
        } elseif ($action === 'close_date' && admin_date_is_choosable($date)) {
            $result = schedule_close_day($date);
            if (!$result['ok']) {
                $saveError = true;
            } else {
                header('Location: ' . $back);
                exit;
            }
        } elseif ($action === 'close_slot' && admin_date_is_choosable($date)) {
            $result = schedule_close_slot($date, $time);
            if (!$result['ok']) {
                $saveError = true;
            } else {
                header('Location: ' . $back);
                exit;
            }
        } elseif ($action === 'open_slot' && admin_date_is_choosable($date)) {
            $result = schedule_open_slot($date, $time);
            if (!$result['ok']) {
                $saveError = true;
            } else {
                header('Location: ' . $back);
                exit;
            }
        }
    }
}

$today = admin_desk_today();
$selected = $postedDate !== '' ? $postedDate : (string) ($_GET['date'] ?? '');
if (!admin_date_is_choosable($selected)) {
    $openDays = admin_schedule_open_dates();
    $selected = $openDays[0]['value'] ?? $today->format('Y-m-d');
}

$monthValue = $postedMonth !== '' ? $postedMonth : (string) ($_GET['month'] ?? substr($selected, 0, 7));
$month = DateTimeImmutable::createFromFormat('!Y-m-d', $monthValue . '-01', new DateTimeZone('America/New_York'));
$firstAllowed = $today->modify('first day of this month');
$lastAllowed = $today->modify('+5 months')->modify('first day of this month');
if ($month === false || $month->format('Y-m') < $firstAllowed->format('Y-m') || $month->format('Y-m') > $lastAllowed->format('Y-m')) {
    $month = $selected !== ''
        ? new DateTimeImmutable(substr($selected, 0, 7) . '-01')
        : $firstAllowed;
}

$openValues = array_column(admin_schedule_open_dates(), 'value');
$daySlots = schedule_slots_for_date($selected);
$selectedIsOpen = false;
foreach ($daySlots as $slot) {
    if ($slot['status'] === 'Open') {
        $selectedIsOpen = true;
        break;
    }
}
$selectedRecord = patient_booking_day_record($selected);
$selectedLabel = $selectedRecord['full'] ?? $selected;
$selectedHours = $draftHours ?? admin_schedule_day_hours($selected) ?? preview_default_day_hours();
$monthKey = $month->format('Y-m');
$previousMonth = $month->modify('-1 month');
$nextMonth = $month->modify('+1 month');

function availability_hours_fields(array $hours, string $action, string $date, string $month, string $submitLabel): void
{
    $start = schedule_time_value((string) ($hours['start'] ?? '09:00'));
    $end = schedule_time_value((string) ($hours['end'] ?? '17:00'));
    $block = (int) ($hours['block'] ?? 15);
    if (!in_array($block, schedule_block_minutes(), true)) {
        $block = 15;
    }
    ?>
    <form method="post" class="hours-form" data-hours-form>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="<?php echo h($action); ?>">
        <input type="hidden" name="date" value="<?php echo h($date); ?>">
        <input type="hidden" name="month" value="<?php echo h($month); ?>">
        <div class="hours-grid">
            <label>
                Open
                <input type="time" name="start" value="<?php echo h($start); ?>" required>
            </label>
            <label>
                Close
                <input type="time" name="end" value="<?php echo h($end); ?>" required>
            </label>
            <label>
                Minutes per visit
                <select name="block">
                    <?php foreach (schedule_block_minutes() as $minutes): ?>
                        <option value="<?php echo h((string) $minutes); ?>"<?php echo $minutes === $block ? ' selected' : ''; ?>>
                            <?php echo h((string) $minutes); ?> minutes
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="muted hours-preview" data-hours-preview></p>
        <button class="btn btn-solid" type="submit"><?php echo h($submitLabel); ?></button>
    </form>
    <?php
}

admin_page_start('Availability', 'availability');
?>
    <header class="page-head">
        <div>
            <h1>Availability</h1>
        </div>
        <p class="head-note">Open a day, set the hours, and choose how long each visit is. Times are stored in the clinic schedule database.</p>
    </header>
    <?php if ($actionError !== ''): ?>
        <p class="login-error" role="alert"><?php echo h($actionError); ?></p>
    <?php elseif ($saveError): ?>
        <p class="login-error" role="alert">The change could not be saved.</p>
    <?php elseif ($hoursError === 'hours'): ?>
        <p class="login-error" role="alert">Choose a close time after the open time.</p>
    <?php elseif ($hoursError === 'blocks'): ?>
        <p class="login-error" role="alert">Those hours do not make any visit blocks. Widen the hours or pick a shorter visit.</p>
    <?php endif; ?>

    <div class="availability-layout">
        <section class="panel" aria-label="Calendar">
            <div class="cal-nav">
                <?php if ($previousMonth->format('Y-m') >= $firstAllowed->format('Y-m')): ?>
                    <a class="btn btn-line" href="availability.php?month=<?php echo h($previousMonth->format('Y-m')); ?>&amp;date=<?php echo h($selected); ?>">Previous</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <h2><?php echo h($month->format('F Y')); ?></h2>
                <?php if ($nextMonth->format('Y-m') <= $lastAllowed->format('Y-m')): ?>
                    <a class="btn btn-line" href="availability.php?month=<?php echo h($nextMonth->format('Y-m')); ?>&amp;date=<?php echo h($selected); ?>">Next</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
            </div>
            <div class="cal-grid" role="grid" aria-label="<?php echo h($month->format('F Y')); ?>">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                    <div class="cal-head"><?php echo h($weekday); ?></div>
                <?php endforeach; ?>
                <?php
                $pad = (int) $month->format('w');
                for ($blank = 0; $blank < $pad; $blank++):
                ?>
                    <div></div>
                <?php endfor; ?>
                <?php
                $daysInMonth = (int) $month->format('t');
                for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++):
                    $day = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $dayNumber);
                    $value = $day->format('Y-m-d');
                    $classes = 'cal-day';
                    if ($value === $today->format('Y-m-d')) {
                        $classes .= ' is-today';
                    }
                    if (in_array($value, $openValues, true)) {
                        $classes .= ' is-open';
                    }
                    if ($value === $selected) {
                        $classes .= ' is-selected';
                    }
                    if ($value < $today->format('Y-m-d')):
                ?>
                    <span class="<?php echo h($classes); ?> is-past"><?php echo h((string) $dayNumber); ?></span>
                <?php else: ?>
                    <a class="<?php echo h($classes); ?>" href="availability.php?month=<?php echo h($monthKey); ?>&amp;date=<?php echo h($value); ?>"<?php echo $value === $selected ? ' aria-current="date"' : ''; ?>><?php echo h((string) $dayNumber); ?></a>
                <?php endif; ?>
                <?php endfor; ?>
            </div>
            <p class="muted cal-legend"><span class="cal-swatch"></span> Open for booking</p>
        </section>

        <section class="panel">
            <h2><?php echo h($selectedLabel); ?></h2>
            <?php if (!admin_date_is_choosable($selected)): ?>
                <p class="muted">Choose a day on the calendar.</p>
            <?php elseif (!$selectedIsOpen && $daySlots === []): ?>
                <p class="muted">Patients cannot book this day yet. Set when you open and close, and how long each visit is.</p>
                <?php availability_hours_fields($selectedHours, 'open_date', $selected, $monthKey, 'Open this day'); ?>
            <?php else: ?>
                <p class="muted"><?php echo h(ucfirst(preview_hours_summary($selectedHours))); ?>. Block a time if you need to hold it. Closing a booked slot keeps the existing visit.</p>
                <?php availability_hours_fields($selectedHours, $selectedIsOpen ? 'update_hours' : 'open_date', $selected, $monthKey, $selectedIsOpen ? 'Save hours' : 'Open this day'); ?>
                <?php if ($selectedIsOpen || $daySlots !== []): ?>
                    <form method="post" class="cal-action">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="close_date">
                        <input type="hidden" name="date" value="<?php echo h($selected); ?>">
                        <input type="hidden" name="month" value="<?php echo h($monthKey); ?>">
                        <button class="btn btn-line" type="submit">Close this day</button>
                    </form>
                    <div class="slot-grid">
                        <?php foreach (admin_schedule_visit_slots($selected) as $slot): ?>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="date" value="<?php echo h($selected); ?>">
                                <input type="hidden" name="month" value="<?php echo h($monthKey); ?>">
                                <input type="hidden" name="time" value="<?php echo h($slot['value']); ?>">
                                <input type="hidden" name="action" value="<?php echo $slot['available'] ? 'close_slot' : 'open_slot'; ?>">
                                <button class="slot-toggle<?php echo $slot['available'] ? '' : ' is-blocked'; ?>" type="submit">
                                    <span><?php echo h($slot['label']); ?></span>
                                    <small><?php
                                        if ($slot['booked']) {
                                            echo $slot['available'] ? 'Open · booked' : 'Blocked · booked';
                                        } else {
                                            echo $slot['available'] ? 'Open' : 'Blocked';
                                        }
                                    ?></small>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <script src="../assets/js/availability.js"></script>
<?php
admin_page_end();
