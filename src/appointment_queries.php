<?php

declare(strict_types=1);

/**
 * Appointment create/read/cancel/reschedule helpers for ordinary patient booking.
 * Administrative double-booking is intentionally not implemented here.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clinic_maps.php';
require_once __DIR__ . '/patient_queries.php';
require_once __DIR__ . '/schedule_queries.php';

/**
 * @return array{
 *   Appointment_ID: int,
 *   ScheduleDate: string,
 *   ScheduleTime: string,
 *   ScheduleTimeLabel: string,
 *   Patient_ID: int,
 *   AppointmentStatus: string,
 *   ConfirmationStatus: string,
 *   AppointmentType: string,
 *   AppointmentTypeLabel: string,
 *   VisitType: string|null,
 *   NotificationStatus: string|null,
 *   NotificationDate: string|null
 * }|null
 */
function appointment_find_by_id(int $appointmentId): ?array
{
    if ($appointmentId < 1) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT Appointment_ID, ScheduleDate, ScheduleTime, Patient_ID,
                AppointmentStatus, ConfirmationStatus, AppointmentType,
                NotificationStatus, NotificationDate
         FROM Appointment
         WHERE Appointment_ID = :appointment_id
         LIMIT 1'
    );
    $statement->execute(['appointment_id' => $appointmentId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    return appointment_row($row);
}

/**
 * Appointments for every Patient row whose phone digits match.
 * Supports multiple people sharing one number.
 *
 * @return list<array{
 *   Appointment_ID: int,
 *   ScheduleDate: string,
 *   ScheduleTime: string,
 *   ScheduleTimeLabel: string,
 *   Patient_ID: int,
 *   PatientName: string,
 *   PatientPhone: string,
 *   AppointmentStatus: string,
 *   ConfirmationStatus: string,
 *   AppointmentType: string,
 *   AppointmentTypeLabel: string,
 *   VisitType: string|null,
 *   NotificationStatus: string|null,
 *   NotificationDate: string|null
 * }>
 */
function appointments_for_phone(string $phoneNumber): array
{
    $digits = patient_phone_digits($phoneNumber);
    if ($digits === '') {
        return [];
    }

    $statement = db()->prepare(
        "SELECT a.Appointment_ID, a.ScheduleDate, a.ScheduleTime, a.Patient_ID,
                a.AppointmentStatus, a.ConfirmationStatus, a.AppointmentType,
                a.NotificationStatus, a.NotificationDate,
                p.Name AS PatientName, p.PhoneNumber AS PatientPhone
         FROM Appointment a
         INNER JOIN Patient p ON p.Patient_ID = a.Patient_ID
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(p.PhoneNumber, '(', ''), ')', ''), '-', ''), ' ', '') = :digits
         ORDER BY a.ScheduleDate ASC, a.ScheduleTime ASC, a.Appointment_ID ASC"
    );
    $statement->execute(['digits' => $digits]);

    $appointments = [];
    foreach ($statement->fetchAll() as $row) {
        $appointment = appointment_row($row);
        $appointment['PatientName'] = (string) $row['PatientName'];
        $appointment['PatientPhone'] = (string) $row['PatientPhone'];
        $appointments[] = $appointment;
    }

    return $appointments;
}

/**
 * Book one patient into an open, unoccupied schedule slot.
 *
 * $visitOrType may be a frontend visit_type (wellness / follow_up) or a DB
 * AppointmentType (New patient / Follow-up).
 *
 * @return array{ok: true, appointment: array}|array{ok: false, error: string, code: string}
 */
