<?php

declare(strict_types=1);

/**
 * Appointment SMS workflows using ConfirmationStatus / NotificationStatus / NotificationDate.
 *
 * Conventions (no Notification table):
 * - NotificationStatus / NotificationDate = most recent outbound SMS attempt
 *   (Sent = provider accepted or mock success; Failed = send failed). Not carrier delivery.
 * - Reminder sets ConfirmationStatus=Pending only after a successful send attempt.
 * - Booking / cancel / reschedule do not change ConfirmationStatus.
 */

require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/sms_consent.php';
require_once __DIR__ . '/appointment_queries.php';
require_once __DIR__ . '/patient_queries.php';
require_once __DIR__ . '/preview_data.php';
require_once __DIR__ . '/clinic_maps.php';

/** @return list<string> */
function sms_notification_types(): array
{
    return ['booking', 'reminder', 'cancel', 'reschedule'];
}

function sms_dedupe_path(): string
{
    return dirname(__DIR__) . '/storage/sms_dedupe.json';
}

/**
 * @return array<string, string>
 */
function sms_dedupe_read(): array
{
    $path = sms_dedupe_path();
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, string> $map
 */
function sms_dedupe_write(array $map): bool
{
    $directory = dirname(sms_dedupe_path());
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    $handle = fopen(sms_dedupe_path(), 'c+');
    if ($handle === false) {
        return false;
    }
    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    $ok = fwrite($handle, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $ok;
}

function sms_dedupe_key(int $appointmentId, string $type): string
{
    return $appointmentId . ':' . $type;
}

function sms_already_sent_type(int $appointmentId, string $type): bool
{
    $map = sms_dedupe_read();

    return isset($map[sms_dedupe_key($appointmentId, $type)]);
}

function sms_mark_sent_type(int $appointmentId, string $type): void
{
    $map = sms_dedupe_read();
    $map[sms_dedupe_key($appointmentId, $type)] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
    // Bound growth.
    if (count($map) > 500) {
        $map = array_slice($map, -500, null, true);
    }
    sms_dedupe_write($map);
}

/**
 * @return array{name: string, phone: string, date: string, time: string}|null
 */
function sms_appointment_message_vars(int $appointmentId): ?array
{
    $appointment = appointment_find_by_id($appointmentId);
    if ($appointment === null) {
        return null;
    }
    $patient = patient_find_by_id((int) $appointment['Patient_ID']);
    if ($patient === null) {
        return null;
    }

    $day = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $appointment['ScheduleDate']);
    $dateLabel = $day !== false ? $day->format('F j, Y') : (string) $appointment['ScheduleDate'];

    return [
        'name' => $patient['Name'],
        'phone' => $patient['PhoneNumber'],
        'date' => $dateLabel,
        'time' => (string) $appointment['ScheduleTimeLabel'],
        'appointment' => $appointment,
        'patient' => $patient,
    ];
}

function sms_build_message(string $templateKey, array $vars): string
{
    $templates = preview_message_templates();
    $template = (string) ($templates[$templateKey] ?? '');
    if ($template === '') {
        $template = match ($templateKey) {
            'cancel' => 'Hi {name}, this is {clinic}. Your visit on {date} at {time} was cancelled. Reply STOP to opt out of texts.',
            'reschedule' => 'Hi {name}, this is {clinic}. Your visit was moved to {date} at {time}. Reply STOP to opt out of texts.',
            default => 'Hi {name}, this is {clinic}. Your visit is on {date} at {time}.',
        };
    }

    return preview_fill_message($template, $vars);
}

/**
 * Persist NotificationStatus / NotificationDate (and optional ConfirmationStatus).
 *
 * @return array{ok: true}|array{ok: false, error: string, code: string}
 */
function sms_update_appointment_notification(
    int $appointmentId,
    string $notificationStatus,
    ?string $confirmationStatus = null
): array {
    if (!clinic_is_notification_status($notificationStatus)) {
        return ['ok' => false, 'error' => 'Invalid notification status.', 'code' => 'validation'];
    }

    try {
        if ($confirmationStatus !== null) {
            if (!clinic_is_confirmation_status($confirmationStatus)) {
                return ['ok' => false, 'error' => 'Invalid confirmation status.', 'code' => 'validation'];
            }
            $statement = db()->prepare(
                'UPDATE Appointment
                 SET NotificationStatus = :notification_status,
                     NotificationDate = :notification_date,
                     ConfirmationStatus = :confirmation_status
                 WHERE Appointment_ID = :id'
            );
            $statement->execute([
                'notification_status' => $notificationStatus,
                'notification_date' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'confirmation_status' => $confirmationStatus,
                'id' => $appointmentId,
            ]);
        } else {
            $statement = db()->prepare(
                'UPDATE Appointment
                 SET NotificationStatus = :notification_status,
                     NotificationDate = :notification_date
                 WHERE Appointment_ID = :id'
            );
            $statement->execute([
                'notification_status' => $notificationStatus,
                'notification_date' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'id' => $appointmentId,
            ]);
        }

        return ['ok' => true];
    } catch (PDOException) {
        return ['ok' => false, 'error' => 'Could not update notification status.', 'code' => 'db'];
    }
}

/**
 * @return array{
 *   ok: bool,
 *   skipped: bool,
 *   sent: bool,
 *   error: string,
 *   code: string,
 *   provider_id: string|null,
 *   notification_status: string|null
 * }
 */
function sms_notify_appointment(int $appointmentId, string $type, bool $force = false): array
{
    $type = in_array($type, sms_notification_types(), true) ? $type : '';
    if ($type === '') {
        return [
            'ok' => false,
            'skipped' => false,
            'sent' => false,
            'error' => 'Unknown notification type.',
            'code' => 'validation',
            'provider_id' => null,
            'notification_status' => null,
        ];
    }

    if (!$force && sms_already_sent_type($appointmentId, $type)) {
        return [
            'ok' => true,
            'skipped' => true,
            'sent' => false,
            'error' => '',
            'code' => 'duplicate',
            'provider_id' => null,
            'notification_status' => null,
        ];
    }

    $vars = sms_appointment_message_vars($appointmentId);
    if ($vars === null) {
        return [
            'ok' => false,
            'skipped' => false,
            'sent' => false,
            'error' => 'Appointment not found.',
            'code' => 'not_found',
            'provider_id' => null,
            'notification_status' => null,
        ];
    }

    /** @var array<string, mixed> $appointment */
    $appointment = $vars['appointment'];
    $phone = (string) $vars['phone'];

    if ($type === 'reminder') {
        $status = (string) $appointment['AppointmentStatus'];
        $confirmation = (string) $appointment['ConfirmationStatus'];
        if ($status === 'Cancelled' || $status === 'Completed') {
            return [
                'ok' => false,
                'skipped' => true,
                'sent' => false,
                'error' => 'That visit cannot be reminded.',
                'code' => 'not_remindable',
                'provider_id' => null,
                'notification_status' => null,
            ];
        }
        if ($confirmation === 'Confirmed' || $confirmation === 'Declined') {
            return [
                'ok' => false,
                'skipped' => true,
                'sent' => false,
                'error' => 'That visit already has a confirmation reply.',
                'code' => 'not_remindable',
                'provider_id' => null,
                'notification_status' => null,
            ];
        }
        // Prevent duplicate automatic reminders unless forced (admin).
        if (
            !$force
            && $confirmation === 'Pending'
            && clinic_normalize_notification_status($appointment['NotificationStatus'] ?? null) === 'Sent'
        ) {
            return [
                'ok' => true,
                'skipped' => true,
                'sent' => false,
                'error' => '',
                'code' => 'already_reminded',
                'provider_id' => null,
                'notification_status' => 'Sent',
            ];
        }
    }

    if (sms_is_opted_out($phone)) {
        return [
            'ok' => false,
            'skipped' => true,
            'sent' => false,
            'error' => 'This phone number has opted out of texts.',
            'code' => 'opt_out',
            'provider_id' => null,
            'notification_status' => null,
        ];
    }

    if (!sms_has_consent($phone)) {
        return [
            'ok' => false,
            'skipped' => true,
            'sent' => false,
            'error' => 'SMS consent was not recorded for this phone number.',
            'code' => 'no_consent',
            'provider_id' => null,
            'notification_status' => null,
        ];
    }

    $templateKey = match ($type) {
        'booking' => 'booking',
        'reminder' => 'follow_up',
        'cancel' => 'cancel',
        'reschedule' => 'reschedule',
        default => 'booking',
    };
    $body = sms_build_message($templateKey, $vars);
    $send = sms_send($phone, $body, $type . ':' . $appointmentId);

    if ($send['ok']) {
        $confirm = $type === 'reminder' ? 'Pending' : null;
        sms_update_appointment_notification($appointmentId, 'Sent', $confirm);
        sms_mark_sent_type($appointmentId, $type);

        return [
            'ok' => true,
            'skipped' => false,
            'sent' => true,
            'error' => '',
            'code' => $send['code'],
            'provider_id' => $send['provider_id'],
            'notification_status' => 'Sent',
        ];
    }

    sms_update_appointment_notification($appointmentId, 'Failed', null);

    return [
        'ok' => false,
        'skipped' => false,
        'sent' => false,
        'error' => $send['error'],
        'code' => $send['code'],
        'provider_id' => null,
        'notification_status' => 'Failed',
    ];
}

function sms_notify_booking(int $appointmentId): array
{
    return sms_notify_appointment($appointmentId, 'booking', false);
}

function sms_notify_cancel(int $appointmentId): array
{
    return sms_notify_appointment($appointmentId, 'cancel', false);
}

function sms_notify_reschedule(int $appointmentId): array
{
    // Reschedule may happen more than once — allow force=false dedupe per appointment
    // by clearing prior reschedule marker is awkward; use force only when new reschedule
    // succeeds from UI (caller passes force via dedicated helper).
    return sms_notify_appointment($appointmentId, 'reschedule', true);
}

function sms_notify_reminder(int $appointmentId, bool $force = false): array
{
    return sms_notify_appointment($appointmentId, 'reminder', $force);
}

/**
 * Cron helper: appointments on the target date that still need a reminder.
 *
 * @return list<array{Appointment_ID: int}>
 */
function sms_appointments_needing_reminder(string $scheduleDate): array
{
    $statement = db()->prepare(
        "SELECT Appointment_ID
         FROM Appointment
         WHERE ScheduleDate = :date
           AND AppointmentStatus = 'Scheduled'
           AND ConfirmationStatus = 'Not Sent'
           AND (NotificationStatus IS NULL OR NotificationStatus = 'Not Sent' OR NotificationStatus = 'Failed')
         ORDER BY ScheduleTime ASC"
    );
    $statement->execute(['date' => $scheduleDate]);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = ['Appointment_ID' => (int) $row['Appointment_ID']];
    }

    return $rows;
}
