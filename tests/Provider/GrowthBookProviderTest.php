<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Provider;

use InvalidArgumentException;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Kipchak\Driver\FeatureFlags\Tests\Fake\BuildsConnections;
use PHPUnit\Framework\TestCase;

/**
 * The fixture is a real SDK endpoint response from the GrowthBook server in tests/Integration: new-checkout is
 * null by default and {"colour": "blue"} for plan "pro", kill-switch is false, and rate-limit is 100.
 */
final class GrowthBookProviderTest extends TestCase
{
    use BuildsConnections;

    private function online(array $settings = []): FeatureFlagsClient
    {
        return $this->connect($settings + [
            'provider' => 'growthbook',
            'url' => 'http://growthbook.test',
            'client_key' => 'sdk-abc123',
            'http_client' => 'mock',
        ]);
    }

    private function bootstrapped(?string $file = null): FeatureFlagsClient
    {
        return $this->connect(['provider' => 'growthbook', 'bootstrap_file' => $file ?? self::FIXTURES . '/growthbook-features.json']);
    }

    private function experiment(): FeatureFlagsClient
    {
        return $this->bootstrapped($this->fixture('growthbook-experiment.json', ['features' => [
            'button-colour' => [
                'defaultValue' => 'grey',
                'rules' => [[
                    'key' => 'button-test',
                    'variations' => ['grey', 'green'],
                    'weights' => [0.5, 0.5],
                    'coverage' => 1,
                    'hashAttribute' => 'id',
                    'meta' => [['key' => 'control'], ['key' => 'treatment']],
                ]],
            ],
            'max-items' => ['defaultValue' => 0],
        ]]));
    }

    public function testEvaluatesRulesAgainstAttributes(): void
    {
        $flags = $this->bootstrapped();
        $pro = new Context('user-1', ['plan' => 'pro']);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $this->assertFalse($flags->isEnabled('new-checkout', new Context('user-2', ['plan' => 'free'])));
        $this->assertFalse($flags->isEnabled('kill-switch', $pro, default: true));
        $this->assertEquals(new Flag('new-checkout', true, ['colour' => 'blue']), $flags->evaluate('new-checkout', $pro));
        $this->assertSame('none', $flags->value('new-checkout', 'none'), 'A null value is no value');
        $this->assertSame(100, $flags->value('rate-limit', 10));
        $this->assertSame('growthbook', $flags->provider());
    }

    public function testUnknownFeatures(): void
    {
        $flags = $this->bootstrapped();

        $this->assertTrue($flags->isEnabled('missing', default: true));
        $this->assertSame(Flag::SOURCE_UNKNOWN, $flags->evaluate('missing')->source);
        $this->assertSame(5, $flags->value('missing', 5));
    }

    public function testAFalsyValueIsAValueOnAFeatureThatIsOff(): void
    {
        $flags = $this->experiment();

        $this->assertFalse($flags->isEnabled('max-items', default: true));
        $this->assertSame(0, $flags->value('max-items', 50));
    }

    public function testExperimentsNameTheVariationAndBucketByUserId(): void
    {
        $flags = $this->experiment();

        $seen = [];
        for ($i = 1; $i <= 40; $i++) {
            $result = $flags->evaluate('button-colour', new Context('user-' . $i));
            $seen[$result->variant] = $result->value;
            $this->assertSame($result->variant, $flags->variant('button-colour', new Context('user-' . $i)), 'The same user always gets the same variation');
        }

        ksort($seen);
        $this->assertSame(['control' => 'grey', 'treatment' => 'green'], $seen);
        $this->assertNull($flags->variant('button-colour'), 'No id, so not in the experiment');
        $this->assertSame('grey', $flags->value('button-colour'));
    }

