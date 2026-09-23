<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use DateTimeZone;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\HandledDomainEventPruner;
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

    /**
     * UTC explicitly: `claimed_at` is a `timestamp WITHOUT time zone` written by Postgres `NOW()` in the session
     * zone (UTC in the image this stack runs), and DBAL formats the threshold in whatever zone it carries without
     * converting it.
     */
    public function __invoke(PruneHandledDomainEventsMessage $message): void
    {
        $this->pruner->pruneClaimedBefore(
            $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('-' . $message->retentionDays . ' days'),
        );
    }
}
