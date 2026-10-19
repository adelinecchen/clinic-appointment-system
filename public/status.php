<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/preview_data.php';
require_once __DIR__ . '/../src/patient_copy.php';
require_once __DIR__ . '/../src/patient_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$booking = $_SESSION['booking_preview'] ?? null;
if (!is_array($booking)) {
    $booking = null;
}

$lookup = '';
$found = null;
$showPicker = isset($_GET['change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'find') {
        $lookup = trim((string) ($_POST['lookup_phone'] ?? ''));
        $digits = preg_replace('/\D+/', '', $lookup) ?? '';
        $bookedDigits = preg_replace('/\D+/', '', (string) ($booking['patient_phone'] ?? '')) ?? '';
        if ($booking !== null && $digits !== '' && $digits === $bookedDigits) {
            $found = $booking;
        } elseif ($digits === '7575551234') {
            $openDays = preview_open_dates();
            $day = $openDays[0] ?? ['full' => 'October 25, 2026', 'value' => '2026-10-25'];
            $found = [
                'visit_type' => 'wellness',
                'visit_label' => 'Wellness exam',
                'date' => $day['full'],
                'date_value' => $day['value'],
                'time' => '9:00 AM',
                'time_value' => '09:00',
                'patient_name' => 'Sarah Smith',
                'patient_phone' => '(757) 555-1234',
                'people' => [
                    ['name' => 'Sarah Smith', 'time' => '9:00 AM', 'time_value' => '09:00'],
                    ['name' => 'Mark Smith', 'time' => '9:15 AM', 'time_value' => '09:15'],
                ],
                'status' => 'booked',
            ];
            $_SESSION['booking_preview'] = $found;
            $booking = $found;
        }
    } elseif ($action === 'cancel' && $booking !== null) {
        $booking['status'] = 'canceled';
        $_SESSION['booking_preview'] = $booking;
        $found = $booking;
    } elseif ($action === 'reschedule' && $booking !== null) {
        $checked = preview_validate_booking([
            'visit_type' => $booking['visit_type'] ?? 'wellness',
            'appointment_date' => $_POST['appointment_date'] ?? '',
            'appointment_time' => $_POST['appointment_time'] ?? '',
            'patient_name' => $booking['patient_name'] ?? '',
            'patient_phone' => $booking['patient_phone'] ?? '',
            'party_name' => array_map(
                static fn (array $person): string => (string) $person['name'],
                array_slice($booking['people'] ?? [], 1)
            ),
        ]);
        if ($checked['errors'] === []) {
            $_SESSION['booking_preview'] = $checked['booking'];
            header('Location: confirmation.php');
            exit;
        }
        $found = $booking;
        $showPicker = true;
    }
} elseif ($booking !== null && ($booking['status'] ?? '') !== 'canceled') {
    $found = $booking;
    $lookup = (string) $booking['patient_phone'];
}

patient_page_start(patient_text('status_title'), 'status');
?>
    <section class="result">
        <div class="result-card">
            <h1><?php echo h(patient_text('status_title')); ?></h1>
            <p class="result-lede"><?php echo h(patient_text('status_lede')); ?></p>
            <form class="lookup" method="post" action="status.php">
                <input type="hidden" name="action" value="find">
                <label>
                    <span class="sr-only"><?php echo h(patient_text('phone')); ?></span>
                    <input name="lookup_phone" type="tel" inputmode="tel" required placeholder="(757) 555-0142" value="<?php echo h($lookup); ?>">
                </label>
                <button class="book-submit" type="submit"><?php echo h(patient_text('find')); ?></button>
            </form>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $found === null): ?>
                <p class="missing"><?php echo h(patient_text('missing')); ?> <a href="index.php"><?php echo h(patient_text('book_instead')); ?></a></p>
            <?php endif; ?>

            <?php if ($found !== null && ($found['status'] ?? '') === 'canceled'): ?>
                <div class="visit-summary">
                    <h2><?php echo h(patient_text('canceled')); ?></h2>
                    <p><?php echo h(patient_text('canceled_note')); ?></p>
                    <a class="book-submit" href="index.php"><?php echo h(patient_text('book_button')); ?></a>
                </div>
            <?php elseif ($found !== null): ?>
                <div class="visit-summary">
                    <p class="coming"><?php echo h(patient_text('coming')); ?></p>
                    <h2><?php echo h((string) $found['date']); ?></h2>
                    <p><?php echo h((string) $found['visit_label']); ?></p>
                    <ul class="people">
                        <?php foreach ($found['people'] ?? [] as $person): ?>
                            <li><?php echo h((string) $person['time']); ?> · <?php echo h((string) $person['name']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="status.php">
                        <input type="hidden" name="action" value="cancel">
                        <button class="text-button" type="submit"><?php echo h(patient_text('cancel')); ?></button>
                    </form>
                    <p><a href="status.php?change=1"><?php echo h(patient_text('reschedule')); ?></a></p>
                </div>
                <?php if ($showPicker): ?>
                    <form class="picker" method="post" action="status.php">
                        <input type="hidden" name="action" value="reschedule">
                        <fieldset>
                            <legend><?php echo h(patient_text('reschedule')); ?></legend>
                            <div class="pills">
                                <?php foreach (preview_open_dates() as $day): ?>
                                    <label class="pill">
                                        <input type="radio" name="appointment_date" value="<?php echo h($day['value']); ?>"<?php echo ($found['date_value'] ?? '') === $day['value'] ? ' checked' : ''; ?>>
                                        <span><?php echo h($day['label']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="pills time-pills">
                                <?php
                                $pickerDays = array_column(preview_open_dates(), 'value');
                                $pickerDate = (string) ($found['date_value'] ?? '');
                                if (!in_array($pickerDate, $pickerDays, true)) {
                                    $pickerDate = $pickerDays[0] ?? '';
                                }
                                foreach ($pickerDate === '' ? [] : preview_open_slots($pickerDate) as $slot):
                                ?>
                                    <label class="pill">
                                        <input type="radio" name="appointment_time" value="<?php echo h($slot['value']); ?>"<?php echo ($found['time_value'] ?? '') === $slot['value'] ? ' checked' : ''; ?>>
                                        <span><?php echo h($slot['label']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <script type="application/json" id="slot-data"><?php
                                $slotMap = [];
                                foreach (preview_clinic_dates() as $clinicDay) {
                                    $slotMap[$clinicDay['value']] = preview_visit_slots($clinicDay['value']);
                                }
                                echo json_encode($slotMap, JSON_UNESCAPED_SLASHES);
                            ?></script>
                        </fieldset>
                        <button class="book-submit" type="submit"><?php echo h(patient_text('save_change')); ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
<script src="assets/js/booking.js"></script>
<?php
patient_page_end();
