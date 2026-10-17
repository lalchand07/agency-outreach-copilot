<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Config\ProductProfile;

/**
 * Prompts and schema for drafting (standard tier).
 */
final class DraftPromptBuilder
{
    public const LANGUAGE_NAMES = [
        'en' => 'English',
        'ur' => 'Urdu (in Urdu script, not Roman Urdu; keep product names in English)',
        'ar' => 'Modern Standard Arabic (keep product names in English)',
    ];

    public const SYSTEM = <<<'TXT'
You write short, honest first-contact B2B messages that a human will review before sending.
Rules:
- Mention exactly ONE of the supplied verified observations, accurately and briefly, so the reader sees why you are writing to them. Do not add any other claim about their business.
- Mention the offer once. Use only the benefits and proof points supplied. No invented numbers, results, customers or awards.
- No exaggeration, no pressure, no flattery, no fake familiarity: you have never spoken to them, so never say "as discussed", "following up on our call", "I loved your…", "great to connect again".
- Plain, polite, specific. One clear, low-effort call to action (a reply, or a short demo).
- Do not include a signature, address or unsubscribe text in the email body: they are added automatically. End the body with your first name only.
- Respect the length limits exactly.
TXT;

    public function __construct(private ProductProfile $product)
    {
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['email_subject', 'email_body', 'linkedin_note', 'whatsapp_message', 'observation_index'],
            'properties' => [
                'email_subject' => ['type' => 'string', 'minLength' => 3, 'maxLength' => DraftLimits::SUBJECT_MAX_CHARS],
                'email_body' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 1500],
                'linkedin_note' => ['type' => 'string', 'minLength' => 20, 'maxLength' => DraftLimits::LINKEDIN_MAX_CHARS],
                'whatsapp_message' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 700],
                'observation_index' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function followupSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['email_subject', 'email_body'],
            'properties' => [
                'email_subject' => ['type' => 'string', 'minLength' => 3, 'maxLength' => DraftLimits::SUBJECT_MAX_CHARS],
                'email_body' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 1000],
            ],
        ];
    }

    /**
     * @param array<string, mixed>                                 $lead
     * @param list<array{text: string, source_url: string}>        $observations
     */
    public function build(array $lead, array $observations, string $language, string $extraInstruction = ''): string
    {
        $lines = [
            'WHO YOU ARE',
            $this->product->toPromptText(),
            '',
            'WHO YOU ARE WRITING TO',
            'Business: ' . (string) ($lead['name'] ?? ''),
            'Contact person: ' . ((string) ($lead['contact_name'] ?? '') !== '' ? $lead['contact_name'] : 'unknown (use a neutral greeting such as "Hello" or the business name)'),
            'Location: ' . trim((string) ($lead['city'] ?? '') . ', ' . (string) ($lead['country'] ?? ''), ', '),
            '',
            'VERIFIED OBSERVATIONS (use exactly one; give its number as observation_index)',
        ];
        foreach ($observations as $i => $obs) {
            $lines[] = sprintf('%d. %s (from %s)', $i + 1, $obs['text'], $obs['source_url']);
        }
        $lines[] = '';
        $lines[] = 'WRITE, in ' . (self::LANGUAGE_NAMES[$language] ?? 'English') . ':';
        $lines[] = sprintf('- email_subject: at most %d characters, specific, no clickbait.', DraftLimits::SUBJECT_MAX_CHARS);
        $lines[] = sprintf('- email_body: at most %d words. Greeting, the observation, why it matters for them, the offer, a simple question. Sign with the sender\'s first name only.', DraftLimits::EMAIL_MAX_WORDS - 15);
        $lines[] = sprintf('- linkedin_note: a connection request note, at most %d characters including spaces. No links.', DraftLimits::LINKEDIN_MAX_CHARS - 20);
        $lines[] = sprintf('- whatsapp_message: at most %d words, friendly and professional, say who you are first.', DraftLimits::WHATSAPP_MAX_WORDS - 8);
        if ($extraInstruction !== '') {
            $lines[] = '';
            $lines[] = 'ALSO: ' . $extraInstruction;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $previous the message that was sent
     */
    public function buildFollowup(array $lead, array $previous, string $language): string
    {
        return implode("\n", [
            'WHO YOU ARE',
            $this->product->toPromptText(),
            '',
            'You emailed ' . (string) ($lead['name'] ?? 'this business') . ' once and have had no reply. This is the only follow-up you will send.',
            'Your first email (subject: ' . (string) ($previous['subject'] ?? '') . '):',
            '"""',
            mb_substr((string) ($previous['body'] ?? ''), 0, 1500),
            '"""',
            '',
            'WRITE, in ' . (self::LANGUAGE_NAMES[$language] ?? 'English') . ', a polite follow-up email:',
            sprintf('- email_subject: at most %d characters (you may reuse the first subject with "Re: ").', DraftLimits::SUBJECT_MAX_CHARS),
            sprintf('- email_body: at most %d words. Briefly restate the offer, make it easy to say no ("if it is not relevant, just let me know and I will not write again"), sign with your first name only.', DraftLimits::FOLLOWUP_MAX_WORDS - 10),
            'Do not add new claims about their business. No guilt, no pressure.',
        ]);
    }
}
