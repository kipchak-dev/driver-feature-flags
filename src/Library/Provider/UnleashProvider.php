<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library\Provider;

use Kipchak\Driver\FeatureFlags\Library\FailureBackoff;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Unleash\Client\Configuration\UnleashContext;
use Unleash\Client\DTO\Variant;
use Unleash\Client\Enum\VariantPayloadType;
use Unleash\Client\Unleash;
use Unleash\Client\UnleashBuilder;

/**
 * Unleash, through its PHP SDK. The SDK evaluates toggles locally from the definitions it fetches, keeping them in
 * $featureCache for the refresh interval and in a separate stale cache to answer from when Unleash is down.
 *
 * Without any toggles (Unleash down since boot, no bootstrap file), the SDK would try to fetch on every check;
 * the backoff makes those checks fail fast between attempts.
 */
final class UnleashProvider implements Provider
{
    private readonly Unleash $unleash;
    private readonly UnleashEventRecorder $events;
    private readonly FailureBackoff $backoff;

    /**
     * @param UnleashBuilder $builder configured with everything except the event subscriber, which this adds
     * @param CacheInterface $featureCache the cache given to the builder's withCacheHandler(), cleared by refresh()
     */
    public function __construct(
        UnleashBuilder $builder,
        private readonly CacheInterface $featureCache,
        private readonly ?string $environment = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->events = new UnleashEventRecorder($logger);
        $this->backoff = new FailureBackoff();
        $this->unleash = $builder->withEventSubscriber($this->events)->build();
    }

    public function name(): string
    {
        return 'unleash';
    }

    public function isEnabled(string $flag, Context $context, bool $default): bool
    {
        return $this->backoff->call(fn (): bool => $this->unleash->isEnabled($flag, $this->context($context), $default));
    }

    public function evaluate(string $flag, Context $context): Flag
    {
        $this->events->reset();
        $variant = $this->backoff->call(fn (): Variant => $this->unleash->getVariant($flag, $this->context($context)));
        if ($this->events->wasNotFound($flag)) {
            return Flag::unknown($flag);
        }

        $hasVariant = $variant->isEnabled();

        return new Flag(
            name: $flag,
            enabled: $hasVariant || $variant->isFeatureEnabled(),
            value: $hasVariant ? $this->payload($variant) : null,
            variant: $hasVariant ? $variant->getName() : null,
        );
    }

    public function refresh(): void
    {
        $this->featureCache->clear();
        $this->backoff->reset();
    }

    private function context(Context $context): UnleashContext
    {
        $properties = [];
        foreach ($context->attributes as $name => $value) {
            if ($value !== null) {
                $properties[$name] = self::string($value);
            }
        }

        return new UnleashContext(
            currentUserId: $context->userId,
            ipAddress: $context->ipAddress,
            sessionId: $context->sessionId,
            customContext: $properties,
            environment: $this->environment,
        );
    }

    /**
     * Unleash context properties are strings; constraints such as NUM_EQ and IN parse them back.
     */
    private static function string(string|int|float|bool|array $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode(',', array_map(self::string(...), $value)),
            default => (string) $value,
        };
    }

    private function payload(Variant $variant): mixed
    {
        $payload = $variant->getPayload();

        return match ($payload?->getType()) {
            null => null,
            VariantPayloadType::JSON => $payload->fromJson(),
            'number' => is_numeric($payload->getValue()) ? $payload->getValue() + 0 : $payload->getValue(),
            default => $payload->getValue(),
        };
    }
}
