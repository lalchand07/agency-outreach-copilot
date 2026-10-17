<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Current time. Timestamps are stored as UTC "Y-m-d H:i:s" strings; the app
 * timezone is used for "today" (daily caps, dashboard days).
 */
class Clock
{
    private \DateTimeZone $timezone;

    public function __construct(string $timezone = 'UTC', private ?\DateTimeImmutable $frozen = null)
    {
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function now(): \DateTimeImmutable
    {
        return ($this->frozen ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function timezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    /** "Y-m-d H:i:s" in UTC. */
    public function nowString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /**
     * Start and end (exclusive) of the local calendar day containing now, as UTC strings.
     *
     * @return array{0: string, 1: string}
     */
    public function todayRangeUtc(): array
    {
        $localStart = $this->now()->setTimezone($this->timezone)->setTime(0, 0);
        $utc = new \DateTimeZone('UTC');

        return [
            $localStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            $localStart->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    public function advance(string $modifier): void
    {
        $this->frozen = $this->now()->modify($modifier);
    }

    public function localDate(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($this->timezone)->format('Y-m-d');
    }
}
