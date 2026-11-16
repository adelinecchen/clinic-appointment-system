<?php

declare(strict_types=1);

/**
 * SMS consent / opt-out stored on Patient (MariaDB).
 *
 * Semantics are phone-level: a STOP/START/booking consent update is applied to
 * every Patient row that shares the same 10-digit phone number.
 *
 * Statuses: unknown | opted_in | opted_out
 * Sources: booking_form | sms_stop | sms_start | (legacy import / test)
 *
 * Providing a phone number alone is not consent (unknown → do not send).
 * Admin UI does not edit these fields.
 *
 * Consent checkbox copy and whether START / booking may reverse STOP are still
 * subject to clinic/legal approval — see docs/DECISIONS.md.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/patient_queries.php';

/** @return list<string> */
function sms_consent_statuses(): array
{
    return ['unknown', 'opted_in', 'opted_out'];
}

/** @return list<string> */
function sms_consent_sources(): array
{
    return ['booking_form', 'sms_stop', 'sms_start', 'file_import', 'test'];
}

function sms_normalize_consent_source(string $source): string
{
    $source = strtolower(trim($source));

    return match ($source) {
        'booking', 'booking_form' => 'booking_form',
        'stop', 'keyword', 'sms_stop' => 'sms_stop',
        'start', 'sms_start' => 'sms_start',
        'file_import' => 'file_import',
        'test' => 'test',
        default => in_array($source, sms_consent_sources(), true) ? $source : 'booking_form',
    };
}

function sms_phone_key(string $phone): string
{
    $digits = patient_phone_digits($phone);
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }

    return $digits;
}

/**
 * SQL expression: PhoneNumber reduced to digits (handles common US formatting).
 */
function sms_phone_digits_sql(string $column = 'PhoneNumber'): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '')";
}

/**
 * Effective status for a phone across all matching Patient rows.
 * opted_out wins if any row is opted_out; else opted_in if any; else unknown.
 */
function sms_consent_status_for_phone(string $phone): string
{
    $key = sms_phone_key($phone);
    if (strlen($key) !== 10) {
        return 'unknown';
    }

    $sql = 'SELECT SmsConsentStatus
            FROM Patient
            WHERE ' . sms_phone_digits_sql('PhoneNumber') . ' IN (:d10, :d11)';
    $statement = db()->prepare($sql);
    $statement->execute([
        'd10' => $key,
        'd11' => '1' . $key,
    ]);

    $sawIn = false;
    foreach ($statement->fetchAll() as $row) {
        $status = (string) ($row['SmsConsentStatus'] ?? 'unknown');
        if ($status === 'opted_out') {
            return 'opted_out';
        }
        if ($status === 'opted_in') {
            $sawIn = true;
        }
    }

    return $sawIn ? 'opted_in' : 'unknown';
}

function sms_is_opted_out(string $phone): bool
{
    return sms_consent_status_for_phone($phone) === 'opted_out';
}

function sms_has_consent(string $phone): bool
{
    return sms_consent_status_for_phone($phone) === 'opted_in';
}

/**
 * True when outbound appointment SMS may be attempted.
 */
function sms_may_send_to(string $phone): bool
{
    return sms_has_consent($phone);
}

/**
 * Apply status to every Patient sharing this phone.
 *
 * @return array{ok: bool, updated: int, error?: string}
 */
function sms_set_consent_for_phone(string $phone, string $status, string $source): array
{
    if (!in_array($status, sms_consent_statuses(), true) || $status === 'unknown') {
        // Callers should use opted_in / opted_out; unknown is default only.
        if ($status !== 'unknown') {
            return ['ok' => false, 'updated' => 0, 'error' => 'Invalid consent status.'];
        }
    }

    $key = sms_phone_key($phone);
    if (strlen($key) !== 10) {
        return ['ok' => false, 'updated' => 0, 'error' => 'Invalid phone number.'];
    }

    $source = sms_normalize_consent_source($source);
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    try {
        $sql = 'UPDATE Patient
                SET SmsConsentStatus = :status,
                    SmsConsentUpdatedAt = :updated_at,
                    SmsConsentSource = :source
                WHERE ' . sms_phone_digits_sql('PhoneNumber') . ' IN (:d10, :d11)';
        $statement = db()->prepare($sql);
        $statement->execute([
            'status' => $status,
            'updated_at' => $now,
            'source' => $source,
            'd10' => $key,
            'd11' => '1' . $key,
        ]);

        return ['ok' => true, 'updated' => $statement->rowCount()];
    } catch (PDOException) {
        return ['ok' => false, 'updated' => 0, 'error' => 'Consent could not be saved.'];
    }
}

