<?php

declare(strict_types=1);

/**
 * Patient-facing booking helpers backed by clinic_db Schedule / Patient / Appointment.
 */

require_once __DIR__ . '/schedule_queries.php';
require_once __DIR__ . '/patient_queries.php';
require_once __DIR__ . '/appointment_queries.php';
require_once __DIR__ . '/clinic_maps.php';

function patient_booking_timezone(): DateTimeZone
{
    return new DateTimeZone('America/New_York');
}

function patient_booking_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', patient_booking_timezone());
}

function patient_booking_day_record(string $value): ?array
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, patient_booking_timezone());
    if ($day === false || $day->format('Y-m-d') !== $value) {
        return null;
    }

    return [
        'value' => $value,
        'label' => $day->format('M j'),
        'full' => $day->format('F j, Y'),
    ];
}

/**
 * Upcoming dates that still have at least one patient-bookable slot.
 *
 * @return list<array{value: string, label: string, full: string}>
 */
function patient_booking_open_dates(?int $exceptAppointmentId = null): array
{
    $start = patient_booking_today()->format('Y-m-d');
    $end = patient_booking_today()->modify('+6 months')->format('Y-m-d');

    if ($exceptAppointmentId !== null && $exceptAppointmentId > 0) {
        $slots = schedule_open_slots_for_patient_range_except($start, $end, $exceptAppointmentId);
        $dateValues = [];
        foreach ($slots as $slot) {
            $dateValues[$slot['date']] = true;
        }
        $rawDates = array_keys($dateValues);
        sort($rawDates);
    } else {
        $rawDates = schedule_open_dates_for_patient($start, $end);
    }

    $days = [];
    foreach ($rawDates as $value) {
        $record = patient_booking_day_record($value);
        if ($record !== null) {
            $days[] = $record;
        }
    }

    return $days;
}

/**
 * @return list<array{value: string, label: string, available: bool}>
 */
function patient_booking_open_slots(string $date, ?int $exceptAppointmentId = null): array
{
    $slots = [];
    foreach (schedule_open_slots_for_patient($date, $exceptAppointmentId) as $slot) {
        $slots[] = [
            'value' => $slot['time'],
            'label' => $slot['label'],
            'available' => true,
        ];
    }

    return $slots;
}

/**
 * Slot map entries for booking.js (open times only, all available).
 *
 * @return list<array{value: string, label: string, available: bool}>
 */
function patient_booking_visit_slots(string $date, ?int $exceptAppointmentId = null): array
{
    return patient_booking_open_slots($date, $exceptAppointmentId);
}

/**
 * @return list<array{value: string, label: string, pad: int, days: list<array>}>
 */
function patient_booking_months(string $selected, ?int $exceptAppointmentId = null): array
{
    $openValues = array_column(patient_booking_open_dates($exceptAppointmentId), 'value');
    if ($openValues === []) {
        return [];
    }

    if ($selected === '' || !in_array($selected, $openValues, true)) {
        $selected = $openValues[0];
    }

    $zone = patient_booking_timezone();
    $first = new DateTimeImmutable(substr(min($openValues), 0, 7) . '-01', $zone);
    $last = new DateTimeImmutable(substr(max($openValues), 0, 7) . '-01', $zone);
    $today = patient_booking_today()->format('Y-m-d');
    $months = [];
    $cursor = $first;

    while ($cursor <= $last) {
        $days = [];
        $daysInMonth = (int) $cursor->format('t');
        for ($number = 1; $number <= $daysInMonth; $number++) {
            $day = $cursor->setDate((int) $cursor->format('Y'), (int) $cursor->format('n'), $number);
            $value = $day->format('Y-m-d');
            $days[] = [
                'number' => $number,
                'value' => $value,
                'open' => in_array($value, $openValues, true),
                'selected' => $value === $selected,
                'past' => $value < $today,
            ];
        }
        $months[] = [
            'value' => $cursor->format('Y-m'),
            'label' => $cursor->format('F Y'),
            'pad' => (int) $cursor->format('w'),
            'days' => $days,
        ];
        $cursor = $cursor->modify('first day of next month');
    }

    return $months;
}

function patient_booking_defaults(): array
{
    $dates = patient_booking_open_dates();
    $date = $dates[0]['value'] ?? '';
    $slots = $date !== '' ? patient_booking_open_slots($date) : [];

    return [
        'visit_type' => 'wellness',
        'appointment_date' => $date,
        'appointment_time' => $slots[0]['value'] ?? '',
        'patient_name' => '',
        'patient_phone' => '',
        'sms_consent' => false,
    ];
}

