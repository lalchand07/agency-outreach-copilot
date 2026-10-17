<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Scoring\LeadScorer;
use App\Scoring\ScoringPromptBuilder;
use App\Tests\Support\TestCase;

final class ScoringTest extends TestCase
{
    /** @return array<string, mixed> */
    private function research(): array
    {
        return [
            'services' => ['umrah', 'hajj'],
            'size' => ['estimate' => 'small', 'signals' => 'team of 6'],
            'pricing' => 'static',
            'has_quote_form' => true,
            'whatsapp' => false,
            'languages' => ['en', 'ur'],
            'accreditations' => ['ATOL'],
            'observations' => [['text' => 'Lists Umrah packages from £1,150', 'source_url' => 'https://a.example/', 'evidence' => '…']],
            'summary' => 'ignored',
        ];
    }

    public function testPromptContainsIdealCustomerCriteriaAndVerifiedResearchOnly(): void
    {
        $builder = new ScoringPromptBuilder(self::product());
        $prompt = $builder->build(
            ['name' => 'Crescent Travel', 'city' => 'London', 'country' => 'UK', 'domain' => 'crescent.example'],
            ['country' => 'UK', 'cities' => ['London', 'Birmingham'], 'keywords' => ['Umrah travel agency'], 'ideal_customer' => ''],
            $this->research()
        );

        self::assertStringContainsString('Ideal customer: Independent travel agencies with 2-50 staff selling Umrah.', $prompt, 'falls back to the product profile');
        self::assertStringContainsString('Cities: London, Birmingham', $prompt);
        self::assertStringContainsString('Business type keywords: Umrah travel agency', $prompt);
        self::assertStringContainsString('Services: umrah, hajj', $prompt);
        self::assertStringContainsString('Size estimate: small (team of 6)', $prompt);
        self::assertStringContainsString('Online enquiry/quote form: yes', $prompt);
        self::assertStringContainsString('WhatsApp contact: no', $prompt);
        self::assertStringContainsString('Accreditations: ATOL', $prompt);
        self::assertStringContainsString('Observation 1: Lists Umrah packages from £1,150', $prompt);
        self::assertStringNotContainsString('ignored', $prompt, 'the unverified summary is not used');
    }

    public function testCampaignIdealCustomerOverridesProfileAndUnknownsAreExplicit(): void
    {
        $prompt = (new ScoringPromptBuilder(self::product()))->build(['name' => 'X'], ['ideal_customer' => 'Hajj operators in Karachi'], []);

        self::assertStringContainsString('Ideal customer: Hajj operators in Karachi', $prompt);
        self::assertStringContainsString('Services: unknown', $prompt);
        self::assertStringContainsString('WhatsApp contact: unknown', $prompt);
        self::assertStringContainsString('Country: any', $prompt);
    }

    public function testSchemaBoundsTheScore(): void
    {
        $schema = ScoringPromptBuilder::schema();
        self::assertSame(0, $schema['properties']['score']['minimum']);
        self::assertSame(100, $schema['properties']['score']['maximum']);
    }

    public function testAiScoreIsStoredWithExplanation(): void
    {
        $this->buildApp(true);
        $leadId = $this->lead();
        $this->db->insert('research', ['lead_id' => $leadId, 'pages' => '[]', 'facts' => '{}', 'result' => (string) json_encode($this->research()), 'dropped' => '[]', 'error' => '', 'created_at' => '2026-10-14 10:00:00']);
        $this->ai()->reply(['score' => 82, 'explanation' => 'Small ATOL Umrah agency with static prices: strong fit.']);

        $result = $this->app->scorer()->score($this->app->leads()->find($leadId) ?? [], ['keywords' => ['Umrah']]);

        self::assertSame(82, $result['score']);
        $lead = $this->app->leads()->find($leadId) ?? [];
        self::assertSame(82, (int) $lead['score']);
        self::assertSame('Small ATOL Umrah agency with static prices: strong fit.', $lead['score_reason']);
        self::assertSame('claude-haiku-5-5', $this->ai()->requestBody(0)['model']);
    }

    public function testOutOfRangeAiScoreIsRejectedBySchema(): void
    {
        $this->buildApp(true);
        $leadId = $this->lead();
        $this->db->insert('research', ['lead_id' => $leadId, 'pages' => '[]', 'facts' => '{}', 'result' => (string) json_encode($this->research()), 'dropped' => '[]', 'error' => '', 'created_at' => '2026-10-14 10:00:00']);
        $this->ai()->reply(['score' => 150, 'explanation' => 'Way too high a score here.'])->reply(['score' => 140, 'explanation' => 'Still too high a score.']);

        $result = $this->app->scorer()->score($this->app->leads()->find($leadId) ?? [], []);

        self::assertSame('rules', $result['source'], 'falls back to rules after the corrective retry fails');
    }

    public function testRuleScoreIsTransparent(): void
    {
        $result = LeadScorer::ruleScore(['email' => 'info@a.example'], ['keywords' => ['Umrah travel agency']], $this->research());

        self::assertSame(95, $result['score']);
        self::assertStringContainsString('offers umrah', $result['explanation']);
        self::assertSame(30, LeadScorer::ruleScore([], [], [])['score']);
    }
}
