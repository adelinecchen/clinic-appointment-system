<?php

declare(strict_types=1);

/**
 * Fictional preview content for the patient site and staff desk.
 * This is not a database schema. Nothing here is stored.
 */

function preview_clinic(): array
{
    $configured = env_value('APP_NAME');
    $name = $configured !== '' && $configured !== 'Clinic Appointment System'
        ? $configured
        : 'Global Health and Education clinic';

    return [
        'name' => $name,
        'short' => 'Global Health',
        'doctor' => 'Dr. Loknath Shandilya',
        'phone' => '804-555-0123',
        'address' => '4440 Springfield Road, Glen Allen, Virginia',
        'address_note' => 'Suite 101. The sign outside says Surya P. Dhakar DDS.',
    ];
}

function preview_availability_path(): string
{
    return dirname(__DIR__) . '/storage/availability.json';
}

function preview_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
}

function preview_suggested_open_dates(): array
{
    $start = preview_today();
    if ($start->format('w') !== '0') {
        $start = $start->modify('next sunday');
    }

    $dates = [];
    for ($index = 0; $index < 4; $index++) {
        $dates[] = $start->modify('+' . $index . ' weeks')->format('Y-m-d');
    }

    return $dates;
}

function preview_string_list(mixed $items): array
{
    if (!is_array($items)) {
        return [];
    }

    return array_values(array_filter($items, 'is_string'));
}

function preview_availability(): array
{
    $state = [
        'open_dates' => preview_suggested_open_dates(),
        'closed_slots' => [],
        'opened_slots' => [],
    ];
    $path = preview_availability_path();
    if (!is_readable($path)) {
        return $state;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $state;
    }

    $state['closed_slots'] = preview_string_list($decoded['closed_slots'] ?? []);
    $state['opened_slots'] = preview_string_list($decoded['opened_slots'] ?? []);
    if (array_key_exists('open_dates', $decoded)) {
        $state['open_dates'] = preview_string_list($decoded['open_dates']);
    } else {
        $closed = preview_string_list($decoded['closed_dates'] ?? []);
        $state['open_dates'] = array_values(array_filter(
            preview_suggested_open_dates(),
            static fn (string $date): bool => !in_array($date, $closed, true)
        ));
    }

    sort($state['open_dates']);

    return $state;
}

function preview_date_is_choosable(string $date): bool
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/New_York'));
    if ($day === false || $day->format('Y-m-d') !== $date) {
        return false;
    }

    $today = preview_today();
    $limit = $today->modify('+6 months');

    return $day >= $today && $day <= $limit;
}

function preview_save_availability(array $state): bool
{
    $directory = dirname(preview_availability_path());
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $handle = fopen(preview_availability_path(), 'c+');
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

function preview_default_blocked_times(): array
{
    return ['10:30'];
}

function preview_relationships(): array
{
    return [
        'Child',
        'Spouse or partner',
        'Parent',
        'Sibling',
        'Other relative',
    ];
}

function preview_slot_label(string $value): string
{
    $time = DateTimeImmutable::createFromFormat('!H:i', $value);

    return $time === false ? $value : $time->format('g:i A');
}

function preview_day_record(string $value): ?array
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($day === false || $day->format('Y-m-d') !== $value) {
        return null;
    }

    return [
        'value' => $value,
        'label' => $day->format('M j'),
        'full' => $day->format('F j, Y'),
    ];
}

function preview_open_dates(): array
{
    $today = preview_today()->format('Y-m-d');
    $days = [];
    foreach (preview_availability()['open_dates'] as $value) {
        if ($value < $today) {
            continue;
        }
        $record = preview_day_record($value);
        if ($record !== null) {
            $days[] = $record;
        }
    }

    return $days;
}

function preview_clinic_dates(): array
{
    return preview_open_dates();
}

function preview_time_is_open(string $date, string $time): bool
{
    $state = preview_availability();
    $key = $date . '|' . $time;
    if (in_array($key, $state['closed_slots'], true)) {
        return false;
    }
    if (in_array($time, preview_default_blocked_times(), true) && !in_array($key, $state['opened_slots'], true)) {
        return false;
    }

    return true;
}

