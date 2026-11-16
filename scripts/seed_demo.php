<?php

declare(strict_types=1);

/**
 * Local-only wipe + reseed for clinic_db demo stories.
 *
 * Usage (from repo root):
 *   php scripts/seed_demo.php --yes
 *   /Applications/XAMPP/xamppfiles/bin/php scripts/seed_demo.php --yes
 *
 * Guards:
 *   - CLI only
 *   - APP_ENV=local
 *   - requires --yes
 *
 * Super-admin credentials come from .env ADMIN_* when set.
 * A second staff admin is always created for the staff page demo.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/db.php';
require_once dirname(__DIR__) . '/src/admin_auth.php';
require_once dirname(__DIR__) . '/src/schedule_writes.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "seed_demo.php is CLI-only.\n");
    exit(1);
}

if (!admin_is_local_env()) {
    fwrite(STDERR, "Refusing to seed: APP_ENV must be local.\n");
    exit(1);
}

$confirm = in_array('--yes', $argv, true) || in_array('-y', $argv, true);
if (!$confirm) {
    fwrite(STDERR, "This wipes Appointment, Waitlist, Schedule, Patient, and Admin in clinic_db.\n");
    fwrite(STDERR, "Re-run with --yes to continue.\n");
    exit(1);
}

$tz = new DateTimeZone('America/New_York');
$today = new DateTimeImmutable('today', $tz);
$demoPassword = env_value('ADMIN_PASSWORD', '') !== ''
    ? env_value('ADMIN_PASSWORD')
    : 'demoaccount';

/**
 * Clinic operates Sundays only. Returns today when it is Sunday, else next Sunday.
 */
function seed_next_sunday(DateTimeImmutable $from): DateTimeImmutable
{
    if ((int) $from->format('N') === 7) {
        return $from;
    }

    return $from->modify('next sunday');
}

/**
 * Previous Sunday strictly before $from (for past completed visits).
 */
function seed_previous_sunday(DateTimeImmutable $from): DateTimeImmutable
{
    if ((int) $from->format('N') === 7) {
        return $from->modify('-7 days');
    }

    return $from->modify('previous sunday');
}

/**
 * @return list<string> Y-m-d Sundays starting at $firstSunday
 */
function seed_clinic_sundays(DateTimeImmutable $firstSunday, int $count): array
{
    $dates = [];
    $cursor = $firstSunday;
    for ($i = 0; $i < $count; $i++) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+7 days');
    }

    return $dates;
}

/**
 * @return array{id: int, name: string, phone: string}
 */
function seed_insert_patient(PDO $pdo, string $name, string $phone): array
{
    $statement = $pdo->prepare(
        'INSERT INTO Patient (Name, PhoneNumber) VALUES (:name, :phone)'
    );
    $statement->execute([
        'name' => $name,
        'phone' => $phone,
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'name' => $name,
        'phone' => $phone,
    ];
}

/**
 * @param array{id: int} $patient
 */
function seed_insert_appointment(
    PDO $pdo,
    array $patient,
    string $date,
    string $time,
    string $type,
    string $appointmentStatus,
    string $confirmationStatus,
    ?string $notificationStatus,
    ?string $notificationDate
): void {
    $statement = $pdo->prepare(
        'INSERT INTO Appointment (
            ScheduleDate, ScheduleTime, Patient_ID,
            AppointmentStatus, ConfirmationStatus, AppointmentType,
            NotificationStatus, NotificationDate
         ) VALUES (
            :date, :time, :patient_id,
            :appointment_status, :confirmation_status, :appointment_type,
            :notification_status, :notification_date
         )'
    );
    $statement->execute([
        'date' => $date,
        'time' => $time,
        'patient_id' => $patient['id'],
        'appointment_status' => $appointmentStatus,
        'confirmation_status' => $confirmationStatus,
        'appointment_type' => $type,
        'notification_status' => $notificationStatus,
        'notification_date' => $notificationDate,
    ]);
}

