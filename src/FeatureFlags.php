<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags;

use Flagsmith\Flagsmith;
use Flagsmith\Utils\Retry;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Growthbook\Growthbook;
use InvalidArgumentException;
use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\Library\CacheFactory;
use Kipchak\Driver\FeatureFlags\Library\ConnectionConfig;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Library\Provider\FlagsmithDocumentHandler;
use Kipchak\Driver\FeatureFlags\Library\Provider\FlagsmithProvider;
use Kipchak\Driver\FeatureFlags\Library\Provider\GrowthBookProvider;
use Kipchak\Driver\FeatureFlags\Library\Provider\Provider;
use Kipchak\Driver\FeatureFlags\Library\Provider\UnleashProvider;
use Kipchak\Driver\FeatureFlags\Library\RefreshSchedule;
use Kipchak\Driver\Http\Http;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Unleash\Client\UnleashBuilder;

class FeatureFlags implements Interfaces\Driver
{
    private const array PACKAGES = [
        'unleash' => [UnleashBuilder::class, 'unleash/client'],
        'flagsmith' => [Flagsmith::class, 'flagsmith/flagsmith-php-client'],
        'growthbook' => [Growthbook::class, 'growthbook/growthbook'],
    ];

    protected static ?ContainerInterface $container = null;

    public static function initialise(ContainerInterface $container, ?string $configFileName = null): void
    {
        self::$container = $container;
        self::set($configFileName);
    }

    public static function set(?string $configFileName = null): void
    {
        $configFileName = $configFileName ?? 'kipchak.feature-flags';
        $config = Config::get($configFileName);

        if (empty($config['connections'])) {
            return;
        }

        foreach ($config['connections'] as $connectionName => $connectionDetails) {
            self::$container->set(
                'drivers.featureflags.' . $connectionName,
                function (ContainerInterface $c) use ($connectionName, $connectionDetails): FeatureFlagsClient {
                    return self::createClient((string) $connectionName, $connectionDetails, $c);
                }
            );
        }
    }

    public static function get(?string $configKey = null): FeatureFlagsClient
    {
        return self::$container->get('drivers.featureflags.' . ($configKey ?? 'default'));
    }

    protected static function createClient(string $connectionName, array $connectionDetails, ContainerInterface $container): FeatureFlagsClient
    {
        $provider = $connectionDetails['provider'] ?? null;
        if (!is_string($provider) || !isset(self::PACKAGES[$provider])) {
            throw new InvalidArgumentException(
                'Feature flag connection "' . $connectionName . '" needs a provider: "unleash", "flagsmith" or "growthbook".'
            );
        }

        [$class, $package] = self::PACKAGES[$provider];
        if (!class_exists($class)) {
            throw new InvalidArgumentException(
                'Feature flag connection "' . $connectionName . '" uses ' . $provider . ', whose SDK is not installed. '
                . 'Run: composer require ' . $package
            );
        }

        $logger = self::logger($container);
        $connection = new ConnectionConfig($connectionName, $connectionDetails, new CacheFactory($container));

        $instance = match ($provider) {
            'unleash' => self::unleash($connection, $logger),
            'flagsmith' => self::flagsmith($connection, $logger),
            'growthbook' => self::growthbook($connection, $logger),
        };

        return new FeatureFlagsClient($instance, (bool) ($connectionDetails['strict'] ?? false), $logger);
    }

    protected static function unleash(ConnectionConfig $connection, ?LoggerInterface $logger): Provider
    {
        $url = $connection->string('url');
        $bootstrap = $connection->bootstrapFile();
        if ($url === null && $bootstrap === null) {
            throw $connection->invalid('needs a url (the Unleash API, such as http://unleash:4242/api) or a bootstrap_file.');
        }

        $features = $connection->cache('features');
        $builder = UnleashBuilder::create()
            ->withAppName($connection->string('app_name') ?? 'kipchak')
            ->withInstanceId($connection->string('instance_id') ?? (gethostname() ?: 'kipchak'))
            ->withHttpClient(self::httpClient($connection))
            ->withRequestFactory(new HttpFactory())
            ->withCacheHandler($features, $connection->refreshInterval())
            ->withStaleCacheHandler($connection->cache('stale'))
            ->withStaleTtl((int) ($connection->details['stale_ttl'] ?? 86400))
            ->withMetricsCacheHandler($connection->cache('metrics'));

        if ($bootstrap !== null) {
            $builder = $builder->withBootstrapFile($bootstrap);
        }

        if ($url === null) {
            $builder = $builder
                ->withFetchingEnabled(false)
                ->withMetricsEnabled(false)
                ->withAutomaticRegistrationEnabled(false);
        } else {
            $builder = $builder
                ->withAppUrl($url)
                ->withMetricsEnabled((bool) ($connection->details['metrics'] ?? true))
                ->withMetricsInterval(1000 * (int) ($connection->details['metrics_interval'] ?? 60))
                ->withAutomaticRegistrationEnabled((bool) ($connection->details['register'] ?? true));
            if ($connection->string('token') !== null) {
                $builder = $builder->withHeader('Authorization', $connection->string('token'));
            }
        }

        return new UnleashProvider($builder, $features, $connection->string('environment'), $logger);
    }

