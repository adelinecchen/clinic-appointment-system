<?php

declare(strict_types=1);

/**
 * PDO connection for clinic_db.
 * Credentials come from .env through bootstrap.php. This file is the only
 * place that opens MySQL. Call db() from later PHP pages; use db_health()
 * for the public health check.
 */

require_once __DIR__ . '/bootstrap.php';

function db_configured(): bool
{
    return env_value('DB_HOST') !== ''
        && env_value('DB_NAME') !== ''
        && env_value('DB_USER') !== '';
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!db_configured()) {
        throw new RuntimeException('Database is not configured.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env_value('DB_HOST'),
        env_value('DB_PORT', '3306'),
        env_value('DB_NAME')
    );

    $pdo = new PDO($dsn, env_value('DB_USER'), env_value('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function db_health(): string
{
    if (!db_configured()) {
        return 'not_configured';
    }

    try {
        db()->query('SELECT 1');

        return 'connected';
    } catch (PDOException) {
        return 'unavailable';
    }
}
