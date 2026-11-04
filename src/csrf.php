<?php

declare(strict_types=1);

/**
 * Simple session CSRF tokens for patient and other HTML form posts.
 */

function csrf_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
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
