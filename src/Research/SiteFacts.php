<?php

declare(strict_types=1);

namespace App\Research;

/**
 * Deterministic signals found in fetched pages, each with the URL it came from.
 * These are facts the LLM cannot invent: they come from the HTML itself.
 */
final class SiteFacts
{
    /** Service → pattern that must appear in the text for the service to be accepted. */
    public const SERVICE_PATTERNS = [
        'umrah' => '/\bumrah?\b|عمرہ|عمرة/iu',
        'hajj' => '/\bhajj?\b|\bhaj\b|حج/iu',
        'holidays' => '/\bholidays?\b|\bvacations?\b|\bpackages?\b|\bgetaways?\b/i',
        'flights' => '/\bflights?\b|\bair ?tickets?\b|\bairfares?\b|\bairlines?\b/i',
        'visas' => '/\bvisas?\b|ویزا|تأشيرة/iu',
        'tours' => '/\btours?\b|\bexcursions?\b|\bsafaris?\b/i',
        'hotels' => '/\bhotels?\b|\baccommodation\b/i',
        'corporate' => '/\bcorporate\b|\bbusiness travel\b/i',
        'cruises' => '/\bcruises?\b/i',
        'other' => '/./u',
    ];

    public const ACCREDITATIONS = [
        'ATOL' => '/\bATOL\b(?:\s*(?:no\.?|number|protected)?\s*:?\s*\d{3,6})?/i',
        'IATA' => '/\bIATA\b/',
        'ABTA' => '/\bABTA\b/',
        'IATAN' => '/\bIATAN\b/',
        'TTA' => '/\bTravel Trust Association\b|\bTTA\b/',
        'DTCM' => '/\bDTCM\b|Dubai Tourism/i',
    ];

    /** Script used by booking engines / live fare search widgets. */
    private const LIVE_PRICING_HINTS = '/travelport|sabre|amadeus|galileo|tbo\.com|hotelbeds|travelcarma|traveltek|trawex|booking ?engine|flight ?search|search ?flights|ibe\./i';

    /**
     * @param array<string, string>                $services       service → first URL where it appears
     * @param array<string, array{url: string, match: string}> $accreditations
     * @param list<string>                         $emails
     * @param list<string>                         $phones
     * @param list<string>                         $languages      ISO-ish codes (html lang + scripts seen)
     */
    public function __construct(
        public array $services = [],
        public array $accreditations = [],
        public ?string $whatsappUrl = null,
        public ?string $quoteFormUrl = null,
        public ?string $liveSearchUrl = null,
        public array $emails = [],
        public array $phones = [],
        public array $languages = [],
        public string $siteTitle = '',
        public int $priceMentions = 0,
    ) {
    }

    public static function fromPages(FetchResult $result): self
    {
        $facts = new self();
        foreach ($result->pages as $i => $page) {
            if ($i === 0) {
                $facts->siteTitle = $page->title;
            }
            $text = $page->title . ' ' . $page->description . ' ' . $page->text;
            foreach (self::SERVICE_PATTERNS as $service => $pattern) {
                if ($service !== 'other' && !isset($facts->services[$service]) && preg_match($pattern, $text)) {
                    $facts->services[$service] = $page->url;
                }
            }
            foreach (self::ACCREDITATIONS as $name => $pattern) {
                if (!isset($facts->accreditations[$name]) && preg_match($pattern, $text, $m)) {
                    $facts->accreditations[$name] = ['url' => $page->url, 'match' => trim($m[0])];
                }
            }
            foreach ($page->links as $link) {
                $href = $link['href'];
                if ($facts->whatsappUrl === null && preg_match('#^(https?://(wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com|web\.whatsapp\.com)/|whatsapp:)#i', $href)) {
                    $facts->whatsappUrl = $page->url;
                }
                if (preg_match('#^mailto:([^?]+)#i', $href, $m)) {
                    $email = strtolower(rawurldecode($m[1]));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $facts->emails, true)) {
                        $facts->emails[] = $email;
                    }
                }
                if (preg_match('#^tel:(.+)$#i', $href, $m)) {
                    $phone = trim(rawurldecode($m[1]));
                    if (!in_array($phone, $facts->phones, true)) {
                        $facts->phones[] = $phone;
                    }
                }
            }
            if ($facts->whatsappUrl === null && preg_match('/whatsapp/i', $page->text)) {
                $facts->whatsappUrl = $page->url;
            }
            foreach ($page->forms as $form) {
                if ($facts->liveSearchUrl === null && $form['dates'] >= 1 && preg_match('/from|to|depart|destination|origin|search/i', $form['text'])) {
                    $facts->liveSearchUrl = $page->url;
                }
                if ($facts->quoteFormUrl === null && $form['fields'] >= 3 && preg_match('/quote|enquir|inquir|request|booking|book now|get in touch|contact|message/i', $form['text'])) {
                    $facts->quoteFormUrl = $page->url;
                }
            }
            if ($facts->liveSearchUrl === null && preg_match(self::LIVE_PRICING_HINTS, $page->html)) {
                $facts->liveSearchUrl = $page->url;
            }
            $facts->priceMentions += preg_match_all('/(£|\$|€|PKR|Rs\.?|AED|SAR|QAR|KWD|﷼)\s?\d[\d,]*/u', $page->text);
            preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $page->text, $m);
            foreach ($m[0] as $email) {
                $email = strtolower($email);
                if (!in_array($email, $facts->emails, true) && !preg_match('/\.(png|jpe?g|gif|webp)$/', $email)) {
                    $facts->emails[] = $email;
                }
            }
            if ($page->lang !== '') {
                $facts->addLanguage(substr($page->lang, 0, 2));
            }
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $page->text)) {
                // Urdu-specific letters (ٹ ڈ ڑ ں ے ہ) distinguish it from Arabic.
                $facts->addLanguage(preg_match('/[\x{0679}\x{0688}\x{0691}\x{06BA}\x{06D2}\x{06C1}]/u', $page->text) ? 'ur' : 'ar');
            }
            if (preg_match('/\b(the|and|our|with)\b/i', $page->text)) {
                $facts->addLanguage('en');
            }
        }
        $facts->emails = array_slice($facts->emails, 0, 10);
        $facts->phones = array_slice($facts->phones, 0, 10);

        return $facts;
    }

    private function addLanguage(string $code): void
    {
        if ($code !== '' && !in_array($code, $this->languages, true)) {
            $this->languages[] = $code;
        }
    }

    /** "static", "live", "on_request" or "none", from what the HTML shows. */
    public function pricing(): string
    {
        if ($this->liveSearchUrl !== null) {
            return 'live';
        }
        if ($this->priceMentions > 0) {
            return 'static';
        }

        return $this->quoteFormUrl !== null ? 'on_request' : 'none';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'site_title' => $this->siteTitle,
            'services' => $this->services,
            'accreditations' => $this->accreditations,
            'whatsapp_url' => $this->whatsappUrl,
            'quote_form_url' => $this->quoteFormUrl,
            'live_search_url' => $this->liveSearchUrl,
            'price_mentions' => $this->priceMentions,
            'pricing' => $this->pricing(),
            'emails' => $this->emails,
            'phones' => $this->phones,
            'languages' => $this->languages,
        ];
    }
}
