<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Research\PageExtractor;
use App\Research\WebsiteFetcher;
use App\Tests\Support\TestCase;

final class WebsiteFetcherTest extends TestCase
{
    public function testReadsHomeAndBestLinkedPagesOnTheSameSite(): void
    {
        $this->agencySite();
        $result = $this->app->fetcher()->fetch('crescent.example');

        $urls = array_map(static fn ($p): string => $p->url, $result->pages);
        self::assertSame('https://crescent.example/', $urls[0]);
        self::assertContains('https://crescent.example/about-us', $urls);
        self::assertContains('https://crescent.example/contact', $urls);
        self::assertContains('https://crescent.example/umrah-packages', $urls);
        self::assertNotContains('https://crescent.example/login', $this->web->urls(), 'login pages are never crawled');
        self::assertNotContains('https://facebook.com/crescent', $this->web->urls(), 'other sites are never crawled');
    }

    public function testRespectsRobotsTxt(): void
    {
        $this->web->text('https://shy.example/robots.txt', "User-agent: *\nDisallow: /about\n");
        $this->web->html('https://shy.example/', '<html><body><a href="/about">About</a><a href="/contact">Contact</a></body></html>');
        $this->web->html('https://shy.example/contact', '<html><body>Contact</body></html>');
        $result = $this->app->fetcher()->fetch('https://shy.example');

        self::assertNotContains('https://shy.example/about', $this->web->urls());
        self::assertContains(['url' => 'https://shy.example/about', 'reason' => 'disallowed by robots.txt'], $result->skipped);
        self::assertSame('https://shy.example/robots.txt', $this->web->urls()[0], 'robots.txt is fetched first');
    }

    public function testFullDisallowMeansNothingIsFetched(): void
    {
        $this->web->text('https://private.example/robots.txt', "User-agent: *\nDisallow: /\n");
        $this->web->html('https://private.example/', '<html><body>Hi</body></html>');
        $result = $this->app->fetcher()->fetch('https://private.example');

        self::assertSame([], $result->pages);
        self::assertSame(['https://private.example/robots.txt'], $this->web->urls());
    }

    public function testUnreachableRobotsMeansDisallow(): void
    {
        $this->web->text('https://down.example/robots.txt', 'error', 503);
        $this->web->html('https://down.example/', '<html><body>Hi</body></html>');

        self::assertSame([], $this->app->fetcher()->fetch('https://down.example')->pages);
    }

    public function testMissingRobotsMeansAllowed(): void
    {
        $this->web->html('https://open.example/', '<html><head><title>Open</title></head><body>Hi</body></html>');

        self::assertCount(1, $this->app->fetcher()->fetch('https://open.example')->pages);
    }

    public function testNeverMoreThanFivePages(): void
    {
        $links = '';
        for ($i = 1; $i <= 10; $i++) {
            $links .= sprintf('<a href="/services-%d">Services %d</a>', $i, $i);
            $this->web->html('https://big.example/services-' . $i, '<html><body>Service ' . $i . '</body></html>');
        }
        $this->web->html('https://big.example/', '<html><body>' . $links . '</body></html>');
        $result = $this->app->fetcher()->fetch('https://big.example', 50);

        self::assertCount(WebsiteFetcher::MAX_PAGES, $result->pages);
        self::assertCount(1 + WebsiteFetcher::MAX_PAGES, $this->web->requests, 'robots.txt + 5 pages');
    }

    public function testIdentifiesItselfAndRateLimits(): void
    {
        $this->agencySite();
        $this->app->fetcher()->fetch('https://crescent.example');

        foreach ($this->web->requests as $request) {
            self::assertSame('AgencyOutreachCopilot/1.0 (+https://seller.example/bot)', $request['headers']['User-Agent']);
        }
        // Every request after the first waited for the minimum delay (1 s by default).
        self::assertCount(count($this->web->requests) - 1, $this->sleeper->slept);
        foreach ($this->sleeper->slept as $ms) {
            self::assertGreaterThanOrEqual(1000, $ms);
        }
    }

    public function testHonoursCrawlDelay(): void
    {
        $this->web->text('https://slow.example/robots.txt', "User-agent: *\nCrawl-delay: 3\n");
        $this->web->html('https://slow.example/', '<html><body><a href="/about">About</a></body></html>');
        $this->web->html('https://slow.example/about', '<html><body>About</body></html>');
        $this->app->fetcher()->fetch('https://slow.example');

        self::assertContains(3000, $this->sleeper->slept);
    }

    public function testRedirectToAnotherSiteIsRejected(): void
    {
        $this->web->redirectTo('https://moved.example/', 'https://parked-domains.example/', '<html><body>For sale</body></html>');
        $result = $this->app->fetcher()->fetch('https://moved.example');

        self::assertSame([], $result->pages);
        self::assertStringContainsString('redirected to another site', $result->skipped[0]['reason']);
    }

    public function testNetworkErrorsAreReportedNotThrown(): void
    {
        $this->web->fail('https://gone.example/', 'Could not resolve host');
        $result = $this->app->fetcher()->fetch('https://gone.example');

        self::assertSame([], $result->pages);
        self::assertStringContainsString('Could not resolve host', $result->skipped[0]['reason']);
    }

    public function testExtractorDropsScriptsAndResolvesLinks(): void
    {
        $page = (new PageExtractor())->extract('https://a.example/dir/page', '<html lang="ur"><head><title>T</title><style>.x{}</style></head><body><script>secret()</script><p>Hello<br>world</p><a href="../up">Up</a><a href="mailto:info@a.example">Mail</a></body></html>');

        self::assertSame('T', $page->title);
        self::assertSame('ur', $page->lang);
        self::assertStringNotContainsString('secret', $page->text);
        self::assertStringContainsString('Hello world', $page->text);
        self::assertSame('https://a.example/up', $page->links[0]['href']);
        self::assertSame('mailto:info@a.example', $page->links[1]['href']);
    }
}
