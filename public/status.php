<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/preview_data.php';
require_once __DIR__ . '/../src/patient_status.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    patient_status_process();
}

header('Location: index.php#status');
exit;
