<?php

declare(strict_types=1);

/**
 * Admin day-desk appointment reads and status updates.
 * Does not send SMS. Marking a reminder only updates confirmation/notification columns.
 * Waitlist fill / intentional double-book are left for a later milestone.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clinic_maps.php';
require_once __DIR__ . '/appointment_queries.php';

/**
 * Appointments for one clinic day, joined to patient fields the desk shows.
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
 *   NotificationDate: string|null,
 *   DeskStatus: string,
 *   DeskStatusLabel: string
 * }>
 */
function admin_appointments_for_date(string $date): array
{
    if (!appointment_date_looks_valid($date)) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT a.Appointment_ID, a.ScheduleDate, a.ScheduleTime, a.Patient_ID,
                a.AppointmentStatus, a.ConfirmationStatus, a.AppointmentType,
                a.NotificationStatus, a.NotificationDate,
                p.Name AS PatientName, p.PhoneNumber AS PatientPhone
         FROM Appointment a
         INNER JOIN Patient p ON p.Patient_ID = a.Patient_ID
         WHERE a.ScheduleDate = :date
         ORDER BY a.ScheduleTime ASC, a.Appointment_ID ASC'
    );
    $statement->execute(['date' => $date]);

    $appointments = [];
    foreach ($statement->fetchAll() as $row) {
        $appointments[] = admin_appointment_row($row);
    }

    return $appointments;
}

/**
 * Summary cards for the desk: booked, confirmed, not_confirmed, cancelled.
 *
 * @param list<array> $appointments rows from admin_appointments_for_date()
 * @return array{booked: int, confirmed: int, not_confirmed: int, cancelled: int}
 */
function admin_appointment_counts(array $appointments): array
{
    $counts = [
        'booked' => count($appointments),
        'confirmed' => 0,
        'not_confirmed' => 0,
        'cancelled' => 0,
    ];

    foreach ($appointments as $appointment) {
        $desk = (string) ($appointment['DeskStatus'] ?? '');
        if ($desk === 'confirmed') {
            $counts['confirmed']++;
        } elseif ($desk === 'cancelled') {
            $counts['cancelled']++;
        } else {
            $counts['not_confirmed']++;
        }
    }

    return $counts;
}

/**
 * @param list<array> $appointments
 * @return list<array>
 */
function admin_unconfirmed_appointments(array $appointments): array
{
    return array_values(array_filter(
        $appointments,
        static fn (array $appointment): bool => in_array(
            (string) ($appointment['DeskStatus'] ?? ''),
            ['no_reply', 'reminder_sent'],
            true
        )
    ));
}

/**
 * Record that a confirmation/reminder text was sent for one visit.
 * ConfirmationStatus becomes Pending; NotificationStatus becomes Sent.
 *
 * @return array{ok: true, appointment: array}|array{ok: false, error: string, code: string}
 */
function admin_mark_reminder_sent(int $appointmentId): array
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
            'SELECT Appointment_ID, AppointmentStatus, ConfirmationStatus
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

        if ((string) $row['AppointmentStatus'] === 'Cancelled') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'A cancelled visit cannot be reminded.',
                'code' => 'not_remindable',
            ];
        }

        if ((string) $row['AppointmentStatus'] === 'Completed') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'A completed visit cannot be reminded.',
                'code' => 'not_remindable',
            ];
        }

        $confirmation = (string) $row['ConfirmationStatus'];
        if ($confirmation === 'Confirmed' || $confirmation === 'Declined') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'error' => 'That visit already has a confirmation reply.',
                'code' => 'not_remindable',
            ];
        }

        $update = $pdo->prepare(
            'UPDATE Appointment
             SET ConfirmationStatus = :confirmation_status,
                 NotificationStatus = :notification_status,
                 NotificationDate = :notification_date
             WHERE Appointment_ID = :appointment_id'
        );
        $update->execute([
            'confirmation_status' => 'Pending',
            'notification_status' => 'Sent',
            'notification_date' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
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
 * Mark reminders for many appointments. Continues past individual failures.
 *
 * @param list<int|string> $appointmentIds
 * @return array{ok: true, marked: list<int>, failed: list<array{id: int, code: string}>}
 */
function admin_mark_reminders_sent(array $appointmentIds): array
{
    $marked = [];
    $failed = [];

    foreach ($appointmentIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }

        $result = admin_mark_reminder_sent($id);
        if ($result['ok']) {
            $marked[] = $id;
        } else {
            $failed[] = [
                'id' => $id,
                'code' => (string) ($result['code'] ?? 'db'),
            ];
        }
    }

    return [
        'ok' => true,
        'marked' => $marked,
        'failed' => $failed,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function admin_appointment_row(array $row): array
{
    $base = appointment_row($row);
    $desk = clinic_desk_status_key(
        $base['AppointmentStatus'],
        $base['ConfirmationStatus'],
        $base['NotificationStatus']
    );

    $base['PatientName'] = (string) $row['PatientName'];
    $base['PatientPhone'] = (string) $row['PatientPhone'];
    $base['DeskStatus'] = $desk;
    $base['DeskStatusLabel'] = clinic_desk_status_label($desk);

    return $base;
}
