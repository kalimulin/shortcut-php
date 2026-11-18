<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable, middlewares: list<callable>}> */
    private array $routes = [];

    /**
     * @param list<callable> $middlewares
     */
    public function add(string $method, string $path, callable $handler, array $middlewares = []): void
    {
        $normalized = $path === '/' ? '/' : rtrim($path, '/');
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $normalized);
        $pattern = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(Request $request): void
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = urldecode($value);
                }
            }

            foreach ($route['middlewares'] as $middleware) {
                $middleware($request, $params);
            }

            ($route['handler'])($request, $params);
            return;
        }

        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Метод не поддерживается для данного маршрута');
        }

        throw ApiException::notFound('NOT_FOUND', 'Маршрут не найден');
    }
}
