<?php

declare(strict_types=1);

namespace App\Net;

final class NetResponse
{
    /** @var array<string, string> lower-cased names */
    public array $headers = [];

    /**
     * @param array<string, string> $headers
     * @param string                $finalUrl URL after redirects
     */
    public function __construct(public int $status, public string $body, array $headers = [], public string $finalUrl = '', public bool $truncated = false)
    {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function isHtml(): bool
    {
        $type = strtolower($this->header('content-type'));

        return $type === '' || str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }
}
