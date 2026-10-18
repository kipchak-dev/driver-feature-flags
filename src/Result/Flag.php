<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Result;

/**
 * The outcome of evaluating one flag for one context.
 *
 * $value is what the provider attaches to the flag:
 *  - Unleash: the selected variant's payload (JSON decoded to an array, numbers to int or float), or null.
 *  - Flagsmith: the feature's remote config value. Flagsmith keeps it whether the flag is on or off.
 *  - GrowthBook: the feature's value. GrowthBook calls a feature on when that value is truthy, so a value of 0 or
 *    "" is a real value on a feature that is off.
 *
 * $variant is the name of the variant this context was given: the Unleash variant, or the key of the GrowthBook
 * experiment variation. Flagsmith does not name its multivariate values, so it is always null there.
 *
 * $source says why the flag has this outcome: "provider" when the provider evaluated it, "unknown" when the
 * provider does not have the flag, and "error" when the provider could not be reached and the defaults were used.
 * Unleash does not report unknown flags, so they come back as "provider" and disabled.
 */
final readonly class Flag
{
    public const string SOURCE_PROVIDER = 'provider';
    public const string SOURCE_UNKNOWN = 'unknown';
    public const string SOURCE_ERROR = 'error';

    public function __construct(
        public string $name,
        public bool $enabled,
        public mixed $value = null,
        public ?string $variant = null,
        public string $source = self::SOURCE_PROVIDER,
    ) {
    }

    public static function unknown(string $name): self
    {
        return new self($name, false, source: self::SOURCE_UNKNOWN);
    }

    public static function failed(string $name): self
    {
        return new self($name, false, source: self::SOURCE_ERROR);
    }

    public function isKnown(): bool
    {
        return $this->source === self::SOURCE_PROVIDER;
    }
}
