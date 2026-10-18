<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Provider;

use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Kipchak\Driver\FeatureFlags\Tests\Fake\BuildsConnections;
use PHPUnit\Framework\TestCase;

/**
 * The fixture is a real /api/client/features response from the Unleash server in tests/Integration: new-checkout
 * is on for plan "pro" with variant "blue" (JSON payload), rate-limit has variant "limit" (number payload 100),
 * and kill-switch is off.
 */
final class UnleashProviderTest extends TestCase
{
    use BuildsConnections;

    private function online(array $settings = []): \Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient
    {
        return $this->connect($settings + [
            'provider' => 'unleash',
            'url' => 'http://unleash.test/api',
            'token' => 'default:development.secret',
            'app_name' => 'tests',
            'http_client' => 'mock',
            'metrics' => false,
            'register' => false,
        ]);
    }

    private function bootstrapped(string $file = self::FIXTURES . '/unleash-features.json'): \Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient
    {
        return $this->connect(['provider' => 'unleash', 'bootstrap_file' => $file]);
    }

    public function testEvaluatesStrategiesConstraintsAndVariants(): void
    {
        $flags = $this->bootstrapped();
        $pro = new Context('user-1', ['plan' => 'pro']);
        $free = new Context('user-2', ['plan' => 'free']);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $this->assertFalse($flags->isEnabled('new-checkout', $free));
        $this->assertFalse($flags->isEnabled('new-checkout'));
        $this->assertFalse($flags->isEnabled('kill-switch', $pro, default: true));

        $this->assertEquals(new Flag('new-checkout', true, ['colour' => 'blue'], 'blue'), $flags->evaluate('new-checkout', $pro));
        $this->assertEquals(new Flag('new-checkout', false), $flags->evaluate('new-checkout', $free));
        $this->assertSame(['colour' => 'blue'], $flags->value('new-checkout', null, $pro));
        $this->assertSame('none', $flags->value('new-checkout', 'none', $free));
        $this->assertSame(100, $flags->value('rate-limit', 10), 'A number payload is a number');
        $this->assertSame('limit', $flags->variant('rate-limit'));
        $this->assertSame('unleash', $flags->provider());
    }

    public function testUnknownToggles(): void
    {
        $flags = $this->bootstrapped();

        $this->assertTrue($flags->isEnabled('missing', default: true));
        $this->assertFalse($flags->isEnabled('missing'));
        $this->assertSame(Flag::SOURCE_UNKNOWN, $flags->evaluate('missing')->source);
        $this->assertSame(Flag::SOURCE_PROVIDER, $flags->evaluate('kill-switch')->source, 'Known but off');
        $this->assertSame(5, $flags->value('missing', 5));
    }

    public function testTheContextReachesUnleashAsStrings(): void
    {
        $constrained = static fn (string $name, string $field, array $values): array => [
            'name' => $name,
            'enabled' => true,
            'strategies' => [[
                'name' => 'default',
                'constraints' => [['contextName' => $field, 'operator' => 'IN', 'values' => $values]],
            ]],
        ];
        $flags = $this->bootstrapped($this->fixture('unleash-context.json', ['version' => 2, 'features' => [
            $constrained('beta', 'beta', ['true']),
            $constrained('tiers', 'tiers', ['gold,silver']),
            $constrained('big-orders', 'orders', ['42']),
            $constrained('by-user', 'userId', ['user-1']),
            $constrained('by-session', 'sessionId', ['session-1']),
            $constrained('by-ip', 'remoteAddress', ['10.0.0.1']),
        ]]));

        $this->assertTrue($flags->isEnabled('beta', new Context(attributes: ['beta' => true])));
        $this->assertFalse($flags->isEnabled('beta', new Context(attributes: ['beta' => false])));
        $this->assertTrue($flags->isEnabled('tiers', new Context(attributes: ['tiers' => ['gold', 'silver']])));
        $this->assertTrue($flags->isEnabled('big-orders', new Context(attributes: ['orders' => 42])));
        $this->assertTrue($flags->isEnabled('by-user', new Context('user-1')));
        $this->assertFalse($flags->isEnabled('by-user', new Context('user-2')));
        $this->assertTrue($flags->isEnabled('by-session', new Context(sessionId: 'session-1')));
        $this->assertTrue($flags->isEnabled('by-ip', new Context(ipAddress: '10.0.0.1')));
    }

