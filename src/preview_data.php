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

function preview_block_minutes(): array
{
    return [10, 15, 20, 30, 45, 60];
}

function preview_default_day_hours(): array
{
    return [
        'start' => '09:00',
        'end' => '17:00',
        'block' => 15,
    ];
}

function preview_legacy_day_hours(): array
{
    return [
        'start' => '09:00',
        'end' => '11:00',
        'block' => 15,
    ];
}

function preview_date_looks_valid(string $date): bool
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/New_York'));

    return $day !== false && $day->format('Y-m-d') === $date;
}

function preview_normalize_time(string $value): ?string
{
    foreach (['!H:i', '!H:i:s', '!G:i'] as $format) {
        $time = DateTimeImmutable::createFromFormat($format, $value);
        if ($time !== false) {
            return $time->format('H:i');
        }
    }

    return null;
}

function preview_generated_slot_values(string $start, string $end, int $block): array
{
    $cursor = DateTimeImmutable::createFromFormat('!H:i', $start);
    $close = DateTimeImmutable::createFromFormat('!H:i', $end);
    if ($cursor === false || $close === false || $block < 1) {
        return [];
    }

    $values = [];
    while (true) {
        $finish = $cursor->modify('+' . $block . ' minutes');
        if ($finish > $close) {
            break;
        }
        $values[] = $cursor->format('H:i');
        $cursor = $finish;
    }

    return $values;
}

function preview_normalize_hours(mixed $hours, ?array $fallback = null): ?array
{
    if (!is_array($hours)) {
        return $fallback;
    }

    $start = preview_normalize_time((string) ($hours['start'] ?? ''));
    $end = preview_normalize_time((string) ($hours['end'] ?? ''));
    $block = (int) ($hours['block'] ?? 0);
    if ($start === null || $end === null || $start >= $end || !in_array($block, preview_block_minutes(), true)) {
        return $fallback;
    }
    if (preview_generated_slot_values($start, $end, $block) === []) {
        return $fallback;
    }

    return [
        'start' => $start,
        'end' => $end,
        'block' => $block,
    ];
}

function preview_hours_from_input(array $input): array
{
    $hours = preview_normalize_hours([
        'start' => (string) ($input['start'] ?? ''),
        'end' => (string) ($input['end'] ?? ''),
        'block' => (string) ($input['block'] ?? ''),
    ]);
    if ($hours !== null) {
        return ['hours' => $hours, 'error' => ''];
    }

    $start = preview_normalize_time((string) ($input['start'] ?? ''));
    $end = preview_normalize_time((string) ($input['end'] ?? ''));
    if ($start === null || $end === null || $start >= $end) {
        return ['hours' => null, 'error' => 'hours'];
    }

    return ['hours' => null, 'error' => 'blocks'];
}

function preview_sync_open_dates(array $state): array
{
    $days = [];
    foreach ($state['days'] ?? [] as $date => $hours) {
        if (!is_string($date) || !preview_date_looks_valid($date)) {
            continue;
        }
        $normalized = preview_normalize_hours($hours);
        if ($normalized !== null) {
            $days[$date] = $normalized;
        }
    }
    ksort($days);
    $state['days'] = $days;
    $state['open_dates'] = array_keys($days);

    return $state;
}

function preview_day_hours(string $date): ?array
{
    $hours = preview_availability()['days'][$date] ?? null;

    return is_array($hours) ? $hours : null;
}

function preview_hours_suggestion(?string $date = null): array
{
    if ($date !== null) {
        $current = preview_day_hours($date);
        if ($current !== null) {
            return $current;
        }
    }

    $days = preview_availability()['days'];
    if ($days !== []) {
        return (array) end($days);
    }

    return preview_default_day_hours();
}

function preview_hours_summary(array $hours): string
{
    $start = preview_slot_label((string) $hours['start']);
    $end = preview_slot_label((string) $hours['end']);
    $block = (int) $hours['block'];

    return $start . ' to ' . $end . ', ' . $block . '-minute visits';
}

