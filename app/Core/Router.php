<?php
declare(strict_types=1);

namespace App\Core;

use Closure;

final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function group(array $attributes, Closure $callback): void
    {
        $parent = end($this->groupStack) ?: ['prefix' => '', 'middleware' => []];
        $group = [
            'prefix' => ($parent['prefix'] ?? '') . ($attributes['prefix'] ?? ''),
            'middleware' => array_merge($parent['middleware'] ?? [], $attributes['middleware'] ?? []),
        ];

        $this->groupStack[] = $group;
        $callback($this);
        array_pop($this->groupStack);
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = rtrim($request->path(), '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $path);
            if ($params === null) {
                continue;
            }

            foreach ($params as $key => $value) {
                $_GET[$key] = $value;
                $_REQUEST[$key] = $value;
            }

            $handler = $route['handler'];
            $middlewareStack = $route['middleware'];

            $runner = array_reduce(
                array_reverse($middlewareStack),
                static function (callable $next, string $middlewareClass): callable {
                    return static function (Request $request) use ($middlewareClass, $next): void {
                        $middleware = new $middlewareClass();
                        $middleware->handle($request, $next);
                    };
                },
                static function (Request $request) use ($handler): void {
                    if (is_array($handler)) {
                        [$class, $method] = $handler;
                        (new $class())->{$method}($request);
                        return;
                    }

                    $handler($request);
                }
            );

            $runner($request);
            return;
        }

        (new Response())->json([
            'status' => 'error',
            'message' => 'Endpoint not found.',
        ], 404);
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middleware): void
    {
        $group = end($this->groupStack) ?: ['prefix' => '', 'middleware' => []];
        $routePath = rtrim(($group['prefix'] ?? '') . $path, '/') ?: '/';

        $this->routes[] = [
            'method' => $method,
            'path' => $routePath,
            'handler' => $handler,
            'middleware' => array_merge($group['middleware'] ?? [], $middleware),
        ];
    }

    private function matchPath(string $routePath, string $requestPath): ?array
    {
        if ($routePath === $requestPath) {
            return [];
        }

        $routeParts = explode('/', trim($routePath, '/'));
        $requestParts = explode('/', trim($requestPath, '/'));

        if (count($routeParts) !== count($requestParts)) {
            return null;
        }

        $params = [];
        foreach ($routeParts as $index => $routePart) {
            $requestPart = $requestParts[$index] ?? '';

            if ($routePart !== '' && $routePart[0] === ':') {
                $params[substr($routePart, 1)] = $requestPart;
                continue;
            }

            if ($routePart !== $requestPart) {
                return null;
            }
        }

        return $params;
    }
}