function preview_visit_slots(string $date): array
{
    $slots = [];
    $cursor = new DateTimeImmutable('09:00');
    $last = new DateTimeImmutable('11:00');

    while ($cursor <= $last) {
        $value = $cursor->format('H:i');
        $slots[] = [
            'value' => $value,
            'label' => $cursor->format('g:i A'),
            'available' => preview_time_is_open($date, $value),
        ];
        $cursor = $cursor->modify('+15 minutes');
    }

    return $slots;
}

function preview_open_slots(string $date): array
{
    return array_values(array_filter(
        preview_visit_slots($date),
        static fn (array $slot): bool => $slot['available']
    ));
}

function preview_fit_count(string $start, string $date): int
{
    $slots = preview_visit_slots($date);
    $index = null;
    foreach ($slots as $position => $slot) {
        if ($slot['value'] === $start) {
            $index = $position;
            break;
        }
    }
    if ($index === null || !$slots[$index]['available']) {
        return 0;
    }

    $count = 0;
    for ($position = $index; $position < count($slots); $position++) {
        if (!$slots[$position]['available']) {
            break;
        }
        $count++;
    }

    return $count;
}

function preview_slots_from(string $start, int $count, string $date): ?array
{
    if ($count < 1 || preview_fit_count($start, $date) < $count) {
        return null;
    }

    $slots = preview_visit_slots($date);
    $index = null;
    foreach ($slots as $position => $slot) {
        if ($slot['value'] === $start) {
            $index = $position;
            break;
        }
    }
    if ($index === null) {
        return null;
    }

    return array_slice($slots, $index, $count);
}

function preview_booking_defaults(): array
{
    return [
        'visit_type' => 'wellness',
        'appointment_date' => preview_open_dates()[0]['value'] ?? '',
        'appointment_time' => '10:00',
        'patient_name' => '',
        'patient_phone' => '',
        'party' => [],
    ];
}

function preview_validate_booking(array $input): array
{
    $values = preview_booking_defaults();
    $errors = [];

    $visit = (string) ($input['visit_type'] ?? '');
    if ($visit === 'wellness' || $visit === 'follow_up') {
        $values['visit_type'] = $visit;
    } else {
        $errors['visit_type'] = 'visit';
    }

    $date = trim((string) ($input['appointment_date'] ?? ''));
    $matchedDay = null;
    foreach (preview_open_dates() as $day) {
        if ($day['value'] === $date) {
            $matchedDay = $day;
            break;
        }
    }
    if ($matchedDay === null) {
        $errors['appointment_date'] = 'date';
    } else {
        $values['appointment_date'] = $date;
    }

    $time = (string) ($input['appointment_time'] ?? '');
    $matchedSlot = null;
    foreach (preview_open_slots($date) as $slot) {
        if ($slot['value'] === $time) {
            $matchedSlot = $slot;
            break;
        }
    }
    if ($matchedSlot === null) {
        $errors['appointment_time'] = 'time';
    } else {
        $values['appointment_time'] = $time;
    }

    $name = trim((string) ($input['patient_name'] ?? ''));
    $values['patient_name'] = $name;
    if (preview_text_length($name) < 2 || preview_text_length($name) > 80) {
        $errors['patient_name'] = 'name';
    }

    $phone = trim((string) ($input['patient_phone'] ?? ''));
    $values['patient_phone'] = $phone;
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) !== 10) {
        $errors['patient_phone'] = 'phone';
    }

    $partyInput = $input['party_name'] ?? [];
    if (!is_array($partyInput)) {
        $partyInput = [];
    }
    $party = [];
    foreach ($partyInput as $partyName) {
        $partyName = trim((string) $partyName);
        if ($partyName === '') {
            continue;
        }
        if (preview_text_length($partyName) < 2 || preview_text_length($partyName) > 80) {
            $errors['party_name'] = 'party_name';
        }
        $party[] = $partyName;
    }
    $values['party'] = $party;

    $people = [];
    if ($matchedSlot !== null && !isset($errors['patient_name'])) {
        $assigned = preview_slots_from($matchedSlot['value'], count($party) + 1, $date);
        if ($assigned === null) {
            $errors['party'] = (string) preview_fit_count($matchedSlot['value'], $date);
        } elseif ($errors === []) {
            $names = array_merge([$name], $party);
            foreach ($assigned as $index => $slot) {
                $people[] = [
                    'name' => $names[$index],
                    'time' => $slot['label'],
                    'time_value' => $slot['value'],
                ];
            }
        }
    }

    $booking = null;
    if ($errors === []) {
        $booking = [
            'visit_type' => $values['visit_type'],
            'visit_label' => $values['visit_type'] === 'wellness' ? 'Wellness exam' : 'Follow-up visit',
            'date' => $matchedDay['full'],
            'date_value' => $matchedDay['value'],
            'time' => $people[0]['time'],
            'time_value' => $people[0]['time_value'],
            'patient_name' => $name,
            'patient_phone' => preview_format_phone($digits),
            'people' => $people,
            'status' => 'booked',
        ];
    }

    return [
        'errors' => $errors,
        'values' => $values,
        'booking' => $booking,
    ];
}

