<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Upload\PruneCommittedIntents;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One retention tick over committed intents; idempotent, the second run at the same instant matches nothing.
 */
#[AsMessageHandler]
final readonly class PruneCommittedIntentsHandler
{
    private const int BATCH = 1000;

    public function __construct(
        private PruneCommittedIntents $pruneCommittedIntents,
    ) {
    }

    public function __invoke(PruneCommittedIntentsMessage $message): void
    {
        unset($message);

        $this->pruneCommittedIntents->prune(self::BATCH);
    }
}
