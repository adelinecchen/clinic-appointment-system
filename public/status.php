<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/csrf.php';
require_once __DIR__ . '/../src/patient_copy.php';
require_once __DIR__ . '/../src/patient_status.php';

csrf_ensure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    patient_status_process();
}

header('Location: index.php#status');
exit;
