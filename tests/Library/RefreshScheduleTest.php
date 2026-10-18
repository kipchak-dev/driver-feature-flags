<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Tests\Library;

use Kipchak\Driver\FeatureFlags\Library\RefreshSchedule;
use PHPUnit\Framework\TestCase;

final class RefreshScheduleTest extends TestCase
{
    private float $now = 1000.0;

    private function schedule(int $interval): RefreshSchedule
    {
        return new RefreshSchedule($interval, fn (): float => $this->now);
    }

    public function testDueAtFirstThenEveryInterval(): void
    {
        $schedule = $this->schedule(30);
        $this->assertTrue($schedule->isDue());
        $this->assertFalse($schedule->hasLoaded());

        $schedule->succeeded();
        $this->assertTrue($schedule->hasLoaded());
        $this->now += 29.9;
        $this->assertFalse($schedule->isDue());
        $this->now += 0.1;
        $this->assertTrue($schedule->isDue());
    }

    public function testAFailureIsRetriedWithinSeconds(): void
    {
        $schedule = $this->schedule(300);
        $schedule->failed();

        $this->assertFalse($schedule->hasLoaded());
        $this->now += 4.9;
        $this->assertFalse($schedule->isDue());
        $this->now += 0.1;
        $this->assertTrue($schedule->isDue());
    }

    public function testAShortIntervalIsNotLengthenedByAFailure(): void
    {
        $schedule = $this->schedule(2);
        $schedule->failed();

        $this->now += 2;
        $this->assertTrue($schedule->isDue());
    }

    public function testRepeatedFailuresWaitLonger(): void
    {
        $schedule = $this->schedule(300);
        $waits = [];
        foreach (range(1, 6) as $failure) {
            $start = $this->now;
            $schedule->failed();
            while (!$schedule->isDue()) {
                $this->now += 1;
            }
            $waits[] = $this->now - $start;
        }

        $this->assertSame([5.0, 10.0, 20.0, 40.0, 60.0, 60.0], $waits);

        $schedule->succeeded();
        $this->now += 300;
        $schedule->failed();
        $this->now += 5;
        $this->assertTrue($schedule->isDue(), 'A success starts the waits again from five seconds');
    }

    public function testExpire(): void
    {
        $schedule = $this->schedule(30);
        $schedule->succeeded();
        $schedule->expire();

        $this->assertTrue($schedule->isDue());
        $this->assertTrue($schedule->hasLoaded());
    }
}
