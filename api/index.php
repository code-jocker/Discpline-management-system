<?php
/**
 * API Router - Main Entry Point
 * 
 * Handles all API requests and routes to appropriate handlers
 */

declare(strict_types=1);

// Include bootstrap
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config/Database.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api(?:/|$)#', '', $path);
$segments = array_filter(explode('/', $path));

// Health check endpoint
if (empty($segments) || $segments[0] === 'health') {
    sendJson([
        'status' => 'ok',
        'message' => 'SBMS API is running',
        'timestamp' => date('Y-m-d H:i:s'),
        'environment' => getenv('APP_ENV') ?: 'unknown',
        'version' => '1.0.0'
    ]);
}

// Route API requests
$resource = $segments[0] ?? null;
$action = $segments[1] ?? null;

try {
    switch ($resource) {
        case 'auth':
            handleAuth($method, $action);
            break;
            
        case 'users':
            handleUsers($method, $action, $segments[2] ?? null);
            break;
            
        case 'students':
            handleStudents($method, $action, $segments[2] ?? null);
            break;
            
        case 'test':
        case 'test-connection':
            handleTest($method);
            break;
            
        default:
            sendError('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    error_log('[API ERROR] ' . $e->getMessage());
    sendError($e->getMessage(), 500);
}

/**
 * Handle authentication endpoints
 */
function handleAuth(string $method, ?string $action): void {
    if ($method === 'POST' && $action === 'login') {
        $data = getJsonBody();
        
        $error = validateRequired($data, ['email', 'password']);
        if ($error) {
            sendError($error, 400);
        }
        
        // TODO: Implement actual login
        sendJson([
            'success' => true,
            'message' => 'Login endpoint ready',
            'token' => 'placeholder_token_' . time()
        ]);
        
    } elseif ($method === 'POST' && $action === 'logout') {
        sendJson([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
        
    } elseif ($method === 'GET' && $action === 'status') {
        sendJson([
            'authenticated' => false,
            'user' => null
        ]);
        
    } else {
        sendError('Auth endpoint not found', 404);
    }
}

/**
 * Handle user endpoints
 */
function handleUsers(string $method, ?string $action, ?string $id): void {
    try {
        $db = Database::connection();
        
        switch ($method) {
            case 'GET':
                if ($id) {
                    // Get specific user
                    $user = $db->fetch(
                        'SELECT id, name, email, role FROM users WHERE id = ?',
                        [$id]
                    );
                    
                    if (!$user) {
                        sendError('User not found', 404);
                    }
                    
                    sendJson(['data' => $user]);
                } else {
                    // List all users
                    $users = $db->fetchAll('SELECT id, name, email, role FROM users LIMIT 100');
                    sendJson(['data' => $users, 'total' => count($users)]);
                }
                break;
                
            case 'POST':
                $data = getJsonBody();
                $error = validateRequired($data, ['name', 'email', 'password']);
                if ($error) {
                    sendError($error, 400);
                }
                
                // Hash password
                $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
                
                $db->query(
                    'INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())',
                    [$data['name'], $data['email'], $hashedPassword, $data['role'] ?? 'user']
                );
                
                sendJson([
                    'success' => true,
                    'message' => 'User created',
                    'id' => $db->lastInsertId()
                ], 201);
                break;
                
            default:
                sendError('Method not allowed', 405);
        }
        
    } catch (Exception $e) {
        error_log('[USERS API ERROR] ' . $e->getMessage());
        sendError('Database error: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle student endpoints
 */
function handleStudents(string $method, ?string $action, ?string $id): void {
    try {
        $db = Database::connection();
        
        switch ($method) {
            case 'GET':
                if ($id) {
                    // Get specific student
                    $student = $db->fetch(
                        'SELECT id, name, email, student_code FROM students WHERE id = ?',
                        [$id]
                    );
                    
                    if (!$student) {
                        sendError('Student not found', 404);
                    }
                    
                    sendJson(['data' => $student]);
                } else {
                    // List students
                    $limit = $_GET['limit'] ?? 100;
                    $offset = $_GET['offset'] ?? 0;
                    
                    $students = $db->fetchAll(
                        'SELECT id, name, email, student_code FROM students LIMIT ? OFFSET ?',
                        [$limit, $offset]
                    );
                    
                    sendJson(['data' => $students, 'limit' => $limit, 'offset' => $offset]);
                }
                break;
                
            default:
                sendError('Method not allowed', 405);
        }
        
    } catch (Exception $e) {
        error_log('[STUDENTS API ERROR] ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

/**
 * Handle test endpoints
 */
function handleTest(string $method): void {
    if ($method === 'GET') {
        try {
            $db = Database::connection();
            
            // Test database connection
            $result = $db->fetch('SELECT 1 as status');
            
            sendJson([
                'status' => 'success',
                'message' => 'Database connection successful',
                'database' => getenv('DB_NAME'),
                'host' => getenv('DB_HOST'),
                'environment' => getenv('APP_ENV'),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            sendError('Database connection failed: ' . $e->getMessage(), 500);
        }
    } else {
        sendError('Method not allowed', 405);
    }
}

