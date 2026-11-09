<?php

declare(strict_types=1);

/**
 * Database-backed Admin authentication and account management.
 *
 * Login is email + password against clinic_db.Admin only.
 * Roles (PHP-validated): admin | super_admin.
 */

require_once __DIR__ . '/db.php';

/** @return list<string> */
function admin_roles(): array
{
    return ['admin', 'super_admin'];
}

function admin_normalize_role(string $role): string
{
    return $role === 'super_admin' ? 'super_admin' : 'admin';
}

function admin_role_is_valid(string $role): bool
{
    return in_array($role, admin_roles(), true);
}

function admin_role_label(string $role): string
{
    return $role === 'super_admin' ? 'Super admin' : 'Admin';
}

function admin_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function admin_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    return $digits;
}

function admin_valid_phone(string $phone): bool
{
    $digits = admin_normalize_phone($phone);

    return strlen($digits) >= 10 && strlen($digits) <= 15;
}

function admin_app_env(): string
{
    return strtolower(env_value('APP_ENV', 'local'));
}

function admin_is_local_env(): bool
{
    return admin_app_env() === 'local';
}

function admin_seed_enabled(): bool
{
    $flag = strtolower(env_value('ADMIN_SEED_ENABLED', 'false'));

    return admin_is_local_env() && in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

function admin_ensure_session(): void
{
    require_once __DIR__ . '/csrf.php';
    csrf_ensure_session();
}

/**
 * @return array{
 *   Admin_ID: int,
 *   Name: string,
 *   PhoneNumber: string,
 *   Email: string,
 *   PasswordHash: string,
 *   Role: string
 * }|null
 */
function admin_find_by_id(int $adminId): ?array
{
    if ($adminId < 1) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT Admin_ID, Name, PhoneNumber, Email, PasswordHash, Role
         FROM Admin
         WHERE Admin_ID = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $adminId]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    return admin_row($row);
}

/**
 * @return array{
 *   Admin_ID: int,
 *   Name: string,
 *   PhoneNumber: string,
 *   Email: string,
 *   PasswordHash: string,
 *   Role: string
 * }|null
 */
function admin_find_by_email(string $email): ?array
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }

    $statement = db()->prepare(
        'SELECT Admin_ID, Name, PhoneNumber, Email, PasswordHash, Role
         FROM Admin
         WHERE Email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }

    return admin_row($row);
}

/**
 * @param array<string, mixed> $row
 * @return array{
 *   Admin_ID: int,
 *   Name: string,
 *   PhoneNumber: string,
 *   Email: string,
 *   PasswordHash: string,
 *   Role: string
 * }
 */
function admin_row(array $row): array
{
    $role = (string) ($row['Role'] ?? 'admin');
    if (!admin_role_is_valid($role)) {
        $role = 'admin';
    }

    return [
        'Admin_ID' => (int) $row['Admin_ID'],
        'Name' => (string) $row['Name'],
        'PhoneNumber' => (string) $row['PhoneNumber'],
        'Email' => (string) $row['Email'],
        'PasswordHash' => (string) $row['PasswordHash'],
        'Role' => $role,
    ];
}

/**
 * UI/session account shape used by the staff desk.
 *
 * @param array{
 *   Admin_ID: int,
 *   Name: string,
 *   PhoneNumber: string,
 *   Email: string,
 *   Role: string
 * } $row
 * @return array{
 *   id: string,
 *   admin_db_id: int,
 *   name: string,
 *   username: string,
 *   email: string,
 *   phone: string,
 *   role: string,
 *   source: string
 * }
 */
function admin_account_view(array $row): array
{
    return [
        'id' => 'db-' . $row['Admin_ID'],
        'admin_db_id' => (int) $row['Admin_ID'],
        'name' => $row['Name'],
        'username' => $row['Email'],
        'email' => $row['Email'],
        'phone' => $row['PhoneNumber'],
        'role' => admin_normalize_role((string) $row['Role']),
        'source' => 'db',
    ];
}

/**
 * Local-only optional demo seed. Never runs unless APP_ENV=local and ADMIN_SEED_ENABLED is true.
 */
