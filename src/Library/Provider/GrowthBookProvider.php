<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library\Provider;

use Growthbook\Growthbook;
use Kipchak\Driver\FeatureFlags\Library\RefreshSchedule;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Stringable;

/**
 * GrowthBook, through its PHP SDK. Feature definitions are fetched from the SDK endpoint every refresh interval and
 * evaluated in process.
 *
 * The SDK object carries the user's attributes and the experiments they have seen, so this keeps one object with
 * the definitions and evaluates each context on a copy of it: nothing about one request reaches the next.
 */
final class GrowthBookProvider implements Provider
{
    private ?string $problem = null;

    /**
     * @param string|null $clientKey the SDK connection's client key; null for a Growthbook given its features up
     *                               front, which is then never refreshed
     */
    public function __construct(
        private readonly Growthbook $growthbook,
        private readonly ?string $clientKey = null,
        private readonly string $apiHost = '',
        private readonly string $decryptionKey = '',
        private readonly ?RefreshSchedule $schedule = null,
        private readonly ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->growthbook->setLogger($this->recorder($logger));
    }

    public function name(): string
    {
        return 'growthbook';
    }

    public function isEnabled(string $flag, Context $context, bool $default): bool
    {
        $result = $this->evaluate($flag, $context);

        return $result->isKnown() ? $result->enabled : $default;
    }

    public function evaluate(string $flag, Context $context): Flag
    {
        $this->loadFeatures();

        $result = $this->growthbook->withAttributes($this->attributes($context))->getFeature($flag);
        if ($result->source === 'unknownFeature') {
            return Flag::unknown($flag);
        }

        $variation = $result->experimentResult?->inExperiment ? $result->experimentResult->key : null;

        return new Flag(
            name: $flag,
            enabled: (bool) $result->on,
            value: $result->value,
            variant: $variation === null ? null : (string) $variation,
        );
    }

    public function refresh(): void
    {
        $this->cache?->clear();
        $this->schedule?->expire();
        $this->loadFeatures();
    }

    /**
     * Fetches the features when the refresh interval has run out. The SDK never throws: it logs the failure and
     * keeps the features it had, so a failure is only thrown when there are none.
     */
    private function loadFeatures(): void
    {
        if ($this->clientKey === null || $this->schedule === null) {
            return;
        }

        if ($this->schedule->isDue()) {
            $this->problem = null;
            $this->growthbook->initialize($this->clientKey, $this->apiHost, $this->decryptionKey);
            $this->problem === null ? $this->schedule->succeeded() : $this->schedule->failed();
        }

        // Until a fetch has worked, every evaluation reports the last failure, not just the one that fetched.
        if ($this->problem !== null && !$this->schedule->hasLoaded() && $this->growthbook->getFeatures() === []) {
            throw new RuntimeException('Could not load the features: ' . $this->problem);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Context $context): array
    {
        $standard = array_filter(
            ['id' => $context->userId, 'sessionId' => $context->sessionId, 'ipAddress' => $context->ipAddress],
            static fn (?string $value): bool => $value !== null,
        );

        return $context->attributes + $standard;
    }

    /**
     * A logger for the SDK that notes the warnings and errors it logs while fetching, and passes those on. Its
     * informational messages (one for every load from cache) are dropped.
     */
    private function recorder(?LoggerInterface $logger): LoggerInterface
    {
        $provider = $this;

        return new class ($logger, function (string $message) use ($provider): void {
            $provider->problem ??= $message;
        }) extends AbstractLogger {
            public function __construct(private readonly ?LoggerInterface $logger, private readonly \Closure $record)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (!in_array($level, [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY], true)) {
                    return;
                }

                $error = isset($context['error']) && is_string($context['error']) ? ': ' . $context['error'] : '';
                ($this->record)($message . $error);
                $this->logger?->log($level, 'Feature flags (growthbook): ' . $message . $error, ['provider' => 'growthbook'] + $context);
            }
        };
    }
}
