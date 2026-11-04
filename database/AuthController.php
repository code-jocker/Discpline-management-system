<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php'; // Added for Database::logError
require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/models/User.php';

final class AuthController
{
    private User $users;

    public function __construct()
    {
        $this->users = new User(Database::connection());
    }

    public function isBootstrapMode(): bool
    {
        return $this->users->countUsers() === 0;
    }

    public function roles(): array
    {
        return $this->users->getRoleOptions();
    }

    public function login(array $input): array
    {
        $email = $this->sanitizeEmail($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $rememberMe = !empty($input['remember_me']);

        if ($email === '' || $password === '') {
            return $this->error('Email and password are required.', 422);
        }

        try {
            $user = $this->users->findByEmail($email);
        } catch (Throwable $exception) {
            Database::logError('Login lookup failed', ['exception' => $exception->getMessage()]);
            return $this->error('We could not complete your login right now.', 500);
        }

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $this->safeAudit(null, 'login_failed');
            return $this->error('Invalid email or password.', 401);
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $this->users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
            } catch (Throwable $exception) {
                Database::logError('Password rehash update failed', ['exception' => $exception->getMessage()]);
            }
        }

        login_session($user);
        $this->safeAudit((int) $user['id'], 'login');

        if ($rememberMe) {
            $this->issueRememberMeCookie((int) $user['id']);
        } else {
            $this->clearRememberMeCookie((int) $user['id']);
        }

        return [
            'status' => 'success',
            'message' => 'Login successful. Redirecting...',
            'redirect' => app_base_url() . '/index.php',
        ];
    }

    public function register(array $input, bool $isAdminAction): array
    {
        $bootstrapMode = $this->isBootstrapMode();
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = $this->sanitizeEmail($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $confirmPassword = (string) ($input['confirm_password'] ?? '');
        $roleId = (int) ($input['role_id'] ?? 0);

        if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
            return $this->error('All fields are required.', 422);
        }

        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 100) {
            return $this->error('Full name must be between 3 and 100 characters.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Enter a valid email address.', 422);
        }

        if ($password !== $confirmPassword) {
            return $this->error('Password confirmation does not match.', 422);
        }

        if (!$this->isStrongPassword($password)) {
            return $this->error('Use at least 12 characters with upper, lower, number, and symbol.', 422);
        }

        if (!$bootstrapMode && !$isAdminAction) {
            return $this->error('You are not authorized to register users.', 403);
        }

        try {
            if ($this->users->existsByEmail($email)) {
                return $this->error('That email address is already registered.', 409);
            }

            if ($bootstrapMode) {
                $roleId = (int) ($this->users->getBootstrapAdminRoleId() ?? 0);
                if ($roleId === 0) { // Changed from <= 0 to === 0 as roleId can be 0 if not found
                    return $this->error('No privileged bootstrap role was found in the roles table.', 500);
                }
            }

            if (!$this->users->roleExists($roleId)) {
                return $this->error('Select a valid role.', 422);
            }

            $userId = $this->users->create(
                $fullName,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $roleId
            );
        } catch (Throwable $exception) {
            Database::logError('User registration failed', ['exception' => $exception->getMessage()]);
            return $this->error('We could not create the user right now.', 500);
        }

        $this->safeAudit($userId, 'register');

        if ($bootstrapMode) {
            $user = $this->users->findById($userId);
            if ($user) {
                login_session($user);
            }

            return [
                'status' => 'success',
                'message' => 'Initial administrator created successfully.',
                'redirect' => app_base_url() . '/index.php',
            ];
        }

        return [
            'status' => 'success',
            'message' => 'User account created successfully.',
            'redirect' => app_base_url() . '/auth/register.php',
        ];
    }

    public function forgotPassword(array $input): array
    {
        $email = $this->sanitizeEmail($input['email'] ?? '');

        if ($email === '') {
            return $this->error('Email address is required.', 422);
        }

        try {
            $user = $this->users->findByEmail($email);
            if ($user) {
                $token = $this->users->generateAndStorePasswordResetToken((int) $user['id']);

                // In a production environment, you would send the email here:
                // Mailer::sendResetLink($email, $token);

                $this->safeAudit((int) $user['id'], 'password_reset_requested');
            }
        } catch (Throwable $exception) {
            Database::logError('Forgot password lookup failed', ['exception' => $exception->getMessage()]);
        }

        return [
            'status' => 'success',
            'message' => 'If that email is in our system, you will receive a reset link shortly.',
        ];
    }

    public function logout(): void
    {
        $userId = current_user_id();
        if ($userId !== null) {
            $this->clearRememberMeCookie($userId);
            $this->safeAudit($userId, 'logout');
        }

        destroy_session();
    }

    public function loginFromRememberMe(): bool
    {
        if (empty($_COOKIE['sbms_remember'])) {
            return false;
        }

        $parts = explode(':', (string) $_COOKIE['sbms_remember']);
        if (count($parts) !== 2) {
            return false;
        }

        [$selector, $validator] = $parts;

        try {
            $token = $this->users->findRememberToken($selector);
        } catch (Throwable $exception) {
            Database::logError('Remember-me lookup failed', ['exception' => $exception->getMessage()]);
            return false;
        }

        if (!$token) {
            return false;
        }

        if (strtotime((string) $token['expires_at']) < time()) {
            $this->users->deleteRememberTokenById((int) $token['id']);
            return false;
        }

        $hashedValidator = hash('sha256', $validator);
        if (!hash_equals((string) $token['hashed_validator'], $hashedValidator)) { // Ensure strict comparison
            $this->users->deleteRememberTokenById((int) $token['id']);
            return false;
        }

        $user = $this->users->findById((int) $token['user_id']);
        if (!$user) {
            return false;
        }

        login_session($user); // user array now contains role_name from findRememberToken
        $this->issueRememberMeCookie((int) $user['id']);
        $this->safeAudit((int) $user['id'], 'remember_login');

        return true;
    }

    private function sanitizeEmail(string $email): string
    {
        return strtolower(trim(filter_var($email, FILTER_SANITIZE_EMAIL)));
    }

    private function isStrongPassword(string $password): bool
    {
        return strlen($password) >= 12
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/\d/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }

    private function issueRememberMeCookie(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $hashedValidator = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + (SBMS_REMEMBER_ME_DAYS * 86400));

        try {
            $this->users->deleteRememberTokensForUser($userId);
            $this->users->storeRememberToken($userId, $selector, $hashedValidator, $expiresAt);
        } catch (Throwable $exception) {
            Database::logError('Remember-me token creation failed', ['exception' => $exception->getMessage()]);
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443');

        setcookie('sbms_remember', $selector . ':' . $validator, [
            'expires' => time() + (SBMS_REMEMBER_ME_DAYS * 86400),
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberMeCookie(int $userId): void
    {
        try {
            $this->users->deleteRememberTokensForUser($userId);
        } catch (Throwable $exception) {
            Database::logError('Remember-me token cleanup failed', ['exception' => $exception->getMessage()]);
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443');

        setcookie('sbms_remember', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function safeAudit(?int $userId, string $action): void
    {
        try {
            $this->users->logAudit($userId, $action, client_ip_address());
        } catch (Throwable $exception) {
            Database::logError('Audit log write failed', ['exception' => $exception->getMessage()]);
        }
    }

    private function error(string $message, int $code): array
    {
        http_response_code($code);

        return [
            'status' => 'error',
            'message' => $message,
        ];
    }
}
