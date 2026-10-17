<?php

declare(strict_types=1);

namespace App\Research;

use App\Leads\Normalizer;
use App\Net\HttpClientInterface;
use App\Net\NetException;
use App\Support\Sleeper;

/**
 * Fetches a business's own public website politely:
 *  - only the home page plus up to (maxPages - 1) pages linked from it on the same site,
 *    preferring about / contact / services pages;
 *  - robots.txt is checked first and obeyed (including Crawl-delay);
 *  - an identifying User-Agent, a minimum delay between requests, timeouts and a size cap.
 */
final class WebsiteFetcher
{
    public const AGENT_TOKEN = 'AgencyOutreachCopilot';
    public const MAX_PAGES = 5;

    /** Path/anchor keywords of pages worth reading, best first. */
    private const PRIORITY = [
        'about' => 10, 'who-we-are' => 10, 'our-story' => 9, 'company' => 6,
        'contact' => 9, 'service' => 9, 'umrah' => 8, 'hajj' => 8, 'package' => 7, 'holiday' => 7,
        'visa' => 6, 'flight' => 6, 'tour' => 5, 'corporate' => 5, 'atol' => 5, 'team' => 4,
        'من-نحن' => 8, 'اتصل' => 8, 'خدمات' => 8,
    ];

    /** Never crawl these: they are not about the business, or could change state. */
    private const SKIP = '/(login|logout|signin|sign-in|register|cart|checkout|basket|account|wp-admin|wp-login|feed|\.pdf$|\.jpe?g$|\.png$|\.gif$|\.zip$|\.docx?$|\.xlsx?$|\/tag\/|\/category\/|\/author\/|\?add-to-cart|privacy|cookie|terms)/i';

    /** @var array<string, RobotsTxt> */
    private array $robots = [];

    /** @var array<string, int> host → time of last request (ms) */
    private array $lastRequest = [];

    public function __construct(
        private HttpClientInterface $http,
        private Sleeper $sleeper,
        private string $contact = '',
        private int $minDelayMs = 1000,
        private float $timeout = 10.0,
        private PageExtractor $extractor = new PageExtractor(),
    ) {
    }

    public function userAgent(): string
    {
        return self::AGENT_TOKEN . '/1.0 (+' . ($this->contact !== '' ? $this->contact : 'https://github.com/lalchand07/agency-outreach-copilot') . ')';
    }

    public function fetch(string $website, int $maxPages = self::MAX_PAGES): FetchResult
    {
        $maxPages = max(1, min(self::MAX_PAGES, $maxPages));
        $result = new FetchResult();
        $home = self::homeUrl($website);
        if ($home === null) {
            $result->skipped[] = ['url' => $website, 'reason' => 'not a valid website address'];

            return $result;
        }

        $homePage = $this->fetchPage($home, $result);
        if ($homePage === null) {
            return $result;
        }
        $result->pages[] = $homePage;
        $siteDomain = Normalizer::domain($homePage->url);

        $candidates = [];
        foreach ($homePage->links as $link) {
            $href = $link['href'];
            if (!preg_match('#^https?://#', $href) || Normalizer::domain($href) !== $siteDomain || preg_match(self::SKIP, $href)) {
                continue;
            }
            $key = FetchResult::key($href);
            if ($key === FetchResult::key($homePage->url) || isset($candidates[$key])) {
                continue;
            }
            $haystack = strtolower(rawurldecode((string) parse_url($href, PHP_URL_PATH)) . ' ' . $link['text']);
            $score = 0;
            foreach (self::PRIORITY as $word => $weight) {
                if (str_contains($haystack, $word)) {
                    $score = max($score, $weight);
                }
            }
            if ($score > 0) {
                $candidates[$key] = ['url' => $href, 'score' => $score];
            }
        }
        uasort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $crawlDelay = $this->robotsFor($homePage->url)->crawlDelay(self::AGENT_TOKEN);
        if ($crawlDelay !== null && $crawlDelay > 10) {
            $result->skipped[] = ['url' => $homePage->url, 'reason' => sprintf('robots.txt asks for %ss between requests; only the home page was read', $crawlDelay)];

            return $result;
        }

        foreach ($candidates as $candidate) {
            if (count($result->pages) >= $maxPages) {
                break;
            }
            $page = $this->fetchPage($candidate['url'], $result);
            if ($page !== null && $result->page($page->url) === null) {
                $result->pages[] = $page;
            }
        }

        return $result;
    }

    /** "example.com/foo" → "https://example.com/". */
    public static function homeUrl(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        $parts = parse_url($website);
        if (!is_array($parts) || !isset($parts['host']) || Normalizer::domain($website) === null) {
            return null;
        }

        return strtolower($parts['scheme'] ?? 'https') . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
    }

    private function fetchPage(string $url, FetchResult $result): ?Page
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $robots = $this->robotsFor($url);
        if (!$robots->isAllowed(self::AGENT_TOKEN, $path)) {
            $result->skipped[] = ['url' => $url, 'reason' => 'disallowed by robots.txt'];

            return null;
        }
        try {
            $response = $this->get($url, $robots->crawlDelay(self::AGENT_TOKEN));
        } catch (NetException $e) {
            $result->skipped[] = ['url' => $url, 'reason' => $e->getMessage()];

            return null;
        }
        if ($response->status < 200 || $response->status >= 300) {
            $result->skipped[] = ['url' => $url, 'reason' => 'HTTP ' . $response->status];

            return null;
        }
        if (!$response->isHtml()) {
            $result->skipped[] = ['url' => $url, 'reason' => 'not an HTML page'];

            return null;
        }
        $finalUrl = $response->finalUrl !== '' ? $response->finalUrl : $url;
        if (Normalizer::domain($finalUrl) !== Normalizer::domain($url)) {
            $result->skipped[] = ['url' => $url, 'reason' => 'redirected to another site (' . Normalizer::domain($finalUrl) . ')'];

            return null;
        }

        return $this->extractor->extract($finalUrl, $response->body);
    }

    private function robotsFor(string $url): RobotsTxt
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (!isset($this->robots[$origin])) {
            try {
                $response = $this->get($origin . '/robots.txt', null);
                $this->robots[$origin] = RobotsTxt::fromResponse($response->status, $response->body);
            } catch (NetException) {
                $this->robots[$origin] = RobotsTxt::fromResponse(null, '');
            }
        }

        return $this->robots[$origin];
    }

    private function get(string $url, ?float $crawlDelay): \App\Net\NetResponse
    {
        $host = (string) Normalizer::domain($url);
        $delay = max($this->minDelayMs, (int) (min(30.0, $crawlDelay ?? 0.0) * 1000));
        if (isset($this->lastRequest[$host])) {
            $wait = $this->lastRequest[$host] + $delay - $this->sleeper->nowMs();
            $this->sleeper->sleepMs($wait);
        }
        try {
            return $this->http->request('GET', $url, [
                'User-Agent' => $this->userAgent(),
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,text/plain;q=0.5',
                'Accept-Language' => 'en,ar;q=0.8,ur;q=0.7',
            ], '', $this->timeout);
        } finally {
            $this->lastRequest[$host] = $this->sleeper->nowMs();
        }
    }
}
