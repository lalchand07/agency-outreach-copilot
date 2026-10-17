<?php

declare(strict_types=1);

namespace App\Leads;

use App\Research\FetchResult;
use App\Research\Researcher;
use App\Research\WebsiteFetcher;

/**
 * Adds leads chosen from Google Places results and from manual entry.
 *
 * What is stored for a Places lead (Places API policies allow only the place
 * ID to be stored): the place ID, plus the business's website address and
 * name as confirmed by fetching the business's own home page (its <title>).
 * Address, phone, rating, opening hours etc. from Google are never stored.
 */
final class LeadSourcing
{
    public function __construct(
        private LeadRepository $leads,
        private Deduplicator $dedup,
        private WebsiteFetcher $fetcher,
    ) {
    }

    /**
     * @param list<array{place_id: string, website: string}> $selected
     * @param array<string, mixed>                           $campaign
     *
     * @return array{imported: int, duplicates: list<string>, skipped: list<string>}
     */
    public function addFromPlaces(array $selected, array $campaign, string $city = ''): array
    {
        $report = ['imported' => 0, 'duplicates' => [], 'skipped' => []];
        foreach (array_slice($selected, 0, 20) as $item) {
            $placeId = trim($item['place_id']);
            $website = trim($item['website']);
            if (!preg_match('/^[A-Za-z0-9_-]{10,300}$/', $placeId)) {
                $report['skipped'][] = 'Invalid place ID.';
                continue;
            }
            if ($website === '' || WebsiteFetcher::homeUrl($website) === null) {
                $report['skipped'][] = 'A listing without a website was skipped: research needs the business\'s own site. Add it manually if you find one.';
                continue;
            }
            $duplicate = $this->dedup->findDuplicate($website, null, null, $placeId);
            if ($duplicate !== null) {
                $report['duplicates'][] = sprintf('%s: %s.', Normalizer::domain($website), $duplicate);
                continue;
            }
            $home = $this->fetcher->fetch($website, 1);
            $page = $home->pages[0] ?? null;
            if ($page === null) {
                $reason = $home->skipped[0]['reason'] ?? 'unreachable';
                $report['skipped'][] = sprintf('%s: could not read the website (%s).', Normalizer::domain($website), $reason);
                continue;
            }
            $this->dedup->remember($page->url, null, null, $placeId);
            $this->leads->create([
                'place_id' => $placeId,
                'campaign_id' => (int) $campaign['id'],
                'name' => $page->title !== '' ? Researcher::nameFromTitle($page->title) : (string) Normalizer::domain($page->url),
                'website' => WebsiteFetcher::homeUrl($page->url) ?? $page->url,
                'city' => $city,
                'country' => (string) ($campaign['country'] ?? ''),
            ], 'places');
            $report['imported']++;
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: int|null, error: string|null}
     */
    public function addManual(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $website = trim((string) ($data['website'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($name === '' && $website === '' && $email === '') {
            return ['id' => null, 'error' => 'Enter at least a name, website or email.'];
        }
        if ($email !== '' && Normalizer::email($email) === null) {
            return ['id' => null, 'error' => 'That email address is not valid.'];
        }
        $duplicate = $this->dedup->findDuplicate($website, (string) ($data['phone'] ?? ''), (string) ($data['country'] ?? ''));
        if ($duplicate !== null) {
            return ['id' => null, 'error' => 'Duplicate: ' . $duplicate . '.'];
        }

        return ['id' => $this->leads->create($data, 'manual'), 'error' => null];
    }

    public static function sameSite(string $a, string $b): bool
    {
        return FetchResult::key((string) WebsiteFetcher::homeUrl($a)) === FetchResult::key((string) WebsiteFetcher::homeUrl($b));
    }
}
