<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Kernel\Domain\Aggregate;

use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Tests\Double\Clock\SuiteInstant;

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
        return new self(SuiteInstant::now());
    }

    /**
     * Invokes the protected non-null id() guard so a test can assert it rejects an
     * unidentified aggregate. Returns nothing on purpose: the guard throws before any id
     * exists, so a never-consumed string return would only read as dead code to static
     * analysis.
     */
    public function exerciseIdGuard(): void
    {
        $this->id();
    }
}
