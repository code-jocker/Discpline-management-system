<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Request;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\JwtService;
use Database;

final class AuthApiController extends ApiController
{
    public function login(Request $request): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            $this->error('Email and password are required.', 422);
            return;
        }

        $service = new AuthService(new UserRepository(Database::connection()), new JwtService());
        $result = $service->login($email, $password);

        if ($result === null) {
            $this->error('Invalid email or password.', 401);
            return;
        }

        $this->success($result, 'Login successful.');
    }
}