    public function testStandardContextFieldsBecomeAttributes(): void
    {
        $flags = $this->bootstrapped($this->fixture('growthbook-attributes.json', ['features' => [
            'by-id' => ['defaultValue' => false, 'rules' => [['condition' => ['id' => 'user-1'], 'force' => true]]],
            'by-session' => ['defaultValue' => false, 'rules' => [['condition' => ['sessionId' => 's-1'], 'force' => true]]],
            'by-ip' => ['defaultValue' => false, 'rules' => [['condition' => ['ipAddress' => '10.0.0.1'], 'force' => true]]],
            'by-list' => ['defaultValue' => false, 'rules' => [['condition' => ['tags' => ['$elemMatch' => ['$eq' => 'beta']]], 'force' => true]]],
        ]]));

        $this->assertTrue($flags->isEnabled('by-id', new Context('user-1')));
        $this->assertTrue($flags->isEnabled('by-session', new Context(sessionId: 's-1')));
        $this->assertTrue($flags->isEnabled('by-ip', new Context(ipAddress: '10.0.0.1')));
        $this->assertTrue($flags->isEnabled('by-list', new Context(attributes: ['tags' => ['alpha', 'beta']])), 'GrowthBook takes lists as they are');
        $this->assertFalse($flags->isEnabled('by-id', new Context('user-1', ['id' => 'override'])), 'An explicit attribute wins');
    }

    public function testNothingAboutOneContextReachesTheNext(): void
    {
        $flags = $this->bootstrapped();

        $this->assertTrue($flags->isEnabled('new-checkout', new Context('user-1', ['plan' => 'pro'])));
        $this->assertFalse($flags->isEnabled('new-checkout'), 'The previous attributes are gone');
        $this->assertFalse($flags->isEnabled('new-checkout', new Context('user-1')));
    }

    public function testFetchesOncePerRefreshInterval(): void
    {
        $this->respondWithFixture('growthbook-features.json');
        $flags = $this->online();

        $this->assertTrue($flags->isEnabled('new-checkout', new Context(attributes: ['plan' => 'pro'])));
        $this->assertSame(100, $flags->value('rate-limit'));

        $this->assertSame(['GET /api/features/sdk-abc123'], $this->requests());
    }

    public function testRefreshFetchesAgainAndKeepsTheFeaturesThroughAnOutage(): void
    {
        $this->respondWithFixture('growthbook-features.json');
        $this->refuseConnection();
        $flags = $this->online();
        $pro = new Context(attributes: ['plan' => 'pro']);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $flags->refresh();

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $this->assertCount(2, $this->requests());
        $this->assertSame(['Feature flags (growthbook): Failed to fetch features from API: Connection refused'], $this->logger->messages('error'));
        $this->assertSame([], $this->logger->messages('info'), 'The SDK\'s informational messages are dropped');
    }

    public function testAnOutageBeforeAnyFetchReturnsDefaults(): void
    {
        $this->refuseConnection();
        $flags = $this->online();

        $this->assertTrue($flags->isEnabled('new-checkout', default: true));
        $this->assertSame(Flag::SOURCE_ERROR, $flags->evaluate('new-checkout')->source);
        $this->assertSame(
            'Feature flags (growthbook): could not evaluate "new-checkout": Could not load the features: Failed to fetch features from API: Connection refused',
            $this->logger->messages()[0],
        );
    }

    public function testAnOutageBeforeAnyFetchUsesTheBootstrapFile(): void
    {
        $this->refuseConnection();

        $this->assertTrue($this->online(['bootstrap_file' => self::FIXTURES . '/growthbook-features.json'])
            ->isEnabled('new-checkout', new Context(attributes: ['plan' => 'pro'])));
    }

    public function testAnUnreadableResponseIsAFailure(): void
    {
        $this->respond(200, 'not json');

        $this->assertTrue($this->online()->isEnabled('new-checkout', default: true));
        $this->assertStringContainsString('Could not load features', $this->logger->messages()[0]);
    }

    public function testABootstrapFileMustBeAFeaturesDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a GrowthBook features document');

        $this->bootstrapped($this->fixture('growthbook-wrong.json', ['flags' => []]));
    }
}
