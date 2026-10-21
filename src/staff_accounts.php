<?php

declare(strict_types=1);

function staff_storage_path(): string
{
    return dirname(__DIR__) . '/storage/staff.json';
}

function staff_seed(): array
{
    $username = env_value('ADMIN_USERNAME', 'staff');
    $password = env_value('ADMIN_PASSWORD', 'clinic-desk');

    return [
        'accounts' => [
            [
                'id' => 'super-1',
                'name' => 'Heera',
                'username' => $username,
                'email' => 'heera@clinic.local',
                'role' => 'super_admin',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => 'active',
            ],
            [
                'id' => 'admin-1',
                'name' => 'Front desk',
                'username' => 'desk',
                'email' => 'desk@clinic.local',
                'role' => 'admin',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => 'active',
            ],
        ],
        'invites' => [],
    ];
}

function staff_store(): array
{
    $path = staff_storage_path();
    if (!is_readable($path)) {
        $seed = staff_seed();
        staff_write_store($seed);

        return $seed;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['accounts']) || !is_array($decoded['accounts'])) {
        $seed = staff_seed();
        staff_write_store($seed);

        return $seed;
    }

    $decoded['invites'] = isset($decoded['invites']) && is_array($decoded['invites']) ? $decoded['invites'] : [];

    return $decoded;
}

function staff_write_store(array $state): bool
{
    $directory = dirname(staff_storage_path());
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $handle = fopen(staff_storage_path(), 'c+');
    if ($handle === false) {
        return false;
    }

    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    $written = fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $written !== false;
}

function staff_role_label(string $role): string
{
    return $role === 'super_admin' ? 'Super admin' : 'Admin';
}

function staff_accounts(): array
{
    return staff_store()['accounts'];
}

function staff_invites(): array
{
    return staff_store()['invites'];
}

function staff_find_account(string $id): ?array
{
    foreach (staff_accounts() as $account) {
        if (($account['id'] ?? '') === $id) {
            return $account;
        }
    }

    return null;
}

function staff_find_username(string $username): ?array
{
    $needle = strtolower($username);
    foreach (staff_accounts() as $account) {
        if (strtolower((string) ($account['username'] ?? '')) === $needle) {
            return $account;
        }
    }

    return null;
}

function staff_find_email(string $email): ?array
{
    $needle = strtolower($email);
    foreach (staff_accounts() as $account) {
        if (strtolower((string) ($account['email'] ?? '')) === $needle) {
            return $account;
        }
    }

    return null;
}

function staff_super_count(?string $exceptId = null): int
{
    $count = 0;
    foreach (staff_accounts() as $account) {
        if (($account['role'] ?? '') !== 'super_admin' || ($account['status'] ?? '') !== 'active') {
            continue;
        }
        if ($exceptId !== null && ($account['id'] ?? '') === $exceptId) {
            continue;
        }
        $count++;
    }

    return $count;
}

function staff_authenticate(string $username, string $password): ?array
{
    $account = staff_find_username($username);
    if ($account === null || ($account['status'] ?? '') !== 'active') {
        return null;
    }

    if (!password_verify($password, (string) ($account['password_hash'] ?? ''))) {
        return null;
    }

    return $account;
}

function staff_current(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $id = (string) ($_SESSION['admin_id'] ?? '');
    if ($id === '') {
        return null;
    }

    $account = staff_find_account($id);
    if ($account === null || ($account['status'] ?? '') !== 'active') {
        return null;
    }

    return $account;
}

function staff_is_super(): bool
{
    $account = staff_current();

    return $account !== null && ($account['role'] ?? '') === 'super_admin';
}

function staff_require_super(): void
{
    if (!staff_is_super()) {
        header('Location: index.php');
        exit;
    }
}

function staff_login(array $account): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $account['id'];
    $_SESSION['admin_user'] = $account['username'];
    $_SESSION['admin_role'] = $account['role'];
}

function staff_logout(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_user'], $_SESSION['admin_role']);
}

function staff_new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function staff_valid_username(string $username): bool
{
    return preg_match('/^[A-Za-z0-9._-]{2,40}$/', $username) === 1;
}

