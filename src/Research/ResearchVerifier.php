<?php

declare(strict_types=1);

namespace App\Research;

/**
 * Checks the LLM's research against the fetched pages and drops anything that
 * cannot be traced back to them. This is what stops invented "facts" from
 * reaching a draft.
 */
final class ResearchVerifier
{
    private const LANGUAGE_CODES = [
        'english' => 'en', 'urdu' => 'ur', 'arabic' => 'ar', 'hindi' => 'hi', 'punjabi' => 'pa', 'bengali' => 'bn',
        'french' => 'fr', 'german' => 'de', 'spanish' => 'es', 'turkish' => 'tr', 'persian' => 'fa', 'farsi' => 'fa',
    ];

    /**
     * @param array<string, mixed> $llm the validated JSON from the research call (or [] without AI)
     *
     * @return array{result: array<string, mixed>, dropped: list<string>}
     */
    public function verify(array $llm, FetchResult $pages, SiteFacts $facts): array
    {
        $dropped = [];

        // Observations: the cited page must be one we fetched and must contain the quote.
        $observations = [];
        foreach ((array) ($llm['observations'] ?? []) as $obs) {
            if (!is_array($obs)) {
                continue;
            }
            $text = trim((string) ($obs['text'] ?? ''));
            $url = trim((string) ($obs['source_url'] ?? ''));
            $evidence = trim((string) ($obs['evidence'] ?? ''));
            $page = $pages->page($url);
            if ($page === null) {
                $dropped[] = sprintf('Observation "%s" cites %s, which was not fetched.', $text, $url !== '' ? $url : '(no URL)');
                continue;
            }
            if (!self::contains($page->title . ' ' . $page->description . ' ' . $page->text, $evidence)) {
                $dropped[] = sprintf('Observation "%s": its quote was not found on %s.', $text, $page->url);
                continue;
            }
            $observations[] = ['text' => $text, 'source_url' => $page->url, 'evidence' => $evidence, 'origin' => 'ai'];
        }
        if ($observations === []) {
            $observations = $this->fallbackObservations($pages, $facts);
        }

        // Services and accreditations: keep only what the text really mentions.
        $services = array_keys($facts->services);
        foreach ((array) ($llm['services'] ?? []) as $service) {
            $service = (string) $service;
            if ($service === 'other' || in_array($service, $services, true)) {
                continue;
            }
            $dropped[] = sprintf('Service "%s" is not mentioned on the fetched pages.', $service);
        }
        $accreditations = array_keys($facts->accreditations);
        foreach ((array) ($llm['accreditations'] ?? []) as $acc) {
            $acc = trim((string) $acc);
            $known = strtoupper((string) preg_replace('/[^A-Za-z]/', '', explode(' ', $acc)[0]));
            if ($acc === '' || in_array($known, $accreditations, true)) {
                continue;
            }
            if (self::containsAny($pages, $acc)) {
                $accreditations[] = $acc;
            } else {
                $dropped[] = sprintf('Accreditation "%s" is not mentioned on the fetched pages.', $acc);
            }
        }

        $languages = $facts->languages;
        foreach ((array) ($llm['languages'] ?? []) as $lang) {
            $lang = strtolower(trim((string) $lang));
            $code = self::LANGUAGE_CODES[$lang] ?? (strlen($lang) === 2 ? $lang : null);
            if ($code !== null && !in_array($code, $languages, true)) {
                $languages[] = $code;
            }
        }

        $size = is_array($llm['size'] ?? null) ? $llm['size'] : [];
        $pricing = $facts->pricing() !== 'none' ? $facts->pricing() : (string) ($llm['pricing'] ?? 'unknown');

        return [
            'result' => [
                'services' => $services,
                'size' => [
                    'estimate' => (string) ($size['estimate'] ?? 'unknown'),
                    'signals' => (string) ($size['signals'] ?? ''),
                ],
                'pricing' => $pricing,
                'has_quote_form' => $facts->quoteFormUrl !== null,
                'whatsapp' => $facts->whatsappUrl !== null,
                'languages' => $languages,
                'accreditations' => array_values(array_unique($accreditations)),
                'observations' => array_slice($observations, 0, 3),
                'summary' => (string) ($llm['summary'] ?? ''),
            ],
            'dropped' => $dropped,
        ];
    }

    /**
     * Observations built from deterministic facts, used when the LLM gave none
     * that could be verified (or AI is not configured).
     *
     * @return list<array{text: string, source_url: string, evidence: string, origin: string}>
     */
    public function fallbackObservations(FetchResult $pages, SiteFacts $facts): array
    {
        $out = [];
        $labels = ['umrah' => 'Umrah', 'hajj' => 'Hajj', 'holidays' => 'holiday packages', 'flights' => 'flights', 'visas' => 'visa services', 'tours' => 'tours', 'hotels' => 'hotels', 'corporate' => 'corporate travel', 'cruises' => 'cruises'];
        foreach ($facts->accreditations as $name => $info) {
            $page = $pages->page($info['url']);
            if ($page !== null) {
                $out[] = ['text' => sprintf('The website mentions %s ("%s").', $name, $info['match']), 'source_url' => $page->url, 'evidence' => self::snippet($page->text, $info['match']), 'origin' => 'facts'];
            }
            break;
        }
        foreach ($facts->services as $service => $url) {
            if (count($out) >= 2) {
                break;
            }
            $page = $pages->page($url);
            if ($page === null || !preg_match(SiteFacts::SERVICE_PATTERNS[$service], $page->text, $m)) {
                continue;
            }
            $out[] = ['text' => sprintf('The website offers %s.', $labels[$service] ?? $service), 'source_url' => $page->url, 'evidence' => self::snippet($page->text, $m[0]), 'origin' => 'facts'];
        }
        if ($facts->whatsappUrl !== null && count($out) < 3) {
            $out[] = ['text' => 'The website invites customers to contact them on WhatsApp.', 'source_url' => $facts->whatsappUrl, 'evidence' => 'WhatsApp link or mention on the page', 'origin' => 'facts'];
        }

        return $out;
    }

    /** Whitespace-, case- and quote-insensitive "does $haystack contain $needle". */
    public static function contains(string $haystack, string $needle): bool
    {
        $n = self::normalise($needle);
        if (mb_strlen($n) < 8) {
            return false; // too short to prove anything
        }

        return str_contains(self::normalise($haystack), $n);
    }

    private static function containsAny(FetchResult $pages, string $needle): bool
    {
        foreach ($pages->pages as $page) {
            if (stripos($page->text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function normalise(string $text): string
    {
        $text = mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = strtr($text, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...']);
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim($text, " .\"'");
    }

    private static function snippet(string $text, string $match): string
    {
        $pos = mb_stripos($text, $match);
        if ($pos === false) {
            return $match;
        }
        $start = max(0, $pos - 60);

        return trim(mb_substr($text, $start, mb_strlen($match) + 120));
    }
}