function patient_booking_format_phone(string $digits): string
{
    return patient_format_phone($digits);
}

/**
 * Validate booking input and create Patient + Appointment rows.
 *
 * @return array{errors: array<string, string>, values: array, booking: ?array, error: string}
 */
function patient_booking_submit(array $input): array
{
    $values = patient_booking_defaults();
    $errors = [];

    $visit = (string) ($input['visit_type'] ?? '');
    if ($visit === 'wellness' || $visit === 'follow_up') {
        $values['visit_type'] = $visit;
    } else {
        $errors['visit_type'] = 'visit';
    }

    $date = trim((string) ($input['appointment_date'] ?? ''));
    $matchedDay = null;
    foreach (patient_booking_open_dates() as $day) {
        if ($day['value'] === $date) {
            $matchedDay = $day;
            break;
        }
    }
    if ($matchedDay === null) {
        $errors['appointment_date'] = 'date';
    } else {
        $values['appointment_date'] = $date;
    }

    $time = schedule_time_value((string) ($input['appointment_time'] ?? ''));
    $matchedSlot = null;
    if ($matchedDay !== null) {
        foreach (patient_booking_open_slots($date) as $slot) {
            if ($slot['value'] === $time) {
                $matchedSlot = $slot;
                break;
            }
        }
    }
    if ($matchedSlot === null) {
        $errors['appointment_time'] = 'time';
    } else {
        $values['appointment_time'] = $time;
    }

    $name = trim((string) ($input['patient_name'] ?? ''));
    $values['patient_name'] = $name;
    $nameLen = patient_text_length($name);
    if ($nameLen < 2 || $nameLen > 50) {
        $errors['patient_name'] = 'name';
    }

    $phone = trim((string) ($input['patient_phone'] ?? ''));
    $values['patient_phone'] = $phone;
    $values['sms_consent'] = !empty($input['sms_consent']) && (string) $input['sms_consent'] !== '0';
    $digits = patient_phone_digits($phone);
    if (strlen($digits) !== 10) {
        $errors['patient_phone'] = 'phone';
    }

    if ($errors !== []) {
        return [
            'errors' => $errors,
            'values' => $values,
            'booking' => null,
            'error' => '',
        ];
    }

    $formattedPhone = patient_booking_format_phone($digits);
    $pdo = db();
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $createdPatient = patient_create($name, $formattedPhone);
        if (!$createdPatient['ok']) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'errors' => ['patient_name' => 'name'],
                'values' => $values,
                'booking' => null,
                'error' => (string) ($createdPatient['error'] ?? 'We could not save your visit. Please try again.'),
            ];
        }

        $patientId = (int) $createdPatient['patient']['Patient_ID'];
        $createdAppointment = appointment_create($patientId, $date, $time, $visit);
        if (!$createdAppointment['ok']) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = (string) ($createdAppointment['code'] ?? 'db');
            $fieldErrors = [];
            if ($code === 'slot_occupied' || $code === 'slot_closed' || $code === 'slot_missing') {
                $fieldErrors['appointment_time'] = 'time';
            }

            return [
                'errors' => $fieldErrors !== [] ? $fieldErrors : ['appointment_time' => 'time'],
                'values' => $values,
                'booking' => null,
                'error' => (string) ($createdAppointment['error'] ?? 'We could not save your visit. Please try again.'),
            ];
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        $appointment = $createdAppointment['appointment'];
        $booking = patient_booking_view_model(
            $appointment,
            $createdPatient['patient']['Name'],
            $createdPatient['patient']['PhoneNumber']
        );

        $wantsSms = !empty($input['sms_consent']) && (string) $input['sms_consent'] !== '0';
        $booking['sms_consent'] = $wantsSms;
        $booking['sms'] = null;
        if ($wantsSms) {
            require_once __DIR__ . '/sms_consent.php';
            require_once __DIR__ . '/sms_notifications.php';
            sms_record_consent($formattedPhone, 'booking');
            $booking['sms'] = sms_notify_booking((int) $appointment['Appointment_ID']);
        }

        return [
            'errors' => [],
            'values' => $values,
            'booking' => $booking,
            'error' => '',
        ];
    } catch (PDOException) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'errors' => ['appointment_time' => 'time'],
            'values' => $values,
            'booking' => null,
            'error' => 'We could not save your visit. Please try again.',
        ];
    }
}

/**
 * Shape used by confirmation + status UI (preserves existing field names).
 *
 * @param array<string, mixed> $appointment
 * @return array<string, mixed>
 */
