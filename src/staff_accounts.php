<?php

declare(strict_types=1);

/**
 * Compatibility wrappers for staff desk pages.
 * Authentication and account storage live in admin_auth.php (MariaDB Admin).
 */

require_once __DIR__ . '/admin_auth.php';

function staff_role_label(string $role): string
{
    return admin_role_label($role);
}

function staff_accounts(): array
{
    return admin_list_accounts();
}

function staff_find_account(string $id): ?array
{
    $row = admin_find_by_id(admin_parse_account_id($id));

    return $row === null ? null : admin_account_view($row);
}

function staff_authenticate(string $email, string $password): ?array
{
    return admin_authenticate_email(trim($email), $password);
}

function staff_current(): ?array
{
    return admin_current();
}

function staff_is_super(): bool
{
    return admin_is_super();
}

function staff_require_super(): void
{
    admin_require_super();
}

function staff_login(array $account): void
{
    admin_login($account);
}

function staff_logout(): void
{
    admin_logout();
}

/**
 * @return array{ok: true, account: array}|array{ok: false, error: string}
 */
function staff_create_account(
    string $name,
    string $phone,
    string $email,
    string $password,
    string $role
): array {
    return admin_create_account($name, $phone, $email, $password, $role);
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function staff_update_account(
    string $id,
    string $name,
    string $phone,
    string $email,
    string $role,
    string $password = ''
): array {
    return admin_update_account($id, $name, $phone, $email, $role, $password);
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function staff_delete_account(string $id): array
{
    return admin_delete_account($id);
}
