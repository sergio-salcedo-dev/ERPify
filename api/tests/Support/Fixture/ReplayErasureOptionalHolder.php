<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Fixture;

use Erpify\Documents\Document\Application\Erasure\ReplayErasure;

/**
 * A planted holder of the replay service, spelled as an optional parameter: the shape a string comparison against the
 * FQCN misses. It exists only for `ReplayErasureCallersGateTest` to prove its engine can find one.
 *
 * @internal
 */
final readonly class ReplayErasureOptionalHolder
{
    public function __construct(
        public ?ReplayErasure $replay = null,
    ) {
    }
}