function admin_ensure_seeded(): void
{
    if (!admin_seed_enabled()) {
        return;
    }

    $count = (int) db()->query('SELECT COUNT(*) FROM Admin')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $email = env_value('ADMIN_EMAIL', 'desk@clinic.local');
    $password = env_value('ADMIN_PASSWORD', '');
    $name = env_value('ADMIN_NAME', 'Front desk');
    $phone = admin_normalize_phone(env_value('ADMIN_PHONE', '5550100000'));
    $role = admin_normalize_role(env_value('ADMIN_ROLE', 'super_admin'));

    if ($password === '' || !admin_valid_email($email) || !admin_valid_phone($phone)) {
        return;
    }

    $statement = db()->prepare(
        'INSERT INTO Admin (Name, PhoneNumber, Email, PasswordHash, Role)
         VALUES (:name, :phone, :email, :hash, :role)'
    );
    $statement->execute([
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);
}

/**
 * Authenticate against Admin by email. Does not auto-seed unless explicitly enabled.
 *
 * @return array{
 *   id: string,
 *   admin_db_id: int,
 *   name: string,
 *   username: string,
 *   email: string,
 *   phone: string,
 *   role: string,
 *   source: string
 * }|null
 */
function admin_authenticate_email(string $email, string $password): ?array
{
    admin_ensure_seeded();
    $row = admin_find_by_email($email);
    if ($row === null) {
        // Constant-time-ish dummy verify when unknown email.
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuvuABCDEFGHIJKLMNOPQRSTUVWX');

        return null;
    }
    if (!password_verify($password, $row['PasswordHash'])) {
        return null;
    }

    return admin_account_view($row);
}

/**
 * @return list<array{
 *   id: string,
 *   admin_db_id: int,
 *   name: string,
 *   username: string,
 *   email: string,
 *   phone: string,
 *   role: string,
 *   source: string
 * }>
 */
function admin_list_accounts(): array
{
    $statement = db()->query(
        'SELECT Admin_ID, Name, PhoneNumber, Email, Role
         FROM Admin
         ORDER BY Role DESC, Name ASC'
    );
    $accounts = [];
    foreach ($statement->fetchAll() as $row) {
        $accounts[] = admin_account_view([
            'Admin_ID' => (int) $row['Admin_ID'],
            'Name' => (string) $row['Name'],
            'PhoneNumber' => (string) $row['PhoneNumber'],
            'Email' => (string) $row['Email'],
            'Role' => (string) $row['Role'],
        ]);
    }

    return $accounts;
}

function admin_super_count(?int $exceptAdminId = null): int
{
    if ($exceptAdminId !== null && $exceptAdminId > 0) {
        $statement = db()->prepare(
            "SELECT COUNT(*) FROM Admin WHERE Role = 'super_admin' AND Admin_ID <> :id"
        );
        $statement->execute(['id' => $exceptAdminId]);
    } else {
        $statement = db()->query("SELECT COUNT(*) FROM Admin WHERE Role = 'super_admin'");
    }

    return (int) $statement->fetchColumn();
}

function admin_parse_account_id(string $id): int
{
    if (str_starts_with($id, 'db-')) {
        return (int) substr($id, 3);
    }

    return (int) $id;
}

/**
 * @return array{ok: true, account: array}|array{ok: false, error: string}
 */
function admin_create_account(
    string $name,
    string $phone,
    string $email,
    string $password,
    string $role
): array {
    if (!admin_is_super()) {
        return ['ok' => false, 'error' => 'Only a super admin can manage staff accounts.'];
    }

    $name = trim($name);
    $email = trim($email);
    $phoneDigits = admin_normalize_phone($phone);
    $role = admin_normalize_role($role);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
        return ['ok' => false, 'error' => 'Enter a name (2–50 characters).'];
    }
    if (!admin_valid_email($email) || strlen($email) > 80) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if (!admin_valid_phone($phoneDigits)) {
        return ['ok' => false, 'error' => 'Enter a phone number with 10–15 digits.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Use a password with at least 8 characters.'];
    }
    if (admin_find_by_email($email) !== null) {
        return ['ok' => false, 'error' => 'That email already has an account.'];
    }

    $phoneCheck = db()->prepare('SELECT Admin_ID FROM Admin WHERE PhoneNumber = :phone LIMIT 1');
    $phoneCheck->execute(['phone' => $phoneDigits]);
    if ($phoneCheck->fetch() !== false) {
        return ['ok' => false, 'error' => 'That phone number already has an account.'];
    }

    try {
        $statement = db()->prepare(
            'INSERT INTO Admin (Name, PhoneNumber, Email, PasswordHash, Role)
             VALUES (:name, :phone, :email, :hash, :role)'
        );
        $statement->execute([
            'name' => $name,
            'phone' => $phoneDigits,
            'email' => $email,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        $id = (int) db()->lastInsertId();
        $row = admin_find_by_id($id);
        if ($row === null) {
            return ['ok' => false, 'error' => 'The account could not be saved.'];
        }

        return ['ok' => true, 'account' => admin_account_view($row)];
    } catch (PDOException) {
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function admin_update_account(
    string $id,
    string $name,
    string $phone,
    string $email,
    string $role,
    string $password = ''
): array {
    if (!admin_is_super()) {
        return ['ok' => false, 'error' => 'Only a super admin can manage staff accounts.'];
    }

    $adminId = admin_parse_account_id($id);
    $existing = admin_find_by_id($adminId);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'That account is gone.'];
    }

    $name = trim($name);
    $email = trim($email);
    $phoneDigits = admin_normalize_phone($phone);
    $role = admin_normalize_role($role);
    $current = admin_current();

    if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
        return ['ok' => false, 'error' => 'Enter a name (2–50 characters).'];
    }
    if (!admin_valid_email($email) || strlen($email) > 80) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if (!admin_valid_phone($phoneDigits)) {
        return ['ok' => false, 'error' => 'Enter a phone number with 10–15 digits.'];
    }
    if ($password !== '' && strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Use a password with at least 8 characters.'];
    }

    $emailOwner = admin_find_by_email($email);
    if ($emailOwner !== null && $emailOwner['Admin_ID'] !== $adminId) {
        return ['ok' => false, 'error' => 'That email already has an account.'];
    }

    $phoneCheck = db()->prepare(
        'SELECT Admin_ID FROM Admin WHERE PhoneNumber = :phone AND Admin_ID <> :id LIMIT 1'
    );
    $phoneCheck->execute(['phone' => $phoneDigits, 'id' => $adminId]);
    if ($phoneCheck->fetch() !== false) {
        return ['ok' => false, 'error' => 'That phone number already has an account.'];
    }

    if (
        $existing['Role'] === 'super_admin'
        && $role !== 'super_admin'
        && admin_super_count($adminId) === 0
    ) {
        return ['ok' => false, 'error' => 'Keep at least one super admin.'];
    }

    try {
        if ($password !== '') {
            $statement = db()->prepare(
                'UPDATE Admin
                 SET Name = :name, PhoneNumber = :phone, Email = :email, Role = :role, PasswordHash = :hash
                 WHERE Admin_ID = :id'
            );
            $statement->execute([
                'name' => $name,
                'phone' => $phoneDigits,
                'email' => $email,
                'role' => $role,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $adminId,
            ]);
        } else {
            $statement = db()->prepare(
                'UPDATE Admin
                 SET Name = :name, PhoneNumber = :phone, Email = :email, Role = :role
                 WHERE Admin_ID = :id'
            );
            $statement->execute([
                'name' => $name,
                'phone' => $phoneDigits,
                'email' => $email,
                'role' => $role,
                'id' => $adminId,
            ]);
        }
    } catch (PDOException) {
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }

    // Session role is refreshed from DB on each request via admin_current().
    unset($current);

    return ['ok' => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function admin_delete_account(string $id): array
{
    if (!admin_is_super()) {
        return ['ok' => false, 'error' => 'Only a super admin can manage staff accounts.'];
    }

    $adminId = admin_parse_account_id($id);
    $existing = admin_find_by_id($adminId);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'That account is gone.'];
    }

    $current = admin_current();
    if ($current !== null && (int) $current['admin_db_id'] === $adminId) {
        return ['ok' => false, 'error' => 'You cannot delete the account you are using.'];
    }

    if ($existing['Role'] === 'super_admin' && admin_super_count($adminId) === 0) {
        return ['ok' => false, 'error' => 'Keep at least one super admin.'];
    }

    try {
        $statement = db()->prepare('DELETE FROM Admin WHERE Admin_ID = :id');
        $statement->execute(['id' => $adminId]);
    } catch (PDOException) {
        return ['ok' => false, 'error' => 'The account could not be deleted.'];
    }

    return ['ok' => true];
}

/**
 * Current authenticated admin, role always reloaded from MariaDB.
 *
 * @return array{
 *   id: string,
 *   admin_db_id: int,
 *   name: string,
 *   username: string,
 *   email: string,
 *   phone: string,
 *   role: string,
 *   source: string
 * }|null
 */
function admin_current(): ?array
{
    admin_ensure_session();
    $id = (string) ($_SESSION['admin_id'] ?? '');
    if ($id === '' || !str_starts_with($id, 'db-')) {
        return null;
    }

    $adminId = (int) substr($id, 3);
    $row = admin_find_by_id($adminId);
    if ($row === null) {
        unset($_SESSION['admin_id'], $_SESSION['admin_user'], $_SESSION['admin_role']);

        return null;
    }

    return admin_account_view($row);
}

function admin_is_super(): bool
{
    $account = admin_current();

    return $account !== null && $account['role'] === 'super_admin';
}

function admin_require_super(): void
{
    if (!admin_is_super()) {
        header('Location: index.php');
        exit;
    }
}

function admin_login(array $account): void
{
    admin_ensure_session();
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    $_SESSION['admin_id'] = $account['id'];
    $_SESSION['admin_user'] = $account['email'] ?? $account['username'] ?? '';
    // Cached for display only; authorization uses admin_current() → DB Role.
    $_SESSION['admin_role'] = $account['role'] ?? 'admin';
    $_SESSION['admin_login_at'] = time();
    require_once __DIR__ . '/csrf.php';
    csrf_rotate();
}

function admin_logout(): void
{
    admin_ensure_session();
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    // Fresh anonymous session; new CSRF issued on next csrf_token() call.
    admin_ensure_session();
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
}
