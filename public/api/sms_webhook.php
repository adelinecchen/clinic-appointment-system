<?php

declare(strict_types=1);

/**
 * Twilio inbound / status callback endpoint.
 *
 * - STOP / START / UNSTOP keywords update opt-out preferences (no PHI logged).
 * - Delivery callbacks are acknowledged but do not claim Appointment delivery
 *   (no ProviderMessageID column without a Notification table).
 *
 * Configure the Twilio number's webhook to POST here over HTTPS in production.
 * Validate X-Twilio-Signature when SMS_MODE=live.
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/sms.php';
require_once __DIR__ . '/../../src/sms_consent.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

function sms_webhook_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/api/sms_webhook.php');

    return ($https ? 'https://' : 'http://') . $host . $uri;
}

function sms_webhook_signature_valid(): bool
{
    if (!sms_live_ready()) {
        // Mock / disabled: accept for local testing only.
        return strtolower(env_value('APP_ENV', 'local')) === 'local';
    }

    $token = env_value('TWILIO_AUTH_TOKEN');
    $signature = (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
    if ($token === '' || $signature === '') {
        return false;
    }

    $data = sms_webhook_url();
    $params = $_POST;
    ksort($params);
    foreach ($params as $key => $value) {
        $data .= $key . $value;
    }
    $expected = base64_encode(hash_hmac('sha1', $data, $token, true));

    return hash_equals($expected, $signature);
}

if (!sms_webhook_signature_valid()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$from = (string) ($_POST['From'] ?? '');
$body = strtoupper(trim((string) ($_POST['Body'] ?? '')));
$messageStatus = strtolower((string) ($_POST['MessageStatus'] ?? ''));

// Keyword opt-out / opt-in (TCPA / carrier expectation).
if ($from !== '' && $body !== '') {
    $keyword = preg_replace('/\s+/', ' ', $body) ?? $body;
    if (in_array($keyword, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
        sms_record_opt_out($from, 'keyword');
        echo 'You are opted out of clinic texts. Reply START to opt in again.';
        exit;
    }
    if (in_array($keyword, ['START', 'UNSTOP', 'YES'], true)) {
        sms_clear_opt_out($from, 'keyword');
        echo 'You are opted in to clinic appointment texts. Reply STOP to opt out.';
        exit;
    }
}

// Status callbacks: acknowledge only. Appointment.NotificationStatus stays at
 // provider-accept time until a future Notification ledger exists.
if ($messageStatus !== '') {
    error_log(sprintf(
        '[sms_webhook] status=%s sid=%s to_last4=%s',
        $messageStatus,
        (string) ($_POST['MessageSid'] ?? 'none'),
        substr(preg_replace('/\D+/', '', (string) ($_POST['To'] ?? '')) ?? '', -4)
    ));
}

http_response_code(204);
exit;
