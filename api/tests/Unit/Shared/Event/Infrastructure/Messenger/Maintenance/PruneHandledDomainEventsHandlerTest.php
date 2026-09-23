<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Event\Application\HandledDomainEventPruner;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneHandledDomainEventsHandler;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneHandledDomainEventsMessage;
use Erpify\Tests\Double\Clock\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PruneHandledDomainEventsHandler::class)]
final class PruneHandledDomainEventsHandlerTest extends TestCase
{
    #[Test]
    public function itPrunesClaimsOlderThanTheRetentionWindow(): void
    {
        $captured = null;

        $pruner = $this->createMock(HandledDomainEventPruner::class);
        $pruner->expects($this->once())
            ->method('pruneClaimedBefore')
            ->willReturnCallback(static function (mixed $threshold) use (&$captured): int {
                $captured = $threshold;

                return 3;
            })
        ;

        (new PruneHandledDomainEventsHandler($pruner, FixedClock::at('2050-06-15T12:00:00+00:00')))(
            new PruneHandledDomainEventsMessage(7),
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $captured);
        $this->assertSame('2050-06-08T12:00:00+00:00', $captured->format(DateTimeInterface::ATOM));
    }
}
