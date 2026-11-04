<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function json(array $payload, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
