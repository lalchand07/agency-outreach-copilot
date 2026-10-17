<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Leads\Deduplicator;
use App\Leads\Normalizer;
use App\Tests\Support\TestCase;

final class DeduplicationTest extends TestCase
{
    public function testDomainNormalisation(): void
    {
        self::assertSame('example.co.uk', Normalizer::domain('https://www.Example.co.uk/about?x=1'));
        self::assertSame('example.co.uk', Normalizer::domain('example.co.uk'));
        self::assertSame('example.co.uk', Normalizer::domain('WWW.EXAMPLE.CO.UK.'));
        self::assertSame('example.com', Normalizer::domain('info@example.com'));
        self::assertNull(Normalizer::domain(''));
        self::assertNull(Normalizer::domain('not a url'));
    }

    public function testPhoneNormalisationUsesCountryCode(): void
    {
        self::assertSame('442079460958', Normalizer::phone('020 7946 0958', 'UK'));
        self::assertSame('442079460958', Normalizer::phone('+44 (0)20 7946 0958'));
        self::assertSame('442079460958', Normalizer::phone('0044 20 7946 0958'));
        self::assertSame('923001234567', Normalizer::phone('0300-1234567', 'Pakistan'));
        self::assertSame('971501234567', Normalizer::phone('050 123 4567', 'UAE'));
        self::assertNull(Normalizer::phone('12345'));
    }

    public function testFindsDuplicateByDomainIgnoringWwwAndPath(): void
    {
        $id = $this->lead(['website' => 'https://www.crescent.example/home']);
        $dedup = new Deduplicator($this->db);

        self::assertSame(sprintf('domain crescent.example matches lead #%d', $id), $dedup->findDuplicate('http://crescent.example', null));
        self::assertNull($dedup->findDuplicate('https://other.example', null));
    }

    public function testFindsDuplicateByPhoneAcrossFormats(): void
    {
        $id = $this->lead(['website' => '', 'phone' => '020 7946 0958', 'country' => 'UK']);
        $dedup = new Deduplicator($this->db);

        self::assertStringContainsString('#' . $id, (string) $dedup->findDuplicate(null, '+44 20 7946 0958'));
        self::assertNull($dedup->findDuplicate(null, '+44 20 7946 0000'));
    }

    public function testSharedHostsAreNotDuplicateKeys(): void
    {
        $this->lead(['website' => 'https://facebook.com/crescent', 'email' => '']);
        $dedup = new Deduplicator($this->db);

        self::assertNull(Deduplicator::dedupDomain('https://www.facebook.com/another-agency'));
        self::assertNull($dedup->findDuplicate('https://facebook.com/another-agency', null));
    }

    public function testDuplicatesWithinTheSameBatch(): void
    {
        $dedup = new Deduplicator($this->db);
        self::assertNull($dedup->findDuplicate('https://a.example', '07700 900123', 'UK'));
        $dedup->remember('https://a.example', '07700 900123', 'UK');

        self::assertStringContainsString('already in this batch', (string) $dedup->findDuplicate('a.example', null));
        self::assertStringContainsString('already in this batch', (string) $dedup->findDuplicate(null, '+447700900123'));
    }

    public function testFindsDuplicateGooglePlace(): void
    {
        $this->app->leads()->create(['name' => 'A', 'website' => 'https://a.example', 'place_id' => 'ChIJabcdefghijk'], 'places');
        $dedup = new Deduplicator($this->db);

        self::assertStringContainsString('same Google place', (string) $dedup->findDuplicate('https://b.example', null, null, 'ChIJabcdefghijk'));
    }

    public function testManualAddRefusesDuplicates(): void
    {
        $this->lead();
        $result = $this->app->sourcing()->addManual(['name' => 'Copy', 'website' => 'www.crescent.example']);

        self::assertNull($result['id']);
        self::assertStringContainsString('Duplicate', (string) $result['error']);
    }
}
