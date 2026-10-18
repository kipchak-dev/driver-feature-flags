<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Unleash\Client\Event\FeatureToggleNotFoundEvent;
use Unleash\Client\Event\FetchingDataFailedEvent;
use Unleash\Client\Event\UnleashEvents;

/**
 * Listens to the Unleash SDK's events. The SDK answers an unknown toggle as disabled and only reports it through an
 * event, so this records which toggle it was; and it logs a failed fetch, which the SDK otherwise answers silently
 * from its stale copy of the toggles.
 *
 * @internal
 */
final class UnleashEventRecorder implements EventSubscriberInterface
{
    private ?string $notFound = null;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UnleashEvents::FEATURE_TOGGLE_NOT_FOUND => 'onNotFound',
            UnleashEvents::FETCHING_DATA_FAILED => 'onFetchFailed',
        ];
    }

    public function onNotFound(FeatureToggleNotFoundEvent $event): void
    {
        $this->notFound = $event->getFeatureName();
    }

    public function onFetchFailed(FetchingDataFailedEvent $event): void
    {
        $this->logger?->warning(
            'Feature flags (unleash): could not fetch the toggles: '
            . $event->getException()->getMessage(),
            ['provider' => 'unleash', 'exception' => $event->getException()],
        );
    }

    /**
     * Forgets the last unknown toggle, before an evaluation.
     */
    public function reset(): void
    {
        $this->notFound = null;
    }

    public function wasNotFound(string $flag): bool
    {
        return $this->notFound === $flag;
    }
}
