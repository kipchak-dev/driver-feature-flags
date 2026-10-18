<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library\Provider;

use Flagsmith\Exceptions\FlagsmithClientError;
use Flagsmith\Flagsmith;
use Flagsmith\Models\Flags;
use Kipchak\Driver\FeatureFlags\Library\FailureBackoff;
use Kipchak\Driver\FeatureFlags\Library\RefreshSchedule;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Flagsmith, through its PHP SDK, in one of two modes:
 *
 *  - local evaluation (a server-side "ser." key): the environment document is downloaded every refresh interval
 *    and every flag is evaluated in process. The SDK keeps the document on the Flagsmith object, so a worker keeps
 *    answering from the last good copy while Flagsmith is down.
 *  - remote evaluation: every evaluation asks the Flagsmith API, which also stores the context's attributes as the
 *    identity's traits. The SDK's cache, when the connection has one, holds each answer for the refresh interval.
 */
final class FlagsmithProvider implements Provider
{
    private ?Throwable $lastFailure = null;
    private readonly FailureBackoff $backoff;

    public function __construct(
        private readonly Flagsmith $flagsmith,
        private readonly ?RefreshSchedule $schedule = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->backoff = new FailureBackoff();
    }

    public function name(): string
    {
        return 'flagsmith';
    }

    public function isEnabled(string $flag, Context $context, bool $default): bool
    {
        $result = $this->evaluate($flag, $context);

        return $result->isKnown() ? $result->enabled : $default;
    }

    public function evaluate(string $flag, Context $context): Flag
    {
        try {
            $found = $this->flags($context)->getFlag($flag);
        } catch (FlagsmithClientError $e) {
            if (str_contains($e->getMessage(), 'does not exist')) {
                return Flag::unknown($flag);
            }
            throw $e;
        }

        return new Flag(name: $flag, enabled: $found->getEnabled(), value: $found->getValue());
    }

    public function refresh(): void
    {
        $this->cache?->clear();
        $this->schedule?->expire();
        $this->backoff->reset();
        $this->loadEnvironment();
    }

    private function flags(Context $context): Flags
    {
        $this->loadEnvironment();

        $flags = fn (): Flags => $context->userId === null
            ? $this->flagsmith->getEnvironmentFlags()
            : $this->flagsmith->getIdentityFlags($context->userId, $this->traits($context));

        // Remote evaluation calls the API on every check, so while it is down, fail fast between attempts.
        return $this->schedule === null ? $this->backoff->call($flags) : $flags();
    }

    /**
     * Downloads the environment document when local evaluation is on and the refresh interval has run out. A
     * failure is only thrown when there is no earlier document, or bootstrap file, to evaluate with.
     */
    private function loadEnvironment(): void
    {
        if ($this->schedule === null) {
            return;
        }

        // Until a download has worked, every evaluation reports the last failure. Without a document the SDK
        // would quietly ask the remote API instead.
        if (!$this->schedule->isDue()) {
            if ($this->lastFailure !== null && $this->flagsmith->getLocalEvaluationContext() === null) {
                throw $this->lastFailure;
            }

            return;
        }

        try {
            $this->flagsmith->updateEnvironment();
            $this->schedule->succeeded();
            $this->lastFailure = null;
        } catch (Throwable $e) {
            $this->schedule->failed();
            $this->lastFailure = $e;
            // The SDK keeps the last document it downloaded, or the bootstrap_file before any download has worked.
            if ($this->flagsmith->getLocalEvaluationContext() === null) {
                throw $e;
            }
            $this->logger?->warning(
                'Feature flags (flagsmith): could not refresh the environment document, using the last one loaded: '
                . $e->getMessage(),
                ['provider' => 'flagsmith', 'exception' => $e],
            );
        }
    }

    /**
     * Flagsmith traits are strings, numbers or booleans. Null attributes are left out: in remote evaluation, a
     * null trait deletes the trait that Flagsmith has stored for the identity.
     */
    private function traits(Context $context): object
    {
        $traits = [];
        foreach ($context->attributes as $name => $value) {
            if ($value !== null) {
                $traits[$name] = is_array($value) ? implode(',', $value) : $value;
            }
        }

        return (object) $traits;
    }
}
