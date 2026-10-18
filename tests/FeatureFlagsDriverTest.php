<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests;

use DI\Container;
use InvalidArgumentException;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\FeatureFlags;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Tests\Fake\BuildsConnections;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeatureFlagsDriverTest extends TestCase
{
    use BuildsConnections;

    private function primeDriver(array $config, string $file = 'kipchak.feature-flags'): Container
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', [$file => $config]);
        FeatureFlags::initialise($container, $file === 'kipchak.feature-flags' ? null : $file);

        return $container;
    }

    private static function boot(string $provider, string $fixture): array
    {
        return ['provider' => $provider, 'bootstrap_file' => __DIR__ . '/fixtures/' . $fixture];
    }

    public function testRegistersOneContainerEntryPerConnection(): void
    {
        $container = $this->primeDriver(['connections' => [
            'default' => self::boot('unleash', 'unleash-features.json'),
            'flagsmith' => self::boot('flagsmith', 'flagsmith-environment.json'),
            'growthbook' => self::boot('growthbook', 'growthbook-features.json'),
        ]]);

        $this->assertTrue($container->has('drivers.featureflags.default'));
        $this->assertInstanceOf(FeatureFlagsClient::class, FeatureFlags::get());
        $this->assertSame('unleash', FeatureFlags::get()->provider());
        $this->assertSame('flagsmith', FeatureFlags::get('flagsmith')->provider());
        $this->assertSame('growthbook', FeatureFlags::get('growthbook')->provider());
        $this->assertSame(FeatureFlags::get(), FeatureFlags::get('default'), 'One client, and so one copy of the flags, per worker');

        $pro = new Context('user-1', ['plan' => 'pro']);
        foreach (['default', 'flagsmith', 'growthbook'] as $name) {
            $this->assertTrue(FeatureFlags::get($name)->isEnabled('new-checkout', $pro), $name);
            $this->assertFalse(FeatureFlags::get($name)->isEnabled('new-checkout', new Context('user-2')), $name);
        }
    }

    public function testNoConnectionsRegistersNothing(): void
    {
        $this->assertFalse($this->primeDriver([])->has('drivers.featureflags.default'));
    }

    public function testCustomConfigFileName(): void
    {
        $this->primeDriver(['connections' => ['default' => self::boot('growthbook', 'growthbook-features.json')]], 'flags');

        $this->assertSame('growthbook', FeatureFlags::get()->provider());
    }

    public function testTheLoggerDriverIsUsedWhenItIsThere(): void
    {
        $this->refuseConnection();
        $flags = $this->connect(['provider' => 'growthbook', 'client_key' => 'sdk-x', 'http_client' => 'mock']);

        $flags->isEnabled('anything');

        $this->assertNotEmpty($this->logger->messages());
    }

    public function testStrictIsMappedOntoTheClient(): void
    {
        $this->refuseConnection();
        $flags = $this->connect(['provider' => 'growthbook', 'client_key' => 'sdk-x', 'http_client' => 'mock', 'strict' => true]);

        $this->expectException(\Kipchak\Driver\FeatureFlags\Exception\FeatureFlagException::class);
        $flags->isEnabled('anything');
    }

    public function testTheSampleConfigBuildsAClient(): void
    {
        require_once dirname((string) (new \ReflectionClass(\Kipchak\Core\Api::class))->getFileName()) . '/Functions.php';
        putenv('UNLEASH_URL=http://127.0.0.1:1/api');
        try {
            $config = require __DIR__ . '/../sample.config.php';
        } finally {
            putenv('UNLEASH_URL');
        }
        $config['connections']['default']['register'] = false;
        $config['connections']['default']['connect_timeout'] = 0.2;
        $this->primeDriver($config);

        $this->assertSame('unleash', FeatureFlags::get()->provider());
        $this->assertTrue(FeatureFlags::get()->isEnabled('new-checkout', default: true), 'Unreachable, so the default');
    }

    public static function invalidConnections(): array
    {
        $file = __DIR__ . '/fixtures/unleash-features.json';

        return [
            'no provider' => [[], 'needs a provider: "unleash", "flagsmith" or "growthbook".'],
            'unknown provider' => [['provider' => 'launchdarkly'], 'needs a provider'],
            'unleash without url' => [['provider' => 'unleash', 'url' => ''], 'needs a url (the Unleash API, such as http://unleash:4242/api) or a bootstrap_file.'],
            'flagsmith without key' => [['provider' => 'flagsmith'], 'needs an environment_key or a bootstrap_file'],
            'growthbook without key' => [['provider' => 'growthbook', 'client_key' => ''], 'needs a client_key'],
            'missing bootstrap file' => [['provider' => 'unleash', 'bootstrap_file' => '/nope.json'], 'bootstrap_file "/nope.json" cannot be read.'],
            'zero refresh interval' => [['provider' => 'unleash', 'bootstrap_file' => $file, 'refresh_interval' => 0], 'refresh_interval must be at least 1 second.'],
            'unknown cache' => [['provider' => 'unleash', 'bootstrap_file' => $file, 'cache' => 'valkey'], 'cache "valkey" is not "memory", "file" or a container entry.'],
        ];
    }

    #[DataProvider('invalidConnections')]
    public function testInvalidConnectionsAreExplained(array $connection, string $message): void
    {
        $this->primeDriver(['connections' => ['flags' => $connection]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        try {
            FeatureFlags::get('flags');
        } catch (\Throwable $e) {
            // PHP-DI wraps what the factory throws.
            throw $e instanceof InvalidArgumentException ? $e : ($e->getPrevious() ?? $e);
        }
    }

    public function testErrorsNameTheConnection(): void
    {
        $this->primeDriver(['connections' => ['partners' => ['provider' => 'flagsmith']]]);

        $this->expectExceptionMessage('Feature flag connection "partners" needs an environment_key');

        try {
            FeatureFlags::get('partners');
        } catch (\Throwable $e) {
            throw $e instanceof InvalidArgumentException ? $e : ($e->getPrevious() ?? $e);
        }
    }
}
