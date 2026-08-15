<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
use DateTimeZone;
use Erpify\Shared\Event\Application\FailedMessagePruner;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneFailedMessagesHandler;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneFailedMessagesMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one step nothing else covers: turning a retention in days into the instant the pruner deletes before.
 *
 * It is three characters of string concatenation, and every way it can be wrong is silent. A `+` instead of a
 * `-` puts the threshold in the future and the first tick empties the whole failure queue; `hours` instead of
 * `days` shrinks the window by a factor of twenty-four. Neither touches the pruner's own tests — those build
 * their threshold themselves — nor the schedule's, which only reads the message's default. Without this test
 * the entire path from a configured window to a `DELETE` has no witness.
 *
 * @internal
 */
#[CoversClass(PruneFailedMessagesHandler::class)]
final class PruneFailedMessagesHandlerTest extends TestCase
{
    private const int TOLERANCE_SECONDS = 60;

    #[Test]
    public function itPrunesFailedMessagesOlderThanTheRetentionWindow(): void
    {
        $expected = new DateTimeImmutable('-7 days', new DateTimeZone('UTC'));

        $pruner = $this->createMock(FailedMessagePruner::class);
        $pruner->expects($this->once())
            ->method('pruneFailedBefore')
            ->with($this->callback(fn (mixed $threshold): bool => $this->isAbout($threshold, $expected)))
            ->willReturn(3)
        ;

        (new PruneFailedMessagesHandler($pruner))(new PruneFailedMessagesMessage(7));
    }

    #[Test]
    public function itBuildsTheThresholdInUtcWhateverZoneTheProcessRunsIn(): void
    {
        // `created_at` is a `timestamp WITHOUT time zone` written in UTC by the transport, and DBAL formats
        // whatever zone the threshold carries without converting it. A process running in another zone would
        // therefore shift the window by its offset, silently, with every other test still green.
        $captured = null;

        $pruner = $this->createMock(FailedMessagePruner::class);
        $pruner->expects($this->once())
            ->method('pruneFailedBefore')
            ->willReturnCallback(static function (mixed $threshold) use (&$captured): int {
                $captured = $threshold;

                return 0;
            })
        ;

        (new PruneFailedMessagesHandler($pruner))(new PruneFailedMessagesMessage());

        $this->assertInstanceOf(DateTimeImmutable::class, $captured);
        $this->assertSame('UTC', $captured->getTimezone()->getName());
    }

    private function isAbout(mixed $threshold, DateTimeImmutable $expected): bool
    {
        return $threshold instanceof DateTimeImmutable
            && \abs($threshold->getTimestamp() - $expected->getTimestamp()) < self::TOLERANCE_SECONDS;
    }
}
