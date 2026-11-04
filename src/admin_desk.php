<?php

declare(strict_types=1);

/**
 * Admin desk shaping and multi-step appointment operations.
 */

require_once __DIR__ . '/admin_appointment_queries.php';
require_once __DIR__ . '/waitlist_queries.php';
require_once __DIR__ . '/patient_booking.php';
require_once __DIR__ . '/schedule_writes.php';
require_once __DIR__ . '/preview_data.php';

function admin_desk_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
}

/**
 * @param array<string, mixed> $row from admin_appointments_for_date
 * @return array<string, mixed> shape expected by admin templates
 */
function admin_desk_ui_row(array $row): array
{
    $day = patient_booking_day_record((string) $row['ScheduleDate']);

    return [
        'id' => (string) $row['Appointment_ID'],
        'date' => (string) $row['ScheduleDate'],
        'date_label' => $day['full'] ?? (string) $row['ScheduleDate'],
        'time' => (string) $row['ScheduleTimeLabel'],
        'time_value' => (string) $row['ScheduleTime'],
        'patient' => (string) $row['PatientName'],
        'visit' => (string) $row['AppointmentTypeLabel'],
        'phone' => (string) $row['PatientPhone'],
        'phone_digits' => patient_phone_digits((string) $row['PatientPhone']),
        'status' => (string) $row['DeskStatus'],
        'status_label' => (string) $row['DeskStatusLabel'],
        'note' => '',
        'held_for' => '',
        'patient_id' => (int) $row['Patient_ID'],
        'appointment_status' => (string) $row['AppointmentStatus'],
        'confirmation_status' => (string) $row['ConfirmationStatus'],
        'notification_status' => $row['NotificationStatus'],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function admin_desk_appointments_for_date(string $date): array
{
    return array_map('admin_desk_ui_row', admin_appointments_for_date($date));
}

/**
 * Upcoming appointments across open patient-bookable horizon.
 *
 * @return list<array<string, mixed>>
 */
function admin_upcoming_appointments(): array
{
    $start = admin_desk_today()->format('Y-m-d');
    $end = admin_desk_today()->modify('+6 months')->format('Y-m-d');

    $statement = db()->prepare(
        'SELECT a.Appointment_ID, a.ScheduleDate, a.ScheduleTime, a.Patient_ID,
                a.AppointmentStatus, a.ConfirmationStatus, a.AppointmentType,
                a.NotificationStatus, a.NotificationDate,
                p.Name AS PatientName, p.PhoneNumber AS PatientPhone
         FROM Appointment a
         INNER JOIN Patient p ON p.Patient_ID = a.Patient_ID
         WHERE a.ScheduleDate BETWEEN :start_date AND :end_date
         ORDER BY a.ScheduleDate ASC, a.ScheduleTime ASC, a.Appointment_ID ASC'
    );
    $statement->execute([
        'start_date' => $start,
        'end_date' => $end,
    ]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = admin_desk_ui_row(admin_appointment_row($row));
    }

    return $rows;
}

function admin_upcoming_matches(array $appointment, string $query): bool
{
    $query = trim($query);
    if ($query === '') {
        return true;
    }

    $digits = patient_phone_digits($query);
    if ($digits !== '' && str_contains((string) $appointment['phone_digits'], $digits)) {
        return true;
    }

    $haystack = strtolower(
        $appointment['patient'] . ' ' . $appointment['phone'] . ' ' . $appointment['visit']
    );

    return str_contains($haystack, strtolower($query));
}

/**
 * @param list<array<string, mixed>> $appointments
 * @return array{upcoming: int, unconfirmed: int, cancelled: int, all: int}
 */
function admin_upcoming_counts(array $appointments): array
{
    $counts = ['upcoming' => 0, 'unconfirmed' => 0, 'cancelled' => 0, 'all' => count($appointments)];
    foreach ($appointments as $appointment) {
        if ($appointment['status'] === 'cancelled') {
            $counts['cancelled']++;
        } else {
            $counts['upcoming']++;
            if (in_array($appointment['status'], ['no_reply', 'reminder_sent'], true)) {
                $counts['unconfirmed']++;
            }
        }
    }

    return $counts;
}

/**
 * Restore a cancelled appointment if the slot is free (or only held by this row).
 *
 * @return array{ok: true, appointment: array}|array{ok: false, error: string, code: string}
 */
function admin_restore_appointment(int $appointmentId): array
{
    $pdo = db();
    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        $lock = $pdo->prepare(
            'SELECT Appointment_ID, ScheduleDate, ScheduleTime, AppointmentStatus
             FROM Appointment WHERE Appointment_ID = :id LIMIT 1 FOR UPDATE'
        );
        $lock->execute(['id' => $appointmentId]);
        $row = $lock->fetch();
        if ($row === false) {
            if ($started) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'error' => 'That appointment was not found.', 'code' => 'not_found'];
        }
        if ((string) $row['AppointmentStatus'] !== 'Cancelled') {
            if ($started) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'error' => 'Only cancelled visits can be restored.', 'code' => 'validation'];
        }

        $occupied = $pdo->prepare(
            'SELECT Appointment_ID FROM Appointment
             WHERE ScheduleDate = :date AND ScheduleTime = :time
               AND AppointmentStatus <> :cancelled AND Appointment_ID <> :id
             LIMIT 1 FOR UPDATE'
        );
        $occupied->execute([
            'date' => $row['ScheduleDate'],
            'time' => schedule_time_value((string) $row['ScheduleTime']),
            'cancelled' => 'Cancelled',
            'id' => $appointmentId,
        ]);
        if ($occupied->fetch() !== false) {
            if ($started) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'error' => 'That time is already booked by someone else.', 'code' => 'slot_occupied'];
        }

        $update = $pdo->prepare(
            'UPDATE Appointment
             SET AppointmentStatus = :scheduled
             WHERE Appointment_ID = :id'
        );
        $update->execute([
            'scheduled' => clinic_default_appointment_status(),
            'id' => $appointmentId,
        ]);

        if ($started) {
            $pdo->commit();
        }

        $appointment = appointment_find_by_id($appointmentId);

        return ['ok' => true, 'appointment' => $appointment];
    } catch (PDOException) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'The appointment could not be restored.', 'code' => 'db'];
    }
}

