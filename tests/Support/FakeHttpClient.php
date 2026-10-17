<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Net\HttpClientInterface;
use App\Net\NetException;
use App\Net\NetResponse;

/**
 * Serves canned responses by exact URL; records every request. Unknown URLs get a 404.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<string, NetResponse|NetException> */
    private array $routes = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    public function html(string $url, string $html, int $status = 200): self
    {
        $this->routes[$url] = new NetResponse($status, $html, ['content-type' => 'text/html; charset=utf-8'], $url);

        return $this;
    }

    public function text(string $url, string $body, int $status = 200): self
    {
        $this->routes[$url] = new NetResponse($status, $body, ['content-type' => 'text/plain'], $url);

        return $this;
    }

    public function json(string $url, mixed $data, int $status = 200): self
    {
        $this->routes[$url] = new NetResponse($status, (string) json_encode($data), ['content-type' => 'application/json'], $url);

        return $this;
    }

    public function redirectTo(string $url, string $finalUrl, string $html): self
    {
        $this->routes[$url] = new NetResponse(200, $html, ['content-type' => 'text/html'], $finalUrl);

        return $this;
    }

    public function fail(string $url, string $message = 'Connection refused'): self
    {
        $this->routes[$url] = new NetException($message);

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', ?float $timeout = null): NetResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $route = $this->routes[$url] ?? new NetResponse(404, 'Not found', ['content-type' => 'text/html'], $url);
        if ($route instanceof NetException) {
            throw $route;
        }

        return $route;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (array $r): string => $r['url'], $this->requests);
    }
}
