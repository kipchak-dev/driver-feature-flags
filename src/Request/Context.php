<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Request;

/**
 * Who a flag is being evaluated for. Build one per request; it is immutable, so it is safe to pass around in worker
 * mode.
 *
 * Each provider receives it in its own terms:
 *  - Unleash: userId, sessionId and ipAddress are the standard context fields, and attributes become custom
 *    properties (Unleash only takes strings, so booleans become "true"/"false" and lists are joined with commas).
 *  - Flagsmith: userId is the identity and attributes are its traits. Without a userId, the environment's flags
 *    are returned and attributes are not used.
 *  - GrowthBook: everything becomes attributes, with userId as "id", sessionId as "sessionId" and ipAddress as
 *    "ipAddress", unless the attributes already set them.
 */
final readonly class Context
{
    /**
     * @param array<string, scalar|list<scalar>|null> $attributes for example ['plan' => 'pro', 'country' => 'GB']
     */
    public function __construct(
        public ?string $userId = null,
        public array $attributes = [],
        public ?string $sessionId = null,
        public ?string $ipAddress = null,
    ) {
    }

    public function withUserId(?string $userId): self
    {
        return new self($userId, $this->attributes, $this->sessionId, $this->ipAddress);
    }

    public function withAttribute(string $name, string|int|float|bool|array|null $value): self
    {
        return new self($this->userId, [$name => $value] + $this->attributes, $this->sessionId, $this->ipAddress);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $attributes merged over the existing attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->userId, $attributes + $this->attributes, $this->sessionId, $this->ipAddress);
    }

    public function withSessionId(?string $sessionId): self
    {
        return new self($this->userId, $this->attributes, $sessionId, $this->ipAddress);
    }

    public function withIpAddress(?string $ipAddress): self
    {
        return new self($this->userId, $this->attributes, $this->sessionId, $ipAddress);
    }
}
