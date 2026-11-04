<?php

declare(strict_types=1);

/**
 * Admin table authentication helpers.
 *
 * Schema note: clinic_db.Admin has Email + PasswordHash, but not Username or Role.
 * Login accepts an email (or the legacy JSON username flow in staff_accounts.php).
 * Roles / invites remain in storage/staff.json until the schema is extended.
 */

require_once __DIR__ . '/db.php';

/**
 * @return array{Admin_ID: int, Name: string, PhoneNumber: string, Email: string}|null
 */
function admin_find_by_email(string $email): ?array
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }

    $statement = db()->prepare(
        'SELECT Admin_ID, Name, PhoneNumber, Email, PasswordHash
         FROM Admin
         WHERE Email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    return [
        'Admin_ID' => (int) $row['Admin_ID'],
        'Name' => (string) $row['Name'],
        'PhoneNumber' => (string) $row['PhoneNumber'],
        'Email' => (string) $row['Email'],
        'PasswordHash' => (string) $row['PasswordHash'],
    ];
}

/**
 * Ensure at least one Admin row exists for local login (fictional defaults).
 */
function admin_ensure_seeded(): void
{
    $count = (int) db()->query('SELECT COUNT(*) FROM Admin')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $email = env_value('ADMIN_EMAIL', 'desk@clinic.local');
    $password = env_value('ADMIN_PASSWORD', 'clinic-desk');
    $name = env_value('ADMIN_NAME', 'Front desk');
    $phone = env_value('ADMIN_PHONE', '5550100000');

    $statement = db()->prepare(
        'INSERT INTO Admin (Name, PhoneNumber, Email, PasswordHash)
         VALUES (:name, :phone, :email, :hash)'
    );
    $statement->execute([
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
}

/**
 * Authenticate against Admin by email.
 *
 * @return array{id: string, name: string, username: string, email: string, role: string, source: string}|null
 */
function admin_authenticate_email(string $email, string $password): ?array
{
    admin_ensure_seeded();
    $row = admin_find_by_email($email);
    if ($row === null) {
        return null;
    }
    if (!password_verify($password, $row['PasswordHash'])) {
        return null;
    }

    return [
        'id' => 'db-' . $row['Admin_ID'],
        'name' => $row['Name'],
        'username' => $row['Email'],
        'email' => $row['Email'],
        'role' => 'admin',
        'source' => 'db',
        'admin_db_id' => $row['Admin_ID'],
    ];
}
