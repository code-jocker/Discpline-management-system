<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private array $attributes = [];
    private ?array $jsonBody = null;
    private array $server;
    private array $get;
    private array $post;
    private array $files;
    private string $rawBody;

    public function __construct(array $server, array $get, array $post, array $files, string $rawBody)
    {
        $this->server = $server;
        $this->get = $get;
        $this->post = $post;
        $this->files = $files;
        $this->rawBody = $rawBody;
    }

    public static function capture(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_FILES, file_get_contents('php://input') ?: '');
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $explicitRoute = $this->get['route'] ?? null;
        if (is_string($explicitRoute) && $explicitRoute !== '') {
            return '/' . trim($explicitRoute, '/');
        }

        $requestUri = $this->server['REQUEST_URI'] ?? '/';
        $uriPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $scriptName = $this->server['SCRIPT_NAME'] ?? '';
        $baseDir = str_replace('\\', '/', dirname($scriptName));

        if ($baseDir !== '/' && $baseDir !== '.') {
            $normalizedBase = rtrim($baseDir, '/');
            if (str_starts_with($uriPath, $normalizedBase)) {
                $uriPath = substr($uriPath, strlen($normalizedBase)) ?: '/';
            }
        }

        $scriptBase = basename($scriptName);
        if ($scriptBase !== '' && str_starts_with($uriPath, '/' . $scriptBase)) {
            $uriPath = substr($uriPath, strlen('/' . $scriptBase)) ?: '/';
        }

        return '/' . trim($uriPath, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        return $this->get[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if ($name === 'Content-Type' && isset($this->server['CONTENT_TYPE'])) {
            return $this->server['CONTENT_TYPE'];
        }

        return $this->server[$normalized] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $authorization = (string) $this->header('Authorization', '');
        if ($authorization !== '' && preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $contentType = strtolower((string) $this->header('Content-Type', ''));
        if (!str_contains($contentType, 'application/json') || $this->rawBody === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($this->rawBody, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];

        return $this->jsonBody;
    }

    public function ipAddress(): string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($this->server[$key])) {
                $parts = explode(',', (string) $this->server[$key]);
                return trim($parts[0]);
            }
        }

        return '0.0.0.0';
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
