<?php

declare(strict_types=1);

require_once __DIR__ . '/patient_booking.php';
require_once __DIR__ . '/csrf.php';

function patient_status_process(): void
{
    csrf_ensure_session();

    $lookup = '';
    $appointments = [];
    $missing = false;
    $changeId = null;
    $message = '';
    $error = '';
    $action = (string) ($_POST['action'] ?? '');

    if (!csrf_verify_request()) {
        $_SESSION['status_view'] = [
            'lookup' => trim((string) ($_POST['lookup_phone'] ?? '')),
            'appointments' => [],
            'missing' => false,
            'change_id' => null,
            'message' => '',
            'error' => 'Your form expired. Please try again.',
        ];

        return;
    }

    if ($action === 'find') {
        $lookup = trim((string) ($_POST['lookup_phone'] ?? ''));
        $digits = patient_phone_digits($lookup);
        if ($digits === '') {
            $missing = false;
        } else {
            $all = patient_booking_lookup_by_phone($lookup);
            $appointments = patient_booking_active_upcoming($all);
            $missing = $appointments === [];
        }
    } elseif ($action === 'cancel') {
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $lookup = trim((string) ($_POST['lookup_phone'] ?? ''));
        $result = appointment_cancel($appointmentId);
        if ($result['ok']) {
            $message = patient_text('canceled');
            $appointment = appointment_find_by_id($appointmentId);
            $patient = $appointment !== null ? patient_find_by_id((int) $appointment['Patient_ID']) : null;
            if ($appointment !== null && $patient !== null) {
                $appointments = [patient_booking_view_model($appointment, $patient['Name'], $patient['PhoneNumber'])];
                if ($lookup === '') {
                    $lookup = $patient['PhoneNumber'];
                }
            }
        } else {
            $error = (string) ($result['error'] ?? 'That visit could not be cancelled.');
            if ($lookup !== '') {
                $appointments = patient_booking_active_upcoming(patient_booking_lookup_by_phone($lookup));
            }
        }
    } elseif ($action === 'reschedule') {
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $lookup = trim((string) ($_POST['lookup_phone'] ?? ''));
        $date = trim((string) ($_POST['appointment_date'] ?? ''));
        $time = (string) ($_POST['appointment_time'] ?? '');
        $result = appointment_reschedule($appointmentId, $date, $time);
        if ($result['ok']) {
            $appointment = $result['appointment'];
            $patient = patient_find_by_id((int) $appointment['Patient_ID']);
            $booking = patient_booking_view_model(
                $appointment,
                $patient['Name'] ?? '',
                $patient['PhoneNumber'] ?? $lookup
            );
            patient_booking_store_confirmation($booking);
            $_SESSION['status_view'] = [
                'lookup' => $lookup !== '' ? $lookup : (string) ($booking['patient_phone'] ?? ''),
                'appointments' => [$booking],
                'missing' => false,
                'change_id' => null,
                'message' => '',
                'error' => '',
            ];
            header('Location: confirmation.php');
            exit;
        }

        $error = (string) ($result['error'] ?? 'That visit could not be moved.');
        $changeId = $appointmentId;
        if ($lookup !== '') {
            $appointments = patient_booking_active_upcoming(patient_booking_lookup_by_phone($lookup));
        }
    }

    $_SESSION['status_view'] = [
        'lookup' => $lookup,
        'appointments' => $appointments,
        'missing' => $missing,
        'change_id' => $changeId,
        'message' => $message,
        'error' => $error,
    ];
}

function patient_status_view(): array
{
    csrf_ensure_session();

    $view = $_SESSION['status_view'] ?? null;
    if (is_array($view)) {
        $changeId = isset($_GET['change']) ? (int) ($_GET['appointment_id'] ?? ($view['change_id'] ?? 0)) : (int) ($view['change_id'] ?? 0);
        if (isset($_GET['change']) && $changeId > 0) {
            $view['change_id'] = $changeId;
        }

        return [
            'lookup' => (string) ($view['lookup'] ?? ''),
            'appointments' => is_array($view['appointments'] ?? null) ? $view['appointments'] : [],
            'missing' => !empty($view['missing']),
            'change_id' => (int) ($view['change_id'] ?? 0) ?: null,
            'message' => (string) ($view['message'] ?? ''),
            'error' => (string) ($view['error'] ?? ''),
        ];
    }

    $confirmation = patient_booking_load_confirmation();
    if ($confirmation !== null && ($confirmation['status'] ?? '') !== 'canceled') {
        return [
            'lookup' => (string) ($confirmation['patient_phone'] ?? ''),
            'appointments' => [$confirmation],
            'missing' => false,
            'change_id' => isset($_GET['change']) ? (int) ($confirmation['appointment_id'] ?? 0) : null,
            'message' => '',
            'error' => '',
        ];
    }

    return [
        'lookup' => '',
        'appointments' => [],
        'missing' => false,
        'change_id' => null,
        'message' => '',
        'error' => '',
    ];
}

