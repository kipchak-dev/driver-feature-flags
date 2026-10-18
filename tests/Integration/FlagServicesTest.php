<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Integration;

use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\FeatureFlags;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Kipchak\Driver\Filecache\Filecache;
use Kipchak\Driver\Http\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Against real Unleash, Flagsmith and GrowthBook servers. Start them first with tests/Integration/start-services.sh,
 * which creates the flags and writes the keys these tests read.
 */
final class FlagServicesTest extends TestCase
{
    /** @var array<string, string> */
    private static array $keys = [];

    public static function setUpBeforeClass(): void
    {
        $file = __DIR__ . '/.keys.env';
        if (!is_file($file)) {
            self::markTestSkipped('Run tests/Integration/start-services.sh first.');
        }
        self::$keys = parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function connections(): array
    {
        return [
            'unleash' => [
                'provider' => 'unleash',
                'url' => self::$keys['UNLEASH_URL'],
                'token' => self::$keys['UNLEASH_TOKEN'],
                'app_name' => 'kipchak-integration-tests',
            ],
            'flagsmith-local' => [
                'provider' => 'flagsmith',
                'url' => self::$keys['FLAGSMITH_URL'],
                'environment_key' => self::$keys['FLAGSMITH_SERVER_KEY'],
            ],
            'flagsmith-remote' => [
                'provider' => 'flagsmith',
                'url' => self::$keys['FLAGSMITH_URL'],
                'environment_key' => self::$keys['FLAGSMITH_ENVIRONMENT_KEY'],
            ],
            'growthbook' => [
                'provider' => 'growthbook',
                'url' => self::$keys['GROWTHBOOK_API_HOST'],
                'client_key' => self::$keys['GROWTHBOOK_CLIENT_KEY'],
            ],
        ];
    }

    private static function client(string $name, array $overrides = []): FeatureFlagsClient
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', [
            'kipchak.api' => ['name' => 'kipchak-flags-integration'],
            'kipchak.feature-flags' => ['connections' => [
                $name => $overrides + self::connections()[$name] + ['strict' => true],
            ]],
            // A client whose every request fails, as if the server were down.
            'kipchak.http' => ['refuse' => ['type' => 'psr18', 'options' => [
                'handler' => static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(new ConnectException('Connection refused', $request)),
            ]]],
        ]);
        Http::initialise($container);
        Filecache::initialise($container);
        FeatureFlags::initialise($container);

        return FeatureFlags::get($name);
    }

    public static function connectionNames(): array
    {
        return array_map(static fn (array $provider): array => [$provider[0]], self::providers());
    }

    public static function providers(): array
    {
        return [
            'unleash' => ['unleash', ['colour' => 'blue']],
            'flagsmith, local evaluation' => ['flagsmith-local', 'blue'],
            'flagsmith, remote evaluation' => ['flagsmith-remote', 'blue'],
            'growthbook' => ['growthbook', ['colour' => 'blue']],
        ];
    }

    #[DataProvider('providers')]
    public function testEvaluatesTheFlagsOnTheServer(string $connection, mixed $checkoutValue): void
    {
        $flags = self::client($connection);
        $pro = new Context('user-pro-' . $connection, ['plan' => 'pro']);
        $free = new Context('user-free-' . $connection, ['plan' => 'free']);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $this->assertFalse($flags->isEnabled('new-checkout', $free));
        $this->assertFalse($flags->isEnabled('kill-switch', $pro, default: true));
        $this->assertTrue($flags->isEnabled('rate-limit'));
        $this->assertSame($checkoutValue, $flags->value('new-checkout', null, $pro));
        $this->assertSame(100, $flags->value('rate-limit', 10));
        $this->assertTrue($flags->isEnabled('no-such-flag', $pro, default: true));
        $this->assertSame(Flag::SOURCE_PROVIDER, $flags->evaluate('new-checkout', $pro)->source);
    }

    #[DataProvider('connectionNames')]
    public function testAChangeOnTheServerArrivesWithRefresh(string $connection): void
    {
        $flags = self::client($connection);
        $context = new Context('user-change-' . $connection);
        $this->assertFalse($flags->isEnabled('kill-switch', $context));

        $this->setKillSwitch($connection, true);
        try {
            $this->assertTrue($this->eventually(static function () use ($flags, $context): bool {
                $flags->refresh();

                return $flags->isEnabled('kill-switch', $context);
            }), 'kill-switch is on after a refresh');
        } finally {
            $this->setKillSwitch($connection, false);
        }
    }

    #[DataProvider('connectionNames')]
    public function testAnUnreachableServerGivesTheDefault(string $connection): void
    {
        $flags = self::client($connection, [
            'url' => 'http://127.0.0.1:1/api',
            'strict' => false,
        ]);

        $this->assertTrue($flags->isEnabled('new-checkout', new Context('user-1', ['plan' => 'free']), default: true));
        $this->assertSame(Flag::SOURCE_ERROR, $flags->evaluate('new-checkout', new Context('user-1'))->source);
    }

    #[DataProvider('connectionNames')]
    public function testAFileCacheIsSharedBetweenClients(string $connection): void
    {
        $first = self::client($connection, ['cache' => 'file']);
        $first->refresh();
        $this->assertTrue($first->isEnabled('new-checkout', new Context('user-1', ['plan' => 'pro'])));

        // Another process with the same connection, while the server is down, answers from the shared cache.
        $second = self::client($connection, ['cache' => 'file', 'http_client' => 'refuse']);
        $this->assertTrue($second->isEnabled('new-checkout', new Context('user-1', ['plan' => 'pro'])));

        $first->refresh();
    }

    /**
     * Polls for a few seconds: GrowthBook and Flagsmith publish changes to their SDK endpoints asynchronously.
     */
    private function eventually(callable $condition): bool
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if ($condition()) {
                return true;
            }
            usleep(500_000);
        }

        return false;
    }

    private function setKillSwitch(string $connection, bool $on): void
    {
        $http = new Client(['http_errors' => true, 'timeout' => 10]);

        match ($connection) {
            'unleash' => $http->post(
                'http://localhost:54242/api/admin/projects/default/features/kill-switch/environments/development/' . ($on ? 'on' : 'off'),
                ['headers' => ['Authorization' => self::$keys['UNLEASH_ADMIN_TOKEN']]],
            ),
            'flagsmith-local', 'flagsmith-remote' => $this->setFlagsmithKillSwitch($http, $on),
            'growthbook' => $http->post(self::$keys['GROWTHBOOK_API_HOST'] . '/api/v1/features/kill-switch', [
                'headers' => ['Authorization' => 'Bearer ' . self::$keys['GROWTHBOOK_SECRET']],
                'json' => ['defaultValue' => $on ? 'true' : 'false'],
            ]),
        };
    }

    private function setFlagsmithKillSwitch(Client $http, bool $on): void
    {
        $headers = ['Authorization' => 'Token ' . self::$keys['FLAGSMITH_ADMIN_TOKEN']];
        $base = self::$keys['FLAGSMITH_URL'] . '/environments/' . self::$keys['FLAGSMITH_ENVIRONMENT_KEY'] . '/featurestates/';

        $states = json_decode((string) $http->get($base, ['headers' => $headers])->getBody(), true);
        foreach ($states['results'] as $state) {
            if ($state['feature_segment'] === null && $state['identity'] === null && $this->featureName($http, $headers, $state['feature']) === 'kill-switch') {
                $http->patch($base . $state['id'] . '/', ['headers' => $headers, 'json' => ['enabled' => $on]]);

                return;
            }
        }
        $this->fail('No environment feature state for kill-switch');
    }

    private function featureName(Client $http, array $headers, int $id): string
    {
        $projects = json_decode((string) $http->get(self::$keys['FLAGSMITH_URL'] . '/projects/', ['headers' => $headers])->getBody(), true);
        $project = $projects[0]['id'] ?? $projects['results'][0]['id'];
        $feature = json_decode((string) $http->get(self::$keys['FLAGSMITH_URL'] . "/projects/$project/features/$id/", ['headers' => $headers])->getBody(), true);

        return $feature['name'];
    }
}