function appointment_create(
    int $patientId,
    string $scheduleDate,
    string $scheduleTime,
    string $visitOrType
): array {
    if ($patientId < 1) {
        return [
            'ok' => false,
            'error' => 'A valid patient is required.',
            'code' => 'validation',
        ];
    }

    if (!appointment_date_looks_valid($scheduleDate)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid appointment date.',
            'code' => 'validation',
        ];
    }

    $timeValue = schedule_time_value($scheduleTime);
    if (!appointment_time_looks_valid($timeValue)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid appointment time.',
            'code' => 'validation',
        ];
    }

    $appointmentType = clinic_is_appointment_type($visitOrType)
        ? $visitOrType
        : clinic_appointment_type_from_visit($visitOrType);
    if ($appointmentType === null || !clinic_is_appointment_type($appointmentType)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid visit type.',
            'code' => 'validation',
        ];
    }

    if (patient_find_by_id($patientId) === null) {
        return [
            'ok' => false,
            'error' => 'That patient was not found.',
            'code' => 'not_found',
        ];
    }

    $pdo = db();
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $slotStatement = $pdo->prepare(
            'SELECT ScheduleDate, ScheduleTime, Status
             FROM Schedule
             WHERE ScheduleDate = :date
               AND ScheduleTime = :time
             LIMIT 1
             FOR UPDATE'
        );
        $slotStatement->execute([
            'date' => $scheduleDate,
            'time' => $timeValue,
        ]);
        $slot = $slotStatement->fetch();

        if ($slot === false) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That time is not on the clinic schedule.',
                'code' => 'slot_missing',
            ];
        }

        if (!clinic_schedule_is_open((string) $slot['Status'])) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That time is closed for booking.',
                'code' => 'slot_closed',
            ];
        }

        $occupiedStatement = $pdo->prepare(
            'SELECT Appointment_ID
             FROM Appointment
             WHERE ScheduleDate = :date
               AND ScheduleTime = :time
               AND AppointmentStatus <> :cancelled
             LIMIT 1
             FOR UPDATE'
        );
        $occupiedStatement->execute([
            'date' => $scheduleDate,
            'time' => $timeValue,
            'cancelled' => 'Cancelled',
        ]);

        if ($occupiedStatement->fetch() !== false) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That time is already booked.',
                'code' => 'slot_occupied',
            ];
        }

        $insert = $pdo->prepare(
            'INSERT INTO Appointment (
                ScheduleDate,
                ScheduleTime,
                Patient_ID,
                AppointmentStatus,
                ConfirmationStatus,
                AppointmentType,
                NotificationStatus,
                NotificationDate
             ) VALUES (
                :date,
                :time,
                :patient_id,
                :appointment_status,
                :confirmation_status,
                :appointment_type,
                NULL,
                NULL
             )'
        );
        $insert->execute([
            'date' => $scheduleDate,
            'time' => $timeValue,
            'patient_id' => $patientId,
            'appointment_status' => clinic_default_appointment_status(),
            'confirmation_status' => clinic_default_confirmation_status(),
            'appointment_type' => $appointmentType,
        ]);

        $appointmentId = (int) $pdo->lastInsertId();

        if ($startedTransaction) {
            $pdo->commit();
        }

        $appointment = appointment_find_by_id($appointmentId);
        if ($appointment === null) {
            return [
                'ok' => false,
                'error' => 'The appointment could not be saved.',
                'code' => 'db',
            ];
        }

        return [
            'ok' => true,
            'appointment' => $appointment,
        ];
    } catch (PDOException) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'error' => 'The appointment could not be saved.',
            'code' => 'db',
        ];
    }
}

/**
 * Cancel a scheduled appointment. Frees the slot for ordinary booking.
 * Completed visits cannot be cancelled. Already-cancelled is a no-op success.
 *
 * @return array{ok: true, appointment: array}|array{ok: false, error: string, code: string}
 */
function appointment_cancel(int $appointmentId): array
{
    if ($appointmentId < 1) {
        return [
            'ok' => false,
            'error' => 'A valid appointment is required.',
            'code' => 'validation',
        ];
    }

    $pdo = db();
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $lock = $pdo->prepare(
            'SELECT Appointment_ID, AppointmentStatus
             FROM Appointment
             WHERE Appointment_ID = :appointment_id
             LIMIT 1
             FOR UPDATE'
        );
        $lock->execute(['appointment_id' => $appointmentId]);
        $row = $lock->fetch();

        if ($row === false) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That appointment was not found.',
                'code' => 'not_found',
            ];
        }

        $status = (string) $row['AppointmentStatus'];
        if ($status === 'Completed') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'A completed visit cannot be cancelled.',
                'code' => 'not_cancellable',
            ];
        }

        if ($status !== 'Cancelled') {
            $update = $pdo->prepare(
                'UPDATE Appointment
                 SET AppointmentStatus = :cancelled
                 WHERE Appointment_ID = :appointment_id'
            );
            $update->execute([
                'cancelled' => 'Cancelled',
                'appointment_id' => $appointmentId,
            ]);
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        $appointment = appointment_find_by_id($appointmentId);
        if ($appointment === null) {
            return [
                'ok' => false,
                'error' => 'The appointment could not be updated.',
                'code' => 'db',
            ];
        }

        return [
            'ok' => true,
            'appointment' => $appointment,
        ];
    } catch (PDOException) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'error' => 'The appointment could not be updated.',
            'code' => 'db',
        ];
    }
}

/**
 * Move a scheduled appointment to a different open, unoccupied slot.
 * Resets confirmation and notification fields so the new time can be confirmed again.
 *
 * @return array{ok: true, appointment: array}|array{ok: false, error: string, code: string}
 */
