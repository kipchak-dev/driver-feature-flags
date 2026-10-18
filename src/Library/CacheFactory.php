<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library;

use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Builds the PSR-16 caches the SDKs keep their flag definitions in, from a connection's 'cache' setting:
 *
 *  - "memory" (the default): in the worker's memory. Each FrankenPHP worker fetches the flags once per refresh
 *    interval. Outside worker mode the cache only lasts one request, so the flags are fetched on every request.
 *  - "file": Kipchak's Filecache driver, shared by every PHP process on the machine.
 *  - anything else: a container entry holding a PSR-6 or PSR-16 cache, such as "drivers.cache.valkey.cache" from
 *    the Valkey driver, shared by every machine using it.
 *
 * The SDKs take PSR-16 caches, and Kipchak's cache drivers are PSR-6 pools, so pools are bridged with Symfony's
 * Psr16Cache (symfony/cache comes with the Filecache driver). Every cache is namespaced by connection and purpose,
 * because the SDKs use fixed cache keys.
 */
final class CacheFactory
{
    private const int MEMORY_ITEMS = 10000;

    /** The container key of Kipchak's Filecache driver. */
    private const string FILECACHE = 'drivers.cache.file';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function create(string $setting, string $connectionName, string $purpose): CacheInterface
    {
        $namespace = self::namespace($connectionName, $purpose);

        return match ($setting) {
            // Storing values as they are (no deep clone) spares copying the flag definitions on every evaluation;
            // the cap bounds a remote-evaluation cache, which holds one answer per user.
            'memory' => new Psr16Cache(new ArrayAdapter(deepClone: false, maxItems: self::MEMORY_ITEMS)),
            'file' => $this->shared(self::FILECACHE, $namespace, $connectionName),
            default => $this->shared($setting, $namespace, $connectionName),
        };
    }

    private function shared(string $service, string $namespace, string $connectionName): CacheInterface
    {
        if ($service === self::FILECACHE && !$this->container->has($service)) {
            throw new InvalidArgumentException(
                'Feature flag connection "' . $connectionName . '": cache "file" uses the Filecache driver. '
                . 'Initialise Kipchak\\Driver\\Filecache\\Filecache before FeatureFlags in drivers/drivers.php.'
            );
        }
        if (!$this->container->has($service)) {
            throw new InvalidArgumentException(
                'Feature flag connection "' . $connectionName . '": cache "' . $service . '" is not "memory", "file" '
                . 'or a container entry. Is the driver that provides it initialised before FeatureFlags?'
            );
        }

        $cache = $this->container->get($service);

        $pool = match (true) {
            $cache instanceof NamespacedPoolInterface && $cache instanceof CacheItemPoolInterface => $cache->withSubNamespace($namespace),
            $cache instanceof CacheItemPoolInterface => new ProxyAdapter($cache, $namespace),
            $cache instanceof CacheInterface => new Psr16Adapter($cache, $namespace),
            default => throw new InvalidArgumentException(
                'Feature flag connection "' . $connectionName . '": cache "' . $service . '" is a '
                . get_debug_type($cache) . ', not a PSR-6 or PSR-16 cache.'
            ),
        };

        return new Psr16Cache($pool);
    }

    /**
     * A namespace made of characters every Symfony adapter accepts.
     */
    private static function namespace(string $connectionName, string $purpose): string
    {
        return 'kipchak-flags-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $connectionName) . '-' . $purpose;
    }
}
