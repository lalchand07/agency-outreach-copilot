<?php

declare(strict_types=1);

namespace App\Research;

final class FetchResult
{
    /**
     * @param list<Page>                                $pages
     * @param list<array{url: string, reason: string}> $skipped
     */
    public function __construct(public array $pages = [], public array $skipped = [])
    {
    }

    public function page(string $url): ?Page
    {
        $wanted = self::key($url);
        foreach ($this->pages as $page) {
            if (self::key($page->url) === $wanted) {
                return $page;
            }
        }

        return null;
    }

    /** Compares URLs ignoring scheme, "www.", trailing slash and case of the host. */
    public static function key(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host'])) {
            return strtolower(rtrim(trim($url), '/'));
        }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host . rtrim($parts['path'] ?? '', '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
