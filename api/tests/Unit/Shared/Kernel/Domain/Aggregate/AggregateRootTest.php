<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Kernel\Domain\Aggregate;

use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AggregateRoot::class)]
final class AggregateRootTest extends TestCase
{
    public function testIdThrowsWhenNoIdHasBeenAssigned(): void
    {
        // An aggregate instantiated before the application assigns its UUID v7 (e.g. a
        // half-built or wrongly-reconstituted entity) must not silently emit a null id;
        // id() turns that invariant breach into an explicit failure.
        $aggregate = IdlessAggregateStub::unidentified();

        $this->expectException(LogicException::class);

        $aggregate->exerciseIdGuard();
    }
}