/**
 * Intentional admin double-book: place a waitlist patient on the same slot.
 *
 * @return array{ok: true, appointment: array}|array{ok: false, error: string, code: string}
 */
function admin_double_book_from_waitlist(int $appointmentId, int $waitlistId): array
{
    $existing = appointment_find_by_id($appointmentId);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'That appointment was not found.', 'code' => 'not_found'];
    }
    if ($existing['AppointmentStatus'] === 'Cancelled' || $existing['AppointmentStatus'] === 'Completed') {
        return ['ok' => false, 'error' => 'Hold only works on an active unconfirmed visit.', 'code' => 'validation'];
    }
    if (in_array((string) $existing['ConfirmationStatus'], ['Confirmed', 'Declined'], true)) {
        return ['ok' => false, 'error' => 'Hold only works on an active unconfirmed visit.', 'code' => 'validation'];
    }

    $wait = waitlist_find_by_id($waitlistId);
    if ($wait === null || !in_array($wait['Status'], ['Waiting', 'Held'], true)) {
        return ['ok' => false, 'error' => 'Choose someone from the waitlist.', 'code' => 'validation'];
    }

    $pdo = db();
    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        $insert = $pdo->prepare(
            'INSERT INTO Appointment (
                ScheduleDate, ScheduleTime, Patient_ID,
                AppointmentStatus, ConfirmationStatus, AppointmentType,
                NotificationStatus, NotificationDate
             ) VALUES (
                :date, :time, :patient_id,
                :appointment_status, :confirmation_status, :appointment_type,
                NULL, NULL
             )'
        );
        $insert->execute([
            'date' => $existing['ScheduleDate'],
            'time' => $existing['ScheduleTime'],
            'patient_id' => $wait['Patient_ID'],
            'appointment_status' => clinic_default_appointment_status(),
            'confirmation_status' => clinic_default_confirmation_status(),
            'appointment_type' => $wait['AppointmentType'],
        ]);
        $newId = (int) $pdo->lastInsertId();

        $waitUpdate = $pdo->prepare(
            'UPDATE Waitlist SET Status = :status WHERE Waitlist_ID = :id'
        );
        $waitUpdate->execute(['status' => 'Held', 'id' => $waitlistId]);

        if ($started) {
            $pdo->commit();
        }

        $appointment = appointment_find_by_id($newId);

        return ['ok' => true, 'appointment' => $appointment];
    } catch (PDOException) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'The hold could not be saved.', 'code' => 'db'];
    }
}

/**
 * Fill a cancelled appointment's slot from the waitlist (ordinary single booking).
 *
 * @return array{ok: true, appointment: array}|array{ok: false, error: string, code: string}
 */
