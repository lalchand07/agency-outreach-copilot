<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pauses between requests. Tests replace it so nothing actually sleeps.
 */
class Sleeper
{
    /** @var list<int> milliseconds slept, for tests */
    public array $slept = [];

    public function __construct(private bool $real = true)
    {
    }

    public function sleepMs(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        $this->slept[] = $ms;
        if ($this->real) {
            usleep($ms * 1000);
        }
    }

    /** Monotonic milliseconds. */
    public function nowMs(): int
    {
        if (!$this->real) {
            return array_sum($this->slept);
        }

        return (int) (hrtime(true) / 1_000_000);
    }
}