function preview_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function preview_format_phone(string $digits): string
{
    return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
}

function preview_desk_date(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-04');
}

function preview_appointment_seed(): array
{
    return [
        ['id' => 'sarah', 'time' => '9:00 AM', 'time_value' => '09:00', 'patient' => 'Sarah Smith', 'visit' => 'Follow-up visit', 'phone' => '(757) 555-1234', 'phone_digits' => '7575551234', 'status' => 'confirmed', 'note' => ''],
        ['id' => 'mark', 'time' => '9:15 AM', 'time_value' => '09:15', 'patient' => 'Mark Smith', 'visit' => 'New patient', 'phone' => 'Sarah’s phone', 'phone_digits' => '7575551234', 'status' => 'confirmed', 'note' => 'Same booking as Sarah. His own 15 minutes.'],
        ['id' => 'george', 'time' => '9:45 AM', 'time_value' => '09:45', 'patient' => 'George Marcus', 'visit' => 'New patient', 'phone' => '(804) 679-1210', 'phone_digits' => '8046791210', 'status' => 'no_reply', 'note' => ''],
        ['id' => 'amina', 'time' => '10:15 AM', 'time_value' => '10:15', 'patient' => 'Amina Cole', 'visit' => 'Wellness exam', 'phone' => '(804) 555-0148', 'phone_digits' => '8045550148', 'status' => 'reminder_sent', 'note' => ''],
        ['id' => 'luis', 'time' => '10:45 AM', 'time_value' => '10:45', 'patient' => 'Luis Ortega', 'visit' => 'Wellness exam', 'phone' => '(757) 555-0190', 'phone_digits' => '7575550190', 'status' => 'no_reply', 'note' => ''],
        ['id' => 'carlos', 'time' => '11:15 AM', 'time_value' => '11:15', 'patient' => 'Carlos Ruiz', 'visit' => 'Follow-up visit', 'phone' => '(804) 555-0114', 'phone_digits' => '8045550114', 'status' => 'cancelled', 'note' => 'Cancelled by patient'],
    ];
}

function preview_waitlist_seed(): array
{
    return [
        ['id' => 'elena', 'name' => 'Elena Park', 'phone' => '(757) 555-0166', 'phone_digits' => '7575550166', 'visit' => 'Wellness exam', 'note' => 'Can take a morning block.'],
        ['id' => 'james', 'name' => 'James Okonkwo', 'phone' => '(804) 555-0177', 'phone_digits' => '8045550177', 'visit' => 'Follow-up visit', 'note' => 'Asked for the first open 15 minutes.'],
    ];
}