function patient_booking_view_model(array $appointment, string $patientName, string $patientPhone): array
{
    $day = patient_booking_day_record((string) $appointment['ScheduleDate']);
    $visitType = (string) ($appointment['VisitType'] ?? clinic_visit_type_from_appointment((string) $appointment['AppointmentType']) ?? 'wellness');
    $status = (string) ($appointment['AppointmentStatus'] ?? 'Scheduled');
    $uiStatus = $status === 'Cancelled' ? 'canceled' : 'booked';

    return [
        'appointment_id' => (int) $appointment['Appointment_ID'],
        'patient_id' => (int) $appointment['Patient_ID'],
        'visit_type' => $visitType,
        'visit_label' => (string) ($appointment['AppointmentTypeLabel'] ?? clinic_appointment_type_label((string) $appointment['AppointmentType'])),
        'date' => $day['full'] ?? (string) $appointment['ScheduleDate'],
        'date_value' => (string) $appointment['ScheduleDate'],
        'time' => (string) $appointment['ScheduleTimeLabel'],
        'time_value' => (string) $appointment['ScheduleTime'],
        'patient_name' => $patientName,
        'patient_phone' => $patientPhone,
        'appointment_status' => $status,
        'people' => [
            [
                'name' => $patientName,
                'time' => (string) $appointment['ScheduleTimeLabel'],
                'time_value' => (string) $appointment['ScheduleTime'],
            ],
        ],
        'status' => $uiStatus,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function patient_booking_lookup_by_phone(string $phone): array
{
    $rows = appointments_for_phone($phone);
    $views = [];
    foreach ($rows as $row) {
        $views[] = patient_booking_view_model(
            $row,
            (string) $row['PatientName'],
            (string) $row['PatientPhone']
        );
    }

    return $views;
}

/**
 * Active (not cancelled) upcoming visits for status display.
 *
 * @param list<array<string, mixed>> $views
 * @return list<array<string, mixed>>
 */
function patient_booking_active_upcoming(array $views): array
{
    $today = patient_booking_today()->format('Y-m-d');
    $active = [];
    foreach ($views as $view) {
        if (($view['status'] ?? '') === 'canceled') {
            continue;
        }
        if (($view['appointment_status'] ?? '') === 'Cancelled') {
            continue;
        }
        if (($view['appointment_status'] ?? '') === 'Completed') {
            continue;
        }
        if ((string) ($view['date_value'] ?? '') < $today) {
            continue;
        }
        $active[] = $view;
    }

    return $active;
}

function patient_booking_store_confirmation(array $booking): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['booking_confirmation_id'] = (int) ($booking['appointment_id'] ?? 0);
    unset($_SESSION['booking_preview']);
}

function patient_booking_load_confirmation(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $id = (int) ($_SESSION['booking_confirmation_id'] ?? 0);
    if ($id < 1) {
        return null;
    }

    $appointment = appointment_find_by_id($id);
    if ($appointment === null || $appointment['AppointmentStatus'] === 'Cancelled') {
        return null;
    }

    $patient = patient_find_by_id((int) $appointment['Patient_ID']);
    if ($patient === null) {
        return null;
    }

    return patient_booking_view_model($appointment, $patient['Name'], $patient['PhoneNumber']);
}

/**
 * Range helper that can ignore one appointment id (for reschedule calendars).
 *
 * @return list<array{date: string, time: string, label: string, status: string, available: bool}>
 */
function schedule_open_slots_for_patient_range_except(string $startDate, string $endDate, int $exceptAppointmentId): array
{
    $exceptId = $exceptAppointmentId > 0 ? $exceptAppointmentId : 0;

    $sql = 'SELECT s.ScheduleDate, s.ScheduleTime, s.Status
            FROM Schedule s
            WHERE s.ScheduleDate BETWEEN :start_date AND :end_date
              AND s.Status = :open_status
              AND NOT EXISTS (
                  SELECT 1
                  FROM Appointment a
                  WHERE a.ScheduleDate = s.ScheduleDate
                    AND a.ScheduleTime = s.ScheduleTime
                    AND a.AppointmentStatus <> :cancelled_status
                    AND a.Appointment_ID <> :except_id
              )
            ORDER BY s.ScheduleDate ASC, s.ScheduleTime ASC';

    $statement = db()->prepare($sql);
    $statement->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'open_status' => clinic_default_schedule_status(),
        'cancelled_status' => 'Cancelled',
        'except_id' => $exceptId,
    ]);

    $slots = [];
    foreach ($statement->fetchAll() as $row) {
        $slots[] = schedule_slot_row($row, true);
    }

    return $slots;
}
