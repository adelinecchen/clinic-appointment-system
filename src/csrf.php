<?php

declare(strict_types=1);

/**
 * Session CSRF tokens for patient and staff HTML form posts.
 */

function csrf_session_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function csrf_configure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $lifetime = (int) (getenv('ADMIN_SESSION_LIFETIME') !== false && getenv('ADMIN_SESSION_LIFETIME') !== ''
        ? (string) getenv('ADMIN_SESSION_LIFETIME')
        : '28800');
    if ($lifetime < 300) {
        $lifetime = 300;
    }

    $https = csrf_session_https();
    session_name('clinic_admin');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($https) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.gc_maxlifetime', (string) $lifetime);
}

function csrf_ensure_session(): void
{
    csrf_configure_session();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (headers_sent()) {
            // CLI tests may print before logout recreates a session; treat as logged out.
            return;
        }
        session_start();
    }
}

function csrf_token(): string
{
    csrf_ensure_session();
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    csrf_ensure_session();
    $expected = $_SESSION['_csrf'] ?? '';
    if (!is_string($expected) || $expected === '' || $token === null || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

function csrf_verify_request(): bool
{
    return csrf_verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null);
}

function csrf_rotate(): void
{
    csrf_ensure_session();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
