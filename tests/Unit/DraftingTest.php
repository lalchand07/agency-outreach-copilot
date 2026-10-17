<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Drafting\DraftLimits;
use App\Drafting\DraftPromptBuilder;
use App\Pipeline\Status;
use App\Tests\Support\TestCase;

final class DraftingTest extends TestCase
{
    private function words(int $n, string $word = 'word'): string
    {
        return implode(' ', array_fill(0, $n, $word));
    }

    public function testEmailWordLimit(): void
    {
        self::assertSame([], DraftLimits::violations('email', 'Subject', $this->words(120)));
        self::assertSame(['Email body is 121 words; the limit is 120.'], DraftLimits::violations('email', 'Subject', $this->words(121)));
        self::assertSame(['Email body is 81 words; the limit is 80.'], DraftLimits::violations('email', 'Re: Subject', $this->words(81), true), 'follow-ups are shorter');
    }

    public function testEmailNeedsASubjectWithinLimit(): void
    {
        self::assertSame(['The email needs a subject.'], DraftLimits::violations('email', ' ', 'Hello there'));
        self::assertCount(1, DraftLimits::violations('email', str_repeat('x', 81), 'Hello there'));
    }

    public function testLinkedInCharacterLimitCountsUnicodeCharacters(): void
    {
        self::assertSame([], DraftLimits::violations('linkedin', '', str_repeat('ب', 300)));
        self::assertSame(['LinkedIn note is 301 characters; the limit is 300.'], DraftLimits::violations('linkedin', '', str_repeat('ب', 301)));
    }

    public function testWhatsAppWordLimitWorksForUrdu(): void
    {
        self::assertSame([], DraftLimits::violations('whatsapp', '', $this->words(60, 'سلام')));
        self::assertSame(['WhatsApp message is 61 words; the limit is 60.'], DraftLimits::violations('whatsapp', '', $this->words(61, 'سلام')));
    }

    public function testEmptyAndUnknown(): void
    {
        self::assertContains('The message is empty.', DraftLimits::violations('whatsapp', '', '   '));
        self::assertContains('Unknown channel sms.', DraftLimits::violations('sms', '', 'hi'));
    }

    public function testWarningsForFakeFamiliarityAndHype(): void
    {
        $warnings = DraftLimits::warnings('Quick one', 'As discussed, I loved your site. We guarantee 300% growth. Hi [Name]');

        self::assertCount(5, $warnings);
        self::assertSame([], DraftLimits::warnings('Your Umrah packages', 'I saw you list Umrah packages from £1,150. Would a short demo help?'));
    }

    public function testPromptUsesOnlyVerifiedObservationsAndLanguage(): void
    {
        $prompt = (new DraftPromptBuilder(self::product()))->build(
            ['name' => 'Crescent Travel', 'contact_name' => '', 'city' => 'London', 'country' => 'UK'],
            [['text' => 'Lists Umrah packages from £1,150', 'source_url' => 'https://crescent.example/']],
            'ur'
        );

        self::assertStringContainsString('1. Lists Umrah packages from £1,150 (from https://crescent.example/)', $prompt);
        self::assertStringContainsString('Urdu (in Urdu script', $prompt);
        self::assertStringContainsString('Offer: A free demo and free data import.', $prompt);
        self::assertStringContainsString('use a neutral greeting', $prompt);
        self::assertStringContainsString('never say "as discussed"', DraftPromptBuilder::SYSTEM);
    }

    private function researchedLead(): int
    {
        $leadId = $this->lead(['phone' => '07700 900123']);
        $this->db->insert('research', ['lead_id' => $leadId, 'pages' => '[]', 'facts' => '{}', 'result' => (string) json_encode([
            'observations' => [
                ['text' => 'Lists Umrah packages from £1,150', 'source_url' => 'https://crescent.example/', 'evidence' => 'x'],
                ['text' => 'ATOL number 12345', 'source_url' => 'https://crescent.example/', 'evidence' => 'y'],
            ],
        ]), 'dropped' => '[]', 'error' => '', 'created_at' => '2026-10-14 10:00:00']);

        return $leadId;
    }

