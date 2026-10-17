<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal router: "GET /leads/{id}" patterns, {id} matches digits, {token} hex.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable, public: bool}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $public = false): void
    {
        $regex = '#^' . preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => $m[1] === 'token' ? '(?P<token>[a-f0-9]{32})' : '(?P<' . $m[1] . '>\d+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'public' => $public];
    }

    /**
     * @return array{handler: callable, params: array<string, string>, public: bool}|null|false null = not found, false = wrong method
     */
    public function match(string $method, string $path): array|null|false
    {
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($route['method'] === 'GET' && $method === 'HEAD')) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return ['handler' => $route['handler'], 'params' => $params, 'public' => $route['public']];
        }

        return $methodMismatch ? false : null;
    }
}
