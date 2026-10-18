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
 * The fixture is a real environment document from the Flagsmith server in tests/Integration: new-checkout is off
 * with value "blue" and on for the "pro" segment (trait plan = pro), rate-limit is on with value 100, and
 * kill-switch is off with no value.
 */
final class FlagsmithProviderTest extends TestCase
{
    use BuildsConnections;

    private function local(array $settings = []): FeatureFlagsClient
    {
        return $this->connect($settings + [
            'provider' => 'flagsmith',
            'url' => 'http://flagsmith.test/api/v1',
            'environment_key' => 'ser.secret',
            'http_client' => 'mock',
        ]);
    }

    private function remote(array $settings = []): FeatureFlagsClient
    {
        return $this->local($settings + ['environment_key' => 'client-key']);
    }

    private function bootstrapped(): FeatureFlagsClient
    {
        return $this->connect(['provider' => 'flagsmith', 'bootstrap_file' => self::FIXTURES . '/flagsmith-environment.json']);
    }

    /**
     * The API's answer for an identity in the "pro" segment.
     */
    private function identityResponse(bool $pro): array
    {
        return ['flags' => [
            ['feature' => ['id' => 1, 'name' => 'new-checkout', 'type' => 'STANDARD'], 'enabled' => $pro, 'feature_state_value' => 'blue'],
            ['feature' => ['id' => 3, 'name' => 'rate-limit', 'type' => 'STANDARD'], 'enabled' => true, 'feature_state_value' => 100],
        ], 'traits' => []];
    }

    public function testEvaluatesSegmentsFromTheEnvironmentDocument(): void
    {
        $flags = $this->bootstrapped();
        $pro = new Context('user-1', ['plan' => 'pro']);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $this->assertFalse($flags->isEnabled('new-checkout', new Context('user-2', ['plan' => 'free'])));
        $this->assertFalse($flags->isEnabled('new-checkout'), 'Without an identity, the environment flag');
        $this->assertFalse($flags->isEnabled('kill-switch', $pro, default: true));
        $this->assertEquals(new Flag('new-checkout', true, 'blue'), $flags->evaluate('new-checkout', $pro));
        $this->assertSame(100, $flags->value('rate-limit', 10));
        $this->assertSame('blue', $flags->value('new-checkout', 'none'), 'Flagsmith keeps the value of a flag that is off');
        $this->assertSame('none', $flags->value('kill-switch', 'none'));
        $this->assertNull($flags->variant('new-checkout', $pro));
        $this->assertSame('flagsmith', $flags->provider());
    }

    public function testUnknownFlags(): void
    {
        $flags = $this->bootstrapped();

        $this->assertTrue($flags->isEnabled('missing', default: true));
        $this->assertTrue($flags->isEnabled('missing', new Context('user-1'), default: true));
        $this->assertSame(Flag::SOURCE_UNKNOWN, $flags->evaluate('missing')->source);
    }

    public function testLocalEvaluationDownloadsTheDocumentOncePerInterval(): void
    {
        $this->respondWithFixture('flagsmith-environment.json');
        $flags = $this->local();

        $this->assertTrue($flags->isEnabled('new-checkout', new Context('user-1', ['plan' => 'pro'])));
        $this->assertFalse($flags->isEnabled('new-checkout', new Context('user-2')));
        $this->assertSame(100, $flags->value('rate-limit'));

        $this->assertSame(['GET /api/v1/environment-document/'], $this->requests());
        $this->assertSame('ser.secret', $this->history[0]['request']->getHeaderLine('X-Environment-Key'));
    }

    public function testLocalEvaluationKeepsTheLastDocumentThroughAnOutage(): void
    {
        $this->respondWithFixture('flagsmith-environment.json');
        $this->refuseConnection();
        $flags = $this->local();
        $pro = new Context('user-1', ['plan' => 'pro']);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $flags->refresh();

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $this->assertCount(2, $this->requests());
        $this->assertStringStartsWith(
            'Feature flags (flagsmith): could not refresh the environment document, using the last one loaded: ',
            $this->logger->messages()[0],
        );
    }

    public function testLocalEvaluationWithoutADocumentReturnsDefaults(): void
    {
        $this->refuseConnection();
        $flags = $this->local();

        $this->assertTrue($flags->isEnabled('new-checkout', default: true));
        $this->assertSame(Flag::SOURCE_ERROR, $flags->evaluate('rate-limit')->source);
        $this->assertCount(1, $this->requests(), 'Neither retried at once nor sent to the remote API');
        $this->assertStringStartsWith('Feature flags (flagsmith): could not evaluate "new-checkout"', $this->logger->messages()[0]);
    }

    public function testLocalEvaluationStartsFromTheBootstrapFile(): void
    {
        $this->refuseConnection();
        $flags = $this->local(['bootstrap_file' => self::FIXTURES . '/flagsmith-environment.json']);

        $this->assertTrue($flags->isEnabled('new-checkout', new Context('user-1', ['plan' => 'pro'])));
    }

    public function testRemoteEvaluationSendsTheIdentityAndCachesTheAnswer(): void
    {
        $this->respond(200, $this->identityResponse(pro: true));
        $flags = $this->remote();
        $pro = new Context('user-1', ['plan' => 'pro', 'beta' => true, 'tags' => ['a', 'b'], 'gone' => null]);

        $this->assertTrue($flags->isEnabled('new-checkout', $pro));
        $this->assertSame(100, $flags->value('rate-limit', 10, $pro), 'Answered from the cached response');

        $this->assertSame(['POST /api/v1/identities/'], $this->requests());
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('user-1', $body['identifier']);
        $this->assertEqualsCanonicalizing([
            ['trait_key' => 'plan', 'trait_value' => 'pro'],
            ['trait_key' => 'beta', 'trait_value' => true],
            ['trait_key' => 'tags', 'trait_value' => 'a,b'],
        ], $body['traits'], 'A null attribute would delete the stored trait, so it is not sent');
    }

    public function testRemoteEvaluationWithoutAnIdentityAsksForTheEnvironmentFlags(): void
    {
        $this->respond(200, $this->identityResponse(pro: false)['flags']);

        $this->assertFalse($this->remote()->isEnabled('new-checkout', default: true));
        $this->assertSame(['GET /api/v1/flags/'], $this->requests());
    }

    public function testRemoteEvaluationFailureReturnsDefaults(): void
    {
        $this->refuseConnection();

        $flags = $this->remote();

        $this->assertTrue($flags->isEnabled('new-checkout', new Context('user-1'), default: true));
        $this->assertSame(Flag::SOURCE_ERROR, $flags->evaluate('rate-limit', new Context('user-2'))->source);
        $this->assertCount(1, $this->requests(), 'Not retried, and the next checks fail fast without calling');
    }

    public function testRemoteEvaluationFallsBackToTheBootstrapFile(): void
    {
        $this->refuseConnection();
        $flags = $this->remote(['bootstrap_file' => self::FIXTURES . '/flagsmith-environment.json']);

        $this->assertTrue($flags->isEnabled('new-checkout', new Context('user-1', ['plan' => 'pro'])));
    }

    public function testLocalEvaluationNeedsAServerKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Feature flag connection "default" local_evaluation needs a server-side environment key, which starts with "ser.".');

        $this->remote(['local_evaluation' => true]);
    }

    public function testAServerKeyCanStillEvaluateRemotely(): void
    {
        $this->respond(200, $this->identityResponse(pro: true));

        $this->assertTrue($this->local(['local_evaluation' => false])->isEnabled('new-checkout', new Context('user-1')));
        $this->assertSame(['POST /api/v1/identities/'], $this->requests());
    }
}
