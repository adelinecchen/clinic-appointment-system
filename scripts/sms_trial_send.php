<?php

declare(strict_types=1);

/**
 * One-off Twilio trial smoke test using the project's sms_send() helper (curl, no Composer).
 *
 * Usage:
 *   /Applications/XAMPP/xamppfiles/bin/php scripts/sms_trial_send.php
 *   /Applications/XAMPP/xamppfiles/bin/php scripts/sms_trial_send.php +1XXXXXXXXXX
 *
 * Requires in .env (gitignored):
 *   SMS_ENABLED=true
 *   SMS_MODE=live
 *   SMS_LIVE_BREAK_GLASS=true  (required when APP_ENV is not production)
 *   TWILIO_ACCOUNT_SID=...
 *   TWILIO_AUTH_TOKEN=...
 *   TWILIO_FROM_NUMBER=+1...   (your Twilio / trial From number)
 *   SMS_TRIAL_TO=+1...         (optional default To; must be a Verified Caller ID on trial)
 *   SMS_TWILIO_TRIAL_MODE=true (required on current free trials — custom bodies are blocked)
 *   SMS_TWILIO_TRIAL_TEMPLATE=sms_appointment_reminders
 *
 * Does not log full phone numbers or message body beyond a short confirmation.
 * No Composer SDK — uses src/sms.php curl helper.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/sms.php';

$to = trim((string) ($argv[1] ?? env_value('SMS_TRIAL_TO', '')));
if ($to === '') {
    fwrite(STDERR, "Usage: php scripts/sms_trial_send.php +1XXXXXXXXXX\n");
    fwrite(STDERR, "Or set SMS_TRIAL_TO in .env\n");
    exit(1);
}

if (!sms_live_ready()) {
    fwrite(STDERR, "Live SMS is not ready. Set in .env:\n");
    fwrite(STDERR, "  SMS_ENABLED=true\n");
    fwrite(STDERR, "  SMS_MODE=live\n");
    fwrite(STDERR, "  TWILIO_ACCOUNT_SID=...\n");
    fwrite(STDERR, "  TWILIO_AUTH_TOKEN=...\n");
    fwrite(STDERR, "  TWILIO_FROM_NUMBER=+1...\n");
    fwrite(STDERR, "Current: enabled=" . (sms_enabled() ? 'yes' : 'no')
        . " mode=" . sms_mode()
        . " from_set=" . (env_value('TWILIO_FROM_NUMBER', '') !== '' ? 'yes' : 'no')
        . " sid_set=" . (env_value('TWILIO_ACCOUNT_SID', '') !== '' ? 'yes' : 'no')
        . "\n");
    exit(1);
}

if (!sms_live_environment_allowed()) {
    fwrite(STDERR, "Live SMS env guard blocked this send.\n");
    fwrite(STDERR, "Set APP_ENV=production, or for intentional local trials:\n");
    fwrite(STDERR, "  SMS_LIVE_BREAK_GLASS=true\n");
    fwrite(STDERR, "Flip break-glass back to false when the trial is done.\n");
    exit(1);
}

$e164 = sms_normalize_e164($to);
if ($e164 === null) {
    fwrite(STDERR, "Invalid US phone number.\n");
    exit(1);
}

$trialMode = in_array(strtolower(env_value('SMS_TWILIO_TRIAL_MODE', 'false')), ['1', 'true', 'yes', 'on'], true);
if ($trialMode) {
    echo "Trial mode: Body will be template "
        . env_value('SMS_TWILIO_TRIAL_TEMPLATE', 'sms_appointment_reminders') . "\n";
}

$body = 'Clinic Capstone SMS wiring check (ignored when SMS_TWILIO_TRIAL_MODE=true).';
$result = sms_send($e164, $body, 'trial_script');

if ($result['ok']) {
    echo "OK mode={$result['mode']} code={$result['code']} provider_id="
        . ($result['provider_id'] ?? 'none')
        . " to_last4=" . substr($e164, -4) . "\n";
    echo "Note: provider accepted the request — that is not proof of carrier delivery.\n";
    exit(0);
}

fwrite(STDERR, "FAIL code={$result['code']} error={$result['error']}\n");
fwrite(STDERR, "Trial accounts can only text Verified Caller IDs in the Twilio console.\n");
exit(1);
