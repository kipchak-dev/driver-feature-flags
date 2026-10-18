<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Fake;

use DI\Container;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\FeatureFlags;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\Http\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Builds connections through the real driver, with HTTP answered by a Guzzle mock: give a connection
 * 'http_client' => 'mock' and queue responses with respond().
 */
trait BuildsConnections
{
    protected const string FIXTURES = __DIR__ . '/../fixtures';

    protected RecordingLogger $logger;
    protected MockHandler $responses;
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    /**
     * @param array<string, mixed> $connection
     */
    protected function connect(array $connection, string $name = 'default'): FeatureFlagsClient
    {
        $this->logger ??= new RecordingLogger();
        $this->responses ??= new MockHandler();

        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', [
            'kipchak.feature-flags' => ['connections' => [$name => $connection]],
            'kipchak.http' => ['mock' => ['type' => 'psr18', 'options' => ['handler' => $stack]]],
        ]);
        $container->set('drivers.logger', $this->logger);
        Http::initialise($container);
        FeatureFlags::initialise($container);

        return FeatureFlags::get($name);
    }

    protected function respond(int $status, array|string $body = '', array $headers = []): void
    {
        $this->responses ??= new MockHandler();
        $this->responses->append(new Response(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
        ));
    }

    protected function respondWithFixture(string $file): void
    {
        $this->respond(200, (string) file_get_contents(self::FIXTURES . '/' . $file));
    }

    protected function refuseConnection(): void
    {
        $this->responses ??= new MockHandler();
        // Queued as a rejection, so the history middleware records the attempt.
        $this->responses->append(new \GuzzleHttp\Exception\ConnectException('Connection refused', new \GuzzleHttp\Psr7\Request('GET', '/')));
    }

    /**
     * @return list<string> "METHOD path" of every request sent
     */
    protected function requests(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(),
            $this->history,
        );
    }

    protected function fixture(string $name, array|string $contents): string
    {
        $file = sys_get_temp_dir() . '/kipchak-flags-' . getmypid() . '-' . $name;
        file_put_contents($file, is_array($contents) ? json_encode($contents, JSON_THROW_ON_ERROR) : $contents);

        return $file;
    }
}
