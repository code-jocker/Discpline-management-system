<?php
/**
 * API Configuration and Bootstrap
 * 
 * Loads environment variables, sets up error handling, and configures CORS
 */

declare(strict_types=1);

// Start output buffering for error handling
ob_start();

// Error reporting configuration
if (getenv('APP_DEBUG') === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Ensure logs directory exists
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Load environment variables from .env file
loadEnvironment();

// Set up headers for API
header('Content-Type: application/json; charset=utf-8');

// CORS Configuration
$allowedOrigins = array_values(array_filter(array_map('trim', explode(
    ',',
    getenv('ALLOWED_ORIGINS')
        ?: 'https://clever-c5b37.web.app,https://clever-c5b37.firebaseapp.com,https://sbms-ten.vercel.app'
))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set timezone
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

// Global error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("[$errno] $errstr in $errfile:$errline");
    
    if (getenv('APP_DEBUG') === 'true') {
        http_response_code(500);
        echo json_encode([
            'error' => 'Internal Server Error',
            'details' => [
                'errno' => $errno,
                'message' => $errstr,
                'file' => $errfile,
                'line' => $errline
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
    exit;
});

// Global exception handler
set_exception_handler(function(Throwable $e) {
    error_log('[EXCEPTION] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => getenv('APP_DEBUG') === 'true' ? $e->getMessage() : 'An error occurred',
        'type' => getenv('APP_DEBUG') === 'true' ? get_class($e) : null
    ]);
    exit;
});

/**
 * Load environment variables from .env file
 */
function loadEnvironment(): void {
    $envFile = __DIR__ . '/../.env';
    
    if (!file_exists($envFile)) {
        error_log('[WARNING] .env file not found at ' . $envFile);
        return;
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse key=value pairs
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }
            
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Get JSON request body
 */
function getJsonBody(): array {
    $input = file_get_contents('php://input');
    
    if (empty($input)) {
        return [];
    }
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    
    return $data ?? [];
}

/**
 * Send JSON response
 */
function sendJson(mixed $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 */
function sendError(string $message, int $statusCode = 400, array $details = []): void {
    $response = [
        'error' => true,
        'message' => $message,
        'status' => $statusCode
    ];
    
    if (!empty($details)) {
        $response['details'] = $details;
    }
    
    sendJson($response, $statusCode);
}

/**
 * Validate required fields
 */
function validateRequired(array $data, array $required): ?string {
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            return "Field '$field' is required";
        }
    }
    return null;
}

/**
 * Log API request
 */
function logRequest(string $endpoint, string $method, string $status = 'ok'): void {
    $logFile = __DIR__ . '/../logs/api.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $log = "[$timestamp] $method $endpoint | Status: $status | IP: $ip\n";
    error_log($log, 3, $logFile);
}
?>