    public function testTheEnvironmentSettingFillsTheContextField(): void
    {
        $file = $this->fixture('unleash-environment.json', ['version' => 2, 'features' => [[
            'name' => 'production-only',
            'enabled' => true,
            'strategies' => [['name' => 'default', 'constraints' => [
                ['contextName' => 'environment', 'operator' => 'IN', 'values' => ['production']],
            ]]],
        ]]]);

        $this->assertTrue($this->connect(['provider' => 'unleash', 'bootstrap_file' => $file, 'environment' => 'production'])->isEnabled('production-only'));
        $this->assertFalse($this->connect(['provider' => 'unleash', 'bootstrap_file' => $file], 'other')->isEnabled('production-only'));
    }

    public function testFetchesOncePerRefreshIntervalWithTheToken(): void
    {
        $this->respondWithFixture('unleash-features.json');
        $flags = $this->online();

        $this->assertTrue($flags->isEnabled('new-checkout', new Context(attributes: ['plan' => 'pro'])));
        $this->assertTrue($flags->isEnabled('rate-limit'));
        $this->assertSame(100, $flags->value('rate-limit'));

        $this->assertSame(['GET /api/client/features'], $this->requests());
        $request = $this->history[0]['request'];
        $this->assertSame('default:development.secret', $request->getHeaderLine('Authorization'));
        $this->assertSame('tests', $request->getHeaderLine('UNLEASH-APPNAME'));
        $this->assertSame('30000', $request->getHeaderLine('Unleash-Interval'));
    }

    public function testRefreshFetchesAgainAndAnOutageAnswersFromTheStaleCopy(): void
    {
        $this->respondWithFixture('unleash-features.json');
        $this->refuseConnection();
        $flags = $this->online();
        $pro = new Context(attributes: ['plan' => 'pro']);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $flags->refresh();

        $this->assertTrue($flags->isEnabled('new-checkout', $pro), 'Still answered while Unleash is down');
        $this->assertSame(Flag::SOURCE_PROVIDER, $flags->evaluate('new-checkout', $pro)->source);
        $this->assertSame(['GET /api/client/features', 'GET /api/client/features'], $this->requests());
        $this->assertStringContainsString('could not fetch the toggles: Connection refused', $this->logger->messages()[0]);
    }

    public function testAnOutageBeforeAnyFetchReturnsDefaults(): void
    {
        $this->refuseConnection();
        $flags = $this->online();

        $this->assertTrue($flags->isEnabled('new-checkout', default: true));
        $this->assertSame(Flag::SOURCE_ERROR, $flags->evaluate('rate-limit')->source);
        $this->assertFalse($flags->isEnabled('rate-limit'));
        $this->assertCount(1, $this->requests(), 'Not every check tries to connect while Unleash is down');
        $this->assertContains('Feature flags (unleash): could not evaluate "new-checkout": Got invalid response code when getting features and no default bootstrap provided: unknown response status code', $this->logger->messages());
    }

    public function testAnOutageBeforeAnyFetchUsesTheBootstrapFile(): void
    {
        $this->refuseConnection();
        $flags = $this->online(['bootstrap_file' => self::FIXTURES . '/unleash-features.json']);

        $this->assertTrue($flags->isEnabled('new-checkout', new Context(attributes: ['plan' => 'pro'])));
    }

    public function testRegistersWithUnleashWhenAskedTo(): void
    {
        $this->respond(202);
        $this->respondWithFixture('unleash-features.json');

        $this->online(['register' => true])->isEnabled('new-checkout');

        $this->assertSame(['POST /api/client/register', 'GET /api/client/features'], $this->requests());
        $payload = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('tests', $payload['appName']);
        $this->assertSame(gethostname(), $payload['instanceId']);
    }
}
