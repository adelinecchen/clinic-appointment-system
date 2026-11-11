<?php

declare(strict_types=1);

/**
 * Schedule write helpers for clinic availability.
 * Stores availability as individual Schedule rows (date + time + Status).
 * Day hours are not stored as a separate table; opening a day inserts slot rows.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clinic_maps.php';
require_once __DIR__ . '/schedule_queries.php';
require_once __DIR__ . '/appointment_queries.php';

function schedule_block_minutes(): array
{
    return [10, 15, 20, 30, 45, 60];
}

/**
 * @return list<string> H:i values
 */
function schedule_generate_slot_times(string $start, string $end, int $block): array
{
    $cursor = DateTimeImmutable::createFromFormat('!H:i', $start);
    $close = DateTimeImmutable::createFromFormat('!H:i', $end);
    if ($cursor === false || $close === false || $block < 1) {
        return [];
    }

    $values = [];
    while (true) {
        $finish = $cursor->modify('+' . $block . ' minutes');
        if ($finish > $close) {
            break;
        }
        $values[] = $cursor->format('H:i');
        $cursor = $finish;
    }

    return $values;
}

/**
 * @return array{ok: true, hours: array{start: string, end: string, block: int}}|array{ok: false, error: string, code: string}
 */
function schedule_normalize_hours(string $start, string $end, int $block): array
{
    $startValue = schedule_time_value($start);
    $endValue = schedule_time_value($end);

    if (!appointment_time_looks_valid($startValue) || !appointment_time_looks_valid($endValue)) {
        return [
            'ok' => false,
            'error' => 'Enter a valid start and end time.',
            'code' => 'validation',
        ];
    }

    if ($startValue >= $endValue) {
        return [
            'ok' => false,
            'error' => 'End time must be after start time.',
            'code' => 'validation',
        ];
    }

    if (!in_array($block, schedule_block_minutes(), true)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid visit length.',
            'code' => 'validation',
        ];
    }

    $times = schedule_generate_slot_times($startValue, $endValue, $block);
    if ($times === []) {
        return [
            'ok' => false,
            'error' => 'Those hours do not create any visit slots.',
            'code' => 'validation',
        ];
    }

    return [
        'ok' => true,
        'hours' => [
            'start' => $startValue,
            'end' => $endValue,
            'block' => $block,
        ],
    ];
}

/**
 * Open a clinic day by inserting/updating Open Schedule rows for each slot.
 *
 * @return array{ok: true, date: string, created: int, opened: int, times: list<string>}|array{ok: false, error: string, code: string}
 */
function schedule_open_day(string $date, string $start, string $end, int $block): array
{
    if (!appointment_date_looks_valid($date)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid date.',
            'code' => 'validation',
        ];
    }

    $hours = schedule_normalize_hours($start, $end, $block);
    if (!$hours['ok']) {
        return $hours;
    }

    $times = schedule_generate_slot_times(
        $hours['hours']['start'],
        $hours['hours']['end'],
        $hours['hours']['block']
    );

    $pdo = db();
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $upsert = $pdo->prepare(
            'INSERT INTO Schedule (ScheduleDate, ScheduleTime, Status)
             VALUES (:date, :time, :status)
             ON DUPLICATE KEY UPDATE Status = VALUES(Status)'
        );

        $created = 0;
        $opened = 0;
        foreach ($times as $time) {
            $upsert->execute([
                'date' => $date,
                'time' => $time,
                'status' => clinic_default_schedule_status(),
            ]);
            $affected = $upsert->rowCount();
            // MySQL: 1 = insert, 2 = update changed, 0 = update no change
            if ($affected === 1) {
                $created++;
            } elseif ($affected >= 2) {
                $opened++;
            }
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'date' => $date,
            'created' => $created,
            'opened' => $opened,
            'times' => $times,
        ];
    } catch (PDOException) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'error' => 'The schedule day could not be saved.',
            'code' => 'db',
        ];
    }
}

/**
 * True when a non-cancelled appointment already occupies this date+time.
 */
function schedule_slot_has_active_booking(string $date, string $time): bool
{
    $timeValue = schedule_time_value($time);
    $statement = db()->prepare(
        'SELECT Appointment_ID
         FROM Appointment
         WHERE ScheduleDate = :date
           AND ScheduleTime = :time
           AND AppointmentStatus <> :cancelled
         LIMIT 1'
    );
    $statement->execute([
        'date' => $date,
        'time' => $timeValue,
        'cancelled' => 'Cancelled',
    ]);

    return $statement->fetch() !== false;
}

/**
 * Close only free slots on a date. Booked visits stay open on the schedule
 * so staff can still see them as booked (not "blocked").
 *
 * @return array{ok: true, date: string, closed: int, kept_booked: int}|array{ok: false, error: string, code: string}
 */
