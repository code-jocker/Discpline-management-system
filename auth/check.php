<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php'; // Ensure DB is available for logging
require_once dirname(__DIR__) . '/auth/session.php';
start_secure_session();
require_once dirname(__DIR__) . '/includes/i18n.php';
require_once dirname(__DIR__) . '/auth/AuthController.php';
require_once dirname(__DIR__) . '/models/User.php';

$authController = new AuthController();
if (!is_authenticated()) {
    $authController->loginFromRememberMe();
}

function require_auth(): void // Moved to global scope
{
    if (!is_authenticated()) {
        if (is_ajax_request()) {
            json_response(['status' => 'error', 'message' => 'Authentication required.'], 401);
        }

        header('Location: ' . app_base_url() . '/auth/login.php');
        exit;
    }
}

function require_role_ids(array $allowedRoleIds): void // Moved to global scope
{
    require_auth();

    if (!in_array(current_role_id(), $allowedRoleIds, true)) {
        if (is_ajax_request()) {
            json_response(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        http_response_code(403);
        exit('Access denied.');
    }
}

function current_auth_user(): ?array // Moved to global scope
{
    // No need to call require_auth() here, as it would cause an infinite loop
    // if called from within require_auth() itself.
    // This function should simply return the user if authenticated.
    if (!is_authenticated()) {
        return null;
    }

    $userModel = new User(Database::connection());
    return $userModel->findById((int) current_user_id());
}

function current_user_has_permission(string $permissionName): bool // Moved to global scope
{
    require_auth();

    $userId = current_user_id();
    if ($userId === null) {
        return false;
    }

    $userModel = new User(Database::connection());

    try {
        return $userModel->hasPermission($userId, $permissionName);
    } catch (Throwable $exception) {
        Database::logError('Permission lookup failed', ['exception' => $exception->getMessage()]);
        return false;
    }
}

function require_permission(string $permissionName): void // Moved to global scope
{
    if (!current_user_has_permission($permissionName)) {
        if (is_ajax_request()) {
            json_response(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        http_response_code(403);
        exit('Access denied.');
    }
}
