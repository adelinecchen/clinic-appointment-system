<?php

declare(strict_types=1);

/**
 * Read-only Schedule helpers.
 * Uses db() from db.php. Does not insert, update, or delete rows.
 * Patient-facing helpers hide slots that already have a non-cancelled
 * appointment so ordinary booking does not offer a taken time.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clinic_maps.php';

/**
 * Normalize a TIME / datetime string from MySQL to H:i for the frontend.
 */
function schedule_time_value(string $time): string
{
    if (preg_match('/^\d{2}:\d{2}/', $time, $matches) === 1) {
        return substr($matches[0], 0, 5);
    }

    $parsed = DateTimeImmutable::createFromFormat('H:i:s', $time)
        ?: DateTimeImmutable::createFromFormat('H:i', $time);

    return $parsed === false ? $time : $parsed->format('H:i');
}

/**
 * Format H:i for display (e.g. 9:00 AM).
 */
function schedule_time_label(string $time): string
{
    $value = schedule_time_value($time);
    $parsed = DateTimeImmutable::createFromFormat('H:i', $value);

    return $parsed === false ? $value : $parsed->format('g:i A');
}

/**
 * Shape one Schedule row the way booking UI expects a slot.
 *
 * @return array{date: string, time: string, label: string, status: string, available: bool}
 */
function schedule_slot_row(array $row, bool $available): array
{
    $time = schedule_time_value((string) $row['ScheduleTime']);

    return [
        'date' => (string) $row['ScheduleDate'],
        'time' => $time,
        'label' => schedule_time_label($time),
        'status' => (string) $row['Status'],
        'available' => $available,
    ];
}

/**
 * All Schedule rows for one date, ordered by time.
 * Does not filter by appointments.
 *
 * @return list<array{date: string, time: string, label: string, status: string, available: bool}>
 */
function schedule_slots_for_date(string $date): array
{
    $sql = 'SELECT ScheduleDate, ScheduleTime, Status
            FROM Schedule
            WHERE ScheduleDate = :date
            ORDER BY ScheduleTime ASC';

    $statement = db()->prepare($sql);
    $statement->execute(['date' => $date]);

    $slots = [];
    foreach ($statement->fetchAll() as $row) {
        $slots[] = schedule_slot_row($row, clinic_schedule_is_open((string) $row['Status']));
    }

    return $slots;
}

/**
 * Schedule rows from start date through end date (inclusive).
 *
 * @return list<array{date: string, time: string, label: string, status: string, available: bool}>
 */
function schedule_slots_for_range(string $startDate, string $endDate): array
{
    $sql = 'SELECT ScheduleDate, ScheduleTime, Status
            FROM Schedule
            WHERE ScheduleDate BETWEEN :start_date AND :end_date
            ORDER BY ScheduleDate ASC, ScheduleTime ASC';

    $statement = db()->prepare($sql);
    $statement->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $slots = [];
    foreach ($statement->fetchAll() as $row) {
        $slots[] = schedule_slot_row($row, clinic_schedule_is_open((string) $row['Status']));
    }

    return $slots;
}

/**
 * Open Schedule rows for one date that are free for ordinary patient booking.
 * A slot is omitted when Status is not Open, or when any non-cancelled
 * Appointment already references that date+time.
 *
 * @return list<array{date: string, time: string, label: string, status: string, available: bool}>
 */
function schedule_open_slots_for_patient(string $date): array
{
    $sql = 'SELECT s.ScheduleDate, s.ScheduleTime, s.Status
            FROM Schedule s
            WHERE s.ScheduleDate = :date
              AND s.Status = :open_status
              AND NOT EXISTS (
                  SELECT 1
                  FROM Appointment a
                  WHERE a.ScheduleDate = s.ScheduleDate
                    AND a.ScheduleTime = s.ScheduleTime
                    AND a.AppointmentStatus <> :cancelled_status
              )
            ORDER BY s.ScheduleTime ASC';

    $statement = db()->prepare($sql);
    $statement->execute([
        'date' => $date,
        'open_status' => clinic_default_schedule_status(),
        'cancelled_status' => 'Cancelled',
    ]);

    $slots = [];
    foreach ($statement->fetchAll() as $row) {
        $slots[] = schedule_slot_row($row, true);
    }

    return $slots;
}

/**
 * Open, unbooked slots across a date range for patient booking.
 *
 * @return list<array{date: string, time: string, label: string, status: string, available: bool}>
 */
function schedule_open_slots_for_patient_range(string $startDate, string $endDate): array
{
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
              )
            ORDER BY s.ScheduleDate ASC, s.ScheduleTime ASC';

    $statement = db()->prepare($sql);
    $statement->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'open_status' => clinic_default_schedule_status(),
        'cancelled_status' => 'Cancelled',
    ]);

    $slots = [];
    foreach ($statement->fetchAll() as $row) {
        $slots[] = schedule_slot_row($row, true);
    }

    return $slots;
}

/**
 * Dates in the range that still have at least one patient-bookable slot.
 *
 * @return list<string> Y-m-d values
 */
function schedule_open_dates_for_patient(string $startDate, string $endDate): array
{
    $sql = 'SELECT DISTINCT s.ScheduleDate
            FROM Schedule s
            WHERE s.ScheduleDate BETWEEN :start_date AND :end_date
              AND s.Status = :open_status
              AND NOT EXISTS (
                  SELECT 1
                  FROM Appointment a
                  WHERE a.ScheduleDate = s.ScheduleDate
                    AND a.ScheduleTime = s.ScheduleTime
                    AND a.AppointmentStatus <> :cancelled_status
              )
            ORDER BY s.ScheduleDate ASC';

    $statement = db()->prepare($sql);
    $statement->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'open_status' => clinic_default_schedule_status(),
        'cancelled_status' => 'Cancelled',
    ]);

    $dates = [];
    foreach ($statement->fetchAll() as $row) {
        $dates[] = (string) $row['ScheduleDate'];
    }

    return $dates;
}
