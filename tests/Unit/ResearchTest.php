<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Pipeline\Status;
use App\Research\ResearchPrompt;
use App\Research\ResearchVerifier;
use App\Research\Researcher;
use App\Tests\Support\TestCase;

final class ResearchTest extends TestCase
{
    /**
     * @param list<array<string, string>> $observations
     * @param array<string, mixed>        $extra
     *
     * @return array<string, mixed>
     */
    private function llmAnswer(array $observations, array $extra = []): array
    {
        return $extra + [
            'services' => ['umrah', 'hajj', 'flights', 'cruises'],
            'size' => ['estimate' => 'small', 'signals' => 'Team of 6 mentioned on the about page'],
            'pricing' => 'static',
            'has_quote_form' => true,
            'whatsapp' => true,
            'languages' => ['English'],
            'accreditations' => ['ATOL', 'ABTA'],
            'observations' => $observations,
            'summary' => 'A small East London Umrah specialist.',
        ];
    }

    public function testKeepsOnlyObservationsThatCiteAFetchedPageAndQuoteIt(): void
    {
        $this->buildApp(true);
        $this->agencySite();
        $this->ai()->reply($this->llmAnswer([
            ['text' => 'Lists Umrah packages for December 2026 from £1,150', 'source_url' => 'https://crescent.example/', 'evidence' => 'Umrah packages for December 2026 from £1,150 per person'],
            ['text' => 'Won Travel Agency of the Year 2025', 'source_url' => 'https://crescent.example/about-us', 'evidence' => 'Travel Agency of the Year 2025'],
            ['text' => 'Offers Ramadan deals', 'source_url' => 'https://crescent.example/ramadan', 'evidence' => 'Ramadan Umrah deals from £999'],
        ]));
        $leadId = $this->lead(['name' => '', 'email' => '', 'phone' => '']);
        $result = $this->app->researcher()->research($this->app->leads()->find($leadId) ?? []);

        self::assertTrue($result['ok'], $result['message']);
        $research = $this->app->leads()->latestResearch($leadId) ?? [];
        $observations = $research['result']['observations'];
        self::assertCount(1, $observations);
        self::assertSame('https://crescent.example/', $observations[0]['source_url']);
        self::assertSame('ai', $observations[0]['origin']);

        $dropped = implode("\n", $research['dropped']);
        self::assertStringContainsString('Travel Agency of the Year', $dropped, 'quote not on the page');
        self::assertStringContainsString('was not fetched', $dropped, 'cites a page we never read');
        self::assertStringContainsString('"cruises" is not mentioned', $dropped);
        self::assertStringContainsString('"ABTA" is not mentioned', $dropped);
        self::assertNotContains('cruises', $research['result']['services']);
        self::assertSame(['ATOL'], $research['result']['accreditations']);
        self::assertTrue($research['result']['whatsapp']);
        self::assertTrue($research['result']['has_quote_form']);
        self::assertSame('static', $research['result']['pricing']);
    }

    public function testFillsNameEmailAndPhoneFromTheBusinessesOwnSiteAndAdvancesStatus(): void
    {
        $this->buildApp(true);
        $this->agencySite();
        $this->ai()->reply($this->llmAnswer([]));
        $leadId = $this->lead(['name' => '', 'email' => '', 'phone' => '']);
        $this->app->researcher()->research($this->app->leads()->find($leadId) ?? []);

        $lead = $this->app->leads()->find($leadId) ?? [];
        self::assertSame('Crescent Travel', $lead['name']);
        self::assertSame('info@crescent.example', $lead['email']);
        self::assertSame('+442079460958', $lead['phone']);
        self::assertSame(Status::RESEARCHED, $lead['status']);
    }

    public function testFallsBackToHtmlFactsWhenNoObservationVerifies(): void
    {
        $this->buildApp(true);
        $this->agencySite();
        $this->ai()->reply($this->llmAnswer([['text' => 'An invented observation', 'source_url' => 'https://crescent.example/', 'evidence' => 'this sentence is not on the page']]));
        $leadId = $this->lead();
        $this->app->researcher()->research($this->app->leads()->find($leadId) ?? []);

        $observations = ($this->app->leads()->latestResearch($leadId) ?? [])['result']['observations'];
        self::assertNotEmpty($observations);
        foreach ($observations as $obs) {
            self::assertSame('facts', $obs['origin']);
            self::assertStringStartsWith('https://crescent.example/', $obs['source_url']);
        }
    }

