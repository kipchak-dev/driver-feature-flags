<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Exception;

use RuntimeException;

/**
 * The provider could not evaluate a flag: it was unreachable and no flags had been loaded, it rejected the API key,
 * or it returned something unreadable.
 *
 * Only thrown by connections with 'strict' => true. Otherwise the error is logged and the caller's default is used.
 */
class FeatureFlagException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $flag = null,
        public readonly ?string $provider = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
