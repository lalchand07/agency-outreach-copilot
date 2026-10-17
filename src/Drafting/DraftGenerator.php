<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Db\Database;
use App\Leads\LeadRepository;
use App\Pipeline\Status;
use App\Research\Researcher;
use App\Support\Clock;
use PhpAiGuard\AiGuard;
use PhpAiGuard\Exception\AiGuardException;

/**
 * Generates email, LinkedIn and WhatsApp drafts for a lead with the standard
 * tier. Drafts always start as "pending": only a human can approve them.
 */
final class DraftGenerator
{
    public function __construct(
        private Database $db,
        private LeadRepository $leads,
        private DraftPromptBuilder $prompts,
        private ?AiGuard $ai,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $lead
     * @param list<string>         $channels
     *
     * @return array{ok: bool, message: string, draft_ids: list<int>}
     */
    public function generate(array $lead, array $channels, string $language, string $extraInstruction = '', bool $fresh = false): array
    {
        $leadId = (int) $lead['id'];
        if (in_array($lead['status'], Status::CLOSED, true)) {
            return ['ok' => false, 'message' => 'This lead is ' . Status::label((string) $lead['status']) . '; no new drafts.', 'draft_ids' => []];
        }
        if ($this->ai === null) {
            return ['ok' => false, 'message' => 'AI is not configured. Add an API key to .env, or write the message yourself with "Write manually".', 'draft_ids' => []];
        }
        $research = $this->leads->latestResearch($leadId);
        /** @var list<array{text: string, source_url: string}> $observations */
        $observations = array_values(array_filter((array) ($research['result']['observations'] ?? []), 'is_array'));
        if ($observations === []) {
            return ['ok' => false, 'message' => 'No verified observation yet: research the lead first. Drafts must reference something real.', 'draft_ids' => []];
        }
        $channels = array_values(array_intersect($channels, ['email', 'linkedin', 'whatsapp'])) ?: ['email'];

        $prompt = $this->prompts->build($lead, $observations, $language, $extraInstruction);
        // Urdu and Arabic need more tokens per word than English.
        $options = ['system' => DraftPromptBuilder::SYSTEM, 'max_tokens' => 1200, 'cache' => !$fresh && $extraInstruction === ''];
        try {
            $draft = $this->ai->withFeature('drafting')->json($prompt, DraftPromptBuilder::schema(), $options);
            $problems = $this->problems($draft, $channels);
            if ($problems !== []) {
                // One shortening retry with the exact problems.
                $draft = $this->ai->withFeature('drafting')->json(
                    $prompt . "\n\nYOUR PREVIOUS DRAFT BROKE THESE LIMITS, rewrite it shorter:\n- " . implode("\n- ", $problems)
                        . "\nPrevious draft: " . json_encode($draft, JSON_UNESCAPED_UNICODE),
                    DraftPromptBuilder::schema(),
                    ['cache' => false] + $options
                );
            }
        } catch (AiGuardException $e) {
            $this->leads->log($leadId, 'draft', 'Drafting failed: ' . Researcher::aiError($e));

            return ['ok' => false, 'message' => 'Drafting failed: ' . Researcher::aiError($e), 'draft_ids' => []];
        }

        $index = max(1, min(count($observations), (int) ($draft['observation_index'] ?? 1))) - 1;
        $observation = $observations[$index]['text'] . ' (' . $observations[$index]['source_url'] . ')';
        $texts = [
            'email' => [(string) $draft['email_subject'], (string) $draft['email_body']],
            'linkedin' => ['', (string) $draft['linkedin_note']],
            'whatsapp' => ['', (string) $draft['whatsapp_message']],
        ];

        $ids = $this->db->transaction(function () use ($leadId, $channels, $texts, $language, $observation): array {
            $now = $this->clock->nowString();
            $this->db->run("UPDATE drafts SET status = 'superseded', decided_at = ? WHERE lead_id = ? AND is_followup = 0 AND status = 'pending'", [$now, $leadId]);
            $ids = [];
            foreach ($channels as $channel) {
                [$subject, $body] = $texts[$channel];
                $ids[] = $this->store($leadId, $channel, $language, trim($subject), trim($body), $observation, false);
            }

            return $ids;
        });
        $this->leads->advance($leadId, Status::DRAFTED);
        $this->leads->log($leadId, 'draft', sprintf('%d draft(s) generated in %s', count($ids), $language));

        return ['ok' => true, 'message' => 'Drafts are ready for review.', 'draft_ids' => $ids];
    }

    /**
     * Drafts the single follow-up email (still needs approval).
     *
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $previous the sent message
     *
     * @return array{ok: bool, message: string, draft_ids: list<int>}
     */
    public function generateFollowup(array $lead, array $previous, string $language): array
    {
        $leadId = (int) $lead['id'];
        if ($this->ai === null) {
            $id = $this->store($leadId, 'email', $language, 'Re: ' . (string) $previous['subject'], '', 'Follow-up to message #' . (int) $previous['id'], true);

            return ['ok' => true, 'message' => 'AI is not configured: an empty follow-up draft was created for you to write.', 'draft_ids' => [$id]];
        }
        try {
            $draft = $this->ai->withFeature('followup')->json(
                $this->prompts->buildFollowup($lead, $previous, $language),
                DraftPromptBuilder::followupSchema(),
                ['system' => DraftPromptBuilder::SYSTEM, 'max_tokens' => 600, 'cache' => false]
            );
        } catch (AiGuardException $e) {
            return ['ok' => false, 'message' => 'Follow-up drafting failed: ' . Researcher::aiError($e), 'draft_ids' => []];
        }
        $id = $this->store($leadId, 'email', $language, trim((string) $draft['email_subject']), trim((string) $draft['email_body']), 'Follow-up to message #' . (int) $previous['id'], true);
        $this->leads->log($leadId, 'draft', 'Follow-up draft generated');

        return ['ok' => true, 'message' => 'Follow-up draft ready for review.', 'draft_ids' => [$id]];
    }

    /**
     * A blank or hand-written draft (no AI).
     */
    public function store(int $leadId, string $channel, string $language, string $subject, string $body, string $observation, bool $followup): int
    {
        $warnings = array_merge(DraftLimits::violations($channel, $subject, $body, $followup), DraftLimits::warnings($subject, $body));

        return $this->db->insert('drafts', [
            'lead_id' => $leadId,
            'channel' => $channel,
            'language' => $language,
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'observation' => $observation,
            'status' => 'pending',
            'is_followup' => $followup ? 1 : 0,
            'edited' => 0,
            'warnings' => implode("\n", $warnings),
            'created_at' => $this->clock->nowString(),
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<string>         $channels
     *
     * @return list<string>
     */
    private function problems(array $draft, array $channels): array
    {
        $problems = [];
        if (in_array('email', $channels, true)) {
            $problems = array_merge($problems, DraftLimits::violations('email', (string) ($draft['email_subject'] ?? ''), (string) ($draft['email_body'] ?? '')));
        }
        if (in_array('linkedin', $channels, true)) {
            $problems = array_merge($problems, DraftLimits::violations('linkedin', '', (string) ($draft['linkedin_note'] ?? '')));
        }
        if (in_array('whatsapp', $channels, true)) {
            $problems = array_merge($problems, DraftLimits::violations('whatsapp', '', (string) ($draft['whatsapp_message'] ?? '')));
        }

        return $problems;
    }
}