function seed_insert_waitlist(PDO $pdo, int $patientId, string $type, string $status): void
{
    $statement = $pdo->prepare(
        'INSERT INTO Waitlist (Patient_ID, AppointmentType, Status)
         VALUES (:patient_id, :type, :status)'
    );
    $statement->execute([
        'patient_id' => $patientId,
        'type' => $type,
        'status' => $status,
    ]);
}

function seed_insert_admin(
    PDO $pdo,
    string $name,
    string $phone,
    string $email,
    string $password,
    string $role
): void {
    $statement = $pdo->prepare(
        'INSERT INTO Admin (Name, PhoneNumber, Email, PasswordHash, Role)
         VALUES (:name, :phone, :email, :hash, :role)'
    );
    $statement->execute([
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);
}

$pdo = db();
if (db_health() !== 'connected') {
    fwrite(STDERR, "Database is not connected. Check .env and start MySQL.\n");
    exit(1);
}

$clinicHoursStart = '09:00';
$clinicHoursEnd = '15:00';
$clinicBlock = 15;

$mainSunday = seed_next_sunday($today);
$nextSunday = $mainSunday->modify('+7 days');
$laterSunday = $mainSunday->modify('+14 days');
$pastSunday = seed_previous_sunday($today);
$openDates = seed_clinic_sundays($mainSunday, 6);

echo "Seeding clinic_db for local demo...\n";
echo 'Today (clinic TZ): ' . $today->format('Y-m-d') . "\n";
echo 'Main clinic Sunday: ' . $mainSunday->format('Y-m-d') . " (9:00–3:00)\n";

try {
    $pdo->beginTransaction();

    $pdo->exec('DELETE FROM Appointment');
    $pdo->exec('DELETE FROM Waitlist');
    $pdo->exec('DELETE FROM Schedule');
    $pdo->exec('DELETE FROM Patient');
    $pdo->exec('DELETE FROM Admin');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Wipe failed: " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($openDates as $date) {
    $opened = schedule_open_day($date, $clinicHoursStart, $clinicHoursEnd, $clinicBlock);
    if (!$opened['ok']) {
        fwrite(STDERR, 'Could not open ' . $date . ': ' . $opened['error'] . "\n");
        exit(1);
    }
}

// Past Sunday so a completed visit has a valid Schedule FK.
$pastOpen = schedule_open_day(
    $pastSunday->format('Y-m-d'),
    $clinicHoursStart,
    $clinicHoursEnd,
    $clinicBlock
);
if (!$pastOpen['ok']) {
    fwrite(STDERR, 'Could not open past Sunday: ' . $pastOpen['error'] . "\n");
    exit(1);
}

$mainKey = $mainSunday->format('Y-m-d');
schedule_close_slot($mainKey, '12:00');
schedule_close_slot($mainKey, '12:15');

try {
    $pdo->beginTransaction();

    $superEmail = env_value('ADMIN_EMAIL', 'demo@clinic.local');
    $superName = env_value('ADMIN_NAME', 'Demo Account');
    $superPhone = admin_normalize_phone(env_value('ADMIN_PHONE', '5550100000'));
    $superRole = admin_normalize_role(env_value('ADMIN_ROLE', 'super_admin'));
    if ($superRole === '') {
        $superRole = 'super_admin';
    }

    seed_insert_admin($pdo, $superName, $superPhone, $superEmail, $demoPassword, $superRole);
    seed_insert_admin(
        $pdo,
        'Front Desk',
        '5550100001',
        'staff@clinic.local',
        $demoPassword,
        'admin'
    );

    $maya = seed_insert_patient($pdo, 'Maya Ortiz', '5550101001');
    $jordan = seed_insert_patient($pdo, 'Jordan Lee', '5550101002');
    $samira = seed_insert_patient($pdo, 'Samira Hassan', '5550101003');
    $chris = seed_insert_patient($pdo, 'Chris Nguyen', '5550101004');
    $alex = seed_insert_patient($pdo, 'Alex Rivera', '5550101005');
    $priya = seed_insert_patient($pdo, 'Priya Shah', '5550101006');
    $devon = seed_insert_patient($pdo, 'Devon Brooks', '5550101007');
    $elena = seed_insert_patient($pdo, 'Elena Vargas', '5550101008');
    $nate = seed_insert_patient($pdo, 'Nate Coleman', '5550101009');
    $hana = seed_insert_patient($pdo, 'Hana Kim', '5550101010');

    $remindedAt = $mainSunday->setTime(8, 15)->format('Y-m-d H:i:s');

    // Main Sunday desk: confirmed / no reply / reminder sent / cancelled / second no-reply
    seed_insert_appointment($pdo, $maya, $mainKey, '09:00', 'Follow-up', 'Scheduled', 'Confirmed', 'Sent', $remindedAt);
    seed_insert_appointment($pdo, $jordan, $mainKey, '09:30', 'New patient', 'Scheduled', 'Not Sent', null, null);
    seed_insert_appointment($pdo, $samira, $mainKey, '10:00', 'Follow-up', 'Scheduled', 'Pending', 'Sent', $remindedAt);
    seed_insert_appointment($pdo, $chris, $mainKey, '10:30', 'New patient', 'Cancelled', 'Declined', 'Sent', $remindedAt);
    seed_insert_appointment($pdo, $alex, $mainKey, '11:00', 'Follow-up', 'Scheduled', 'Not Sent', null, null);

    // Later Sundays
    seed_insert_appointment(
        $pdo,
        $priya,
        $nextSunday->format('Y-m-d'),
        '09:00',
        'New patient',
        'Scheduled',
        'Not Sent',
        null,
        null
    );
    seed_insert_appointment(
        $pdo,
        $devon,
        $nextSunday->format('Y-m-d'),
        '14:00',
        'Follow-up',
        'Scheduled',
        'Confirmed',
        'Sent',
        $remindedAt
    );
    seed_insert_appointment(
        $pdo,
        $elena,
        $laterSunday->format('Y-m-d'),
        '10:15',
        'Follow-up',
        'Scheduled',
        'Not Sent',
        null,
        null
    );

    // Past Sunday completed (patients list / history flavor)
    seed_insert_appointment(
        $pdo,
        $maya,
        $pastSunday->format('Y-m-d'),
        '14:00',
        'Follow-up',
        'Completed',
        'Confirmed',
        'Sent',
        $pastSunday->setTime(9, 0)->format('Y-m-d H:i:s')
    );

    // Waitlist people for fill / double-book demos
    seed_insert_waitlist($pdo, $nate['id'], 'New patient', 'Waiting');
    seed_insert_waitlist($pdo, $hana['id'], 'Follow-up', 'Held');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Seed insert failed: " . $e->getMessage() . "\n");
    exit(1);
}

$counts = [
    'Admin' => (int) $pdo->query('SELECT COUNT(*) FROM Admin')->fetchColumn(),
    'Patient' => (int) $pdo->query('SELECT COUNT(*) FROM Patient')->fetchColumn(),
    'Schedule' => (int) $pdo->query('SELECT COUNT(*) FROM Schedule')->fetchColumn(),
    'Appointment' => (int) $pdo->query('SELECT COUNT(*) FROM Appointment')->fetchColumn(),
    'Waitlist' => (int) $pdo->query('SELECT COUNT(*) FROM Waitlist')->fetchColumn(),
];

echo "Done.\n";
foreach ($counts as $table => $count) {
    echo sprintf("  %-12s %d\n", $table, $count);
}
echo "\nLog in with:\n";
echo '  Super admin: ' . $superEmail . ' / ' . $demoPassword . "\n";
echo "  Staff admin: staff@clinic.local / {$demoPassword}\n";
echo "\nDemo beats:\n";
echo "  Desk (next/open Sunday) — confirmed, no-reply, reminder_sent, cancelled, fill-from-waitlist\n";
echo "  Patients — upcoming / unconfirmed / cancelled filters\n";
echo "  Availability — Sundays only, 9:00–3:00, closed holds at 12:00 and 12:15 on main Sunday\n";
echo "  Waitlist — Waiting + Held\n";
echo "  Booking — leftover Open slots on upcoming Sundays\n";
