<?php

declare(strict_types=1);

namespace App\Net;

/**
 * Outbound HTTP for websites, robots.txt and Google Places. Must return any
 * HTTP status as a response and throw NetException only for transport errors.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws NetException
     */
    public function request(string $method, string $url, array $headers = [], string $body = '', ?float $timeout = null): NetResponse;
}
