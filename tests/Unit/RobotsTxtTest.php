<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Research\RobotsTxt;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    private const UA = 'AgencyOutreachCopilot';

    public function testWildcardGroupApplies(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /private/\nDisallow: /tmp\n");

        self::assertTrue($robots->isAllowed(self::UA, '/'));
        self::assertTrue($robots->isAllowed(self::UA, '/about'));
        self::assertFalse($robots->isAllowed(self::UA, '/private/page'));
        self::assertFalse($robots->isAllowed(self::UA, '/tmp-file'));
    }

    public function testSpecificGroupWinsOverWildcard(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: AgencyOutreachCopilot\nDisallow: /secret\n");

        self::assertTrue($robots->isAllowed(self::UA, '/about'));
        self::assertFalse($robots->isAllowed(self::UA, '/secret'));
        self::assertFalse($robots->isAllowed('OtherBot', '/about'));
    }

    public function testAgentMatchingIsCaseInsensitiveAndGroupsMerge(): void
    {
        $robots = RobotsTxt::parse("User-agent: agencyoutreachcopilot\nDisallow: /a\n\nUser-agent: AGENCYOUTREACHCOPILOT\nDisallow: /b\n");

        self::assertFalse($robots->isAllowed(self::UA, '/a'));
        self::assertFalse($robots->isAllowed(self::UA, '/b'));
        self::assertTrue($robots->isAllowed(self::UA, '/c'));
    }

    public function testLongestMatchWinsAndAllowWinsTies(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /services\nAllow: /services/umrah\nAllow: /x\nDisallow: /x\n");

        self::assertFalse($robots->isAllowed(self::UA, '/services/visa'));
        self::assertTrue($robots->isAllowed(self::UA, '/services/umrah-2026'));
        self::assertTrue($robots->isAllowed(self::UA, '/x'));
    }

    public function testWildcardsAndEndAnchor(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /*?session=\n");

        self::assertFalse($robots->isAllowed(self::UA, '/brochure.pdf'));
        self::assertTrue($robots->isAllowed(self::UA, '/brochure.pdf.html'));
        self::assertFalse($robots->isAllowed(self::UA, '/page?session=1'));
        self::assertTrue($robots->isAllowed(self::UA, '/page?lang=en'));
    }

    public function testEmptyDisallowAllowsEverythingAndCommentsAreIgnored(): void
    {
        $robots = RobotsTxt::parse("# comment\nUser-agent: * # all\nDisallow:\n");

        self::assertTrue($robots->isAllowed(self::UA, '/anything'));
    }

    public function testMultipleAgentsShareAGroup(): void
    {
        $robots = RobotsTxt::parse("User-agent: Googlebot\nUser-agent: AgencyOutreachCopilot\nDisallow: /shared\n");

        self::assertFalse($robots->isAllowed(self::UA, '/shared'));
    }

    public function testHttpStatusHandlingPerRfc9309(): void
    {
        self::assertTrue(RobotsTxt::fromResponse(404, '')->isAllowed(self::UA, '/x'), '4xx means no restrictions');
        self::assertFalse(RobotsTxt::fromResponse(503, '')->isAllowed(self::UA, '/x'), '5xx means assume full disallow');
        self::assertFalse(RobotsTxt::fromResponse(null, '')->isAllowed(self::UA, '/x'), 'unreachable means assume full disallow');
        self::assertTrue(RobotsTxt::fromResponse(null, '')->isAllowed(self::UA, '/robots.txt'));
    }

    public function testCrawlDelay(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nCrawl-delay: 4\n");

        self::assertSame(4.0, $robots->crawlDelay(self::UA));
        self::assertNull(RobotsTxt::parse("User-agent: *\nDisallow:\n")->crawlDelay(self::UA));
    }

    public function testPercentEncodingIsNormalised(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /%7Ejoe/\n");

        self::assertFalse($robots->isAllowed(self::UA, '/~joe/index.html'));
    }
}