function sms_record_consent(string $phone, string $source = 'booking_form'): bool
{
    // Booking checkbox currently re-opts in even after STOP (same as prior file store).
    // Clinic/legal may later forbid reversing STOP except via sms_start.
    $result = sms_set_consent_for_phone($phone, 'opted_in', $source);

    return $result['ok'];
}

function sms_record_opt_out(string $phone, string $source = 'sms_stop'): bool
{
    $result = sms_set_consent_for_phone($phone, 'opted_out', $source);

    return $result['ok'];
}

function sms_clear_opt_out(string $phone, string $source = 'sms_start'): bool
{
    $result = sms_set_consent_for_phone($phone, 'opted_in', $source);

    return $result['ok'];
}

/**
 * When a new Patient row is created, copy phone-level consent from siblings.
 */
function sms_inherit_consent_for_new_patient(int $patientId, string $phone): void
{
    $key = sms_phone_key($phone);
    if ($patientId < 1 || strlen($key) !== 10) {
        return;
    }

    $status = sms_consent_status_for_phone($phone);
    if ($status === 'unknown') {
        return;
    }

    $statement = db()->prepare(
        'SELECT SmsConsentSource, SmsConsentUpdatedAt
         FROM Patient
         WHERE Patient_ID <> :id
           AND ' . sms_phone_digits_sql('PhoneNumber') . ' IN (:d10, :d11)
           AND SmsConsentStatus = :status
         ORDER BY SmsConsentUpdatedAt DESC
         LIMIT 1'
    );
    $statement->execute([
        'id' => $patientId,
        'd10' => $key,
        'd11' => '1' . $key,
        'status' => $status,
    ]);
    $sibling = $statement->fetch();
    $source = is_array($sibling) && !empty($sibling['SmsConsentSource'])
        ? (string) $sibling['SmsConsentSource']
        : 'booking_form';
    $updatedAt = is_array($sibling) && !empty($sibling['SmsConsentUpdatedAt'])
        ? (string) $sibling['SmsConsentUpdatedAt']
        : (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    try {
        $update = db()->prepare(
            'UPDATE Patient
             SET SmsConsentStatus = :status,
                 SmsConsentUpdatedAt = :updated_at,
                 SmsConsentSource = :source
             WHERE Patient_ID = :id'
        );
        $update->execute([
            'status' => $status,
            'updated_at' => $updatedAt,
            'source' => $source,
            'id' => $patientId,
        ]);
    } catch (PDOException) {
        // Non-fatal for patient create.
    }
}

/**
 * One-time import from legacy storage/sms_preferences.json (if present).
 *
 * @return array{imported_in: int, imported_out: int, skipped: bool}
 */
function sms_import_file_preferences_if_present(): array
{
    $path = dirname(__DIR__) . '/storage/sms_preferences.json';
    if (!is_readable($path)) {
        return ['imported_in' => 0, 'imported_out' => 0, 'skipped' => true];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return ['imported_in' => 0, 'imported_out' => 0, 'skipped' => true];
    }

    $in = 0;
    $out = 0;
    foreach (($decoded['opt_out'] ?? []) as $digits => $_meta) {
        $result = sms_set_consent_for_phone((string) $digits, 'opted_out', 'file_import');
        if ($result['ok'] && $result['updated'] > 0) {
            $out++;
        }
    }
    foreach (($decoded['consent'] ?? []) as $digits => $_meta) {
        // Do not override an imported opt-out.
        if (sms_is_opted_out((string) $digits)) {
            continue;
        }
        $result = sms_set_consent_for_phone((string) $digits, 'opted_in', 'file_import');
        if ($result['ok'] && $result['updated'] > 0) {
            $in++;
        }
    }

    $bak = $path . '.imported.bak';
    @rename($path, $bak);

    return ['imported_in' => $in, 'imported_out' => $out, 'skipped' => false];
}

/**
 * Test helper: reset consent for phones whose digits start with $prefix.
 */
function sms_preferences_clear_test_phones(string $prefix = '555080'): void
{
    $prefix = preg_replace('/\D+/', '', $prefix) ?? $prefix;
    if ($prefix === '') {
        return;
    }

    try {
        $sql = 'UPDATE Patient
                SET SmsConsentStatus = \'unknown\',
                    SmsConsentUpdatedAt = NULL,
                    SmsConsentSource = NULL
                WHERE ' . sms_phone_digits_sql('PhoneNumber') . ' LIKE :prefix';
        $statement = db()->prepare($sql);
        $statement->execute(['prefix' => $prefix . '%']);
    } catch (PDOException) {
        // ignore in tests
    }
}
