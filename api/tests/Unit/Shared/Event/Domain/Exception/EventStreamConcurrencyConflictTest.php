<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\Event\Domain\Exception\EventStreamConcurrencyConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(EventStreamConcurrencyConflict::class)]
final class EventStreamConcurrencyConflictTest extends TestCase
{
    public function testIsA409ConflictCarryingTheAggregateContext(): void
    {
        $exception = EventStreamConcurrencyConflict::forAggregate('agg-1', 'Bank');

        $this->assertContains(
            Conflict::class,
            (new ReflectionClass(EventStreamConcurrencyConflict::class))->getInterfaceNames(),
        );
        $this->assertSame('event-stream-conflict', $exception->type());
        $this->assertSame(['aggregateId' => 'agg-1', 'aggregateType' => 'Bank'], $exception->context());
    }
}
