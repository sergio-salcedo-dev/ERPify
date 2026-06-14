<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Application\DomainEvent\HandledDomainEventPruner;
use Erpify\Shared\Domain\Clock\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Prunes claim rows older than the message's retention window (see {@see PruneHandledDomainEventsMessage}).
 */
#[AsMessageHandler]
final readonly class PruneHandledDomainEventsHandler
{
    public function __construct(
        private HandledDomainEventPruner $pruner,
        private Clock $clock,
    ) {
    }

    public function __invoke(PruneHandledDomainEventsMessage $message): void
    {
        $this->pruner->pruneClaimedBefore($this->clock->now()->modify('-' . $message->retentionDays . ' days'));
    }
}
