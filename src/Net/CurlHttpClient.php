<?php

declare(strict_types=1);

namespace App\Net;

/**
 * cURL client with a hard response size limit, timeouts and limited redirects
 * (http/https only).
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private float $timeout = 10.0,
        private int $maxBytes = 1_048_576,
        private int $maxRedirects = 3,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', ?float $timeout = null): NetResponse
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new NetException('Only http and https URLs are allowed.');
        }
        $ch = curl_init();
        $received = '';
        $truncated = false;
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $timeout ??= $this->timeout;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min(5.0, $timeout) * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (preg_match('#^HTTP/\S+\s+\d+#', $line)) {
                    $responseHeaders = []; // a new response after a redirect
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$received, &$truncated): int {
                $received .= $chunk;
                if (strlen($received) > $this->maxBytes) {
                    $received = substr($received, 0, $this->maxBytes);
                    $truncated = true;

                    return 0; // abort the transfer
                }

                return strlen($chunk);
            },
        ]);
        if ($method !== 'GET' && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false && !$truncated) {
            throw new NetException(sprintf('Request to %s failed: %s (%d)', parse_url($url, PHP_URL_HOST) ?: $url, $error, $errno));
        }

        return new NetResponse($status, $received, $responseHeaders, $finalUrl, $truncated);
    }
}
