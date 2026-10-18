<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Library;

use Kipchak\Driver\FeatureFlags\Library\FailureBackoff;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FailureBackoffTest extends TestCase
{
    private float $now = 1000.0;
    private int $calls = 0;
    private bool $down = true;

    private function backoff(): FailureBackoff
    {
        return new FailureBackoff(fn (): float => $this->now);
    }

    private function attempt(FailureBackoff $backoff): string
    {
        try {
            return $backoff->call(function (): string {
                $this->calls++;
                if ($this->down) {
                    throw new RuntimeException('down #' . $this->calls);
                }

                return 'ok';
            });
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function testFailsFastWithTheLastFailureBetweenAttempts(): void
    {
        $backoff = $this->backoff();

        $this->assertSame('down #1', $this->attempt($backoff));
        $this->now += 4.9;
        $this->assertSame('down #1', $this->attempt($backoff), 'Not called again yet');
        $this->assertSame(1, $this->calls);

        $this->now += 0.1;
        $this->assertSame('down #2', $this->attempt($backoff));
    }

    public function testTheWaitDoublesUpToAMinute(): void
    {
        $backoff = $this->backoff();
        $this->attempt($backoff);

        $waits = [];
        for ($i = 0; $i < 6; $i++) {
            $start = $this->now;
            $calls = $this->calls;
            while ($this->calls === $calls) {
                $this->now += 1;
                $this->attempt($backoff);
            }
            $waits[] = $this->now - $start;
        }

        $this->assertSame([5.0, 10.0, 20.0, 40.0, 60.0, 60.0], $waits);
    }

    public function testASuccessResetsTheWait(): void
    {
        $backoff = $this->backoff();
        $this->attempt($backoff);
        $this->now += 5;
        $this->attempt($backoff);
        $this->now += 10;
        $this->down = false;

        $this->assertSame('ok', $this->attempt($backoff));
        $this->down = true;
        $this->attempt($backoff);
        $this->now += 5;
        $this->assertSame('down #5', $this->attempt($backoff), 'Back to five seconds');
    }

    public function testResetLetsTheNextCallThrough(): void
    {
        $backoff = $this->backoff();
        $this->attempt($backoff);
        $backoff->reset();

        $this->assertSame('down #2', $this->attempt($backoff));
    }

    public function testDelay(): void
    {
        $this->assertSame(5.0, FailureBackoff::delay(1, 5.0));
        $this->assertSame(10.0, FailureBackoff::delay(2, 5.0));
        $this->assertSame(40.0, FailureBackoff::delay(4, 5.0));
        $this->assertSame(60.0, FailureBackoff::delay(5, 5.0));
        $this->assertSame(60.0, FailureBackoff::delay(50, 5.0));
        $this->assertSame(2.0, FailureBackoff::delay(1, 2.0));
    }
}
