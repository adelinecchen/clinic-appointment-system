<?php

declare(strict_types=1);

function patient_page_start(string $title, string $active): void
{
    $clinic = preview_clinic();
    $lang = patient_language();
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    ?>
<!DOCTYPE html>
<html lang="<?php echo h($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title . ' — ' . $clinic['name']); ?></title>
    <meta name="description" content="<?php echo h($clinic['name'] . ' — ' . $clinic['doctor']); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/patient.css">
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    <header class="site-header">
        <div class="wrap header-inner">
            <a class="brand" href="index.php">
                <span class="mark" aria-hidden="true">
                    <svg viewBox="0 0 48 48" width="22" height="22" focusable="false">
                        <rect x="18" y="6" width="12" height="36" rx="3" fill="currentColor"/>
                        <rect x="6" y="18" width="36" height="12" rx="3" fill="currentColor"/>
                    </svg>
                </span>
                <span>
                    <span class="brand-name"><?php echo h($clinic['short']); ?></span>
                    <span class="brand-note"><?php echo h($clinic['doctor']); ?></span>
                </span>
            </a>
            <div class="header-tools">
                <div class="langs" role="group" aria-label="Language">
                    <a href="<?php echo h($page . '?lang=en'); ?>"<?php echo $lang === 'en' ? ' aria-current="true"' : ''; ?>><?php echo h(patient_text('english')); ?></a>
                    <a href="<?php echo h($page . '?lang=es'); ?>"<?php echo $lang === 'es' ? ' aria-current="true"' : ''; ?>><?php echo h(patient_text('spanish')); ?></a>
                </div>
                <a class="tool-link<?php echo $active === 'status' ? ' is-current' : ''; ?>" href="index.php#status"><?php echo h(patient_text('check')); ?></a>
                <a class="tool-link tool-staff" href="admin/index.php"><?php echo h(patient_text('staff')); ?></a>
            </div>
        </div>
    </header>
    <p class="address">
        <span><?php echo h($clinic['address']); ?></span>
        <span><?php echo h($clinic['address_note']); ?></span>
    </p>
    <main id="main">
    <?php
}

function patient_page_end(): void
{
    ?>
    </main>
</body>
</html>
    <?php
}