function appointment_reschedule(
    int $appointmentId,
    string $scheduleDate,
    string $scheduleTime
): array {
    if ($appointmentId < 1) {
        return [
            'ok' => false,
            'error' => 'A valid appointment is required.',
            'code' => 'validation',
        ];
    }

    if (!appointment_date_looks_valid($scheduleDate)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid appointment date.',
            'code' => 'validation',
        ];
    }

    $timeValue = schedule_time_value($scheduleTime);
    if (!appointment_time_looks_valid($timeValue)) {
        return [
            'ok' => false,
            'error' => 'Choose a valid appointment time.',
            'code' => 'validation',
        ];
    }

    $pdo = db();
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $lockAppointment = $pdo->prepare(
            'SELECT Appointment_ID, ScheduleDate, ScheduleTime, AppointmentStatus
             FROM Appointment
             WHERE Appointment_ID = :appointment_id
             LIMIT 1
             FOR UPDATE'
        );
        $lockAppointment->execute(['appointment_id' => $appointmentId]);
        $current = $lockAppointment->fetch();

        if ($current === false) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That appointment was not found.',
                'code' => 'not_found',
            ];
        }

        $status = (string) $current['AppointmentStatus'];
        if ($status === 'Cancelled') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'A cancelled visit cannot be rescheduled.',
                'code' => 'not_reschedulable',
            ];
        }

        if ($status === 'Completed') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'A completed visit cannot be rescheduled.',
                'code' => 'not_reschedulable',
            ];
        }

        $currentDate = (string) $current['ScheduleDate'];
        $currentTime = schedule_time_value((string) $current['ScheduleTime']);
        if ($currentDate === $scheduleDate && $currentTime === $timeValue) {
            if ($startedTransaction) {
                $pdo->commit();
            }

            $appointment = appointment_find_by_id($appointmentId);

            return [
                'ok' => true,
                'appointment' => $appointment,
            ];
        }

        $slotStatement = $pdo->prepare(
            'SELECT ScheduleDate, ScheduleTime, Status
             FROM Schedule
             WHERE ScheduleDate = :date
               AND ScheduleTime = :time
             LIMIT 1
             FOR UPDATE'
        );
        $slotStatement->execute([
            'date' => $scheduleDate,
            'time' => $timeValue,
        ]);
        $slot = $slotStatement->fetch();

        if ($slot === false) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That time is not on the clinic schedule.',
                'code' => 'slot_missing',
            ];
        }

        if (!clinic_schedule_is_open((string) $slot['Status'])) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That time is closed for booking.',
                'code' => 'slot_closed',
            ];
        }

        $occupiedStatement = $pdo->prepare(
            'SELECT Appointment_ID
             FROM Appointment
             WHERE ScheduleDate = :date
               AND ScheduleTime = :time
               AND AppointmentStatus <> :cancelled
               AND Appointment_ID <> :appointment_id
             LIMIT 1
             FOR UPDATE'
        );
        $occupiedStatement->execute([
            'date' => $scheduleDate,
            'time' => $timeValue,
            'cancelled' => 'Cancelled',
            'appointment_id' => $appointmentId,
        ]);

        if ($occupiedStatement->fetch() !== false) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That time is already booked.',
                'code' => 'slot_occupied',
            ];
        }

        $update = $pdo->prepare(
            'UPDATE Appointment
             SET ScheduleDate = :date,
                 ScheduleTime = :time,
                 ConfirmationStatus = :confirmation_status,
                 NotificationStatus = NULL,
                 NotificationDate = NULL
             WHERE Appointment_ID = :appointment_id'
        );
        $update->execute([
            'date' => $scheduleDate,
            'time' => $timeValue,
            'confirmation_status' => clinic_default_confirmation_status(),
            'appointment_id' => $appointmentId,
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        $appointment = appointment_find_by_id($appointmentId);
        if ($appointment === null) {
            return [
                'ok' => false,
                'error' => 'The appointment could not be updated.',
                'code' => 'db',
            ];
        }

        return [
            'ok' => true,
            'appointment' => $appointment,
        ];
    } catch (PDOException) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'error' => 'The appointment could not be updated.',
            'code' => 'db',
        ];
    }
}

/**
 * @param array<string, mixed> $row
 * @return array{
 *   Appointment_ID: int,
 *   ScheduleDate: string,
 *   ScheduleTime: string,
 *   ScheduleTimeLabel: string,
 *   Patient_ID: int,
 *   AppointmentStatus: string,
 *   ConfirmationStatus: string,
 *   AppointmentType: string,
 *   AppointmentTypeLabel: string,
 *   VisitType: string|null,
 *   NotificationStatus: string|null,
 *   NotificationDate: string|null
 * }
 */
function appointment_row(array $row): array
{
    $type = (string) $row['AppointmentType'];
    $time = schedule_time_value((string) $row['ScheduleTime']);
    $notification = $row['NotificationStatus'] ?? null;

    return [
        'Appointment_ID' => (int) $row['Appointment_ID'],
        'ScheduleDate' => (string) $row['ScheduleDate'],
        'ScheduleTime' => $time,
        'ScheduleTimeLabel' => schedule_time_label($time),
        'Patient_ID' => (int) $row['Patient_ID'],
        'AppointmentStatus' => (string) $row['AppointmentStatus'],
        'ConfirmationStatus' => (string) $row['ConfirmationStatus'],
        'AppointmentType' => $type,
        'AppointmentTypeLabel' => clinic_appointment_type_label($type),
        'VisitType' => clinic_visit_type_from_appointment($type),
        'NotificationStatus' => $notification === null ? null : (string) $notification,
        'NotificationDate' => isset($row['NotificationDate']) && $row['NotificationDate'] !== null
            ? (string) $row['NotificationDate']
            : null,
    ];
}

function appointment_date_looks_valid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function appointment_time_looks_valid(string $time): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!H:i', $time);

    return $parsed !== false && $parsed->format('H:i') === $time;
}
