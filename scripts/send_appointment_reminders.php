<?php

declare(strict_types=1);

/**
 * Cron entry: send appointment reminders for visits N days ahead (America/New_York).
 *
 * Example crontab (clinic Eastern Time — set the crontab host TZ to America/New_York,
 * or use an explicit TZ= prefix):
 *   0 10 * * * TZ=America/New_York /Applications/XAMPP/xamppfiles/bin/php /path/to/Capstone/scripts/send_appointment_reminders.php
 *
 * Env:
 *   SMS_REMINDER_DAYS_AHEAD=2   (default 2)
 *
 * Overlap: a non-blocking flock on storage/sms_reminder_cron.lock skips a second run.
 *
 * Failure / retry:
 * - Successful reminder → ConfirmationStatus=Pending (excluded from later cron picks).
 * - Failed send → NotificationStatus=Failed, ConfirmationStatus stays Not Sent → next cron retries.
 * - Skips (opt-out, no consent, already reminded) are counted but not retried as failures.
 * - Exit code 1 if any candidate failed to send; 0 if locked-out / all ok or only skips.
 *
 * Live Twilio traffic only when SMS_ENABLED=true, SMS_MODE=live, credentials set,
 * and APP_ENV=production (or SMS_LIVE_BREAK_GLASS=true). Otherwise mock / env_guard.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/sms_notifications.php';

$lock = sms_reminder_cron_acquire_lock();
if ($lock === false) {
    echo "reminder_cron=skipped reason=already_running\n";
    exit(0);
}

$exitCode = 0;
try {
    $days = (int) env_value('SMS_REMINDER_DAYS_AHEAD', '2');
    if ($days < 1) {
        $days = 1;
    }

    $tz = new DateTimeZone('America/New_York');
    $target = (new DateTimeImmutable('today', $tz))->modify('+' . $days . ' days')->format('Y-m-d');
    $candidates = sms_appointments_needing_reminder($target);

    $sent = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($candidates as $row) {
        $result = sms_notify_reminder((int) $row['Appointment_ID'], false);
        if (!empty($result['sent'])) {
            $sent++;
        } elseif (!empty($result['skipped'])) {
            $skipped++;
        } else {
            $failed++;
        }
    }

    echo sprintf(
        "reminder_date=%s candidates=%d sent=%d skipped=%d failed=%d mode=%s\n",
        $target,
        count($candidates),
        $sent,
        $skipped,
        $failed,
        sms_mode()
    );

    $exitCode = $failed > 0 ? 1 : 0;
} finally {
    sms_reminder_cron_release_lock($lock);
}

exit($exitCode);
