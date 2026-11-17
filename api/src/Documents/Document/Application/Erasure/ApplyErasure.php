<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The erasure transaction both ways in share: `EraseDocument` (an operator's request) and `ReplayErasure` (a restore
 * or the completion sweep) end here, so there is one place that knows what erasing a document means.
 *
 * **One transaction, locks taken in one order: the upload intents of the scope, the document row, the keystore row.**
 * The upload commit and the reaper take intent then keystore (the document row does not exist yet in the commit), so
 * sharing the intent lock first serialises every path that could meet the keystore row; the scan's compare-and-set
 * takes only the document row. Then the `Erased` compare-and-set, the custody row and the key's destruction commit
 * together, so a failure anywhere leaves the row, its custody and its key as they were.
 *
 * `destroy()` runs even when the compare-and-set lost: it is first-writer-wins and idempotent, and a scope whose key
 * was never minted (a `Received` document before its first byte is sealed) is tombstoned too, which closes it to a
 * writer that arrives later. Its boolean decides nothing; `markErased()` is the only verdict on the row.
 *
 * A scan in flight is neither waited on nor stolen: the compare-and-set matches `Scanning`, and the worker's verdict,
 * which names the state it expects, then matches nothing.
 */
final readonly class ApplyErasure
{
    public function __construct(
        private UploadIntentRepository $intents,
        private DocumentRepository $documents,
        private DocumentErasures $erasures,
        private AppendCustody $custody,
        private Keystore $keystore,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param string|null $actorId              the operator, or NULL when the system finishes an entry the ledger holds
     * @param bool        $reopenObjectDeletion a restore replay empties the row's sealed-object marker in this same
     *                                          transaction, so the objects a backup brought back are taken up again by
     *                                          the deletion sweep; otherwise an already `Erased` row keeps its marker
     *
     * @throws DocumentNotFound when no document has this id
     *
     * @return bool whether THIS call moved the row to `Erased`
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") one named fact, set by exactly one of the two callers
     */
    public function apply(
        string $documentId,
        ?string $actorId,
        string $reason,
        bool $reopenObjectDeletion = false,
    ): bool {
        Uuid::ensure($documentId);

        return $this->transactionManager->transactional(function () use (
            $documentId,
            $actorId,
            $reason,
            $reopenObjectDeletion,
        ): bool {
            $this->erasures->boundTransactionTime();

            $document = $this->documents->findById($documentId) ?? throw DocumentNotFound::create();
            $scopeId = $document->scopeId();

            $this->intents->lockByScope($scopeId);
            $this->documents->lockForUpdate($documentId);

            $scope = EncryptionScopeId::forDocument($scopeId);
            $this->keystore->lockState($scope);

            $erased = $this->erasures->markErased($documentId);

            if ($erased) {
                $this->custody->append($documentId, CustodyEvent::Erased, $actorId, $reason);
            }

            $this->keystore->destroy($scope, DestroyReason::Erasure);

            if ($reopenObjectDeletion) {
                $this->erasures->reopenObjectDeletion($documentId);
            }

            return $erased;
        });
    }

    /**
     * Tombstones the scope of a ledger entry that no document holds, under the intent lock the commit and the reaper
     * share, so a writer that arrives later cannot mint a key under it.
     */
    public function tombstoneScopeWithoutRow(string $scopeId): void
    {
        $this->transactionManager->transactional(function () use ($scopeId): void {
            $this->erasures->boundTransactionTime();
            $this->intents->lockByScope($scopeId);

            $scope = EncryptionScopeId::forDocument($scopeId);
            $this->keystore->lockState($scope);
            $this->keystore->destroy($scope, DestroyReason::Erasure);
        });
    }
}
