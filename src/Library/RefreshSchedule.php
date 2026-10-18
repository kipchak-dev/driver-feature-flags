<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library;

use Closure;

/**
 * When a provider that keeps its flag definitions in memory should fetch them again: every $interval seconds once
 * a fetch has worked. After a failure it is retried sooner, within seconds, so a provider that was down at boot
 * comes back quickly; but the wait doubles with each further failure (see FailureBackoff), so a long outage costs
 * a timeout once a minute rather than every few seconds.
 */
final class RefreshSchedule
{
    private float $nextAt = 0.0;
    private int $failures = 0;
    private bool $loaded = false;
    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param Closure(): float|null $clock seconds, for tests; defaults to microtime(true)
     */
    public function __construct(private readonly int $interval, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function isDue(): bool
    {
        return ($this->clock)() >= $this->nextAt;
    }

    public function hasLoaded(): bool
    {
        return $this->loaded;
    }

    public function succeeded(): void
    {
        $this->loaded = true;
        $this->failures = 0;
        $this->nextAt = ($this->clock)() + $this->interval;
    }

    public function failed(): void
    {
        $first = min((float) $this->interval, FailureBackoff::FIRST);
        $this->nextAt = ($this->clock)() + FailureBackoff::delay(++$this->failures, $first);
    }

    public function expire(): void
    {
        $this->nextAt = 0.0;
    }
}
