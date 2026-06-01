<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Aggregate;

use Erpify\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Minimal concrete {@see AggregateRoot} for unit-testing shared aggregate behaviour.
 * `unidentified()` builds one whose id was never assigned (the `new self()` reaches the
 * protected constructor from within the class), so the non-null id() guard can be
 * exercised without bypassing constructor access via reflection.
 *
 * @internal
 */
final class IdlessAggregateStub extends AggregateRoot
{
    public static function unidentified(): self
    {
        return new self();
    }

    public function readId(): string
    {
        return $this->id();
    }
}
