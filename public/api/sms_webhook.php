<?php

declare(strict_types=1);

/**
 * Twilio inbound / status callback endpoint.
 *
 * - STOP / START / UNSTOP keywords update opt-out preferences (no PHI logged).
 * - Delivery callbacks are acknowledged but do not claim Appointment delivery
 *   (no ProviderMessageID column without a Notification table).
 *
 * Signature rules (see sms_webhook_request_authorized in src/sms.php):
 * - Unsigned accepted only when APP_ENV=local AND (SMS_ENABLED=false OR SMS_MODE=mock).
 * - Otherwise require valid X-Twilio-Signature over SMS_WEBHOOK_URL + POST params.
 *
 * Twilio Console: Phone Numbers → Active numbers → your number → Messaging
 * configure "A message comes in" (and status callback, if used) to the same
 * public HTTPS URL stored in SMS_WEBHOOK_URL (must match exactly).
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/sms.php';
require_once __DIR__ . '/../../src/sms_consent.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$signature = (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
/** @var array<string, scalar|null> $params */
$params = [];
foreach ($_POST as $key => $value) {
    if (is_scalar($value) || $value === null) {
        $params[(string) $key] = $value;
    }
}

if (!sms_webhook_request_authorized($signature, $params)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$from = (string) ($_POST['From'] ?? '');
$body = strtoupper(trim((string) ($_POST['Body'] ?? '')));
$messageStatus = strtolower((string) ($_POST['MessageStatus'] ?? ''));

// Keyword opt-out / opt-in / help (TCPA / carrier expectation).
if ($from !== '' && $body !== '') {
    $keyword = preg_replace('/\s+/', ' ', $body) ?? $body;
    if (in_array($keyword, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
        sms_record_opt_out($from, 'sms_stop');
        echo 'You are opted out of clinic texts. Reply START to opt in again.';
        exit;
    }
    if (in_array($keyword, ['START', 'UNSTOP'], true)) {
        sms_clear_opt_out($from, 'sms_start');
        echo 'You are opted in to clinic appointment texts. Reply STOP to opt out; HELP for more info.';
        exit;
    }
    if (in_array($keyword, ['HELP', 'INFO'], true)) {
        $clinic = preview_clinic();
        $name = $clinic['short'] !== '' ? $clinic['short'] : $clinic['name'];
        echo $name . ' help: call ' . $clinic['phone'] . '. Reply STOP to opt out.';
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
