<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Audit\Application\AuditLogPruner;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Clock\Domain\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Prunes each audit level past its own retention window (see {@see PruneAuditLogMessage}). The window
 * math is the {@see AuditRetentionPolicy}'s; this handler is the messaging adapter that resolves "now"
 * from the {@see Clock} and fans the per-level threshold out to the {@see AuditLogPruner}.
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
        $now = $this->clock->now();

        foreach (AuditLevel::cases() as $level) {
            $this->pruner->pruneOlderThan($level, $policy->thresholdFor($level, $now));
        }
    }
}
