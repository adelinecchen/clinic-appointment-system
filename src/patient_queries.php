<?php

declare(strict_types=1);

/**
 * Patient read/create helpers.
 * One row per person. PhoneNumber is required but not unique.
 */

require_once __DIR__ . '/db.php';

/**
 * @return array{Patient_ID: int, Name: string, PhoneNumber: string}|null
 */
function patient_find_by_id(int $patientId): ?array
{
    if ($patientId < 1) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT Patient_ID, Name, PhoneNumber
         FROM Patient
         WHERE Patient_ID = :patient_id
         LIMIT 1'
    );
    $statement->execute(['patient_id' => $patientId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    return patient_row($row);
}

/**
 * Create a patient. Does not look up or reuse an existing phone number.
 *
 * @return array{ok: true, patient: array{Patient_ID: int, Name: string, PhoneNumber: string}}|array{ok: false, error: string, code: string}
 */
function patient_create(string $name, string $phoneNumber): array
{
    $name = trim($name);
    $phoneNumber = trim($phoneNumber);

    if ($name === '' || patient_text_length($name) > 50) {
        return [
            'ok' => false,
            'error' => 'Enter a name with at most 50 characters.',
            'code' => 'validation',
        ];
    }

    if ($phoneNumber === '' || patient_text_length($phoneNumber) > 15) {
        return [
            'ok' => false,
            'error' => 'Enter a phone number with at most 15 characters.',
            'code' => 'validation',
        ];
    }

    try {
        $statement = db()->prepare(
            'INSERT INTO Patient (Name, PhoneNumber)
             VALUES (:name, :phone)'
        );
        $statement->execute([
            'name' => $name,
            'phone' => $phoneNumber,
        ]);

        $patientId = (int) db()->lastInsertId();
        $patient = patient_find_by_id($patientId);
        if ($patient === null) {
            return [
                'ok' => false,
                'error' => 'The patient could not be saved.',
                'code' => 'db',
            ];
        }

        return [
            'ok' => true,
            'patient' => $patient,
        ];
    } catch (PDOException) {
        return [
            'ok' => false,
            'error' => 'The patient could not be saved.',
            'code' => 'db',
        ];
    }
}

/**
 * Digits only, for phone matching across formatting differences.
 */
function patient_phone_digits(string $phoneNumber): string
{
    return preg_replace('/\D+/', '', $phoneNumber) ?? '';
}

/**
 * @return list<array{Patient_ID: int, Name: string, PhoneNumber: string}>
 */
function patients_find_by_phone(string $phoneNumber): array
{
    $digits = patient_phone_digits($phoneNumber);
    if ($digits === '') {
        return [];
    }

    // Compare digit forms so "(757) 555-1234" and "7575551234" match.
    $statement = db()->prepare(
        "SELECT Patient_ID, Name, PhoneNumber
         FROM Patient
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(PhoneNumber, '(', ''), ')', ''), '-', ''), ' ', '') = :digits
         ORDER BY Patient_ID ASC"
    );
    $statement->execute(['digits' => $digits]);

    $patients = [];
    foreach ($statement->fetchAll() as $row) {
        $patients[] = patient_row($row);
    }

    return $patients;
}

/**
 * @param array<string, mixed> $row
 * @return array{Patient_ID: int, Name: string, PhoneNumber: string}
 */
function patient_row(array $row): array
{
    return [
        'Patient_ID' => (int) $row['Patient_ID'],
        'Name' => (string) $row['Name'],
        'PhoneNumber' => (string) $row['PhoneNumber'],
    ];
}

function patient_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}
