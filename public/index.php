<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/preview_data.php';
require_once __DIR__ . '/../src/patient_copy.php';
require_once __DIR__ . '/../src/patient_layout.php';
require_once __DIR__ . '/../src/patient_calendar.php';
require_once __DIR__ . '/../src/patient_status.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    patient_status_process();
    if (!headers_sent()) {
        header('Location: index.php#status');
        exit;
    }
}

$errors = [];
$values = preview_booking_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $checked = preview_validate_booking($_POST);
    $errors = $checked['errors'];
    $values = $checked['values'];
    if ($errors === []) {
        $_SESSION['booking_preview'] = $checked['booking'];
        header('Location: confirmation.php');
        exit;
    }
}

patient_page_start(patient_text('book_title'), isset($_GET['change']) ? 'status' : 'home');
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

            <form class="book-card" method="post" action="index.php">
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
                    <p class="picker-label"><?php echo h(patient_text('choose_date')); ?></p>
                    <?php patient_render_calendar((string) $values['appointment_date']); ?>
                    <?php if (preview_open_dates() !== []): ?>
                        <p class="picker-label"><?php echo h(patient_text('choose_time')); ?></p>
                        <?php patient_render_times((string) $values['appointment_date'], (string) $values['appointment_time']); ?>
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
                </fieldset>

                <button class="book-submit" type="submit"><?php echo h(patient_text('book_button')); ?></button>
            </form>
        </div>
    </section>

    <?php patient_status_render(); ?>

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
    <?php patient_slot_map_json(); ?>
    <script src="assets/js/booking.js"></script>
<?php
patient_page_end();
