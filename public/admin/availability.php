<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

$saveError = false;
$hoursError = '';
$draftHours = null;
$postedDate = '';
$postedMonth = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $date = (string) ($_POST['date'] ?? '');
    $time = (string) ($_POST['time'] ?? '');
    $month = (string) ($_POST['month'] ?? '');
    $postedDate = $date;
    $postedMonth = $month;
    $state = preview_availability();
    $slotValues = array_column(preview_visit_slots($date !== '' ? $date : '2000-01-01'), 'value');
    $key = $date . '|' . $time;
    $isOpen = isset($state['days'][$date]);

    if (preview_date_is_choosable($date) && in_array($action, ['open_date', 'update_hours'], true)) {
        $parsed = preview_hours_from_input($_POST);
        if ($parsed['hours'] === null) {
            $hoursError = (string) $parsed['error'];
            $draftHours = [
                'start' => (string) ($_POST['start'] ?? ''),
                'end' => (string) ($_POST['end'] ?? ''),
                'block' => (int) ($_POST['block'] ?? 15),
            ];
        } elseif ($action === 'open_date' || $isOpen) {
            $state['days'][$date] = $parsed['hours'];
        }
    } elseif ($action === 'close_date' && $isOpen) {
        unset($state['days'][$date]);
        $state['closed_slots'] = array_values(array_filter(
            $state['closed_slots'],
            static fn (string $closed): bool => !str_starts_with($closed, $date . '|')
        ));
    } elseif ($isOpen && in_array($time, $slotValues, true) && $action === 'close_slot') {
        if (!in_array($key, $state['closed_slots'], true)) {
            $state['closed_slots'][] = $key;
        }
    } elseif ($isOpen && in_array($time, $slotValues, true) && $action === 'open_slot') {
        $state['closed_slots'] = array_values(array_filter(
            $state['closed_slots'],
            static fn (string $closed): bool => $closed !== $key
        ));
    }

    $back = 'availability.php?date=' . rawurlencode($date);
    if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
        $back .= '&month=' . rawurlencode($month);
    }

    if ($hoursError !== '') {
        $saveError = false;
    } elseif (!preview_save_availability($state)) {
        $saveError = true;
    } else {
        header('Location: ' . $back);
        exit;
    }
}

$today = preview_today();
$selected = $postedDate !== '' ? $postedDate : (string) ($_GET['date'] ?? '');
if (!preview_date_is_choosable($selected)) {
    $openDays = preview_open_dates();
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

$openValues = array_column(preview_open_dates(), 'value');
$selectedIsOpen = in_array($selected, $openValues, true);
$selectedRecord = preview_day_record($selected);
$selectedLabel = $selectedRecord['full'] ?? $selected;
$selectedHours = $draftHours ?? preview_hours_suggestion($selectedIsOpen ? $selected : null);
$monthKey = $month->format('Y-m');
$previousMonth = $month->modify('-1 month');
$nextMonth = $month->modify('+1 month');

function availability_hours_fields(array $hours, string $action, string $date, string $month, string $submitLabel): void
{
    $start = preview_normalize_time((string) ($hours['start'] ?? '')) ?? '09:00';
    $end = preview_normalize_time((string) ($hours['end'] ?? '')) ?? '17:00';
    $block = (int) ($hours['block'] ?? 15);
    if (!in_array($block, preview_block_minutes(), true)) {
        $block = 15;
    }
    ?>
    <form method="post" class="hours-form" data-hours-form>
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
                    <?php foreach (preview_block_minutes() as $minutes): ?>
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

admin_page_start('Clinic hours', 'settings');
?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Settings</p>
            <h1>Clinic hours</h1>
        </div>
        <p class="head-note">Open a day, set the hours, and choose how long each visit is. The times patients can book are made from that.</p>
    </header>
    <?php if ($saveError): ?>
        <p class="login-error" role="alert">The change could not be saved on this computer.</p>
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
            <?php if (!preview_date_is_choosable($selected)): ?>
                <p class="muted">Choose a day on the calendar.</p>
            <?php elseif (!$selectedIsOpen): ?>
                <p class="muted">Patients cannot book this day yet. Set when you open and close, and how long each visit is.</p>
                <?php availability_hours_fields($selectedHours, 'open_date', $selected, $monthKey, 'Open this day'); ?>
            <?php else: ?>
                <p class="muted"><?php echo h(ucfirst(preview_hours_summary(preview_day_hours($selected) ?? $selectedHours))); ?>. Block a time if you need to hold it.</p>
                <?php availability_hours_fields($selectedHours, 'update_hours', $selected, $monthKey, 'Save hours'); ?>
                <form method="post" class="cal-action">
                    <input type="hidden" name="action" value="close_date">
                    <input type="hidden" name="date" value="<?php echo h($selected); ?>">
                    <input type="hidden" name="month" value="<?php echo h($monthKey); ?>">
                    <button class="btn btn-line" type="submit">Close this day</button>
                </form>
                <div class="slot-grid">
                    <?php foreach (preview_visit_slots($selected) as $slot): ?>
                        <form method="post">
                            <input type="hidden" name="date" value="<?php echo h($selected); ?>">
                            <input type="hidden" name="month" value="<?php echo h($monthKey); ?>">
                            <input type="hidden" name="time" value="<?php echo h($slot['value']); ?>">
                            <input type="hidden" name="action" value="<?php echo $slot['available'] ? 'close_slot' : 'open_slot'; ?>">
                            <button class="slot-toggle<?php echo $slot['available'] ? '' : ' is-blocked'; ?>" type="submit">
                                <span><?php echo h($slot['label']); ?></span>
                                <small><?php echo $slot['available'] ? 'Open' : 'Blocked'; ?></small>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <script src="../assets/js/availability.js"></script>
<?php
admin_page_end();
