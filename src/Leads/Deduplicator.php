<?php

declare(strict_types=1);

namespace App\Leads;

use App\Db\Database;

/**
 * Finds an existing lead with the same website domain, phone number or Google place ID.
 */
final class Deduplicator
{
    /** Shared hosts where many unrelated businesses live: never a duplicate key. */
    private const SHARED_DOMAINS = [
        'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'google.com', 'sites.google.com',
        'business.site', 'wixsite.com', 'blogspot.com', 'wordpress.com', 'gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com',
        'linktr.ee', 'wa.me', 'whatsapp.com',
    ];

    /** @var array<string, int> keys seen in this batch → lead id (0 for not yet saved) */
    private array $seen = [];

    public function __construct(private Database $db)
    {
    }

    public static function dedupDomain(?string $website): ?string
    {
        $domain = Normalizer::domain($website);
        if ($domain === null) {
            return null;
        }
        foreach (self::SHARED_DOMAINS as $shared) {
            if ($domain === $shared || str_ends_with($domain, '.' . $shared)) {
                return null;
            }
        }

        return $domain;
    }

    /**
     * Returns a reason string ("domain example.com matches lead #4") when the
     * candidate duplicates an existing lead or one already seen in this batch,
     * otherwise null.
     */
    public function findDuplicate(?string $website, ?string $phone, ?string $country = null, ?string $placeId = null): ?string
    {
        $domain = self::dedupDomain($website);
        $phoneKey = Normalizer::phone($phone, $country);

        if ($placeId !== null && $placeId !== '') {
            if (isset($this->seen['place:' . $placeId])) {
                return 'Google place already in this batch';
            }
            $id = $this->db->value('SELECT id FROM leads WHERE place_id = ?', [$placeId]);
            if ($id !== null) {
                return sprintf('same Google place as lead #%d', (int) $id);
            }
        }
        if ($domain !== null) {
            if (isset($this->seen['domain:' . $domain])) {
                return sprintf('domain %s already in this batch', $domain);
            }
            $id = $this->db->value('SELECT id FROM leads WHERE domain = ?', [$domain]);
            if ($id !== null) {
                return sprintf('domain %s matches lead #%d', $domain, (int) $id);
            }
        }
        if ($phoneKey !== null) {
            if (isset($this->seen['phone:' . $phoneKey])) {
                return sprintf('phone %s already in this batch', $phoneKey);
            }
            $id = $this->db->value('SELECT id FROM leads WHERE phone_normalized = ?', [$phoneKey]);
            if ($id !== null) {
                return sprintf('phone %s matches lead #%d', $phoneKey, (int) $id);
            }
        }

        return null;
    }

    /** Remembers keys of a lead accepted in the current batch. */
    public function remember(?string $website, ?string $phone, ?string $country = null, ?string $placeId = null): void
    {
        if ($placeId !== null && $placeId !== '') {
            $this->seen['place:' . $placeId] = 0;
        }
        $domain = self::dedupDomain($website);
        if ($domain !== null) {
            $this->seen['domain:' . $domain] = 0;
        }
        $phoneKey = Normalizer::phone($phone, $country);
        if ($phoneKey !== null) {
            $this->seen['phone:' . $phoneKey] = 0;
        }
    }
}
