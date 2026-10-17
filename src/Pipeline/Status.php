<?php

declare(strict_types=1);

namespace App\Pipeline;

/**
 * Lead pipeline statuses.
 */
final class Status
{
    public const NEW = 'new';
    public const RESEARCHED = 'researched';
    public const DRAFTED = 'drafted';
    public const APPROVED = 'approved';
    public const CONTACTED = 'contacted';
    public const REPLIED = 'replied';
    public const DEMO_BOOKED = 'demo_booked';
    public const TRIAL = 'trial';
    public const CUSTOMER = 'customer';
    public const NOT_INTERESTED = 'not_interested';
    public const DO_NOT_CONTACT = 'do_not_contact';

    public const ALL = [
        self::NEW => 'New',
        self::RESEARCHED => 'Researched',
        self::DRAFTED => 'Drafted',
        self::APPROVED => 'Approved',
        self::CONTACTED => 'Contacted',
        self::REPLIED => 'Replied',
        self::DEMO_BOOKED => 'Demo booked',
        self::TRIAL => 'Trial',
        self::CUSTOMER => 'Customer',
        self::NOT_INTERESTED => 'Not interested',
        self::DO_NOT_CONTACT => 'Do not contact',
    ];

    /** Statuses where automation (research, drafting, follow-ups) must stop. */
    public const CLOSED = [self::REPLIED, self::DEMO_BOOKED, self::TRIAL, self::CUSTOMER, self::NOT_INTERESTED, self::DO_NOT_CONTACT];

    /** Statuses whose details are deleted after the retention period. */
    public const RETENTION = [self::NOT_INTERESTED, self::DO_NOT_CONTACT];

    public static function isValid(string $status): bool
    {
        return isset(self::ALL[$status]);
    }

    public static function label(string $status): string
    {
        return self::ALL[$status] ?? $status;
    }

    /** Rank used to avoid moving a lead backwards automatically (e.g. drafting a contacted lead). */
    public static function rank(string $status): int
    {
        $pos = array_search($status, array_keys(self::ALL), true);

        return $pos === false ? 0 : (int) $pos;
    }
}
