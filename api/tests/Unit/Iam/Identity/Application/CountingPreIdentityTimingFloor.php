<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Override;

/**
 * Counts floor invocations so structural timing tests can assert the hash-equalisation work RUNS on every
 * pre-identity branch — never that wall-clock latencies match (a flaky benchmark the repo bans). Cost
 * equality is the hasher's own property, not re-tested through this fake.
 */
final class CountingPreIdentityTimingFloor implements PreIdentityTimingFloor
{
    public int $invocations = 0;

    #[Override]
    public function equalise(): void
    {
        ++$this->invocations;
    }
}