function schedule_close_day(string $date): array
{
    if (!appointment_date_looks_valid($date)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid date.',
            'code' => 'validation',
        ];
    }

    try {
        $bookedCount = db()->prepare(
            'SELECT COUNT(DISTINCT ScheduleTime)
             FROM Appointment
             WHERE ScheduleDate = :date
               AND AppointmentStatus <> :cancelled'
        );
        $bookedCount->execute([
            'date' => $date,
            'cancelled' => 'Cancelled',
        ]);
        $keptBooked = (int) $bookedCount->fetchColumn();

        $statement = db()->prepare(
            'UPDATE Schedule s
             SET s.Status = :new_status
             WHERE s.ScheduleDate = :date
               AND s.Status <> :old_status
               AND NOT EXISTS (
                   SELECT 1
                   FROM Appointment a
                   WHERE a.ScheduleDate = s.ScheduleDate
                     AND a.ScheduleTime = s.ScheduleTime
                     AND a.AppointmentStatus <> :cancelled
               )'
        );
        $statement->execute([
            'new_status' => 'Closed',
            'old_status' => 'Closed',
            'date' => $date,
            'cancelled' => 'Cancelled',
        ]);

        return [
            'ok' => true,
            'date' => $date,
            'closed' => $statement->rowCount(),
            'kept_booked' => $keptBooked,
        ];
    } catch (PDOException) {
        return [
            'ok' => false,
            'error' => 'The schedule day could not be closed.',
            'code' => 'db',
        ];
    }
}

/**
 * Close one slot. Refuses if that time already has an active visit.
 *
 * @return array{ok: true, date: string, time: string, status: string}|array{ok: false, error: string, code: string}
 */
function schedule_close_slot(string $date, string $time): array
{
    if (!appointment_date_looks_valid($date)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid date.',
            'code' => 'validation',
        ];
    }

    $timeValue = schedule_time_value($time);
    if (!appointment_time_looks_valid($timeValue)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid time.',
            'code' => 'validation',
        ];
    }

    if (schedule_slot_has_active_booking($date, $timeValue)) {
        return [
            'ok' => false,
            'error' => 'That time already has a visit. Cancel the visit first if you need to free the slot.',
            'code' => 'slot_booked',
        ];
    }

    return schedule_set_slot_status($date, $timeValue, 'Closed');
}

/**
 * Open one slot. Creates an Open row if the time did not exist yet.
 *
 * @return array{ok: true, date: string, time: string, status: string}|array{ok: false, error: string, code: string}
 */
function schedule_open_slot(string $date, string $time): array
{
    return schedule_set_slot_status($date, $time, clinic_default_schedule_status());
}

/**
 * @return array{ok: true, date: string, time: string, status: string}|array{ok: false, error: string, code: string}
 */
function schedule_set_slot_status(string $date, string $time, string $status): array
{
    if (!appointment_date_looks_valid($date)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid date.',
            'code' => 'validation',
        ];
    }

    $timeValue = schedule_time_value($time);
    if (!appointment_time_looks_valid($timeValue)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid time.',
            'code' => 'validation',
        ];
    }

    if (!in_array($status, clinic_schedule_statuses(), true)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid schedule status.',
            'code' => 'validation',
        ];
    }

    try {
        $statement = db()->prepare(
            'INSERT INTO Schedule (ScheduleDate, ScheduleTime, Status)
             VALUES (:date, :time, :status)
             ON DUPLICATE KEY UPDATE Status = VALUES(Status)'
        );
        $statement->execute([
            'date' => $date,
            'time' => $timeValue,
            'status' => $status,
        ]);

        return [
            'ok' => true,
            'date' => $date,
            'time' => $timeValue,
            'status' => $status,
        ];
    } catch (PDOException) {
        return [
            'ok' => false,
            'error' => 'The schedule slot could not be saved.',
            'code' => 'db',
        ];
    }
}

/**
 * Replace a day's open hours: open generated slots, close other existing times
 * on that date that are not in the new set.
 *
 * @return array{ok: true, date: string, times: list<string>, closed_extra: int}|array{ok: false, error: string, code: string}
 */
function schedule_update_day_hours(string $date, string $start, string $end, int $block): array
{
    $opened = schedule_open_day($date, $start, $end, $block);
    if (!$opened['ok']) {
        return $opened;
    }

    $keep = $opened['times'];
    $placeholders = [];
    $params = [
        'date' => $date,
        'new_status' => 'Closed',
        'old_status' => 'Closed',
    ];
    foreach ($keep as $index => $time) {
        $key = 't' . $index;
        $placeholders[] = ':' . $key;
        // Match TIME column values as H:i:s under native prepares.
        $params[$key] = strlen($time) === 5 ? $time . ':00' : $time;
    }

    try {
        if ($placeholders === []) {
            $closedExtra = schedule_close_day($date);
            if (!$closedExtra['ok']) {
                return $closedExtra;
            }

            return [
                'ok' => true,
                'date' => $date,
                'times' => [],
                'closed_extra' => $closedExtra['closed'],
            ];
        }

        $sql = 'UPDATE Schedule
                SET Status = :new_status
                WHERE ScheduleDate = :date
                  AND Status <> :old_status
                  AND ScheduleTime NOT IN (' . implode(', ', $placeholders) . ')';
        $statement = db()->prepare($sql);
        $statement->execute($params);

        return [
            'ok' => true,
            'date' => $date,
            'times' => $keep,
            'closed_extra' => $statement->rowCount(),
        ];
    } catch (PDOException) {
        return [
            'ok' => false,
            'error' => 'The schedule hours could not be updated.',
            'code' => 'db',
        ];
    }
}
