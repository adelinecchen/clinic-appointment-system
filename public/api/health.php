<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'status' => 'ok',
        'service' => 'clinic-appointments',
        'database' => db_health(),
    ],
    JSON_UNESCAPED_SLASHES
);
