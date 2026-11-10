<?php

declare(strict_types=1);

/**
 * Cron entry: send appointment reminders for visits N days ahead.
 *
 * Example crontab (daily at 10:00 clinic time):
 *   0 10 * * * /Applications/XAMPP/xamppfiles/bin/php /path/to/Capstone/scripts/send_appointment_reminders.php
 *
 * Env:
 *   SMS_REMINDER_DAYS_AHEAD=2   (default 2)
 *
 * Live Twilio traffic only when SMS_ENABLED=true and SMS_MODE=live.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/sms_notifications.php';

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

exit($failed > 0 ? 1 : 0);
