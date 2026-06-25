<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Audit\Application\AuditLogPruner;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Clock\Domain\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Triggers the differentiated retention prune (see {@see PruneAuditLogMessage}). It is a thin adapter: it
 * resolves "now" from the {@see Clock}, asks the {@see AuditRetentionPolicy} for the deletion plan, and
 * hands it to the {@see AuditLogPruner}. The per-level decision lives in the policy and the deletion
 * strategy lives in the pruner — neither leaks here as control flow.
 */
#[AsMessageHandler]
final readonly class PruneAuditLogHandler
{
    public function __construct(
        private AuditLogPruner $pruner,
        private Clock $clock,
    ) {
    }

    public function __invoke(PruneAuditLogMessage $message): void
    {
        $policy = new AuditRetentionPolicy($message->activityRetentionDays, $message->securityRetentionDays);

        $this->pruner->prune(...$policy->thresholdsAt($this->clock->now()));
    }
}
