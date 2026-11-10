<?php

declare(strict_types=1);

/**
 * Admin-facing appointment reads and reminder actions.
 */

require_once __DIR__ . '/appointment_queries.php';
require_once __DIR__ . '/clinic_maps.php';
require_once __DIR__ . '/sms_notifications.php';

/**
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
 *   NotificationStatus: string|null,
 *   NotificationDate: string|null,
 *   DeskStatus: string,
 *   DeskStatusLabel: string
 * }>
 */
function admin_appointments_for_date(string $date): array
{
    $statement = db()->prepare(
        'SELECT
                a.Appointment_ID, a.ScheduleDate, a.ScheduleTime, a.Patient_ID,
                a.AppointmentStatus, a.ConfirmationStatus, a.AppointmentType,
                a.NotificationStatus, a.NotificationDate,
                p.Name AS PatientName, p.PhoneNumber AS PatientPhone
         FROM Appointment a
         INNER JOIN Patient p ON p.Patient_ID = a.Patient_ID
         WHERE a.ScheduleDate = :date
         ORDER BY a.ScheduleTime ASC, a.Appointment_ID ASC'
    );
    $statement->execute(['date' => $date]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = admin_appointment_row($row);
    }

    return $rows;
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

    return $base + [
        'PatientName' => (string) ($row['PatientName'] ?? ''),
        'PatientPhone' => (string) ($row['PatientPhone'] ?? ''),
        'DeskStatus' => $desk,
        'DeskStatusLabel' => clinic_desk_status_label($desk),
    ];
}

/**
 * @param list<array{DeskStatus?: string}> $appointments
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
        $status = (string) ($appointment['DeskStatus'] ?? '');
        if ($status === 'confirmed') {
            $counts['confirmed']++;
        } elseif ($status === 'cancelled') {
            $counts['cancelled']++;
        } elseif (in_array($status, ['no_reply', 'reminder_sent'], true)) {
            $counts['not_confirmed']++;
        }
    }

    return $counts;
}

/**
 * Send a reminder SMS (when consent allows) and update confirmation/notification columns.
 * NotificationStatus Sent means the provider accepted the request (or mock success),
 * not that the carrier delivered the message.
 *
 * @return array{ok: true, appointment: array, sms: array}|array{ok: false, error: string, code: string, sms?: array}
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

    $existing = appointment_find_by_id($appointmentId);
    if ($existing === null) {
        return [
            'ok' => false,
            'error' => 'That appointment was not found.',
            'code' => 'not_found',
        ];
    }

    $sms = sms_notify_reminder($appointmentId, true);
    if (!$sms['ok'] && ($sms['code'] ?? '') !== 'already_reminded') {
        // Still surface appointment for UI; distinguish opt-out / consent skips.
        $appointment = appointment_find_by_id($appointmentId);

        return [
            'ok' => false,
            'error' => (string) ($sms['error'] !== '' ? $sms['error'] : 'The reminder text could not be sent.'),
            'code' => (string) ($sms['code'] ?? 'sms'),
            'sms' => $sms,
            'appointment' => $appointment,
        ];
    }

    $appointment = appointment_find_by_id($appointmentId);
    if ($appointment === null) {
        return [
            'ok' => false,
            'error' => 'The appointment could not be updated.',
            'code' => 'db',
            'sms' => $sms,
        ];
    }

    return [
        'ok' => true,
        'appointment' => $appointment,
        'sms' => $sms,
    ];
}

/**
 * @param list<int|string> $appointmentIds
 * @return array{ok: true, marked: list<int>, failed: list<array{id: int, code: string, error: string}>}
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
                'error' => (string) ($result['error'] ?? ''),
            ];
        }
    }

    return [
        'ok' => true,
        'marked' => $marked,
        'failed' => $failed,
    ];
}
