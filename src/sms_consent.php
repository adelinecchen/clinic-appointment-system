<?php

declare(strict_types=1);

/**
 * SMS consent and opt-out preferences (file-backed; storage/ is gitignored).
 *
 * Providing a phone number alone is not treated as SMS consent.
 * Opt-out always wins over prior consent.
 *
 * Production recommendation: move these flags onto Patient columns after clinic
 * legal review (SmsConsent / SmsOptOut). File storage is intentional for this
 * phase so Appointment/Patient schemas stay unchanged.
 */

function sms_preferences_path(): string
{
    return dirname(__DIR__) . '/storage/sms_preferences.json';
}

/**
 * @return array{consent: array<string, array<string, string>>, opt_out: array<string, array<string, string>>}
 */
function sms_preferences_read(): array
{
    $empty = ['consent' => [], 'opt_out' => []];
    $path = sms_preferences_path();
    if (!is_readable($path)) {
        return $empty;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $empty;
    }

    return [
        'consent' => isset($decoded['consent']) && is_array($decoded['consent']) ? $decoded['consent'] : [],
        'opt_out' => isset($decoded['opt_out']) && is_array($decoded['opt_out']) ? $decoded['opt_out'] : [],
    ];
}

/**
 * @param array{consent: array<string, array<string, string>>, opt_out: array<string, array<string, string>>} $state
 */
function sms_preferences_write(array $state): bool
{
    $directory = dirname(sms_preferences_path());
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $handle = fopen(sms_preferences_path(), 'c+');
    if ($handle === false) {
        return false;
    }

    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    $written = fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $written !== false;
}

function sms_phone_key(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }

    return $digits;
}

function sms_is_opted_out(string $phone): bool
{
    $key = sms_phone_key($phone);
    if (strlen($key) !== 10) {
        return false;
    }
    $state = sms_preferences_read();

    return isset($state['opt_out'][$key]);
}

function sms_has_consent(string $phone): bool
{
    $key = sms_phone_key($phone);
    if (strlen($key) !== 10) {
        return false;
    }
    if (sms_is_opted_out($phone)) {
        return false;
    }
    $state = sms_preferences_read();

    return isset($state['consent'][$key]);
}

/**
 * True when outbound appointment SMS may be attempted.
 */
function sms_may_send_to(string $phone): bool
{
    return sms_has_consent($phone) && !sms_is_opted_out($phone);
}

function sms_record_consent(string $phone, string $source = 'booking'): bool
{
    $key = sms_phone_key($phone);
    if (strlen($key) !== 10) {
        return false;
    }
    $state = sms_preferences_read();
    unset($state['opt_out'][$key]);
    $state['consent'][$key] = [
        'at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'source' => $source,
    ];

    return sms_preferences_write($state);
}

function sms_record_opt_out(string $phone, string $source = 'stop'): bool
{
    $key = sms_phone_key($phone);
    if (strlen($key) !== 10) {
        return false;
    }
    $state = sms_preferences_read();
    unset($state['consent'][$key]);
    $state['opt_out'][$key] = [
        'at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'source' => $source,
    ];

    return sms_preferences_write($state);
}

function sms_clear_opt_out(string $phone, string $source = 'start'): bool
{
    $key = sms_phone_key($phone);
    if (strlen($key) !== 10) {
        return false;
    }
    $state = sms_preferences_read();
    unset($state['opt_out'][$key]);
    $state['consent'][$key] = [
        'at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'source' => $source,
    ];

    return sms_preferences_write($state);
}

function sms_preferences_clear_test_phones(string $prefix = '555080'): void
{
    $state = sms_preferences_read();
    foreach (['consent', 'opt_out'] as $bucket) {
        foreach (array_keys($state[$bucket]) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($state[$bucket][$key]);
            }
        }
    }
    sms_preferences_write($state);
}
