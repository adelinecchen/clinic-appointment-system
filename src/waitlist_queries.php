<?php

declare(strict_types=1);

/**
 * Waitlist read/write helpers.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clinic_maps.php';
require_once __DIR__ . '/patient_queries.php';

function waitlist_statuses(): array
{
    return ['Waiting', 'Held', 'Placed', 'Removed'];
}

/**
 * @return list<array{
 *   Waitlist_ID: int,
 *   Patient_ID: int,
 *   PatientName: string,
 *   PatientPhone: string,
 *   AppointmentType: string,
 *   AppointmentTypeLabel: string,
 *   Status: string
 * }>
 */
function waitlist_active(): array
{
    $statement = db()->prepare(
        "SELECT w.Waitlist_ID, w.Patient_ID, w.AppointmentType, w.Status,
                p.Name AS PatientName, p.PhoneNumber AS PatientPhone
         FROM Waitlist w
         INNER JOIN Patient p ON p.Patient_ID = w.Patient_ID
         WHERE w.Status IN ('Waiting', 'Held')
         ORDER BY w.Waitlist_ID ASC"
    );
    $statement->execute();

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = waitlist_row($row);
    }

    return $rows;
}

function waitlist_find_by_id(int $waitlistId): ?array
{
    if ($waitlistId < 1) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT w.Waitlist_ID, w.Patient_ID, w.AppointmentType, w.Status,
                p.Name AS PatientName, p.PhoneNumber AS PatientPhone
         FROM Waitlist w
         INNER JOIN Patient p ON p.Patient_ID = w.Patient_ID
         WHERE w.Waitlist_ID = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $waitlistId]);
    $row = $statement->fetch();

    return $row === false ? null : waitlist_row($row);
}

/**
 * @return array{ok: true, entry: array}|array{ok: false, error: string, code: string}
 */
function waitlist_add(int $patientId, string $visitOrType): array
{
    if (patient_find_by_id($patientId) === null) {
        return ['ok' => false, 'error' => 'That patient was not found.', 'code' => 'not_found'];
    }

    $type = clinic_is_appointment_type($visitOrType)
        ? $visitOrType
        : clinic_appointment_type_from_visit($visitOrType);
    if ($type === null) {
        return ['ok' => false, 'error' => 'Choose a valid visit type.', 'code' => 'validation'];
    }

    try {
        $statement = db()->prepare(
            'INSERT INTO Waitlist (Patient_ID, AppointmentType, Status)
             VALUES (:patient_id, :type, :status)'
        );
        $statement->execute([
            'patient_id' => $patientId,
            'type' => $type,
            'status' => 'Waiting',
        ]);
        $entry = waitlist_find_by_id((int) db()->lastInsertId());
        if ($entry === null) {
            return ['ok' => false, 'error' => 'The waitlist entry could not be saved.', 'code' => 'db'];
        }

        return ['ok' => true, 'entry' => $entry];
    } catch (PDOException) {
        return ['ok' => false, 'error' => 'The waitlist entry could not be saved.', 'code' => 'db'];
    }
}

/**
 * @return array{ok: true, entry: array}|array{ok: false, error: string, code: string}
 */
function waitlist_set_status(int $waitlistId, string $status): array
{
    if (!in_array($status, waitlist_statuses(), true)) {
        return ['ok' => false, 'error' => 'Choose a valid waitlist status.', 'code' => 'validation'];
    }

    $existing = waitlist_find_by_id($waitlistId);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'That waitlist entry was not found.', 'code' => 'not_found'];
    }

    try {
        $statement = db()->prepare(
            'UPDATE Waitlist SET Status = :status WHERE Waitlist_ID = :id'
        );
        $statement->execute([
            'status' => $status,
            'id' => $waitlistId,
        ]);
        $entry = waitlist_find_by_id($waitlistId);

        return ['ok' => true, 'entry' => $entry];
    } catch (PDOException) {
        return ['ok' => false, 'error' => 'The waitlist could not be updated.', 'code' => 'db'];
    }
}

/**
 * UI shape used by existing waitlist/desk templates.
 *
 * @return list<array{id: string, name: string, phone: string, phone_digits: string, visit: string, note: string, patient_id: int, type: string, status: string}>
 */
function waitlist_desk_entries(): array
{
    $entries = [];
    foreach (waitlist_active() as $row) {
        $entries[] = [
            'id' => (string) $row['Waitlist_ID'],
            'name' => $row['PatientName'],
            'phone' => patient_format_phone($row['PatientPhone']),
            'phone_digits' => patient_phone_digits($row['PatientPhone']),
            'visit' => $row['AppointmentTypeLabel'],
            'note' => $row['Status'] === 'Held'
                ? 'Holding an unconfirmed time until that patient confirms.'
                : 'Waiting for an open slot or an unconfirmed visit.',
            'patient_id' => $row['Patient_ID'],
            'type' => $row['AppointmentType'],
            'status' => $row['Status'],
        ];
    }

    return $entries;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function waitlist_row(array $row): array
{
    $type = (string) $row['AppointmentType'];

    return [
        'Waitlist_ID' => (int) $row['Waitlist_ID'],
        'Patient_ID' => (int) $row['Patient_ID'],
        'PatientName' => (string) $row['PatientName'],
        'PatientPhone' => (string) $row['PatientPhone'],
        'AppointmentType' => $type,
        'AppointmentTypeLabel' => clinic_appointment_type_label($type),
        'Status' => (string) $row['Status'],
    ];
}
