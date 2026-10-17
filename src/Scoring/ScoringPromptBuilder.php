<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Config\ProductProfile;

/**
 * Builds the scoring prompt from the ideal customer, the campaign criteria and
 * the verified research only (no raw page text, so nothing unverified leaks in).
 */
final class ScoringPromptBuilder
{
    public const SYSTEM = <<<'TXT'
You score how well one business fits a seller's ideal customer, from 0 (no fit) to 100 (perfect fit).
Use only the research given. Unknown information is neither good nor bad: score it in the middle and say it is unknown.
Explain the score in one or two short, factual sentences that a salesperson can check.
TXT;

    public function __construct(private ProductProfile $product)
    {
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['score', 'explanation'],
            'properties' => [
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'explanation' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 300],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $research verified research result
     */
    public function build(array $lead, array $campaign, array $research): string
    {
        $ideal = trim((string) ($campaign['ideal_customer'] ?? '')) !== '' ? (string) $campaign['ideal_customer'] : $this->product->idealCustomer;
        $lines = [
            'SELLER',
            'Product: ' . $this->product->productName . ' — ' . $this->product->pitch,
            'Ideal customer: ' . $ideal,
            '',
            'CAMPAIGN CRITERIA',
            'Country: ' . ((string) ($campaign['country'] ?? '') !== '' ? $campaign['country'] : 'any'),
            'Cities: ' . self::listText($campaign['cities'] ?? []),
            'Business type keywords: ' . self::listText($campaign['keywords'] ?? []),
            '',
            'BUSINESS',
            'Name: ' . (string) ($lead['name'] ?? ''),
            'Location: ' . trim((string) ($lead['city'] ?? '') . ', ' . (string) ($lead['country'] ?? ''), ', '),
            'Website: ' . (string) ($lead['domain'] ?? $lead['website'] ?? ''),
            '',
            'VERIFIED RESEARCH',
            'Services: ' . self::listText($research['services'] ?? []),
            'Size estimate: ' . (string) ($research['size']['estimate'] ?? 'unknown') . (($research['size']['signals'] ?? '') !== '' ? ' (' . $research['size']['signals'] . ')' : ''),
            'Pricing on site: ' . (string) ($research['pricing'] ?? 'unknown'),
            'Online enquiry/quote form: ' . self::yesNo($research['has_quote_form'] ?? null),
            'WhatsApp contact: ' . self::yesNo($research['whatsapp'] ?? null),
            'Languages: ' . self::listText($research['languages'] ?? []),
            'Accreditations: ' . self::listText($research['accreditations'] ?? []),
        ];
        foreach ((array) ($research['observations'] ?? []) as $i => $obs) {
            if (is_array($obs)) {
                $lines[] = sprintf('Observation %d: %s', (int) $i + 1, (string) ($obs['text'] ?? ''));
            }
        }
        $lines[] = '';
        $lines[] = 'Return the score and explanation.';

        return implode("\n", $lines);
    }

    private static function listText(mixed $items): string
    {
        $items = array_filter(array_map('strval', array_filter((array) $items, 'is_scalar')));

        return $items === [] ? 'unknown' : implode(', ', $items);
    }

    private static function yesNo(mixed $value): string
    {
        return $value === null ? 'unknown' : ($value ? 'yes' : 'no');
    }
}
