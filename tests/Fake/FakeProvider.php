<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Fake;

use Kipchak\Driver\FeatureFlags\Library\Provider\Provider;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use RuntimeException;

/**
 * A provider answering from a name => Flag map, which can be told to fail like an unreachable service.
 */
final class FakeProvider implements Provider
{
    /** @var list<Context> */
    public array $contexts = [];
    public int $refreshes = 0;
    public bool $down = false;

    /**
     * @param array<string, Flag> $flags
     */
    public function __construct(public array $flags = [])
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isEnabled(string $flag, Context $context, bool $default): bool
    {
        $result = $this->evaluate($flag, $context);

        return $result->isKnown() ? $result->enabled : $default;
    }

    public function evaluate(string $flag, Context $context): Flag
    {
        $this->contexts[] = $context;
        $this->failIfDown();

        return $this->flags[$flag] ?? Flag::unknown($flag);
    }

    public function refresh(): void
    {
        $this->refreshes++;
        $this->failIfDown();
    }

    private function failIfDown(): void
    {
        if ($this->down) {
            throw new RuntimeException('Connection refused');
        }
    }
}
