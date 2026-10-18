<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library;

use Closure;
use Throwable;

/**
 * Fails fast while a provider is down. After a failed call, the same failure is thrown again without calling, for
 * 5 seconds, then 10, 20 and 40, and at most 60 seconds between attempts during a long outage. A hung provider
 * then costs one request a timeout per attempt, not every request.
 */
final class FailureBackoff
{
    public const float FIRST = 5.0;
    public const float MAX = 60.0;

    private int $failures = 0;
    private float $retryAt = 0.0;
    private ?Throwable $failure = null;
    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param Closure(): float|null $clock seconds, for tests; defaults to microtime(true)
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @template T
     * @param Closure(): T $call
     * @return T
     */
    public function call(Closure $call): mixed
    {
        if ($this->failure !== null && ($this->clock)() < $this->retryAt) {
            throw $this->failure;
        }

        try {
            $result = $call();
        } catch (Throwable $e) {
            $this->failed($e);
            throw $e;
        }
        $this->succeeded();

        return $result;
    }

    public function failed(Throwable $e): void
    {
        $this->failure = $e;
        $this->retryAt = ($this->clock)() + self::delay(++$this->failures, self::FIRST);
    }

    public function succeeded(): void
    {
        $this->failures = 0;
        $this->failure = null;
    }

    /**
     * Allows the next call through, for an explicit refresh.
     */
    public function reset(): void
    {
        $this->retryAt = 0.0;
    }

    /**
     * Seconds to wait after the given number of consecutive failures: $first, doubling, capped at MAX.
     */
    public static function delay(int $failures, float $first): float
    {
        return min($first * 2 ** max(0, $failures - 1), max($first, self::MAX));
    }
}
