<?php

declare(strict_types=1);

/**
 * Central maps between frontend form/UI values and clinic_db strings.
 * The schema only CHECKs AppointmentType. Status columns are VARCHAR with
 * documented defaults; these helpers keep the allowed strings in one place.
 */

// --- Appointment type ---

function clinic_appointment_types(): array
{
    return ['New patient', 'Follow-up'];
}

/**
 * Map a patient-form visit_type to the DB AppointmentType value.
 * Frontend radios use wellness / follow_up.
 */
function clinic_appointment_type_from_visit(string $visitType): ?string
{
    return match ($visitType) {
        'wellness' => 'New patient',
        'follow_up' => 'Follow-up',
        default => null,
    };
}

/**
 * Map a DB AppointmentType back to the patient-form visit_type.
 */
function clinic_visit_type_from_appointment(string $appointmentType): ?string
{
    return match ($appointmentType) {
        'New patient' => 'wellness',
        'Follow-up' => 'follow_up',
        default => null,
    };
}

/**
 * Short label for staff/patient UI. Keeps existing "First Visit" wording
 * for New patient; uses "Follow-up visit" for Follow-up.
 */
function clinic_appointment_type_label(string $appointmentType): string
{
    return match ($appointmentType) {
        'New patient' => 'First Visit',
        'Follow-up' => 'Follow-up visit',
        default => $appointmentType,
    };
}

function clinic_is_appointment_type(string $value): bool
{
    return in_array($value, clinic_appointment_types(), true);
}

// --- AppointmentStatus (overall visit state) ---

function clinic_appointment_statuses(): array
{
    return ['Scheduled', 'Cancelled', 'Completed'];
}

function clinic_default_appointment_status(): string
{
    return 'Scheduled';
}

function clinic_is_appointment_status(string $value): bool
{
    return in_array($value, clinic_appointment_statuses(), true);
}

/**
 * True when the appointment still occupies the slot for ordinary
 * patient booking (double-book admin actions are separate).
 */
function clinic_appointment_occupies_slot(string $appointmentStatus): bool
{
    return $appointmentStatus !== 'Cancelled';
}

// --- ConfirmationStatus (patient reply to confirmation request) ---

function clinic_confirmation_statuses(): array
{
    return ['Not Sent', 'Pending', 'Confirmed', 'Declined'];
}

function clinic_default_confirmation_status(): string
{
    return 'Not Sent';
}

function clinic_is_confirmation_status(string $value): bool
{
    return in_array($value, clinic_confirmation_statuses(), true);
}

// --- NotificationStatus (delivery of the text/email itself) ---

function clinic_notification_statuses(): array
{
    return ['Not Sent', 'Sent', 'Failed'];
}

/**
 * Schema allows NULL. Treat NULL the same as Not Sent when reading.
 */
function clinic_normalize_notification_status(?string $value): string
{
    if ($value === null || $value === '') {
        return 'Not Sent';
    }

    return $value;
}

function clinic_is_notification_status(string $value): bool
{
    return in_array($value, clinic_notification_statuses(), true);
}

// --- Schedule.Status ---

function clinic_schedule_statuses(): array
{
    // Only Open is defined by the DDL default. Closed is the value we will
    // use later when availability writes land; not enforced by a CHECK yet.
    return ['Open', 'Closed'];
}

function clinic_default_schedule_status(): string
{
    return 'Open';
}

function clinic_schedule_is_open(string $status): bool
{
    return $status === 'Open';
}

// --- Desk UI bridge (frontend still uses one composite status) ---

/**
 * Map the three DB columns into the existing desk badge keys:
 * confirmed | no_reply | reminder_sent | cancelled.
 * Used later when wiring admin pages; not used by Schedule reads.
 */
function clinic_desk_status_key(
    string $appointmentStatus,
    string $confirmationStatus,
    ?string $notificationStatus
): string {
    if ($appointmentStatus === 'Cancelled') {
        return 'cancelled';
    }

    if ($confirmationStatus === 'Confirmed') {
        return 'confirmed';
    }

    $notification = clinic_normalize_notification_status($notificationStatus);
    if ($notification === 'Sent' && in_array($confirmationStatus, ['Pending', 'Not Sent'], true)) {
        return 'reminder_sent';
    }

    return 'no_reply';
}

function clinic_desk_status_label(string $deskKey): string
{
    return match ($deskKey) {
        'confirmed' => 'Confirmed',
        'no_reply' => 'No reply',
        'reminder_sent' => 'Reminder sent',
        'cancelled' => 'Cancelled',
        default => $deskKey,
    };
}
