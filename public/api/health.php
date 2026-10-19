<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$databaseReady = env_value('DB_HOST') !== ''
    && env_value('DB_NAME') !== ''
    && env_value('DB_USER') !== '';

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'status' => 'ok',
        'service' => 'clinic-appointments',
        'database' => $databaseReady ? 'configured' : 'not_configured',
    ],
    JSON_UNESCAPED_SLASHES
);
