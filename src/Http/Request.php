<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $files
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $headers = [],
        public readonly string $ip = '',
        public readonly bool $https = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        // Support installs in a sub-folder (https://example.com/outreach/).
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base !== '' && $base !== '.' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . trim(rawurldecode($path), '/'),
            $_GET,
            $_POST,
            $_FILES,
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $https,
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => trim((string) $v), array_filter($value, 'is_scalar')));
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function isHtmx(): bool
    {
        return $this->header('hx-request') === 'true';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
