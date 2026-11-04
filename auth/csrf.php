<?php
declare(strict_types=1);

function csrf_token(): string
{
    start_secure_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf_token(?string $token): bool
{
    start_secure_session();

    if (!is_string($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function assert_csrf_token(?string $token): void
{
    if (!verify_csrf_token($token)) {
        json_response(
            [
                'status' => 'error',
                'message' => 'Your session token is invalid or expired. Refresh the page and try again.',
            ],
            419
        );
    }
}
