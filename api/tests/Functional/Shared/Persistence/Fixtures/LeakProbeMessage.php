<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

/**
 * A message whose handler, in the worker boundary's end-to-end test, leaks a transaction the first time it runs.
 *
 * @internal
 */
final readonly class LeakProbeMessage
{
}
