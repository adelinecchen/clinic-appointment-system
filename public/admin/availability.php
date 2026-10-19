<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/admin_layout.php';

$saveError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $date = (string) ($_POST['date'] ?? '');
    $time = (string) ($_POST['time'] ?? '');
    $month = (string) ($_POST['month'] ?? '');
    $state = preview_availability();
    $slotValues = array_column(preview_visit_slots($date !== '' ? $date : '2000-01-01'), 'value');
    $key = $date . '|' . $time;
    $isOpen = in_array($date, $state['open_dates'], true);

    if (preview_date_is_choosable($date) && $action === 'open_date' && !$isOpen) {
        $state['open_dates'][] = $date;
        sort($state['open_dates']);
    } elseif ($action === 'close_date' && $isOpen) {
        $state['open_dates'] = array_values(array_filter(
            $state['open_dates'],
            static fn (string $open): bool => $open !== $date
        ));
    } elseif ($isOpen && in_array($time, $slotValues, true) && $action === 'close_slot') {
        if (!in_array($key, $state['closed_slots'], true)) {
            $state['closed_slots'][] = $key;
        }
        $state['opened_slots'] = array_values(array_filter(
            $state['opened_slots'],
            static fn (string $opened): bool => $opened !== $key
        ));
    } elseif ($isOpen && in_array($time, $slotValues, true) && $action === 'open_slot') {
        $state['closed_slots'] = array_values(array_filter(
            $state['closed_slots'],
            static fn (string $closed): bool => $closed !== $key
        ));
        if (in_array($time, preview_default_blocked_times(), true) && !in_array($key, $state['opened_slots'], true)) {
            $state['opened_slots'][] = $key;
        }
    }

    $back = 'availability.php?date=' . rawurlencode($date);
    if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
        $back .= '&month=' . rawurlencode($month);
    }

    if (!preview_save_availability($state)) {
        $saveError = true;
    } else {
        header('Location: ' . $back);
        exit;
    }
}

$today = preview_today();
$selected = (string) ($_GET['date'] ?? '');
if (!preview_date_is_choosable($selected)) {
    $openDays = preview_open_dates();
    $selected = $openDays[0]['value'] ?? $today->format('Y-m-d');
}

$monthValue = (string) ($_GET['month'] ?? substr($selected, 0, 7));
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
$monthKey = $month->format('Y-m');
$previousMonth = $month->modify('-1 month');
$nextMonth = $month->modify('+1 month');

admin_page_start('Availability', 'availability');
?>
    <header class="page-head">
        <div>
            <h1>Availability</h1>
        </div>
        <p class="head-note">Choose the days patients can book. On an open day, block any 15-minute time you need to hold. 10:30 AM starts blocked.</p>
    </header>
    <?php if ($saveError): ?>
        <p class="login-error" role="alert">The change could not be saved on this computer.</p>
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
                <p class="muted">Patients cannot book this day yet.</p>
                <form method="post" class="cal-action">
                    <input type="hidden" name="action" value="open_date">
                    <input type="hidden" name="date" value="<?php echo h($selected); ?>">
                    <input type="hidden" name="month" value="<?php echo h($monthKey); ?>">
                    <button class="btn btn-solid" type="submit">Open for booking</button>
                </form>
            <?php else: ?>
                <p class="muted">Patients can book this day. Each block is 15 minutes.</p>
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
<?php
admin_page_end();
