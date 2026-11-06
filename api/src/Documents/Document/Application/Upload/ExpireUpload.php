<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Throwable;

/**
 * A writer's own abort: the body differed from what was declared, the lease or the deadline was lost, the
 * commit refused. It deletes its staged object and, under the intent row's lock, expires the intent if it is
 * still `Streaming` — which nulls the key hash and sets the lease to now, so the reaper can tombstone the
 * scope at once. It never tombstones itself: the reaper decides that under both locks, as it does for every
 * other expired intent, so there is one place where a scope is destroyed for an aborted upload.
 *
 * Best effort by design. Whatever it cannot do is done by the sweeps — the reaper expires and tombstones by
 * time, the staging age sweep removes an object nobody owns — so a failure here is swallowed rather than
 * replacing the answer the caller is about to receive.
 */
final readonly class ExpireUpload
{
    public function __construct(
        private UploadIntentRepository $intents,
        private DocumentStorage $storage,
        private TransactionManager $transactionManager,
    ) {
    }

    public function expire(string $intentId, string $scopeId): void
    {
        try {
            $this->storage->delete(StorageArea::Staging, $scopeId);
        } catch (Throwable) {
            // The staging age sweep removes an object no live intent and no row owns.
        }

        try {
            $this->transactionManager->transactional(function () use ($intentId): void {
                $intent = $this->intents->lockForUpdate($intentId);

                if (!$intent instanceof UploadIntent || UploadIntentState::Streaming !== $intent->state()) {
                    return;
                }

                $intent->expire($this->intents->now());
                $this->intents->save($intent);
            });
        } catch (Throwable) {
            // A Streaming intent whose lease is not renewed counts as expired within the lease, and the reaper
            // takes it from there.
        }
    }
}
