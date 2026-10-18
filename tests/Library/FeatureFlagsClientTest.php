<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Library;

use Kipchak\Driver\FeatureFlags\Exception\FeatureFlagException;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Kipchak\Driver\FeatureFlags\Tests\Fake\FakeProvider;
use Kipchak\Driver\FeatureFlags\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class FeatureFlagsClientTest extends TestCase
{
    private FakeProvider $provider;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider([
            'on' => new Flag('on', true),
            'off' => new Flag('off', false),
            'limit' => new Flag('limit', true, 100),
            'zero' => new Flag('zero', false, 0),
            'checkout' => new Flag('checkout', true, ['colour' => 'blue'], 'blue'),
        ]);
        $this->logger = new RecordingLogger();
    }

    private function client(bool $strict = false): FeatureFlagsClient
    {
        return new FeatureFlagsClient($this->provider, $strict, $this->logger);
    }

    public function testIsEnabledAnswersKnownFlagsAndDefaultsUnknownOnes(): void
    {
        $client = $this->client();

        $this->assertTrue($client->isEnabled('on'));
        $this->assertFalse($client->isEnabled('off', default: true), 'A flag that is off ignores the default');
        $this->assertFalse($client->isEnabled('missing'));
        $this->assertTrue($client->isEnabled('missing', default: true));
    }

    public function testValueReturnsTheProvidersValueOrTheDefault(): void
    {
        $client = $this->client();

        $this->assertSame(100, $client->value('limit', 10));
        $this->assertSame(0, $client->value('zero', 10), 'A falsy value is still a value');
        $this->assertSame(10, $client->value('on', 10), 'A flag with no value');
        $this->assertSame(10, $client->value('missing', 10));
        $this->assertNull($client->value('missing'));
    }

    public function testVariantAndEvaluate(): void
    {
        $client = $this->client();

        $this->assertSame('blue', $client->variant('checkout'));
        $this->assertNull($client->variant('on'));
        $this->assertSame(['colour' => 'blue'], $client->evaluate('checkout')->value);
        $this->assertSame(Flag::SOURCE_UNKNOWN, $client->evaluate('missing')->source);
        $this->assertFalse($client->evaluate('missing')->isKnown());
    }

    public function testTheContextIsPassedThroughAndDefaultsToAnEmptyOne(): void
    {
        $client = $this->client();
        $context = new Context('user-1', ['plan' => 'pro']);

        $client->isEnabled('on', $context);
        $client->value('on', null, $context);
        $client->isEnabled('on');

        $this->assertSame($context, $this->provider->contexts[0]);
        $this->assertSame($context, $this->provider->contexts[1]);
        $this->assertEquals(new Context(), $this->provider->contexts[2]);
    }

    public function testAnUnreachableProviderReturnsDefaultsAndLogs(): void
    {
        $this->provider->down = true;
        $client = $this->client();

        $this->assertTrue($client->isEnabled('on', default: true));
        $this->assertFalse($client->isEnabled('on'));
        $this->assertSame(10, $client->value('limit', 10));
        $this->assertNull($client->variant('checkout'));
        $this->assertSame(Flag::SOURCE_ERROR, $client->evaluate('on')->source);
        $client->refresh();

        $warnings = $this->logger->messages();
        $this->assertCount(1, $warnings, 'Logged once a minute, not on every check');
        $this->assertSame('Feature flags (fake): could not evaluate "on": Connection refused', $warnings[0]);
        $this->assertSame('fake', $this->logger->records[0]['context']['provider']);
        $this->assertInstanceOf(\RuntimeException::class, $this->logger->records[0]['context']['exception']);
    }

    public function testStrictModeThrows(): void
    {
        $this->provider->down = true;

        try {
            $this->client(strict: true)->isEnabled('on', default: true);
            $this->fail('Expected a FeatureFlagException');
        } catch (FeatureFlagException $e) {
            $this->assertSame('on', $e->flag);
            $this->assertSame('fake', $e->provider);
            $this->assertSame('Connection refused', $e->getPrevious()?->getMessage());
        }

        $this->expectException(FeatureFlagException::class);
        $this->expectExceptionMessage('Feature flags (fake): refresh failed: Connection refused');
        $this->client(strict: true)->refresh();
    }

    public function testRefreshIsPassedOn(): void
    {
        $this->client()->refresh();

        $this->assertSame(1, $this->provider->refreshes);
        $this->assertSame('fake', $this->client()->provider());
    }

    public function testWorksWithoutALogger(): void
    {
        $this->provider->down = true;

        $this->assertTrue((new FeatureFlagsClient($this->provider))->isEnabled('on', default: true));
    }
}
