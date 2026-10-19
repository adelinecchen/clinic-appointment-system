<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/preview_data.php';
require_once __DIR__ . '/../src/patient_copy.php';
require_once __DIR__ . '/../src/patient_layout.php';

$errors = [];
$values = preview_booking_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checked = preview_validate_booking($_POST);
    $errors = $checked['errors'];
    $values = $checked['values'];
    if ($errors === []) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['booking_preview'] = $checked['booking'];
        header('Location: confirmation.php');
        exit;
    }
}

patient_page_start(patient_text('book_title'), 'home');
?>
    <section class="book-hero">
        <div class="wrap book-grid">
            <div class="pitch">
                <h1><?php echo h(patient_text('book_title')); ?></h1>
                <p><?php echo h(patient_text('book_lede')); ?></p>
                <ul class="perks">
                    <li><?php echo h(patient_text('perk_password')); ?></li>
                    <li><?php echo h(patient_text('perk_change')); ?></li>
                    <li><?php echo h(patient_text('perk_text')); ?></li>
                </ul>
            </div>

            <form class="book-card" method="post" action="index.php" data-hint="<?php echo h(patient_text('party_hint')); ?>" data-fit-one="<?php echo h(patient_fit_message(1)); ?>" data-fit-many="<?php echo h(patient_fit_message(2)); ?>" data-another="<?php echo h(patient_text('you')); ?>">
                <h2><?php echo h(patient_text('card_title')); ?></h2>
                <?php if ($errors !== []): ?>
                    <div class="errors" role="alert">
                        <ul>
                            <?php foreach ($errors as $key => $code): ?>
                                <li><?php echo h(patient_error_text((string) $key, (string) $code)); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <fieldset>
                    <legend>1. <?php echo h(patient_text('step_visit')); ?></legend>
                    <div class="visit-choices">
                        <label class="visit">
                            <input type="radio" name="visit_type" value="wellness"<?php echo $values['visit_type'] === 'wellness' ? ' checked' : ''; ?>>
                            <span>
                                <strong><?php echo h(patient_text('wellness')); ?></strong>
                                <small><?php echo h(patient_text('wellness_note')); ?></small>
                            </span>
                        </label>
                        <label class="visit">
                            <input type="radio" name="visit_type" value="follow_up"<?php echo $values['visit_type'] === 'follow_up' ? ' checked' : ''; ?>>
                            <span>
                                <strong><?php echo h(patient_text('follow')); ?></strong>
                                <small><?php echo h(patient_text('follow_note')); ?></small>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>2. <?php echo h(patient_text('step_time')); ?></legend>
                    <div class="pills" role="radiogroup" aria-label="<?php echo h(patient_text('step_time')); ?>">
                        <?php foreach (preview_open_dates() as $day): ?>
                            <label class="pill">
                                <input type="radio" name="appointment_date" value="<?php echo h($day['value']); ?>"<?php echo $values['appointment_date'] === $day['value'] ? ' checked' : ''; ?>>
                                <span><?php echo h($day['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (preview_open_dates() === []): ?>
                        <p class="hint"><?php echo h(patient_text('no_dates')); ?></p>
                    <?php else: ?>
                    <div class="pills time-pills">
                        <?php foreach (preview_open_slots((string) $values['appointment_date']) as $slot): ?>
                            <label class="pill">
                                <input type="radio" name="appointment_time" value="<?php echo h($slot['value']); ?>"<?php echo $values['appointment_time'] === $slot['value'] ? ' checked' : ''; ?>>
                                <span><?php echo h($slot['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </fieldset>

                <fieldset>
                    <legend>3. <?php echo h(patient_text('step_contact')); ?></legend>
                    <div class="contact-row">
                        <label>
                            <span class="sr-only"><?php echo h(patient_text('full_name')); ?></span>
                            <input name="patient_name" type="text" autocomplete="name" maxlength="80" required placeholder="<?php echo h(patient_text('full_name')); ?>" value="<?php echo h($values['patient_name']); ?>"<?php echo isset($errors['patient_name']) ? ' aria-invalid="true"' : ''; ?>>
                        </label>
                        <label>
                            <span class="sr-only"><?php echo h(patient_text('phone')); ?></span>
                            <input name="patient_phone" type="tel" autocomplete="tel" inputmode="tel" required placeholder="(757) 555-0142" value="<?php echo h($values['patient_phone']); ?>"<?php echo isset($errors['patient_phone']) ? ' aria-invalid="true"' : ''; ?>>
                        </label>
                    </div>
                    <div id="party-list">
                        <?php foreach ($values['party'] as $partyName): ?>
                            <div class="party-card">
                                <label>
                                    <span class="sr-only"><?php echo h(patient_text('person')); ?></span>
                                    <input name="party_name[]" type="text" autocomplete="name" maxlength="80" placeholder="<?php echo h(patient_text('person')); ?>" value="<?php echo h($partyName); ?>">
                                </label>
                                <button type="button" class="text-button" data-remove-person><?php echo h(patient_text('remove_person')); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="text-button" id="add-person"><?php echo h(patient_text('add_person')); ?></button>
                    <p id="party-note" class="hint" aria-live="polite"><?php echo h(patient_text('party_hint')); ?></p>
                    <ol id="party-plan" class="party-plan"></ol>
                    <template id="party-template">
                        <div class="party-card">
                            <label>
                                <span class="sr-only"><?php echo h(patient_text('person')); ?></span>
                                <input name="party_name[]" type="text" autocomplete="name" maxlength="80" placeholder="<?php echo h(patient_text('person')); ?>">
                            </label>
                            <button type="button" class="text-button" data-remove-person><?php echo h(patient_text('remove_person')); ?></button>
                        </div>
                    </template>
                    <script type="application/json" id="slot-data"><?php
                        $slotMap = [];
                        foreach (preview_clinic_dates() as $clinicDay) {
                            $slotMap[$clinicDay['value']] = preview_visit_slots($clinicDay['value']);
                        }
                        echo json_encode($slotMap, JSON_UNESCAPED_SLASHES);
                    ?></script>
                </fieldset>

                <button class="book-submit" type="submit"><?php echo h(patient_text('book_button')); ?></button>
            </form>
        </div>
    </section>

    <section class="how">
        <div class="wrap how-grid">
            <article>
                <h2><?php echo h(patient_text('save_title')); ?></h2>
                <p><?php echo h(patient_text('save_note')); ?></p>
            </article>
            <article>
                <h2><?php echo h(patient_text('text_title')); ?></h2>
                <p><?php echo h(patient_text('text_note')); ?></p>
            </article>
            <article>
                <h2><?php echo h(patient_text('confirm_title')); ?></h2>
                <p><?php echo h(patient_text('confirm_note')); ?></p>
            </article>
        </div>
    </section>
    <script src="assets/js/booking.js"></script>
<?php
patient_page_end();
