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
 * - Dedupe uses Appointment fields (not a file ledger). Cancel/reschedule DB paths
 *   clear Notification* so the next lifecycle SMS can send once; Sent then blocks duplicates.
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

/**
 * Field-based duplicate skip (when !$force).
 *
 * Rules:
 * - booking / reschedule: skip if NotificationStatus is Sent
 * - cancel: skip if AppointmentStatus is Cancelled and NotificationStatus is Sent
 * - reminder: skip if ConfirmationStatus is Pending and NotificationStatus is Sent
 * Failed never skips (retry allowed). force bypasses these checks.
 *
 * @param array<string, mixed> $appointment
 * @return array{
 *   ok: bool,
 *   skipped: bool,
 *   sent: bool,
 *   error: string,
 *   code: string,
 *   provider_id: string|null,
 *   notification_status: string|null
 * }|null
 */
function sms_field_dedupe_skip(array $appointment, string $type): ?array
{
    $notification = clinic_normalize_notification_status($appointment['NotificationStatus'] ?? null);
    $confirmation = (string) ($appointment['ConfirmationStatus'] ?? '');
    $status = (string) ($appointment['AppointmentStatus'] ?? '');

    $duplicate = static function (string $code, ?string $notificationStatus): array {
        return [
            'ok' => true,
            'skipped' => true,
            'sent' => false,
            'error' => '',
            'code' => $code,
            'provider_id' => null,
            'notification_status' => $notificationStatus,
        ];
    };

    return match ($type) {
        'booking', 'reschedule' => $notification === 'Sent'
            ? $duplicate('duplicate', 'Sent')
            : null,
        'cancel' => ($status === 'Cancelled' && $notification === 'Sent')
            ? $duplicate('duplicate', 'Sent')
            : null,
        'reminder' => ($confirmation === 'Pending' && $notification === 'Sent')
            ? $duplicate('already_reminded', 'Sent')
            : null,
        default => null,
    };
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
            'cancel' => '{clinic}: Hi {name}, your visit on {date} at {time} was cancelled. Book again anytime if you need a new time. Msg & data rates may apply. Reply STOP to opt out; HELP for more info.',
            'reschedule' => '{clinic}: Hi {name}, your visit was moved to {date} at {time}. Reply YES to confirm the new time. Msg & data rates may apply. Reply STOP to opt out; HELP for more info.',
            default => '{clinic}: Hi {name}, your visit is on {date} at {time}. Msg & data rates may apply. Reply STOP to opt out; HELP for more info.',
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

    if (!$force) {
        $skip = sms_field_dedupe_skip($appointment, $type);
        if ($skip !== null) {
            return $skip;
        }
    }

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
    // force=false: reschedule DB clears Notification* first, so one send is allowed;
    // a second call sees Sent and skips. Failed remains retryable.
    return sms_notify_appointment($appointmentId, 'reschedule', false);
}

function sms_notify_reminder(int $appointmentId, bool $force = false): array
{
    return sms_notify_appointment($appointmentId, 'reminder', $force);
}

/**
 * Cron helper: appointments on the target date that still need a first reminder.
 * Includes booking/reschedule already-Sent rows while ConfirmationStatus is Not Sent.
 * Excludes successfully reminded visits (ConfirmationStatus becomes Pending).
 * Failed reminder attempts stay eligible (ConfirmationStatus remains Not Sent).
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
         ORDER BY ScheduleTime ASC"
    );
    $statement->execute(['date' => $scheduleDate]);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = ['Appointment_ID' => (int) $row['Appointment_ID']];
    }

    return $rows;
}

function sms_reminder_cron_lock_path(): string
{
    return dirname(__DIR__) . '/storage/sms_reminder_cron.lock';
}

/**
 * Non-blocking exclusive lock so overlapping cron invocations do not double-send.
 *
 * @return resource|false
 */
function sms_reminder_cron_acquire_lock()
{
    $path = sms_reminder_cron_lock_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return false;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite(
        $handle,
        (string) getmypid() . ' ' . (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))
            ->format(DateTimeInterface::ATOM) . "\n"
    );
    fflush($handle);

    return $handle;
}

/**
 * @param resource $handle
 */
function sms_reminder_cron_release_lock($handle): void
{
    flock($handle, LOCK_UN);
    fclose($handle);
}
