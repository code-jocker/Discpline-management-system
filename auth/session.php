<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php'; // For Database::logError
const SBMS_SESSION_TIMEOUT = 1800;
const SBMS_REMEMBER_ME_DAYS = 30;

function app_base_url(): string
{
    $envBaseUrl = getenv('SBMS_BASE_URL');
    if ($envBaseUrl) {
        return rtrim($envBaseUrl, '/');
    }

    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)) {
        $scheme = 'https';
    }

    $hostHeader = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $hostHeader = trim($hostHeader);
    $parsedHost = parse_url('http://' . $hostHeader, PHP_URL_HOST) ?: $hostHeader;
    $parsedPort = parse_url('http://' . $hostHeader, PHP_URL_PORT);

    $host = $parsedHost;
    if (preg_match('/^(localhost|127(?:\.\d{1,3}){3}|0\.0\.0\.0|::1)$/i', $host)) {
        $host = '127.0.0.1';
    }

    $port = '';
    if (is_numeric($parsedPort) && (int) $parsedPort > 0) {
        $port = ':' . (int) $parsedPort;
    }

    $basePath = '';
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $projectRoot = dirname(__DIR__);

    if ($documentRoot !== '' && realpath($documentRoot) !== false && realpath($projectRoot) !== false) {
        $normalizedDocumentRoot = str_replace('\\', '/', realpath($documentRoot));
        $normalizedProjectRoot = str_replace('\\', '/', realpath($projectRoot));

        if (str_starts_with($normalizedProjectRoot, $normalizedDocumentRoot)) {
            $relativePath = substr($normalizedProjectRoot, strlen($normalizedDocumentRoot));
            $basePath = '/' . trim(str_replace('\\', '/', $relativePath), '/');
            if ($basePath === '/') {
                $basePath = '';
            }
        }
    }

    return rtrim($scheme . '://' . $host . $port . $basePath, '/');
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        enforce_session_security();
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');

    session_name('SBMSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_start();
    enforce_session_security();
}

function enforce_session_security(): void
{
    $now = time();
    $fingerprint = session_fingerprint();

    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = $fingerprint;
    } elseif (!hash_equals((string) $_SESSION['fingerprint'], $fingerprint)) {
        // Refresh the fingerprint for users behind proxies or changing local network conditions
        // instead of destroying the session mid-request.
        $_SESSION['fingerprint'] = $fingerprint;
    }

    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SBMS_SESSION_TIMEOUT) {
        destroy_session();
        return;
    }

    $_SESSION['last_activity'] = $now;
}

function session_fingerprint(): string
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    // Keep the session fingerprint stable across local/proxy network changes.
    return hash('sha256', $userAgent . '|' . $accept . '|' . $acceptLanguage);
}

function destroy_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function is_authenticated(): bool
{
    start_secure_session();

    return !empty($_SESSION['user_id']) && !empty($_SESSION['role_id']);
}

function login_session(array $user): void
{
    start_secure_session();

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role_id'] = (int) $user['role_id'];
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['fingerprint'] = session_fingerprint();
}

function current_user_id(): ?int
{
    return is_authenticated() ? (int) $_SESSION['user_id'] : null;
}

function current_role_id(): ?int
{
    return is_authenticated() ? (int) $_SESSION['role_id'] : null;
}

function e(?string $value): string // Moved to session.php as a core utility
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_ajax_request(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip_address(): string
{
    $keys = ['REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $parts = array_map('trim', explode(',', (string) $_SERVER[$key]));
            foreach ($parts as $part) {
                if ($part !== '' && filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }
    }

    return '0.0.0.0';
}
