<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\AuditLogPruner;
use Erpify\Shared\Audit\Domain\AuditRetentionThreshold;
use Override;

/**
 * Captures the deletion plan a handler hands to {@see AuditLogPruner::prune()} so the dispatch can be
 * asserted without a database.
 *
 * @internal
 */
final class RecordingAuditLogPruner implements AuditLogPruner
{
    public int $callCount = 0;

    /** @var list<AuditRetentionThreshold> */
    public array $plan = [];

    #[Override]
    public function prune(AuditRetentionThreshold ...$plan): int
    {
        ++$this->callCount;
        $this->plan = \array_values($plan);

        return 0;
    }
}