    public function testWorksWithoutAi(): void
    {
        $this->agencySite();
        $leadId = $this->lead();
        $result = $this->app->researcher()->research($this->app->leads()->find($leadId) ?? []);

        self::assertTrue($result['ok']);
        self::assertStringContainsString('AI is not configured', $result['message']);
        self::assertContains('umrah', ($this->app->leads()->latestResearch($leadId) ?? [])['result']['services']);
    }

    public function testPromptUsesPhpAiGuardCheapTierWithSchemaAndPageText(): void
    {
        $this->buildApp(true);
        $this->agencySite();
        $this->ai()->reply($this->llmAnswer([]));
        $this->app->researcher()->research($this->app->leads()->find($this->lead()) ?? [], ['name' => 'UK Umrah', 'keywords' => ['Umrah travel agency'], 'country' => 'UK']);

        $body = $this->ai()->requestBody(0);
        self::assertSame('claude-haiku-5-5', $body['model'], 'research uses the cheap tier');
        self::assertStringContainsString('JSON schema', (string) $body['system']);
        self::assertStringContainsString('Never use outside knowledge', (string) $body['system']);
        $prompt = $this->ai()->prompt(0);
        self::assertStringContainsString('URL: https://crescent.example/about-us', $prompt);
        self::assertStringNotContainsString('ignore me', $prompt, 'scripts are stripped');

        $usage = $this->ai()->ai->reporter()->month('2026-10', 'feature');
        self::assertSame('research', $usage[0]['group']);
    }

    public function testClosedLeadsAndLeadsWithoutWebsiteAreNotResearched(): void
    {
        $id = $this->lead(['website' => '']);
        self::assertFalse($this->app->researcher()->research($this->app->leads()->find($id) ?? [])['ok']);
        self::assertSame([], $this->web->requests);
    }

    public function testVerifierTextMatchingIsWhitespaceAndQuoteInsensitive(): void
    {
        self::assertTrue(ResearchVerifier::contains("Family-run   Umrah\nspecialists in East London", 'family-run umrah specialists'));
        self::assertTrue(ResearchVerifier::contains('We’re open daily from 9am', "we're open daily"));
        self::assertFalse(ResearchVerifier::contains('anything', 'short'), 'very short quotes prove nothing');
    }

    public function testNameFromTitleAndBestEmail(): void
    {
        self::assertSame('Crescent Travel', Researcher::nameFromTitle('Crescent Travel | Umrah Packages'));
        self::assertSame('Crescent Travel', Researcher::nameFromTitle('Home - Crescent Travel'));
        self::assertSame('info@crescent.example', Researcher::bestEmail(['noreply@crescent.example', 'someone@gmail.com', 'info@crescent.example'], 'crescent.example'));
    }

    public function testSchemaRequiresSourceAndEvidenceForObservations(): void
    {
        $schema = ResearchPrompt::schema();
        self::assertSame(3, $schema['properties']['observations']['maxItems']);
        self::assertSame(['text', 'source_url', 'evidence'], $schema['properties']['observations']['items']['required']);
    }

    public function testInvalidAnswerGetsOneCorrectiveRetryFromPhpAiGuard(): void
    {
        $this->buildApp(true);
        $this->agencySite();
        $this->ai()->reply('not json at all')->reply($this->llmAnswer([]));
        $result = $this->app->researcher()->research($this->app->leads()->find($this->lead()) ?? []);

        self::assertTrue($result['ok']);
        self::assertCount(2, $this->ai()->http->requests);
    }

    public function testAiFailureStillSavesHtmlFacts(): void
    {
        $this->buildApp(true);
        $this->agencySite();
        $this->ai()->reply('nope')->reply('still nope');
        $leadId = $this->lead();
        $result = $this->app->researcher()->research($this->app->leads()->find($leadId) ?? []);

        self::assertTrue($result['ok']);
        self::assertStringContainsString('AI research failed', $result['message']);
        self::assertNotEmpty(($this->app->leads()->latestResearch($leadId) ?? [])['result']['observations']);
    }
}
