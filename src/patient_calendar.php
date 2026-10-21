<?php

declare(strict_types=1);

function patient_weekday_labels(): array
{
    return patient_language() === 'es'
        ? ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']
        : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
}

function patient_month_label(string $value, string $fallback): string
{
    if (patient_language() !== 'es') {
        return $fallback;
    }

    $names = [
        '01' => 'enero',
        '02' => 'febrero',
        '03' => 'marzo',
        '04' => 'abril',
        '05' => 'mayo',
        '06' => 'junio',
        '07' => 'julio',
        '08' => 'agosto',
        '09' => 'septiembre',
        '10' => 'octubre',
        '11' => 'noviembre',
        '12' => 'diciembre',
    ];
    $parts = explode('-', $value);
    $month = $names[$parts[1] ?? ''] ?? $fallback;
    $year = $parts[0] ?? '';

    return trim($month . ' ' . $year);
}

function patient_render_calendar(string $selected, string $field = 'appointment_date'): void
{
    $months = preview_booking_months($selected);
    if ($months === []) {
        echo '<p class="hint">' . h(patient_text('no_dates')) . '</p>';

        return;
    }

    $openValues = array_column(preview_open_dates(), 'value');
    if ($selected === '' || !in_array($selected, $openValues, true)) {
        $selected = $openValues[0];
    }
    $visible = substr($selected, 0, 7);
    ?>
    <div class="book-cal" data-visible="<?php echo h($visible); ?>">
        <div class="book-cal-nav">
            <button type="button" class="cal-shift" data-cal-prev><?php echo h(patient_text('cal_prev')); ?></button>
            <p class="book-cal-label" data-cal-label><?php
                foreach ($months as $month) {
                    if ($month['value'] === $visible) {
                        echo h(patient_month_label($month['value'], $month['label']));
                        break;
                    }
                }
            ?></p>
            <button type="button" class="cal-shift" data-cal-next><?php echo h(patient_text('cal_next')); ?></button>
        </div>
        <?php foreach ($months as $month): ?>
            <div class="book-cal-month" data-month="<?php echo h($month['value']); ?>" data-label="<?php echo h(patient_month_label($month['value'], $month['label'])); ?>"<?php echo $month['value'] === $visible ? '' : ' hidden'; ?>>
                <div class="book-cal-grid" role="grid" aria-label="<?php echo h(patient_month_label($month['value'], $month['label'])); ?>">
                    <?php foreach (patient_weekday_labels() as $weekday): ?>
                        <div class="book-cal-head"><?php echo h($weekday); ?></div>
                    <?php endforeach; ?>
                    <?php for ($blank = 0; $blank < $month['pad']; $blank++): ?>
                        <div></div>
                    <?php endfor; ?>
                    <?php foreach ($month['days'] as $day): ?>
                        <?php if ($day['open']): ?>
                            <label class="book-cal-day is-open<?php echo $day['selected'] ? ' is-selected' : ''; ?>">
                                <input type="radio" name="<?php echo h($field); ?>" value="<?php echo h($day['value']); ?>"<?php echo $day['selected'] ? ' checked' : ''; ?>>
                                <span><?php echo h((string) $day['number']); ?></span>
                            </label>
                        <?php else: ?>
                            <span class="book-cal-day<?php echo $day['past'] ? ' is-past' : ''; ?>"><?php echo h((string) $day['number']); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <p class="hint"><?php echo h(patient_text('cal_hint')); ?></p>
    </div>
    <?php
}

function patient_render_times(string $selectedDate, string $selectedTime): void
{
    if ($selectedDate === '') {
        return;
    }
    ?>
    <div class="time-pills" role="radiogroup" aria-label="<?php echo h(patient_text('choose_time')); ?>">
        <?php foreach (preview_open_slots($selectedDate) as $slot): ?>
            <label class="time-chip">
                <input type="radio" name="appointment_time" value="<?php echo h($slot['value']); ?>"<?php echo $selectedTime === $slot['value'] ? ' checked' : ''; ?>>
                <span><?php echo h($slot['label']); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}

function patient_slot_map_json(): void
{
    $slotMap = [];
    foreach (preview_clinic_dates() as $clinicDay) {
        $slotMap[$clinicDay['value']] = preview_visit_slots($clinicDay['value']);
    }
    echo '<script type="application/json" id="slot-data">' . json_encode($slotMap, JSON_UNESCAPED_SLASHES) . '</script>';
}
