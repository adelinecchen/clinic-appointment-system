<?php

declare(strict_types=1);

/**
 * One-time first super_admin when the Admin table is empty.
 *
 * Usage:
 *   /Applications/XAMPP/xamppfiles/bin/php scripts/create_first_super_admin.php \
 *     "Clinic Lead" lead@example.org 5550100000 'choose-a-strong-password'
 *
 * Refuses to run when any Admin row already exists (use Staff UI instead).
 * Does not enable ADMIN_SEED_ENABLED. Prefer this over demo seed for production.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/admin_auth.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php scripts/create_first_super_admin.php \"Full Name\" email@example.org 5550100000 'password'\n");
    exit(1);
}

$name = trim((string) $argv[1]);
$email = trim((string) $argv[2]);
$phone = trim((string) $argv[3]);
$password = (string) $argv[4];

$result = admin_create_first_super_admin($name, $phone, $email, $password);
if (!$result['ok']) {
    fwrite(STDERR, 'Could not create account: ' . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

$id = (int) ($result['account']['admin_db_id'] ?? 0);
$emailOut = (string) ($result['account']['email'] ?? $email);
echo "Created first super_admin id={$id} email={$emailOut}\n";
echo "Sign in at public/admin/login.php. Keep ADMIN_SEED_ENABLED=false.\n";
exit(0);
