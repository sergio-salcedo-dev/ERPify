<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Deletes `Committed` intents older than {@see UploadLimits::COMMITTED_RETENTION_SECONDS}. Until then the
 * intent keeps holding its key, so a replay is answered from it; afterwards the document's own `UNIQUE` on the
 * key hash and the declare step's re-read take over, which is why nothing is lost by the deletion.
 */
final readonly class PruneCommittedIntents
{
    public function __construct(
        private UploadIntentRepository $intents,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @return int how many intents this run deleted
     */
    public function prune(int $limit): int
    {
        return $this->transactionManager->transactional(function () use ($limit): int {
            $cutoff = $this->intents->now()->modify(\sprintf('-%d seconds', UploadLimits::COMMITTED_RETENTION_SECONDS));

            return $this->intents->deleteCommittedBefore($cutoff, $limit);
        });
    }
}