function staff_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function staff_create_account(string $name, string $email, string $username, string $password, string $role): array
{
    $name = trim($name);
    $email = trim($email);
    $username = trim($username);
    $role = $role === 'super_admin' ? 'super_admin' : 'admin';

    if (preview_text_length($name) < 2) {
        return ['ok' => false, 'error' => 'Enter a name.'];
    }
    if (!staff_valid_email($email)) {
        return ['ok' => false, 'error' => 'Enter a real email address.'];
    }
    if (!staff_valid_username($username)) {
        return ['ok' => false, 'error' => 'Usernames can use letters, numbers, and . _ -'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Use a password with at least 8 characters.'];
    }
    if (staff_find_username($username) !== null) {
        return ['ok' => false, 'error' => 'That username is already in use.'];
    }
    if (staff_find_email($email) !== null) {
        return ['ok' => false, 'error' => 'That email already has an account.'];
    }

    $state = staff_store();
    $account = [
        'id' => staff_new_id('acct'),
        'name' => $name,
        'username' => $username,
        'email' => $email,
        'role' => $role,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'status' => 'active',
    ];
    $state['accounts'][] = $account;
    if (!staff_write_store($state)) {
        return ['ok' => false, 'error' => 'The account could not be saved on this computer.'];
    }

    return ['ok' => true, 'account' => $account];
}

function staff_update_account(string $id, string $name, string $email, string $role, string $password = ''): array
{
    $name = trim($name);
    $email = trim($email);
    $role = $role === 'super_admin' ? 'super_admin' : 'admin';
    $current = staff_current();

    if (preview_text_length($name) < 2) {
        return ['ok' => false, 'error' => 'Enter a name.'];
    }
    if (!staff_valid_email($email)) {
        return ['ok' => false, 'error' => 'Enter a real email address.'];
    }

    $match = staff_find_email($email);
    if ($match !== null && ($match['id'] ?? '') !== $id) {
        return ['ok' => false, 'error' => 'That email already has an account.'];
    }

    $existing = staff_find_account($id);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'That account is gone.'];
    }
    if (($existing['role'] ?? '') === 'super_admin' && $role !== 'super_admin' && staff_super_count($id) === 0) {
        return ['ok' => false, 'error' => 'Keep at least one super admin.'];
    }
    if ($current !== null && $current['id'] === $id && $role !== 'super_admin' && staff_super_count($id) === 0) {
        return ['ok' => false, 'error' => 'You cannot remove your own super admin access.'];
    }
    if ($password !== '' && strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Use a password with at least 8 characters.'];
    }

    $state = staff_store();
    foreach ($state['accounts'] as $index => $account) {
        if (($account['id'] ?? '') !== $id) {
            continue;
        }
        $state['accounts'][$index]['name'] = $name;
        $state['accounts'][$index]['email'] = $email;
        $state['accounts'][$index]['role'] = $role;
        if ($password !== '') {
            $state['accounts'][$index]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    if (!staff_write_store($state)) {
        return ['ok' => false, 'error' => 'The account could not be saved on this computer.'];
    }

    if ($current !== null && $current['id'] === $id) {
        $_SESSION['admin_role'] = $role;
    }

    return ['ok' => true];
}

function staff_delete_account(string $id): array
{
    $current = staff_current();
    if ($current !== null && $current['id'] === $id) {
        return ['ok' => false, 'error' => 'You cannot delete the account you are using.'];
    }

    $existing = staff_find_account($id);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'That account is gone.'];
    }
    if (($existing['role'] ?? '') === 'super_admin' && staff_super_count($id) === 0) {
        return ['ok' => false, 'error' => 'Keep at least one super admin.'];
    }

    $state = staff_store();
    $state['accounts'] = array_values(array_filter(
        $state['accounts'],
        static fn (array $account): bool => ($account['id'] ?? '') !== $id
    ));
    if (!staff_write_store($state)) {
        return ['ok' => false, 'error' => 'The account could not be deleted on this computer.'];
    }

    return ['ok' => true];
}

function staff_create_invite(string $name, string $email, string $role): array
{
    $name = trim($name);
    $email = trim($email);
    $role = $role === 'super_admin' ? 'super_admin' : 'admin';

    if (preview_text_length($name) < 2) {
        return ['ok' => false, 'error' => 'Enter a name.'];
    }
    if (!staff_valid_email($email)) {
        return ['ok' => false, 'error' => 'Enter a real email address.'];
    }
    if (staff_find_email($email) !== null) {
        return ['ok' => false, 'error' => 'That email already has an account.'];
    }

    foreach (staff_invites() as $invite) {
        if (strtolower((string) ($invite['email'] ?? '')) === strtolower($email) && ($invite['status'] ?? '') === 'pending') {
            return ['ok' => false, 'error' => 'An invite is already waiting for that email.'];
        }
    }

    $token = bin2hex(random_bytes(16));
    $invite = [
        'id' => staff_new_id('invite'),
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'token' => $token,
        'status' => 'pending',
        'created' => preview_today()->format('Y-m-d'),
    ];
    $state = staff_store();
    $state['invites'][] = $invite;
    if (!staff_write_store($state)) {
        return ['ok' => false, 'error' => 'The invite could not be saved on this computer.'];
    }

    return ['ok' => true, 'invite' => $invite];
}

function staff_find_invite(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    foreach (staff_invites() as $invite) {
        if (($invite['token'] ?? '') === $token && ($invite['status'] ?? '') === 'pending') {
            return $invite;
        }
    }

    return null;
}

function staff_accept_invite(string $token, string $username, string $password): array
{
    $invite = staff_find_invite($token);
    if ($invite === null) {
        return ['ok' => false, 'error' => 'This invite is no longer open.'];
    }
    if (!staff_valid_username($username)) {
        return ['ok' => false, 'error' => 'Usernames can use letters, numbers, and . _ -'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Use a password with at least 8 characters.'];
    }
    if (staff_find_username($username) !== null) {
        return ['ok' => false, 'error' => 'That username is already in use.'];
    }

    $created = staff_create_account(
        (string) $invite['name'],
        (string) $invite['email'],
        $username,
        $password,
        (string) $invite['role']
    );
    if (!($created['ok'] ?? false)) {
        return $created;
    }

    $state = staff_store();
    foreach ($state['invites'] as $index => $row) {
        if (($row['id'] ?? '') === $invite['id']) {
            $state['invites'][$index]['status'] = 'accepted';
        }
    }
    staff_write_store($state);

    return ['ok' => true, 'account' => $created['account']];
}

function staff_revoke_invite(string $id): array
{
    $state = staff_store();
    $found = false;
    foreach ($state['invites'] as $index => $invite) {
        if (($invite['id'] ?? '') === $id) {
            $state['invites'][$index]['status'] = 'revoked';
            $found = true;
        }
    }
    if (!$found) {
        return ['ok' => false, 'error' => 'That invite is gone.'];
    }
    if (!staff_write_store($state)) {
        return ['ok' => false, 'error' => 'The invite could not be updated on this computer.'];
    }

    return ['ok' => true];
}

function staff_invite_url(string $token): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');

    return ($https ? 'https://' : 'http://') . $host . '/admin/invite.php?token=' . rawurlencode($token);
}
