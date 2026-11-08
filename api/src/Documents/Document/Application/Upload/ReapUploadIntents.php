<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The reaper: ends every upload intent that can no longer become a document, one intent per transaction.
 *
 * **Lock order is the commit's: the intent row, then the keystore row of its scope.** Under both locks it
 * rechecks what the candidate query only suggested — that the intent counts as expired by the database clock
 * and is not `Committed` — and that no document holds the scope. Holding the intent row is what makes the
 * second check final: a commit needs that same row to file the document, so none can be in flight.
 *
 * Then exactly one of three outcomes:
 *  - never claimed (`claimed_at` NULL): no key was minted, the intent is deleted, no tombstone — abandoned
 *    declares add no keystore row;
 *  - a document holds the scope: the committed row owns the scope and its object, so the intent is deleted
 *    and nothing else is touched;
 *  - otherwise: the staged object is deleted, the scope is tombstoned `aborted-upload` (a tombstone is minted
 *    even when no key was, so a late writer cannot mint one under it), and the intent is deleted.
 *
 * A committed row's staged object is never deleted here and its scope never destroyed: the reaper acts on
 * intents, never on documents. Aborted-upload tombstones are never pruned.
 *
 * One intent that cannot be reaped is logged and passed over, never the end of the batch: a candidate that
 * throws would otherwise head every tick's batch and stop all the others behind it.
 */
final readonly class ReapUploadIntents
{
    public function __construct(
        private UploadIntentRepository $intents,
        private DocumentRepository $documents,
        private Keystore $keystore,
        private DocumentStorage $storage,
        private TransactionManager $transactionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many intents this run ended
     */
    public function reap(int $limit): int
    {
        $reaped = 0;

        foreach ($this->intents->reapCandidates($limit) as $intentId) {
            try {
                if ($this->reapOne($intentId)) {
                    ++$reaped;
                }
            } catch (Throwable $throwable) {
                $this->logger->error('An upload intent could not be reaped; the batch goes on.', [
                    'intentId' => $intentId,
                    'exceptionClass' => $throwable::class,
                ]);
            }
        }

        return $reaped;
    }

    public function reapOne(string $intentId): bool
    {
        return $this->transactionManager->transactional(function () use ($intentId): bool {
            $intent = $this->intents->lockForUpdate($intentId);

            if (!$intent instanceof UploadIntent || !$intent->isReapable($this->intents->now())) {
                return false;
            }

            if (!$intent->wasClaimed()) {
                $this->intents->delete($intent);

                return true;
            }

            $scope = EncryptionScopeId::forDocument($intent->scopeId());
            $this->keystore->lockState($scope);

            if (!$this->documents->existsForScope($intent->scopeId())) {
                $this->storage->delete(StorageArea::Staging, $intent->scopeId());
                $this->keystore->destroy($scope, DestroyReason::AbortedUpload);
            }

            $this->intents->delete($intent);

            return true;
        });
    }
}