function patient_status_render(): void
{
    $view = patient_status_view();
    $appointments = $view['appointments'];
    $lookup = (string) ($view['lookup'] ?? '');
    $changeId = $view['change_id'];
    $changing = null;
    foreach ($appointments as $appointment) {
        if ($changeId !== null && (int) ($appointment['appointment_id'] ?? 0) === $changeId) {
            $changing = $appointment;
            break;
        }
    }
    ?>
    <section class="status-block" id="status">
        <div class="wrap status-inner">
            <div class="status-copy">
                <h2><?php echo h(patient_text('status_title')); ?></h2>
                <p class="result-lede"><?php echo h(patient_text('status_lede')); ?></p>
            </div>
            <div class="status-tools">
                <form class="lookup" method="post" action="index.php#status">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="find">
                    <label>
                        <span class="sr-only"><?php echo h(patient_text('phone')); ?></span>
                        <input name="lookup_phone" type="tel" inputmode="tel" required placeholder="(757) 555-0142" value="<?php echo h($lookup); ?>">
                    </label>
                    <button class="book-submit" type="submit"><?php echo h(patient_text('find')); ?></button>
                </form>

                <?php if ($view['error'] !== ''): ?>
                    <p class="missing" role="alert"><?php echo h($view['error']); ?></p>
                <?php endif; ?>
                <?php if ($view['message'] !== ''): ?>
                    <p class="coming"><?php echo h($view['message']); ?></p>
                <?php endif; ?>

                <?php if (!empty($view['missing'])): ?>
                    <p class="missing"><?php echo h(patient_text('missing')); ?></p>
                <?php endif; ?>

                <?php foreach ($appointments as $found): ?>
                    <?php if (($found['status'] ?? '') === 'canceled'): ?>
                        <div class="visit-summary">
                            <h3><?php echo h(patient_text('canceled')); ?></h3>
                            <p><?php echo h((string) ($found['patient_name'] ?? '')); ?> · <?php echo h((string) ($found['date'] ?? '')); ?></p>
                            <p><?php echo h(patient_text('canceled_note')); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="visit-summary">
                            <p class="coming"><?php echo h(patient_text('coming')); ?></p>
                            <h3><?php echo h((string) $found['date']); ?></h3>
                            <p><?php echo h((string) $found['visit_label']); ?> · <?php echo h((string) $found['patient_name']); ?></p>
                            <ul class="people">
                                <?php foreach ($found['people'] ?? [] as $person): ?>
                                    <li><?php echo h((string) $person['time']); ?> · <?php echo h((string) $person['name']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="post" action="index.php#status">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="appointment_id" value="<?php echo h((string) ($found['appointment_id'] ?? '')); ?>">
                                <input type="hidden" name="lookup_phone" value="<?php echo h($lookup !== '' ? $lookup : (string) ($found['patient_phone'] ?? '')); ?>">
                                <button class="text-button" type="submit"><?php echo h(patient_text('cancel')); ?></button>
                            </form>
                            <p>
                                <a href="index.php?change=1&amp;appointment_id=<?php echo h((string) ($found['appointment_id'] ?? '')); ?>#status">
                                    <?php echo h(patient_text('reschedule')); ?>
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($changing !== null && ($changing['status'] ?? '') !== 'canceled'): ?>
                    <form class="picker" method="post" action="index.php#status">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reschedule">
                        <input type="hidden" name="appointment_id" value="<?php echo h((string) ($changing['appointment_id'] ?? '')); ?>">
                        <input type="hidden" name="lookup_phone" value="<?php echo h($lookup !== '' ? $lookup : (string) ($changing['patient_phone'] ?? '')); ?>">
                        <fieldset>
                            <legend><?php echo h(patient_text('reschedule')); ?></legend>
                            <p class="picker-label"><?php echo h(patient_text('choose_date')); ?></p>
                            <?php
                            $exceptId = (int) ($changing['appointment_id'] ?? 0);
                            patient_render_calendar((string) ($changing['date_value'] ?? ''), 'appointment_date', $exceptId);
                            ?>
                            <p class="picker-label"><?php echo h(patient_text('choose_time')); ?></p>
                            <?php
                            $pickerDays = array_column(patient_booking_open_dates($exceptId), 'value');
                            $pickerDate = (string) ($changing['date_value'] ?? '');
                            if (!in_array($pickerDate, $pickerDays, true)) {
                                $pickerDate = $pickerDays[0] ?? '';
                            }
                            patient_render_times($pickerDate, (string) ($changing['time_value'] ?? ''), $exceptId);
                            ?>
                        </fieldset>
                        <button class="book-submit" type="submit"><?php echo h(patient_text('save_change')); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}
