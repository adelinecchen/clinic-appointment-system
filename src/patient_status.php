<?php

declare(strict_types=1);

function patient_status_sample(array $day): array
{
    return [
        'visit_type' => 'wellness',
        'visit_label' => preview_visit_label('wellness'),
        'date' => $day['full'],
        'date_value' => $day['value'],
        'time' => '9:00 AM',
        'time_value' => '09:00',
        'patient_name' => 'Sarah Smith',
        'patient_phone' => '(757) 555-1234',
        'people' => [
            ['name' => 'Sarah Smith', 'time' => '9:00 AM', 'time_value' => '09:00'],
            ['name' => 'Mark Smith', 'time' => '9:15 AM', 'time_value' => '09:15'],
        ],
        'status' => 'booked',
    ];
}

function patient_status_process(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $booking = $_SESSION['booking_preview'] ?? null;
    if (!is_array($booking)) {
        $booking = null;
    }

    $lookup = '';
    $found = null;
    $missing = false;
    $showPicker = false;
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'find') {
        $lookup = trim((string) ($_POST['lookup_phone'] ?? ''));
        $digits = preg_replace('/\D+/', '', $lookup) ?? '';
        $bookedDigits = preg_replace('/\D+/', '', (string) ($booking['patient_phone'] ?? '')) ?? '';
        if ($booking !== null && $digits !== '' && $digits === $bookedDigits) {
            $found = $booking;
        } elseif ($digits === '7575551234') {
            $openDays = preview_open_dates();
            $day = $openDays[0] ?? ['full' => 'October 25, 2026', 'value' => '2026-10-25'];
            $found = patient_status_sample($day);
            $_SESSION['booking_preview'] = $found;
        } else {
            $missing = $digits !== '';
        }
    } elseif ($action === 'cancel' && $booking !== null) {
        $booking['status'] = 'canceled';
        $_SESSION['booking_preview'] = $booking;
        $found = $booking;
        $lookup = (string) $booking['patient_phone'];
    } elseif ($action === 'reschedule' && $booking !== null) {
        $checked = preview_validate_booking([
            'visit_type' => $booking['visit_type'] ?? 'wellness',
            'appointment_date' => $_POST['appointment_date'] ?? '',
            'appointment_time' => $_POST['appointment_time'] ?? '',
            'patient_name' => $booking['patient_name'] ?? '',
            'patient_phone' => $booking['patient_phone'] ?? '',
        ]);
        if ($checked['errors'] === []) {
            $_SESSION['booking_preview'] = $checked['booking'];
            $_SESSION['status_view'] = [
                'lookup' => (string) $checked['booking']['patient_phone'],
                'found' => $checked['booking'],
                'missing' => false,
                'change' => false,
            ];
            header('Location: confirmation.php');
            exit;
        }
        $found = $booking;
        $lookup = (string) $booking['patient_phone'];
        $showPicker = true;
    }

    $_SESSION['status_view'] = [
        'lookup' => $lookup,
        'found' => $found,
        'missing' => $missing,
        'change' => $showPicker,
    ];
}

function patient_status_view(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $view = $_SESSION['status_view'] ?? null;
    if (is_array($view)) {
        if (isset($_GET['change'])) {
            $view['change'] = true;
        }
        if (is_array($view['found'] ?? null)) {
            $view['found']['visit_label'] = preview_visit_label((string) ($view['found']['visit_type'] ?? 'wellness'));
        }

        return $view;
    }

    $booking = $_SESSION['booking_preview'] ?? null;
    if (is_array($booking)) {
        $booking['visit_label'] = preview_visit_label((string) ($booking['visit_type'] ?? 'wellness'));

        return [
            'lookup' => (string) ($booking['patient_phone'] ?? ''),
            'found' => $booking,
            'missing' => false,
            'change' => isset($_GET['change']),
        ];
    }

    return [
        'lookup' => '',
        'found' => null,
        'missing' => false,
        'change' => false,
    ];
}

function patient_status_render(): void
{
    $view = patient_status_view();
    $found = is_array($view['found'] ?? null) ? $view['found'] : null;
    $lookup = (string) ($view['lookup'] ?? '');
    ?>
    <section class="status-block" id="status">
        <div class="wrap status-inner">
            <div class="status-copy">
                <h2><?php echo h(patient_text('status_title')); ?></h2>
                <p class="result-lede"><?php echo h(patient_text('status_lede')); ?></p>
            </div>
            <div class="status-tools">
                <form class="lookup" method="post" action="index.php#status">
                    <input type="hidden" name="action" value="find">
                    <label>
                        <span class="sr-only"><?php echo h(patient_text('phone')); ?></span>
                        <input name="lookup_phone" type="tel" inputmode="tel" required placeholder="(757) 555-0142" value="<?php echo h($lookup); ?>">
                    </label>
                    <button class="book-submit" type="submit"><?php echo h(patient_text('find')); ?></button>
                </form>

                <?php if (!empty($view['missing'])): ?>
                    <p class="missing"><?php echo h(patient_text('missing')); ?></p>
                <?php endif; ?>

                <?php if ($found !== null && ($found['status'] ?? '') === 'canceled'): ?>
                    <div class="visit-summary">
                        <h3><?php echo h(patient_text('canceled')); ?></h3>
                        <p><?php echo h(patient_text('canceled_note')); ?></p>
                    </div>
                <?php elseif ($found !== null): ?>
                    <div class="visit-summary">
                        <p class="coming"><?php echo h(patient_text('coming')); ?></p>
                        <h3><?php echo h((string) $found['date']); ?></h3>
                        <p><?php echo h((string) $found['visit_label']); ?></p>
                        <ul class="people">
                            <?php foreach ($found['people'] ?? [] as $person): ?>
                                <li><?php echo h((string) $person['time']); ?> · <?php echo h((string) $person['name']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <form method="post" action="index.php#status">
                            <input type="hidden" name="action" value="cancel">
                            <button class="text-button" type="submit"><?php echo h(patient_text('cancel')); ?></button>
                        </form>
                        <p><a href="index.php?change=1#status"><?php echo h(patient_text('reschedule')); ?></a></p>
                    </div>
                    <?php if (!empty($view['change'])): ?>
                        <form class="picker" method="post" action="index.php#status">
                            <input type="hidden" name="action" value="reschedule">
                            <fieldset>
                                <legend><?php echo h(patient_text('reschedule')); ?></legend>
                                <p class="picker-label"><?php echo h(patient_text('choose_date')); ?></p>
                                <?php patient_render_calendar((string) ($found['date_value'] ?? ''), 'appointment_date'); ?>
                                <p class="picker-label"><?php echo h(patient_text('choose_time')); ?></p>
                                <?php
                                $pickerDays = array_column(preview_open_dates(), 'value');
                                $pickerDate = (string) ($found['date_value'] ?? '');
                                if (!in_array($pickerDate, $pickerDays, true)) {
                                    $pickerDate = $pickerDays[0] ?? '';
                                }
                                patient_render_times($pickerDate, (string) ($found['time_value'] ?? ''));
                                ?>
                            </fieldset>
                            <button class="book-submit" type="submit"><?php echo h(patient_text('save_change')); ?></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}
