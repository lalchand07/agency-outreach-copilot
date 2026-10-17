<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Leads\LeadRepository;
use App\Research\Researcher;
use PhpAiGuard\AiGuard;
use PhpAiGuard\Exception\AiGuardException;

/**
 * Scores a lead 0-100 against the campaign's ideal customer: cheap tier when
 * AI is configured, otherwise a transparent rule-based score.
 */
final class LeadScorer
{
    public function __construct(
        private LeadRepository $leads,
        private ScoringPromptBuilder $prompts,
        private ?AiGuard $ai,
    ) {
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $campaign
     *
     * @return array{score: int, explanation: string, source: string}
     */
    public function score(array $lead, array $campaign): array
    {
        $research = $this->leads->latestResearch((int) $lead['id']);
        $result = $research['result'] ?? [];
        $scored = null;
        if ($this->ai !== null && $result !== []) {
            try {
                $json = $this->ai->withFeature('scoring')->json(
                    $this->prompts->build($lead, $campaign, $result),
                    ScoringPromptBuilder::schema(),
                    ['system' => ScoringPromptBuilder::SYSTEM, 'max_tokens' => 300]
                );
                $scored = ['score' => max(0, min(100, (int) $json['score'])), 'explanation' => trim((string) $json['explanation']), 'source' => 'ai'];
            } catch (AiGuardException $e) {
                $this->leads->log((int) $lead['id'], 'score', 'AI scoring failed, used rules: ' . Researcher::aiError($e));
            }
        }
        $scored ??= self::ruleScore($lead, $campaign, $result);
        $this->leads->setFields((int) $lead['id'], ['score' => $scored['score'], 'score_reason' => mb_substr($scored['explanation'], 0, 500)]);

        return $scored;
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $research
     *
     * @return array{score: int, explanation: string, source: string}
     */
    public static function ruleScore(array $lead, array $campaign, array $research): array
    {
        if ($research === []) {
            return ['score' => 30, 'explanation' => 'Not researched yet: default score.', 'source' => 'rules'];
        }
        $score = 30;
        $reasons = [];
        $services = (array) ($research['services'] ?? []);
        $keywords = strtolower(implode(' ', (array) ($campaign['keywords'] ?? [])));
        $matched = array_values(array_filter($services, static fn ($s): bool => $keywords === '' || str_contains($keywords, rtrim((string) $s, 's'))));
        if ($matched !== []) {
            $score += 25;
            $reasons[] = 'offers ' . implode(', ', $matched);
        } elseif ($services !== []) {
            $score += 10;
            $reasons[] = 'travel services found (' . implode(', ', $services) . ')';
        }
        $size = (string) ($research['size']['estimate'] ?? 'unknown');
        if (in_array($size, ['small', 'medium'], true)) {
            $score += 15;
            $reasons[] = $size . ' business';
        } elseif ($size === 'large') {
            $score += 5;
        }
        if (in_array($research['pricing'] ?? '', ['static', 'on_request'], true)) {
            $score += 10;
            $reasons[] = 'prices are ' . ($research['pricing'] === 'static' ? 'static' : 'on request') . ' (likely manual back office)';
        }
        if (($research['accreditations'] ?? []) !== []) {
            $score += 10;
            $reasons[] = 'accredited (' . implode(', ', (array) $research['accreditations']) . ')';
        }
        if (!empty($research['whatsapp']) || !empty($research['has_quote_form'])) {
            $score += 5;
        }
        if (($lead['email'] ?? '') === '') {
            $score -= 10;
            $reasons[] = 'no email address yet';
        }

        return ['score' => max(0, min(100, $score)), 'explanation' => 'Rule-based: ' . ($reasons !== [] ? implode('; ', $reasons) : 'little matching information') . '.', 'source' => 'rules'];
    }
}
