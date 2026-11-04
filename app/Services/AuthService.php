<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class AuthService
{
    private UserRepository $users;
    private JwtService $jwt;

    public function __construct(UserRepository $users, JwtService $jwt)
    {
        $this->users = $users;
        $this->jwt = $jwt;
    }

    public function login(string $email, string $password): ?array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        $token = $this->jwt->issue([
            'sub' => (int) $user['id'],
            'role_id' => (int) $user['role_id'],
            'role_name' => (string) $user['role_name'],
            'email' => (string) $user['email'],
        ]);

        return [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => (string) $user['full_name'],
                'email' => (string) $user['email'],
                'role_id' => (int) $user['role_id'],
                'role_name' => (string) $user['role_name'],
            ],
        ];
    }
}
