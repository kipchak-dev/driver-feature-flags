<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Library;

use DI\Container;
use InvalidArgumentException;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\Library\CacheFactory;
use Kipchak\Driver\Filecache\Filecache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class CacheFactoryTest extends TestCase
{
    private Container $container;
    private CacheFactory $factory;

    protected function setUp(): void
    {
        $this->container = self::containerWithFilecache();
        $this->factory = new CacheFactory($this->container);
    }

    private static function containerWithFilecache(): Container
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.api' => ['name' => 'kipchak-flags-tests']]);
        Filecache::initialise($container);

        return $container;
    }

    public function testMemoryCachesAreSeparateAndKeepObjectsAsTheyAre(): void
    {
        $features = $this->factory->create('memory', 'default', 'features');
        $stale = $this->factory->create('memory', 'default', 'stale');
        $object = new \ArrayObject(['a' => 1]);

        $features->set('key', $object);

        $this->assertSame($object, $features->get('key'), 'Not serialised on every read');
        $this->assertNull($stale->get('key'));
    }

    public function testFileUsesTheFilecacheDriverSharedBetweenProcesses(): void
    {
        $first = $this->factory->create('file', 'file-test-' . getmypid(), 'features');
        $second = (new CacheFactory(self::containerWithFilecache()))->create('file', 'file-test-' . getmypid(), 'features');
        $other = $this->factory->create('file', 'file-test-' . getmypid() . '-other', 'features');

        $first->set('key', 'value');

        try {
            $this->assertSame('value', $second->get('key'), 'Another process sees it');
            $this->assertNull($other->get('key'), 'Another connection does not');
        } finally {
            $first->clear();
        }
    }

    public function testASharedPsr6CacheIsNamespacedPerConnection(): void
    {
        $pool = new FilesystemAdapter('shared-' . getmypid());
        $this->container->set('drivers.cache.shared', $pool);

        $one = $this->factory->create('drivers.cache.shared', 'one', 'features');
        $two = $this->factory->create('drivers.cache.shared', 'two', 'features');

        try {
            $one->set('unleash.client.feature.list', 'one');
            $two->set('unleash.client.feature.list', 'two');

            $this->assertSame('one', $one->get('unleash.client.feature.list'));
            $this->assertSame('two', $two->get('unleash.client.feature.list'));

            $one->clear();
            $this->assertNull($one->get('unleash.client.feature.list'));
            $this->assertSame('two', $two->get('unleash.client.feature.list'), 'Clearing one connection leaves the other');
        } finally {
            $pool->clear();
        }
    }

    public function testAPsr6CacheWithoutNamespacesIsProxied(): void
    {
        $pool = new class (new ArrayAdapter()) implements \Psr\Cache\CacheItemPoolInterface {
            public function __construct(private readonly ArrayAdapter $inner)
            {
            }

            public function getItem(string $key): \Psr\Cache\CacheItemInterface
            {
                return $this->inner->getItem($key);
            }

            public function getItems(array $keys = []): iterable
            {
                return $this->inner->getItems($keys);
            }

            public function hasItem(string $key): bool
            {
                return $this->inner->hasItem($key);
            }

            public function clear(): bool
            {
                return $this->inner->clear();
            }

            public function deleteItem(string $key): bool
            {
                return $this->inner->deleteItem($key);
            }

            public function deleteItems(array $keys): bool
            {
                return $this->inner->deleteItems($keys);
            }

            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return $this->inner->save($item);
            }

            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return $this->inner->saveDeferred($item);
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }
        };
        $this->container->set('plain', $pool);

        $one = $this->factory->create('plain', 'one', 'features');
        $two = $this->factory->create('plain', 'two', 'features');
        $one->set('key', 'one');

        $this->assertSame('one', $one->get('key'));
        $this->assertNull($two->get('key'));
    }

    public function testASharedPsr16CacheIsNamespacedPerConnection(): void
    {
        $this->container->set('simple', new Psr16Cache(new ArrayAdapter()));

        $one = $this->factory->create('simple', 'one', 'features');
        $two = $this->factory->create('simple', 'one', 'stale');
        $one->set('key', 'one');

        $this->assertSame('one', $one->get('key'));
        $this->assertNull($two->get('key'));
    }

    public function testConnectionNamesWithReservedCharactersAreAccepted(): void
    {
        $cache = $this->factory->create('file', 'team/{a}:b@c', 'features');
        $cache->set('key', 'value');

        $this->assertSame('value', $cache->get('key'));
        $cache->clear();
    }

    public function testFileNeedsTheFilecacheDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feature flag connection "default": cache "file" uses the Filecache driver. Initialise Kipchak\\Driver\\Filecache\\Filecache before FeatureFlags');

        (new CacheFactory(new Container()))->create('file', 'default', 'features');
    }

    public function testAMissingServiceIsExplained(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feature flag connection "default": cache "drivers.cache.valkey.cache" is not "memory", "file" or a container entry.');

        $this->factory->create('drivers.cache.valkey.cache', 'default', 'features');
    }

    public function testAServiceThatIsNotACacheIsRejected(): void
    {
        $this->container->set('not-a-cache', new \stdClass());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cache "not-a-cache" is a stdClass, not a PSR-6 or PSR-16 cache.');

        $this->factory->create('not-a-cache', 'default', 'features');
    }
}
