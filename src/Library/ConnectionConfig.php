<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Reads one connection's settings, with the error messages naming the connection.
 *
 * @internal
 */
final readonly class ConnectionConfig
{
    public function __construct(
        public string $name,
        public array $details,
        private CacheFactory $caches,
    ) {
    }

    /**
     * A string setting, or null when it is missing or empty (env() returns "" for an unset variable).
     */
    public function string(string $key): ?string
    {
        $value = $this->details[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function refreshInterval(): int
    {
        $interval = (int) ($this->details['refresh_interval'] ?? 30);
        if ($interval < 1) {
            throw $this->invalid('refresh_interval must be at least 1 second.');
        }

        return $interval;
    }

    public function bootstrapFile(): ?string
    {
        $file = $this->string('bootstrap_file');
        if ($file === null) {
            return null;
        }
        if (!is_readable($file)) {
            throw $this->invalid('bootstrap_file "' . $file . '" cannot be read.');
        }

        // Absolute, because the Unleash SDK reads it as a file:// URL.
        return realpath($file) ?: $file;
    }

    public function cache(string $purpose): CacheInterface
    {
        return $this->caches->create($this->string('cache') ?? 'memory', $this->name, $purpose);
    }

    public function invalid(string $problem): InvalidArgumentException
    {
        return new InvalidArgumentException('Feature flag connection "' . $this->name . '" ' . $problem);
    }
}
