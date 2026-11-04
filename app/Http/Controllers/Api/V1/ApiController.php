<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Response;

abstract class ApiController
{
    protected function respond(array $payload, int $status = 200, array $headers = []): void
    {
        (new Response())->json($payload, $status, $headers);
    }

    protected function success(array $data = [], string $message = 'OK', int $status = 200): void
    {
        $this->respond([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $status = 422, array $data = []): void
    {
        $this->respond([
            'status' => 'error',
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
