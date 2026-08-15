<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
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
    ) {
    }

    public function __invoke(PruneFailedMessagesMessage $message): void
    {
        $this->pruner->pruneFailedBefore(new DateTimeImmutable('-' . $message->retentionDays . ' days'));
    }
}
