<?php
declare(strict_types=1);
// Compatibility: some legacy links/redirects target /auth/index.php
require_once __DIR__ . '/session.php';
start_secure_session();
if (function_exists('is_authenticated') && is_authenticated()) {
    header('Location: ' . app_base_url() . '/index.php');
} else {
    header('Location: ' . app_base_url() . '/auth/login.php');
}
exit;
