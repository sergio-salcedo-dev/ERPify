<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
use Erpify\Shared\Event\Application\HandledDomainEventPruner;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneHandledDomainEventsHandler;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneHandledDomainEventsMessage;
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
        $pruner = $this->createMock(HandledDomainEventPruner::class);
        $pruner->expects($this->once())
            ->method('pruneClaimedBefore')
            ->with($this->callback(static fn (mixed $threshold): bool => $threshold instanceof DateTimeImmutable
                && \abs($threshold->getTimestamp() - (new DateTimeImmutable('-7 days'))->getTimestamp()) < 60))
            ->willReturn(3)
        ;

        (new PruneHandledDomainEventsHandler($pruner))(new PruneHandledDomainEventsMessage(7));
    }
}