    protected static function flagsmith(ConnectionConfig $connection, ?LoggerInterface $logger): Provider
    {
        $key = $connection->string('environment_key');
        $bootstrap = $connection->bootstrapFile();
        if ($key === null && $bootstrap === null) {
            throw $connection->invalid('needs an environment_key or a bootstrap_file (an environment document).');
        }

        $localEvaluation = $key !== null && (bool) ($connection->details['local_evaluation'] ?? str_starts_with($key, 'ser.'));
        if ($localEvaluation && !str_starts_with($key, 'ser.')) {
            throw $connection->invalid('local_evaluation needs a server-side environment key, which starts with "ser.".');
        }

        $flagsmith = new Flagsmith(
            apiKey: $key,
            host: $connection->string('url'),
            environmentTtl: $localEvaluation ? $connection->refreshInterval() : null,
            // One attempt: a flag check should fail fast, and the SDK's retry loop throws on the first failure anyway.
            retries: new Retry(1),
            enableAnalytics: $key !== null && (bool) ($connection->details['analytics'] ?? false),
            offlineMode: $key === null,
            offlineHandler: $bootstrap === null ? null : new FlagsmithDocumentHandler($bootstrap),
        );

        $cache = null;
        if ($key !== null) {
            $factory = new HttpFactory();
            $cache = $connection->cache('flagsmith');
            $flagsmith = $flagsmith
                ->withClient(self::httpClient($connection))
                ->withRequestFactory($factory)
                ->withStreamFactory($factory)
                ->withCachePrefix('flagsmith')
                ->withTimeToLive($connection->refreshInterval())
                ->withCache($cache);
        }

        return new FlagsmithProvider(
            $flagsmith,
            $localEvaluation ? new RefreshSchedule($connection->refreshInterval()) : null,
            $cache,
            $logger,
        );
    }

    protected static function growthbook(ConnectionConfig $connection, ?LoggerInterface $logger): Provider
    {
        $clientKey = $connection->string('client_key');
        $bootstrap = $connection->bootstrapFile();
        if ($clientKey === null && $bootstrap === null) {
            throw $connection->invalid('needs a client_key (an SDK connection key, sdk-...) or a bootstrap_file.');
        }

        $options = ['url' => '', 'enabled' => true];
        if ($bootstrap !== null) {
            $document = json_decode((string) file_get_contents($bootstrap), true);
            if (!is_array($document) || !is_array($document['features'] ?? null)) {
                throw $connection->invalid('bootstrap_file "' . $bootstrap . '" is not a GrowthBook features document ({"features": {...}}).');
            }
            $options['features'] = $document['features'];
            $options['savedGroups'] = is_array($document['savedGroups'] ?? null) ? $document['savedGroups'] : [];
        }

        $cache = null;
        if ($clientKey !== null) {
            $cache = $connection->cache('growthbook');
            $options['httpClient'] = self::httpClient($connection);
            $options['requestFactory'] = new HttpFactory();
        }

        $growthbook = new Growthbook($options);
        if ($cache !== null) {
            $growthbook->setCache($cache, $connection->refreshInterval());
        }

        return new GrowthBookProvider(
            $growthbook,
            $clientKey,
            $connection->string('url') ?? '',
            $connection->string('decryption_key') ?? '',
            $clientKey === null ? null : new RefreshSchedule($connection->refreshInterval()),
            $cache,
            $logger,
        );
    }

    /**
     * The PSR-18 client for a connection: the psr18 client named by its 'http_client' setting in kipchak.http, or
     * a Guzzle client of its own with short timeouts, because flags are fetched on the request path.
     */
    protected static function httpClient(ConnectionConfig $connection): ClientInterface
    {
        $name = $connection->string('http_client');
        if ($name !== null) {
            return Http::psr18($name);
        }

        return new GuzzleClient([
            'timeout' => (float) ($connection->details['timeout'] ?? 2.0),
            'connect_timeout' => (float) ($connection->details['connect_timeout'] ?? 1.0),
        ]);
    }

    protected static function logger(ContainerInterface $container): ?LoggerInterface
    {
        if (!$container->has('drivers.logger')) {
            return null;
        }
        $logger = $container->get('drivers.logger');

        return $logger instanceof LoggerInterface ? $logger : null;
    }
}
