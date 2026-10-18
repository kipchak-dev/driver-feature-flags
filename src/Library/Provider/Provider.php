<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library\Provider;

use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;

/**
 * One feature flag service, evaluating flags through its own SDK.
 *
 * Implementations hold the flag definitions between calls, so a FrankenPHP worker fetches them once per refresh
 * interval rather than once per request. They must not keep anything about the context between calls.
 *
 * Methods throw when the service cannot be reached and nothing usable has been loaded; FeatureFlagsClient turns that
 * into the caller's default.
 */
interface Provider
{
    /**
     * The provider's name, as it appears in config: "unleash", "flagsmith" or "growthbook".
     */
    public function name(): string;

    /**
     * Whether the flag is on for this context, or $default when the provider does not have the flag.
     */
    public function isEnabled(string $flag, Context $context, bool $default): bool;

    public function evaluate(string $flag, Context $context): Flag;

    /**
     * Discards the cached flag definitions and fetches them again.
     */
    public function refresh(): void;
}
