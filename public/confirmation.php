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
if (!is_array($booking) || ($booking['status'] ?? '') === 'canceled') {
    $booking = null;
}

patient_page_start(patient_text('thanks'), 'home');
?>
    <section class="result">
        <div class="result-card">
            <?php if ($booking === null): ?>
                <h1><?php echo h(patient_text('none')); ?></h1>
                <a class="book-submit" href="index.php"><?php echo h(patient_text('book_button')); ?></a>
            <?php else: ?>
                <p class="check" aria-hidden="true">✓</p>
                <h1><?php echo h(patient_text('thanks')); ?></h1>
                <p class="result-lede"><?php echo h(patient_text('text_soon')); ?> <?php echo h((string) $booking['patient_phone']); ?>. <?php echo h(patient_text('text_soon_end')); ?></p>
                <dl>
                    <div>
                        <dt><?php echo h(patient_text('step_visit')); ?></dt>
                        <dd><?php echo h((string) $booking['visit_label']); ?></dd>
                    </div>
                    <div>
                        <dt><?php echo h(patient_text('step_time')); ?></dt>
                        <dd>
                            <?php echo h((string) $booking['date']); ?>
                            <ul class="people">
                                <?php foreach ($booking['people'] ?? [] as $person): ?>
                                    <li><?php echo h((string) $person['time']); ?> · <?php echo h((string) $person['name']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </dd>
                    </div>
                </dl>
                <div class="result-actions">
                    <a class="book-submit" href="status.php"><?php echo h(patient_text('change')); ?></a>
                    <a class="quiet-link" href="index.php"><?php echo h(patient_text('book_another')); ?></a>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php
patient_page_end();
