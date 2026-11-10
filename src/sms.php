<?php

declare(strict_types=1);

/**
 * SMS provider helper (Twilio Programmable Messaging).
 *
 * Modes:
 * - mock (default): no network calls; records metadata for tests
 * - live: Twilio REST API when SMS_ENABLED=true and credentials are set
 *
 * NotificationStatus on Appointment means provider-accept / send-attempt result,
 * not carrier delivery confirmation.
 */

require_once __DIR__ . '/bootstrap.php';

function sms_mode(): string
{
    $mode = strtolower(env_value('SMS_MODE', 'mock'));

    return $mode === 'live' ? 'live' : 'mock';
}

function sms_enabled(): bool
{
    $flag = strtolower(env_value('SMS_ENABLED', 'false'));

    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

function sms_live_ready(): bool
{
    return sms_enabled()
        && sms_mode() === 'live'
        && env_value('TWILIO_ACCOUNT_SID', '') !== ''
        && env_value('TWILIO_AUTH_TOKEN', '') !== ''
        && env_value('TWILIO_FROM_NUMBER', '') !== '';
}

/**
 * Normalize a US phone to E.164 (+1XXXXXXXXXX) or null if invalid.
 */
function sms_normalize_e164(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) !== 10) {
        return null;
    }

    return '+1' . $digits;
}

function sms_mock_log_path(): string
{
    return dirname(__DIR__) . '/storage/sms_mock_log.json';
}

/**
 * @return list<array<string, mixed>>
 */
function sms_mock_log_read(): array
{
    $path = sms_mock_log_path();
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Persist mock send metadata without message body (privacy).
 *
 * @param array<string, mixed> $entry
 */
function sms_mock_log_append(array $entry): void
{
    $directory = dirname(sms_mock_log_path());
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }

    $handle = fopen(sms_mock_log_path(), 'c+');
    if ($handle === false) {
        return;
    }

    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($list)) {
        $list = [];
    }
    $list[] = $entry;
    // Keep the file small in local/dev.
    if (count($list) > 200) {
        $list = array_slice($list, -200);
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function sms_clear_mock_log(): void
{
    $path = sms_mock_log_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Send one SMS. Never logs message body.
 *
 * @return array{
 *   ok: bool,
 *   status: string,
 *   provider_id: string|null,
 *   error: string,
 *   code: string,
 *   mode: string
 * }
 */
function sms_send(string $to, string $body, string $context = ''): array
{
    $e164 = sms_normalize_e164($to);
    if ($e164 === null) {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'That phone number is not a valid US mobile number.',
            'code' => 'invalid_phone',
            'mode' => sms_mode(),
        ];
    }

    $body = trim($body);
    if ($body === '' || strlen($body) > 1600) {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'The message text is empty or too long.',
            'code' => 'invalid_body',
            'mode' => sms_mode(),
        ];
    }

    // Forced failure hooks for tests / dry runs.
    $force = strtolower(env_value('SMS_FORCE_FAIL', ''));
    if ($force === 'auth') {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'Provider authentication failed.',
            'code' => 'auth',
            'mode' => sms_mode(),
        ];
    }
    if ($force === 'timeout') {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'The SMS provider timed out.',
            'code' => 'timeout',
            'mode' => sms_mode(),
        ];
    }
    if ($force === 'request') {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'The SMS provider rejected the request.',
            'code' => 'provider',
            'mode' => sms_mode(),
        ];
    }

    if (!sms_live_ready()) {
        $providerId = 'mock_' . bin2hex(random_bytes(8));
        sms_mock_log_append([
            'at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'to_last4' => substr($e164, -4),
            'context' => $context,
            'provider_id' => $providerId,
            'body_chars' => strlen($body),
            'mode' => 'mock',
        ]);

        return [
            'ok' => true,
            'status' => 'Sent',
            'provider_id' => $providerId,
            'error' => '',
            'code' => 'mock',
            'mode' => 'mock',
        ];
    }

    return sms_send_twilio($e164, $body, $context);
}

/**
 * @return array{ok: bool, status: string, provider_id: string|null, error: string, code: string, mode: string}
 */
function sms_send_twilio(string $e164, string $body, string $context): array
{
    $sid = env_value('TWILIO_ACCOUNT_SID');
    $token = env_value('TWILIO_AUTH_TOKEN');
    $from = env_value('TWILIO_FROM_NUMBER');
    $timeout = (int) env_value('SMS_TIMEOUT_SECONDS', '15');
    if ($timeout < 5) {
        $timeout = 5;
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $payload = http_build_query([
        'To' => $e164,
        'From' => $from,
        'Body' => $body,
    ]);

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'The SMS client could not start.',
            'code' => 'client',
            'mode' => 'live',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $sid . ':' . $token,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'The SMS provider timed out.',
            'code' => 'timeout',
            'mode' => 'live',
        ];
    }

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'The SMS provider request failed.',
            'code' => 'network',
            'mode' => 'live',
        ];
    }

    $decoded = json_decode($raw, true);
    if ($http === 401 || $http === 403) {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'Provider authentication failed.',
            'code' => 'auth',
            'mode' => 'live',
        ];
    }

    if ($http < 200 || $http >= 300 || !is_array($decoded)) {
        return [
            'ok' => false,
            'status' => 'Failed',
            'provider_id' => null,
            'error' => 'The SMS provider rejected the request.',
            'code' => 'provider',
            'mode' => 'live',
        ];
    }

    $providerId = isset($decoded['sid']) ? (string) $decoded['sid'] : null;

    // Metadata only — never body.
    error_log(sprintf(
        '[sms] live accept context=%s to_last4=%s sid=%s',
        $context !== '' ? $context : 'none',
        substr($e164, -4),
        $providerId ?? 'none'
    ));

    return [
        'ok' => true,
        'status' => 'Sent',
        'provider_id' => $providerId,
        'error' => '',
        'code' => 'accepted',
        'mode' => 'live',
    ];
}