function admin_fill_cancelled_from_waitlist(int $cancelledAppointmentId, int $waitlistId): array
{
    $cancelled = appointment_find_by_id($cancelledAppointmentId);
    if ($cancelled === null || $cancelled['AppointmentStatus'] !== 'Cancelled') {
        return ['ok' => false, 'error' => 'Choose a cancelled visit to fill.', 'code' => 'validation'];
    }

    $wait = waitlist_find_by_id($waitlistId);
    if ($wait === null || !in_array($wait['Status'], ['Waiting', 'Held'], true)) {
        return ['ok' => false, 'error' => 'Choose someone from the waitlist.', 'code' => 'validation'];
    }

    $pdo = db();
    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        $created = appointment_create(
            (int) $wait['Patient_ID'],
            (string) $cancelled['ScheduleDate'],
            (string) $cancelled['ScheduleTime'],
            (string) $wait['AppointmentType']
        );
        if (!$created['ok']) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $created;
        }

        $placed = waitlist_set_status($waitlistId, 'Placed');
        if (!$placed['ok']) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $placed;
        }

        if ($started) {
            $pdo->commit();
        }

        return $created;
    } catch (PDOException) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'The waitlist fill could not be saved.', 'code' => 'db'];
    }
}

/**
 * Admin reminder template text (not sent externally).
 */
function admin_reminder_preview_text(array $uiRow): string
{
    return preview_fill_message(preview_message_templates()['follow_up'], [
        'name' => $uiRow['patient'] ?? '',
        'time' => $uiRow['time'] ?? '',
        'date' => $uiRow['date_label'] ?? $uiRow['date'] ?? '',
        'phone' => $uiRow['phone'] ?? '',
    ]);
}

/**
 * Open clinic dates derived from Schedule (any Open slot).
 *
 * @return list<array{value: string, label: string, full: string}>
 */
function admin_schedule_open_dates(): array
{
    $start = admin_desk_today()->format('Y-m-d');
    $end = admin_desk_today()->modify('+6 months')->format('Y-m-d');
    $statement = db()->prepare(
        'SELECT DISTINCT ScheduleDate
         FROM Schedule
         WHERE ScheduleDate BETWEEN :start_date AND :end_date
           AND Status = :open_status
         ORDER BY ScheduleDate ASC'
    );
    $statement->execute([
        'start_date' => $start,
        'end_date' => $end,
        'open_status' => clinic_default_schedule_status(),
    ]);

    $days = [];
    foreach ($statement->fetchAll() as $row) {
        $record = patient_booking_day_record((string) $row['ScheduleDate']);
        if ($record !== null) {
            $days[] = $record;
        }
    }

    return $days;
}

/**
 * Infer hours UI values from Open schedule rows for a date.
 *
 * @return array{start: string, end: string, block: int}|null
 */
function admin_schedule_day_hours(string $date): ?array
{
    $slots = schedule_slots_for_date($date);
    if ($slots === []) {
        return null;
    }

    $times = array_column($slots, 'time');
    sort($times);
    $start = $times[0];
    $gaps = [];
    for ($i = 1; $i < count($times); $i++) {
        $a = DateTimeImmutable::createFromFormat('!H:i', $times[$i - 1]);
        $b = DateTimeImmutable::createFromFormat('!H:i', $times[$i]);
        if ($a !== false && $b !== false) {
            $gaps[] = (int) (($b->getTimestamp() - $a->getTimestamp()) / 60);
        }
    }
    $block = 15;
    if ($gaps !== []) {
        $counts = array_count_values($gaps);
        arsort($counts);
        $candidate = (int) array_key_first($counts);
        if (in_array($candidate, schedule_block_minutes(), true)) {
            $block = $candidate;
        }
    }
    $last = DateTimeImmutable::createFromFormat('!H:i', $times[count($times) - 1]);
    $end = $last === false ? $start : $last->modify('+' . $block . ' minutes')->format('H:i');

    return ['start' => $start, 'end' => $end, 'block' => $block];
}

/**
 * Visit slots for admin availability toggle (Open vs Closed).
 *
 * @return list<array{value: string, label: string, available: bool, booked: bool}>
 */
function admin_schedule_visit_slots(string $date): array
{
    $slots = schedule_slots_for_date($date);
    $bookedTimes = [];
    foreach (admin_appointments_for_date($date) as $appt) {
        if ($appt['AppointmentStatus'] !== 'Cancelled') {
            $bookedTimes[$appt['ScheduleTime']] = true;
        }
    }

    $out = [];
    foreach ($slots as $slot) {
        $out[] = [
            'value' => $slot['time'],
            'label' => $slot['label'],
            'available' => $slot['status'] === 'Open',
            'booked' => isset($bookedTimes[$slot['time']]),
        ];
    }

    return $out;
}

function admin_date_is_choosable(string $date): bool
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/New_York'));
    if ($day === false || $day->format('Y-m-d') !== $date) {
        return false;
    }
    $today = admin_desk_today();
    $limit = $today->modify('+6 months');

    return $day >= $today && $day <= $limit;
}