    /** @return array<string, mixed> */
    private function draft(int $emailWords = 60): array
    {
        return [
            'email_subject' => 'Your Umrah packages',
            'email_body' => $this->words($emailWords),
            'linkedin_note' => 'Hello, I saw Crescent Travel lists Umrah packages. I build back-office software for agencies; happy to connect.',
            'whatsapp_message' => 'Salaam, this is Sam from Seller Ltd. I saw your Umrah packages online. Could I show you a free 20-minute demo of our agency back office?',
            'observation_index' => 2,
        ];
    }

    public function testGeneratesPendingDraftsPerChannelWithStandardTier(): void
    {
        $this->buildApp(true);
        $leadId = $this->researchedLead();
        $this->ai()->reply($this->draft());

        $result = $this->app->drafts()->generate($this->app->leads()->find($leadId) ?? [], ['email', 'linkedin', 'whatsapp'], 'en');

        self::assertTrue($result['ok'], $result['message']);
        self::assertCount(3, $result['draft_ids']);
        $drafts = $this->app->review()->forLead($leadId);
        self::assertSame(['pending'], array_values(array_unique(array_column($drafts, 'status'))), 'nothing is approved automatically');
        self::assertStringContainsString('ATOL number 12345', $drafts[0]['observation'], 'observation_index is honoured');
        self::assertSame('claude-sonnet-5-5', $this->ai()->requestBody(0)['model']);
        self::assertSame(Status::DRAFTED, ($this->app->leads()->find($leadId) ?? [])['status']);
        self::assertSame([], $this->mailer->sent, 'drafting never sends');
    }

    public function testTooLongDraftGetsOneShorteningRetry(): void
    {
        $this->buildApp(true);
        $leadId = $this->researchedLead();
        $this->ai()->reply($this->draft(150))->reply($this->draft(90));

        $result = $this->app->drafts()->generate($this->app->leads()->find($leadId) ?? [], ['email'], 'en');

        self::assertTrue($result['ok']);
        self::assertCount(2, $this->ai()->http->requests);
        self::assertStringContainsString('Email body is 150 words; the limit is 120.', $this->ai()->prompt(1));
        $draft = $this->app->review()->forLead($leadId)[0];
        self::assertSame(90, DraftLimits::words($draft['body']));
        self::assertSame('', $draft['warnings']);
    }

    public function testStillTooLongIsFlaggedAndCannotBeApproved(): void
    {
        $this->buildApp(true);
        $leadId = $this->researchedLead();
        $this->ai()->reply($this->draft(150))->reply($this->draft(140));
        $this->app->drafts()->generate($this->app->leads()->find($leadId) ?? [], ['email'], 'en');

        $draft = $this->app->review()->forLead($leadId)[0];
        self::assertStringContainsString('140 words', $draft['warnings']);
        self::assertStringStartsWith('Fix before approving', (string) $this->app->review()->approve((int) $draft['id']));
        self::assertSame([], $this->db->all('SELECT * FROM messages'));
    }

    public function testRefusesWithoutVerifiedObservation(): void
    {
        $this->buildApp(true);
        $leadId = $this->lead();
        $result = $this->app->drafts()->generate($this->app->leads()->find($leadId) ?? [], ['email'], 'en');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('No verified observation', $result['message']);
        self::assertSame([], $this->ai()->http->requests);
    }

    public function testRegeneratingSupersedesPendingDrafts(): void
    {
        $this->buildApp(true);
        $leadId = $this->researchedLead();
        $this->ai()->reply($this->draft())->reply($this->draft(50));
        $lead = $this->app->leads()->find($leadId) ?? [];
        $this->app->drafts()->generate($lead, ['email'], 'en');
        $this->app->drafts()->generate($lead, ['email'], 'en', '', true);

        $statuses = array_column($this->app->review()->forLead($leadId), 'status');
        self::assertSame(['pending', 'superseded'], $statuses);
    }

    public function testEditingAnApprovedDraftRequiresApprovalAgain(): void
    {
        $leadId = $this->lead(['email' => '']);
        $id = $this->app->drafts()->store($leadId, 'linkedin', 'en', '', 'Hello, happy to connect about agency software.', 'obs', false);
        self::assertNull($this->app->review()->approve($id));
        self::assertSame('approved', ($this->app->review()->find($id) ?? [])['status']);

        $this->app->review()->edit($id, '', 'Hello, a different note now.');

        $draft = $this->app->review()->find($id) ?? [];
        self::assertSame('pending', $draft['status']);
        self::assertSame(1, (int) $draft['edited']);
    }
}
