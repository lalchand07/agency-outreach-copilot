<?php

declare(strict_types=1);

namespace App\Drafting;

/**
 * Length limits and wording checks for drafts. Approval is refused while a
 * draft breaks a hard limit.
 */
final class DraftLimits
{
    public const EMAIL_MAX_WORDS = 120;
    public const SUBJECT_MAX_CHARS = 80;
    public const LINKEDIN_MAX_CHARS = 300;
    public const WHATSAPP_MAX_WORDS = 60;
    public const FOLLOWUP_MAX_WORDS = 80;

    /** Phrases that fake familiarity or overclaim. They produce warnings for the reviewer. */
    private const RED_FLAGS = [
        '/\bas (we )?discussed\b/i' => 'implies a previous conversation',
        '/\b(following|circling) (up|back) on our (call|chat|conversation|meeting)\b/i' => 'implies a previous conversation',
        '/\bgreat (talking|speaking|chatting) (to|with) you\b/i' => 'implies a previous conversation',
        '/\b(i|we) (loved|love|really enjoyed)\b/i' => 'fake familiarity',
        '/\bhuge fan\b/i' => 'fake familiarity',
        '/\bguarantee[ds]?\b/i' => 'unverifiable promise',
        '/\b(100|200|300)\s?%/' => 'exaggerated claim',
        '/\b(#1|number one|best in the (world|market|industry)|revolutionary|game.?chang)/i' => 'exaggerated claim',
        '/\b(double|triple|10x) your\b/i' => 'exaggerated claim',
        '/\b(last chance|act now|limited time|urgent)\b/i' => 'pressure tactic',
        '/\[(name|company|first name)\]|\{\{.*?\}\}/i' => 'unfilled placeholder',
    ];

    public static function words(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/[\s\x{00A0}]+/u', $text) ?: []);
    }

    public static function chars(string $text): int
    {
        return mb_strlen(trim($text));
    }

    /**
     * Hard-limit violations; an empty list means the draft may be approved.
     *
     * @return list<string>
     */
    public static function violations(string $channel, string $subject, string $body, bool $followup = false): array
    {
        $problems = [];
        if (trim($body) === '') {
            $problems[] = 'The message is empty.';
        }
        switch ($channel) {
            case 'email':
                $max = $followup ? self::FOLLOWUP_MAX_WORDS : self::EMAIL_MAX_WORDS;
                if (trim($subject) === '') {
                    $problems[] = 'The email needs a subject.';
                } elseif (self::chars($subject) > self::SUBJECT_MAX_CHARS) {
                    $problems[] = sprintf('Subject is %d characters; the limit is %d.', self::chars($subject), self::SUBJECT_MAX_CHARS);
                }
                if (self::words($body) > $max) {
                    $problems[] = sprintf('Email body is %d words; the limit is %d.', self::words($body), $max);
                }
                break;
            case 'linkedin':
                if (self::chars($body) > self::LINKEDIN_MAX_CHARS) {
                    $problems[] = sprintf('LinkedIn note is %d characters; the limit is %d.', self::chars($body), self::LINKEDIN_MAX_CHARS);
                }
                break;
            case 'whatsapp':
                if (self::words($body) > self::WHATSAPP_MAX_WORDS) {
                    $problems[] = sprintf('WhatsApp message is %d words; the limit is %d.', self::words($body), self::WHATSAPP_MAX_WORDS);
                }
                break;
            default:
                $problems[] = 'Unknown channel ' . $channel . '.';
        }

        return $problems;
    }

    /**
     * Soft warnings shown to the reviewer.
     *
     * @return list<string>
     */
    public static function warnings(string $subject, string $body): array
    {
        $warnings = [];
        foreach (self::RED_FLAGS as $pattern => $why) {
            if (preg_match($pattern, $subject . "\n" . $body, $m)) {
                $warnings[] = sprintf('"%s": %s.', $m[0], $why);
            }
        }

        return $warnings;
    }
}