function preview_appointments(): array
{
    $rows = preview_appointment_seed();
    $fills = [];
    $reminded = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $fills = $_SESSION['desk_fills'] ?? [];
        $reminded = $_SESSION['desk_reminded'] ?? [];
    }

    $waiting = [];
    foreach (preview_waitlist_seed() as $person) {
        $waiting[$person['id']] = $person;
    }

    $appointments = [];
    foreach ($rows as $row) {
        $filledFrom = $fills[$row['id']] ?? '';
        if ($filledFrom !== '' && isset($waiting[$filledFrom])) {
            $person = $waiting[$filledFrom];
            $row['patient'] = $person['name'];
            $row['visit'] = $person['visit'];
            $row['phone'] = $person['phone'];
            $row['phone_digits'] = $person['phone_digits'];
            $row['status'] = in_array($row['id'], $reminded, true) ? 'reminder_sent' : 'no_reply';
            $row['note'] = 'Filled from the waitlist';
        } elseif ($row['status'] === 'no_reply' && in_array($row['id'], $reminded, true)) {
            $row['status'] = 'reminder_sent';
        }

        $row['status_label'] = preview_status_label($row['status']);
        $appointments[] = $row;
    }

    return $appointments;
}

function preview_status_label(string $status): string
{
    return match ($status) {
        'confirmed' => 'Confirmed',
        'no_reply' => 'No reply',
        'reminder_sent' => 'Reminder sent',
        'pending' => 'No reply',
        'cancelled' => 'Cancelled',
        default => $status,
    };
}

function preview_appointment_counts(array $appointments): array
{
    $counts = [
        'booked' => count($appointments),
        'confirmed' => 0,
        'not_confirmed' => 0,
        'cancelled' => 0,
    ];

    foreach ($appointments as $appointment) {
        if ($appointment['status'] === 'confirmed') {
            $counts['confirmed']++;
        } elseif ($appointment['status'] === 'cancelled') {
            $counts['cancelled']++;
        } else {
            $counts['not_confirmed']++;
        }
    }

    return $counts;
}

function preview_unconfirmed(array $appointments): array
{
    return array_values(array_filter(
        $appointments,
        static fn (array $appointment): bool => in_array($appointment['status'], ['no_reply', 'reminder_sent'], true)
    ));
}

function preview_patients(): array
{
    $patients = [];
    foreach (preview_appointment_seed() as $appointment) {
        $patients[] = [
            'name' => $appointment['patient'],
            'phone' => $appointment['phone'],
            'visit' => $appointment['visit'],
            'household' => $appointment['note'] !== '' ? $appointment['note'] : 'On the sample clinic day',
        ];
    }

    return $patients;
}

function preview_waitlist(): array
{
    $used = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $used = array_values($_SESSION['desk_fills'] ?? []);
    }

    $people = [];
    foreach (preview_waitlist_seed() as $person) {
        if (!in_array($person['id'], $used, true)) {
            $people[] = $person;
        }
    }

    return $people;
}

function preview_reminder_text(array $appointment): string
{
    $clinic = preview_clinic();

    return 'Hi ' . $appointment['patient'] . ', this is ' . $clinic['name'] . '. Please tap to confirm your visit at ' . $appointment['time'] . '.';
}

function preview_notifications(): array
{
    return [
        [
            'title' => 'Booking confirmation',
            'audience' => 'Sarah Smith, when the visit is booked · Sep 22, 9:58 a.m.',
            'message' => 'Global Health and Education clinic booked Sarah Smith for 9:00 a.m. and Mark Smith for the next 15 minutes, 9:15 a.m. Reply YES to confirm, or open the link in this text.',
        ],
        [
            'title' => '2-day notification',
            'audience' => 'Sarah Smith, two days before · Sep 25, 8:00 a.m.',
            'message' => 'Please confirm Sarah at 9:00 a.m. and Mark at 9:15 a.m. Each person has their own 15 minutes. Reply YES or NO, or use the confirmation link.',
        ],
        [
            'title' => '1-day notification',
            'audience' => 'Sarah Smith, one day before if there is no reply · Sep 26, 8:00 a.m.',
            'message' => 'We still need a confirmation for tomorrow. The sample marks this text confirmed. If there is still no reply, the desk calls on the day of the visit.',
        ],
    ];
}
