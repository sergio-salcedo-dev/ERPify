<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Fixture;

use Erpify\Documents\Document\Application\Erasure\ApplyErasure;

/**
 * A planted holder of the erasure transaction, spelled as a union with null: the shape a string comparison against the
 * FQCN misses. It exists only for `ReplayErasureCallersGateTest` to prove its engine can find one.
 *
 * @internal
 */
final readonly class ApplyErasureHolder
{
    public function __construct(
        public ?ApplyErasure $apply = null,
    ) {
    }
}
