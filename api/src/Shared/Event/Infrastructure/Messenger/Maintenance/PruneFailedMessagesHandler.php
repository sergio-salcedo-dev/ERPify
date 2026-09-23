<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use DateTimeZone;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\FailedMessagePruner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Prunes failed messages older than the message's retention window (see {@see PruneFailedMessagesMessage}).
 */
#[AsMessageHandler]
final readonly class PruneFailedMessagesHandler
{
    public function __construct(
        private FailedMessagePruner $pruner,
        private Clock $clock,
    ) {
    }

    /**
     * UTC explicitly. Symfony's Doctrine transport writes `created_at` with `new DateTimeImmutable('UTC')`
     * into a `timestamp WITHOUT time zone` column, and DBAL formats a threshold in whatever zone it carries
     * with no conversion — so the two agree today only because a `php.ini` pins `date.timezone`, which
     * nothing in this code references. Naming the zone here makes the window mean the same thing wherever
     * the tick runs.
     */
    public function __invoke(PruneFailedMessagesMessage $message): void
    {
        $this->pruner->pruneFailedBefore(
            $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('-' . $message->retentionDays . ' days'),
        );
    }
}
