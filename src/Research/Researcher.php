<?php

declare(strict_types=1);

namespace App\Research;

use App\Db\Database;
use App\Leads\LeadRepository;
use App\Leads\Normalizer;
use App\Pipeline\Status;
use App\Support\Clock;
use PhpAiGuard\AiGuard;
use PhpAiGuard\Exception\AiGuardException;

/**
 * Fetches a lead's own website, extracts verifiable facts, asks the cheap
 * tier for structured observations and keeps only what can be verified.
 */
final class Researcher
{
    public function __construct(
        private Database $db,
        private LeadRepository $leads,
        private WebsiteFetcher $fetcher,
        private ?AiGuard $ai,
        private Clock $clock,
        private ResearchVerifier $verifier = new ResearchVerifier(),
        private int $maxPages = WebsiteFetcher::MAX_PAGES,
    ) {
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $campaign
     *
     * @return array{ok: bool, message: string, research_id: int|null}
     */
    public function research(array $lead, array $campaign = []): array
    {
        $leadId = (int) $lead['id'];
        if (in_array($lead['status'], [Status::DO_NOT_CONTACT, Status::NOT_INTERESTED], true)) {
            return ['ok' => false, 'message' => 'This lead is closed; research skipped.', 'research_id' => null];
        }
        if (trim((string) $lead['website']) === '') {
            return ['ok' => false, 'message' => 'Add the business website first: research only reads the business\'s own site.', 'research_id' => null];
        }

        $fetched = $this->fetcher->fetch((string) $lead['website'], $this->maxPages);
        $facts = SiteFacts::fromPages($fetched);
        $error = '';
        $llm = [];
        if ($fetched->pages === []) {
            $error = 'No page could be fetched: ' . implode('; ', array_map(static fn (array $s): string => $s['url'] . ' (' . $s['reason'] . ')', $fetched->skipped));
        } elseif ($this->ai !== null) {
            try {
                $llm = $this->ai->withFeature('research')->json(
                    ResearchPrompt::build($fetched, $facts, $campaign),
                    ResearchPrompt::schema(),
                    ['system' => ResearchPrompt::SYSTEM, 'max_tokens' => 900]
                );
            } catch (AiGuardException $e) {
                $error = 'AI research failed, showing facts from the HTML only: ' . self::aiError($e);
            }
        } else {
            $error = 'AI is not configured; showing facts from the HTML only.';
        }

        $verified = $fetched->pages === [] ? ['result' => [], 'dropped' => []] : $this->verifier->verify($llm, $fetched, $facts);

        $researchId = $this->db->insert('research', [
            'lead_id' => $leadId,
            'pages' => (string) json_encode(['fetched' => array_map(static fn (Page $p): array => $p->summary(), $fetched->pages), 'skipped' => $fetched->skipped], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'facts' => (string) json_encode($facts->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'result' => (string) json_encode($verified['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'dropped' => (string) json_encode($verified['dropped'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'error' => $error,
            'created_at' => $this->clock->nowString(),
        ]);

        if ($fetched->pages === []) {
            $this->leads->log($leadId, 'research', $error);

            return ['ok' => false, 'message' => $error, 'research_id' => $researchId];
        }

        // Fill gaps from the business's own site (never overwrite what the user entered).
        $fill = ['researched_at' => $this->clock->nowString()];
        if (trim((string) $lead['name']) === '' && $facts->siteTitle !== '') {
            $fill['name'] = self::nameFromTitle($facts->siteTitle);
        }
        if (trim((string) $lead['email']) === '' && $facts->emails !== []) {
            $fill['email'] = self::bestEmail($facts->emails, (string) Normalizer::domain((string) $lead['website']));
        }
        if (trim((string) $lead['phone']) === '' && $facts->phones !== []) {
            $fill['phone'] = $facts->phones[0];
            $fill['phone_normalized'] = Normalizer::phone($facts->phones[0], (string) $lead['country']);
        }
        $this->leads->setFields($leadId, array_filter($fill, static fn ($v): bool => $v !== null && $v !== ''));
        $this->leads->advance($leadId, Status::RESEARCHED);
        $this->leads->log($leadId, 'research', sprintf('%d page(s) read, %d observation(s) kept, %d claim(s) dropped', count($fetched->pages), count($verified['result']['observations'] ?? []), count($verified['dropped'])));

        return ['ok' => true, 'message' => $error !== '' ? $error : 'Research complete.', 'research_id' => $researchId];
    }

    /** "Acme Travel | Umrah Packages from London" → "Acme Travel". */
    public static function nameFromTitle(string $title): string
    {
        $parts = preg_split('/\s+[|\-–—:•·]\s+/u', $title) ?: [$title];
        $name = trim((string) $parts[0]);
        if (preg_match('/^(home|welcome|homepage)$/i', $name) && isset($parts[1])) {
            $name = trim((string) $parts[1]);
        }

        return mb_substr($name, 0, 120);
    }

    /**
     * Prefers a generic business address on the site's own domain (info@, sales@)
     * over personal or third-party ones.
     *
     * @param list<string> $emails
     */
    public static function bestEmail(array $emails, string $domain): string
    {
        $score = static function (string $email) use ($domain): int {
            $points = 0;
            $at = (string) strrchr($email, '@');
            if ($domain !== '' && str_ends_with($at, $domain)) {
                $points += 10;
            }
            if (preg_match('/^(info|sales|hello|contact|enquir|inquir|bookings?|office|admin)/', $email)) {
                $points += 5;
            }
            if (preg_match('/^(no-?reply|donotreply|privacy|gdpr|abuse|webmaster|postmaster)/', $email)) {
                $points -= 20;
            }

            return $points;
        };
        usort($emails, static fn (string $a, string $b): int => $score($b) <=> $score($a));

        return $emails[0] ?? '';
    }

    public static function aiError(AiGuardException $e): string
    {
        if ($e instanceof \PhpAiGuard\Exception\BudgetExceededException || $e instanceof \PhpAiGuard\Exception\RateLimitExceededException) {
            return $e->userMessage();
        }

        return $e->getMessage();
    }
}
