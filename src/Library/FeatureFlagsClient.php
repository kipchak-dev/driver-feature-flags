<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library;

use Kipchak\Driver\FeatureFlags\Exception\FeatureFlagException;
use Kipchak\Driver\FeatureFlags\Library\Provider\Provider;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates feature flags from one provider connection.
 *
 * A flag check sits on the request path, so by default it never fails the request: when the provider cannot be
 * reached and nothing has been loaded yet, the error is logged and the default you pass is returned. With
 * $strict, the error is thrown as a FeatureFlagException instead.
 */
final class FeatureFlagsClient
{
    private const float WARNING_INTERVAL = 60.0;

    private float $nextWarningAt = 0.0;
    private int $suppressedWarnings = 0;

    public function __construct(
        private readonly Provider $provider,
        private readonly bool $strict = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function provider(): string
    {
        return $this->provider->name();
    }

    /**
     * Whether the flag is on for this context. $default is returned for a flag the provider does not have, and
     * when the provider cannot be reached.
     */
    public function isEnabled(string $flag, ?Context $context = null, bool $default = false): bool
    {
        try {
            return $this->provider->isEnabled($flag, $context ?? new Context(), $default);
        } catch (Throwable $e) {
            $this->failed($flag, $e);

            return $default;
        }
    }

    /**
     * The flag's value for this context (see Flag::$value for what each provider returns), or $default when the
     * flag is unknown, has no value, or the provider cannot be reached.
     */
    public function value(string $flag, mixed $default = null, ?Context $context = null): mixed
    {
        $result = $this->evaluate($flag, $context);

        return $result->isKnown() && $result->value !== null ? $result->value : $default;
    }

    /**
     * The name of the variant this context was given, or null when there is none.
     */
    public function variant(string $flag, ?Context $context = null): ?string
    {
        return $this->evaluate($flag, $context)->variant;
    }

    /**
     * Everything the provider says about the flag for this context.
     */
    public function evaluate(string $flag, ?Context $context = null): Flag
    {
        try {
            return $this->provider->evaluate($flag, $context ?? new Context());
        } catch (Throwable $e) {
            $this->failed($flag, $e);

            return Flag::failed($flag);
        }
    }

    /**
     * Fetches the flag definitions again now, rather than when the refresh interval runs out. Use it from a
     * webhook that the provider calls when a flag changes. With an in-memory cache, this only refreshes the
     * worker that handles the call; a shared cache refreshes every worker.
     */
    public function refresh(): void
    {
        try {
            $this->provider->refresh();
        } catch (Throwable $e) {
            $this->failed(null, $e);
        }
    }

    private function failed(?string $flag, Throwable $e): void
    {
        $message = 'Feature flags (' . $this->provider->name() . '): '
            . ($flag === null ? 'refresh failed' : 'could not evaluate "' . $flag . '"') . ': ' . $e->getMessage();

        if ($this->strict) {
            throw new FeatureFlagException($message, $flag, $this->provider->name(), $e);
        }

        // Every flag check fails while the provider is down, so log the first failure and then one a minute.
        $now = microtime(true);
        if ($now < $this->nextWarningAt) {
            $this->suppressedWarnings++;

            return;
        }
        $this->nextWarningAt = $now + self::WARNING_INTERVAL;

        $this->logger?->warning($message, [
            'flag' => $flag,
            'provider' => $this->provider->name(),
            'suppressed' => $this->suppressedWarnings,
            'exception' => $e,
        ]);
        $this->suppressedWarnings = 0;
    }
}