function preview_availability(): array
{
    $state = [
        'days' => [],
        'open_dates' => [],
        'closed_slots' => [],
        'opened_slots' => [],
    ];
    $path = preview_availability_path();
    if (!is_readable($path)) {
        foreach (preview_suggested_open_dates() as $date) {
            $state['days'][$date] = preview_default_day_hours();
        }

        return preview_sync_open_dates($state);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        foreach (preview_suggested_open_dates() as $date) {
            $state['days'][$date] = preview_default_day_hours();
        }

        return preview_sync_open_dates($state);
    }

    $state['closed_slots'] = preview_string_list($decoded['closed_slots'] ?? []);
    $state['opened_slots'] = preview_string_list($decoded['opened_slots'] ?? []);

    $openDates = [];
    if (array_key_exists('open_dates', $decoded)) {
        $openDates = preview_string_list($decoded['open_dates']);
    } else {
        $closed = preview_string_list($decoded['closed_dates'] ?? []);
        $openDates = array_values(array_filter(
            preview_suggested_open_dates(),
            static fn (string $date): bool => !in_array($date, $closed, true)
        ));
    }

    $storedDays = is_array($decoded['days'] ?? null) ? $decoded['days'] : [];
    foreach ($storedDays as $date => $hours) {
        if (!is_string($date) || !preview_date_looks_valid($date)) {
            continue;
        }
        $normalized = preview_normalize_hours($hours);
        if ($normalized !== null) {
            $state['days'][$date] = $normalized;
        }
    }
    foreach ($openDates as $date) {
        if (preview_date_looks_valid($date) && !isset($state['days'][$date])) {
            $state['days'][$date] = preview_legacy_day_hours();
        }
    }

    return preview_sync_open_dates($state);
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

    $state = preview_sync_open_dates($state);
    unset($state['opened_slots']);

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

function preview_visit_label(string $type): string
{
    return $type === 'follow_up' ? 'Follow-up visit' : 'First Visit';
}

function preview_booking_months(string $selected): array
{
    $openValues = array_column(preview_open_dates(), 'value');
    if ($openValues === []) {
        return [];
    }

    if ($selected === '' || !in_array($selected, $openValues, true)) {
        $selected = $openValues[0];
    }

    $zone = new DateTimeZone('America/New_York');
    $first = new DateTimeImmutable(substr(min($openValues), 0, 7) . '-01', $zone);
    $last = new DateTimeImmutable(substr(max($openValues), 0, 7) . '-01', $zone);
    $today = preview_today()->format('Y-m-d');
    $months = [];
    $cursor = $first;

    while ($cursor <= $last) {
        $days = [];
        $daysInMonth = (int) $cursor->format('t');
        for ($number = 1; $number <= $daysInMonth; $number++) {
            $day = $cursor->setDate((int) $cursor->format('Y'), (int) $cursor->format('n'), $number);
            $value = $day->format('Y-m-d');
            $days[] = [
                'number' => $number,
                'value' => $value,
                'open' => in_array($value, $openValues, true),
                'selected' => $value === $selected,
                'past' => $value < $today,
            ];
        }
        $months[] = [
            'value' => $cursor->format('Y-m'),
            'label' => $cursor->format('F Y'),
            'pad' => (int) $cursor->format('w'),
            'days' => $days,
        ];
        $cursor = $cursor->modify('first day of next month');
    }

    return $months;
}

function preview_message_path(): string
{
    return dirname(__DIR__) . '/storage/messages.json';
}

function preview_message_defaults(): array
{
    return [
        'booking' => 'Hi {name}, this is {clinic}. We booked your visit on {date} at {time}. Reply YES to confirm, or open the link in this text.',
        'two_day' => 'Hi {name}, this is {clinic}. Please confirm your visit on {date} at {time}. Reply YES or NO, or use the confirmation link.',
        'one_day' => 'Hi {name}, this is {clinic}. We still need a confirmation for tomorrow at {time}. Reply YES or tap the link. If we do not hear back, the desk will call.',
        'follow_up' => 'Hi {name}, this is {clinic}. Please tap to confirm your visit at {time}.',
    ];
}

function preview_message_templates(): array
{
    $defaults = preview_message_defaults();
    $path = preview_message_path();
    if (!is_readable($path)) {
        return $defaults;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    foreach ($defaults as $key => $text) {
        $custom = trim((string) ($decoded[$key] ?? ''));
        if ($custom !== '') {
            $defaults[$key] = $custom;
        }
    }

    return $defaults;
}

function preview_save_message_templates(array $templates): bool
{
    $clean = [];
    foreach (preview_message_defaults() as $key => $default) {
        $text = trim((string) ($templates[$key] ?? ''));
        $clean[$key] = $text !== '' ? $text : $default;
    }

    $directory = dirname(preview_message_path());
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $handle = fopen(preview_message_path(), 'c+');
    if ($handle === false) {
        return false;
    }

    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    $written = fwrite($handle, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $written !== false;
}

function preview_fill_message(string $template, array $vars): string
{
    $clinic = preview_clinic();
    $replacements = [
        '{clinic}' => $clinic['name'],
        '{name}' => (string) ($vars['name'] ?? 'Sarah Smith'),
        '{date}' => (string) ($vars['date'] ?? 'October 25, 2026'),
        '{time}' => (string) ($vars['time'] ?? '9:00 AM'),
        '{phone}' => (string) ($vars['phone'] ?? '(757) 555-1234'),
    ];

    return strtr($template, $replacements);
}

function preview_time_is_open(string $date, string $time): bool
{
    $hours = preview_day_hours($date);
    if ($hours === null) {
        return false;
    }
    if (!in_array($time, preview_generated_slot_values($hours['start'], $hours['end'], (int) $hours['block']), true)) {
        return false;
    }

    return !in_array($date . '|' . $time, preview_availability()['closed_slots'], true);
}

function preview_visit_slots(string $date): array
{
    $hours = preview_day_hours($date);
    if ($hours === null) {
        return [];
    }

    $slots = [];
    foreach (preview_generated_slot_values($hours['start'], $hours['end'], (int) $hours['block']) as $value) {
        $slots[] = [
            'value' => $value,
            'label' => preview_slot_label($value),
            'available' => preview_time_is_open($date, $value),
        ];
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
        'appointment_time' => preview_open_slots(preview_open_dates()[0]['value'] ?? '')[0]['value'] ?? '',
        'patient_name' => '',
        'patient_phone' => '',
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

    $people = [];
    if ($matchedSlot !== null && $errors === []) {
        $people[] = [
            'name' => $name,
            'time' => $matchedSlot['label'],
            'time_value' => $matchedSlot['value'],
        ];
    }

    $booking = null;
    if ($errors === []) {
        $booking = [
            'visit_type' => $values['visit_type'],
            'visit_label' => preview_visit_label($values['visit_type']),
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
        ['id' => 'amina', 'time' => '10:15 AM', 'time_value' => '10:15', 'patient' => 'Amina Cole', 'visit' => 'First Visit', 'phone' => '(804) 555-0148', 'phone_digits' => '8045550148', 'status' => 'reminder_sent', 'note' => ''],
        ['id' => 'luis', 'time' => '10:45 AM', 'time_value' => '10:45', 'patient' => 'Luis Ortega', 'visit' => 'First Visit', 'phone' => '(757) 555-0190', 'phone_digits' => '7575550190', 'status' => 'no_reply', 'note' => ''],
        ['id' => 'carlos', 'time' => '11:15 AM', 'time_value' => '11:15', 'patient' => 'Carlos Ruiz', 'visit' => 'Follow-up visit', 'phone' => '(804) 555-0114', 'phone_digits' => '8045550114', 'status' => 'cancelled', 'note' => 'Cancelled by patient'],
    ];
}

function preview_waitlist_seed(): array
{
    return [
        ['id' => 'elena', 'name' => 'Elena Park', 'phone' => '(757) 555-0166', 'phone_digits' => '7575550166', 'visit' => 'First Visit', 'note' => 'Can take a morning block.'],
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
    $seen = [];
    foreach (preview_upcoming_appointments() as $appointment) {
        $key = $appointment['phone_digits'] !== '' ? $appointment['phone_digits'] : $appointment['patient'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $patients[] = [
            'name' => $appointment['patient'],
            'phone' => $appointment['phone'],
            'visit' => $appointment['visit'],
            'household' => $appointment['note'] !== '' ? $appointment['note'] : 'Upcoming visit',
        ];
    }

    return $patients;
}

function preview_session_string_list(string $key): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }

    return preview_string_list($_SESSION[$key] ?? []);
}

function preview_session_map(string $key): array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !is_array($_SESSION[$key] ?? null)) {
        return [];
    }

    return $_SESSION[$key];
}

function preview_upcoming_templates(): array
{
    return [
        ['id' => 'up-sarah', 'patient' => 'Sarah Smith', 'visit' => 'Follow-up visit', 'phone' => '(757) 555-1234', 'phone_digits' => '7575551234', 'status' => 'confirmed', 'note' => ''],
        ['id' => 'up-george', 'patient' => 'George Marcus', 'visit' => 'First Visit', 'phone' => '(804) 679-1210', 'phone_digits' => '8046791210', 'status' => 'no_reply', 'note' => 'Has not answered the reminder'],
        ['id' => 'up-amina', 'patient' => 'Amina Cole', 'visit' => 'First Visit', 'phone' => '(804) 555-0148', 'phone_digits' => '8045550148', 'status' => 'reminder_sent', 'note' => ''],
        ['id' => 'up-luis', 'patient' => 'Luis Ortega', 'visit' => 'First Visit', 'phone' => '(757) 555-0190', 'phone_digits' => '7575550190', 'status' => 'no_reply', 'note' => ''],
        ['id' => 'up-priya', 'patient' => 'Priya Shah', 'visit' => 'First Visit', 'phone' => '(804) 555-0188', 'phone_digits' => '8045550188', 'status' => 'confirmed', 'note' => ''],
        ['id' => 'up-omar', 'patient' => 'Omar Haddad', 'visit' => 'Follow-up visit', 'phone' => '(757) 555-0133', 'phone_digits' => '7575550133', 'status' => 'confirmed', 'note' => ''],
        ['id' => 'up-carlos', 'patient' => 'Carlos Ruiz', 'visit' => 'Follow-up visit', 'phone' => '(804) 555-0114', 'phone_digits' => '8045550114', 'status' => 'confirmed', 'note' => ''],
    ];
}

function preview_upcoming_seed(): array
{
    $rows = [];
    $index = 0;
    $templates = preview_upcoming_templates();
    foreach (preview_open_dates() as $day) {
        $slots = preview_open_slots($day['value']);
        $take = min(3, count($slots), count($templates) - $index);
        for ($offset = 0; $offset < $take; $offset++) {
            $person = $templates[$index];
            $slot = $slots[$offset];
            $rows[] = [
                'id' => $person['id'],
                'date' => $day['value'],
                'date_label' => $day['full'],
                'time' => $slot['label'],
                'time_value' => $slot['value'],
                'patient' => $person['patient'],
                'visit' => $person['visit'],
                'phone' => $person['phone'],
                'phone_digits' => $person['phone_digits'],
                'status' => $person['status'],
                'note' => $person['note'],
                'source' => 'seed',
                'held_for' => '',
            ];
            $index++;
        }
        if ($index >= count($templates)) {
            break;
        }
    }

    return $rows;
}

function preview_live_booking_row(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $booking = $_SESSION['booking_preview'] ?? null;
    if (!is_array($booking)) {
        return null;
    }

    $date = (string) ($booking['date_value'] ?? '');
    if ($date === '' || $date < preview_today()->format('Y-m-d')) {
        return null;
    }

    $status = (string) ($booking['status'] ?? 'booked');
    $cancelled = in_array($status, ['canceled', 'cancelled'], true);
    $digits = preg_replace('/\D+/', '', (string) ($booking['patient_phone'] ?? '')) ?? '';

    return [
        'id' => 'live-booking',
        'date' => $date,
        'date_label' => (string) ($booking['date'] ?? $date),
        'time' => (string) ($booking['time'] ?? ''),
        'time_value' => (string) ($booking['time_value'] ?? ''),
        'patient' => (string) ($booking['patient_name'] ?? ''),
        'visit' => (string) ($booking['visit_label'] ?? preview_visit_label((string) ($booking['visit_type'] ?? 'wellness'))),
        'phone' => (string) ($booking['patient_phone'] ?? ''),
        'phone_digits' => $digits,
        'status' => $cancelled ? 'cancelled' : 'no_reply',
        'note' => $cancelled ? 'Cancelled' : 'Booked on the patient website',
        'source' => 'live',
        'held_for' => '',
    ];
}

function preview_sync_live_booking(array $appointment): void
{
    if (($appointment['source'] ?? '') !== 'live' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $booking = $_SESSION['booking_preview'] ?? null;
    if (!is_array($booking)) {
        return;
    }

    $day = preview_day_record((string) $appointment['date']);
    $booking['date_value'] = $appointment['date'];
    $booking['date'] = $day['full'] ?? $appointment['date_label'];
    $booking['time'] = $appointment['time'];
    $booking['time_value'] = $appointment['time_value'];
    $booking['status'] = $appointment['status'] === 'cancelled' ? 'canceled' : 'booked';
    if (isset($booking['people'][0]) && is_array($booking['people'][0])) {
        $booking['people'][0]['time'] = $appointment['time'];
        $booking['people'][0]['time_value'] = $appointment['time_value'];
    }
    $_SESSION['booking_preview'] = $booking;
}

function preview_upcoming_appointments(): array
{
    $cancelled = preview_session_string_list('upcoming_cancelled');
    $reminded = preview_session_string_list('upcoming_reminded');
    $doubles = preview_session_map('upcoming_doubles');
    $moves = preview_session_map('upcoming_moves');
    $waiting = [];
    foreach (preview_waitlist_seed() as $person) {
        $waiting[$person['id']] = $person;
    }

    $rows = preview_upcoming_seed();
    $live = preview_live_booking_row();
    if ($live !== null) {
        $rows[] = $live;
    }

    $appointments = [];
    foreach ($rows as $row) {
        $move = $moves[$row['id']] ?? null;
        if (is_array($move)) {
            $nextDate = (string) ($move['date'] ?? '');
            $nextTime = (string) ($move['time'] ?? '');
            $day = preview_day_record($nextDate);
            if ($day !== null && preview_time_is_open($nextDate, $nextTime)) {
                $row['date'] = $nextDate;
                $row['date_label'] = $day['full'];
                $row['time_value'] = $nextTime;
                $row['time'] = preview_slot_label($nextTime);
                $row['note'] = $row['note'] === '' ? 'Time changed by the desk' : $row['note'];
            }
        }

        $heldId = (string) ($doubles[$row['id']] ?? '');
        $held = $waiting[$heldId] ?? null;
        if (in_array($row['id'], $cancelled, true)) {
            if ($held !== null) {
                $row['patient'] = $held['name'];
                $row['visit'] = $held['visit'];
                $row['phone'] = $held['phone'];
                $row['phone_digits'] = $held['phone_digits'];
                $row['status'] = in_array($row['id'], $reminded, true) ? 'reminder_sent' : 'no_reply';
                $row['note'] = 'Filled after a cancellation';
                $row['held_for'] = '';
            } else {
                $row['status'] = 'cancelled';
                $row['note'] = 'Cancelled by the desk';
            }
        } else {
            if ($row['status'] === 'no_reply' && in_array($row['id'], $reminded, true)) {
                $row['status'] = 'reminder_sent';
            }
            if ($held !== null) {
                $row['held_for'] = $held['name'];
                $row['note'] = 'Also holding this time for ' . $held['name'] . ' until this visit is confirmed';
            }
        }

        $row['status_label'] = preview_status_label($row['status']);
        $appointments[] = $row;
    }

    usort($appointments, static function (array $left, array $right): int {
        $date = strcmp((string) $left['date'], (string) $right['date']);
        if ($date !== 0) {
            return $date;
        }

        return strcmp((string) $left['time_value'], (string) $right['time_value']);
    });

    return $appointments;
}

function preview_upcoming_by_id(string $id): ?array
{
    foreach (preview_upcoming_appointments() as $appointment) {
        if ($appointment['id'] === $id) {
            return $appointment;
        }
    }

    return null;
}

function preview_upcoming_ids(): array
{
    $ids = array_column(preview_upcoming_templates(), 'id');
    $ids[] = 'live-booking';

    return $ids;
}

function preview_upcoming_slot_taken(string $date, string $time, string $exceptId = ''): bool
{
    foreach (preview_upcoming_appointments() as $appointment) {
        if ($appointment['id'] === $exceptId || $appointment['status'] === 'cancelled') {
            continue;
        }
        if ($appointment['date'] === $date && $appointment['time_value'] === $time) {
            return true;
        }
    }

    return false;
}

function preview_upcoming_matches(array $appointment, string $query): bool
{
    $query = trim($query);
    if ($query === '') {
        return true;
    }

    $digits = preg_replace('/\D+/', '', $query) ?? '';
    if ($digits !== '' && str_contains((string) $appointment['phone_digits'], $digits)) {
        return true;
    }

    $haystack = strtolower($appointment['patient'] . ' ' . $appointment['phone'] . ' ' . $appointment['visit']);

    return str_contains($haystack, strtolower($query));
}

function preview_upcoming_counts(array $appointments): array
{
    $counts = [
        'upcoming' => 0,
        'unconfirmed' => 0,
        'cancelled' => 0,
        'all' => count($appointments),
    ];
    foreach ($appointments as $appointment) {
        if ($appointment['status'] === 'cancelled') {
            $counts['cancelled']++;
        } else {
            $counts['upcoming']++;
            if (in_array($appointment['status'], ['no_reply', 'reminder_sent'], true)) {
                $counts['unconfirmed']++;
            }
        }
    }

    return $counts;
}

function preview_waitlist(): array
{
    $used = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $used = array_values($_SESSION['desk_fills'] ?? []);
        $used = array_merge($used, array_values($_SESSION['upcoming_doubles'] ?? []));
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
    return preview_fill_message(preview_message_templates()['follow_up'], [
        'name' => $appointment['patient'] ?? '',
        'time' => $appointment['time'] ?? '',
        'date' => $appointment['date'] ?? preview_desk_date()->format('F j, Y'),
        'phone' => $appointment['phone'] ?? '',
    ]);
}

function preview_notifications(): array
{
    $templates = preview_message_templates();
    $sample = [
        'name' => 'Sarah Smith',
        'date' => 'October 5, 2026',
        'time' => '9:00 AM',
        'phone' => '(757) 555-1234',
    ];

    return [
        [
            'key' => 'booking',
            'title' => 'Booking confirmation',
            'when' => 'Sent when the visit is booked',
            'message' => preview_fill_message($templates['booking'], $sample),
        ],
        [
            'key' => 'two_day',
            'title' => '2-day reminder',
            'when' => 'Two days before the visit',
            'message' => preview_fill_message($templates['two_day'], $sample),
        ],
        [
            'key' => 'one_day',
            'title' => '1-day reminder',
            'when' => 'One day before if there is no reply',
            'message' => preview_fill_message($templates['one_day'], $sample),
        ],
        [
            'key' => 'follow_up',
            'title' => 'Follow-up text',
            'when' => 'From the desk when someone has not confirmed',
            'message' => preview_fill_message($templates['follow_up'], $sample),
        ],
    ];
}
