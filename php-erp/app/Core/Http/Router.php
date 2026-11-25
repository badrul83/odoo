<?php
declare(strict_types=1);

namespace Erp\Core\Http;

final class Router
{
    /** @var array<string, list<array{pattern: string, keys: list<string>, handler: callable(Request, array): Response}>> */
    private array $routes = [];

    /** @param callable(Request, array): Response $handler */
    public function get(string $path, callable $handler): void { $this->add('GET', $path, $handler); }
    /** @param callable(Request, array): Response $handler */
    public function post(string $path, callable $handler): void { $this->add('POST', $path, $handler); }
    /** @param callable(Request, array): Response $handler */
    public function patch(string $path, callable $handler): void { $this->add('PATCH', $path, $handler); }
    /** @param callable(Request, array): Response $handler */
    public function put(string $path, callable $handler): void { $this->add('PUT', $path, $handler); }
    /** @param callable(Request, array): Response $handler */
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    /** @param callable(Request, array): Response $handler */
    private function add(string $method, string $path, callable $handler): void
    {
        $keys = [];
        $pattern = '';
        foreach (explode('/', trim($path, '/')) as $segment) {
            if (preg_match('/^\{([a-zA-Z][a-zA-Z0-9_]*)\}$/', $segment, $match) === 1) {
                $keys[] = $match[1];
                $pattern .= '/([^/]+)';
            } else {
                $pattern .= '/' . preg_quote($segment, '#');
            }
        }
        $this->routes[$method][] = [
            'pattern' => '#^' . ($pattern !== '' ? $pattern : '/') . '$#',
            'keys' => $keys,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        if ($request->hasInvalidJson()) {
            return Response::json(['ok' => false, 'error' => 'Request body must be a JSON object.'], 400);
        }

        foreach ($this->routes[$request->method()] ?? [] as $route) {
            if (preg_match($route['pattern'], $request->path(), $matches) !== 1) {
                continue;
            }
            array_shift($matches);
            $params = [];
            foreach ($route['keys'] as $index => $key) {
                $params[$key] = rawurldecode($matches[$index] ?? '');
            }
            try {
                return ($route['handler'])($request, $params);
            } catch (\Throwable $error) {
                error_log(sprintf('ERP request failed [%s %s]: %s', $request->method(), $request->path(), $error->getMessage()));
                return Response::json(['ok' => false, 'error' => 'The request could not be completed.'], 500);
            }
        }

        return Response::json(['ok' => false, 'error' => 'Route not found.'], 404);
    }
}
