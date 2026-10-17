<?php

declare(strict_types=1);

namespace App\Outreach;

use App\Db\Database;
use App\Leads\Deduplicator;
use App\Leads\Normalizer;
use App\Support\Clock;

/**
 * The do-not-contact list. Checked when a message is queued and again right
 * before it is sent. Entries survive data retention.
 */
final class Suppression
{
    public const TYPES = ['email', 'domain', 'phone'];

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** Adds an entry; returns false when the value is invalid. Duplicates are ignored. */
    public function add(string $type, string $value, string $reason = ''): bool
    {
        $normalised = self::normalise($type, $value);
        if ($normalised === null) {
            return false;
        }
        if (!$this->has($type, $normalised)) {
            $this->db->insert('suppression', ['type' => $type, 'value' => $normalised, 'reason' => mb_substr($reason, 0, 255), 'created_at' => $this->clock->nowString()]);
        }

        return true;
    }

    public function remove(int $id): void
    {
        $this->db->run('DELETE FROM suppression WHERE id = ?', [$id]);
    }

    /**
     * Is this email address (or its domain) suppressed?
     */
    public function isEmailSuppressed(string $email): bool
    {
        $email = Normalizer::email($email);
        if ($email === null) {
            return true; // an invalid address is never sent to
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);

        return $this->has('email', $email) || $this->has('domain', $domain);
    }

    public function isPhoneSuppressed(string $phone, string $country = ''): bool
    {
        $normalised = Normalizer::phone($phone, $country);

        return $normalised !== null && $this->has('phone', $normalised);
    }

    /**
     * Does any suppression entry match this lead (email, website domain, phone)?
     *
     * @param array<string, mixed> $lead
     */
    public function matchesLead(array $lead): ?string
    {
        $email = (string) ($lead['email'] ?? '');
        if ($email !== '' && $this->isEmailSuppressed($email)) {
            return 'email ' . $email;
        }
        $domain = Deduplicator::dedupDomain((string) ($lead['website'] ?? ''));
        if ($domain !== null && $this->has('domain', $domain)) {
            return 'domain ' . $domain;
        }
        $phone = (string) ($lead['phone'] ?? '');
        if ($phone !== '' && $this->isPhoneSuppressed($phone, (string) ($lead['country'] ?? ''))) {
            return 'phone ' . $phone;
        }

        return null;
    }

    /**
     * Suppresses everything we know about a lead (used for "do not contact").
     *
     * @param array<string, mixed> $lead
     */
    public function suppressLead(array $lead, string $reason): void
    {
        if (!empty($lead['email'])) {
            $this->add('email', (string) $lead['email'], $reason);
        }
        $domain = Deduplicator::dedupDomain((string) ($lead['website'] ?? ''));
        if ($domain !== null) {
            $this->add('domain', $domain, $reason);
        }
        if (!empty($lead['phone'])) {
            $this->add('phone', (string) Normalizer::phone((string) $lead['phone'], (string) ($lead['country'] ?? '')), $reason);
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 500): array
    {
        return $this->db->all('SELECT * FROM suppression ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM suppression');
    }

    private function has(string $type, string $value): bool
    {
        return $this->db->value('SELECT 1 FROM suppression WHERE type = ? AND value = ?', [$type, $value]) !== null;
    }

    public static function normalise(string $type, string $value): ?string
    {
        return match ($type) {
            'email' => Normalizer::email($value),
            'domain' => Normalizer::domain($value),
            'phone' => Normalizer::phone($value) ?? null,
            default => null,
        };
    }
}
