<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application;

use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Files the first version of a document: the snapshot in `Received` and its custody row 1, in one
 * transaction, so a rollback leaves neither.
 *
 * It reads no byte and computes no digest: the digest, the size and the scope id arrive from the upload flow
 * that sealed the bytes. The scope id is minted by that flow before the first byte is sealed and is only
 * copied here, because the staging key already embeds it.
 *
 * A first version has no row to lock, so the document insert and its first custody row only have the
 * `UNIQUE` constraints to guard them; the actor-scoped idempotency key is one of them, and its violation is a
 * conflict that is never retried. A violation of `uniq_document_scope_id` is not mapped: the scope id is minted
 * random by the flow that seals the bytes, so a collision is a defect of that flow and surfaces as the raw
 * violation.
 */
final readonly class ReceiveDocument
{
    private const int FIRST_SEQUENCE = 1;

    public function __construct(
        private DocumentRepository $documents,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws ConcurrentUniqueWrite when another document already holds the idempotency key hash
     */
    public function receive(
        string $scopeId,
        string $uploaderId,
        string $digest,
        int $sizeBytes,
        ?string $idempotencyKeyHash = null,
    ): Document {
        $documentId = Uuid::generate();

        $document = Document::receive($documentId, $scopeId, $uploaderId, $digest, $sizeBytes, $idempotencyKeyHash);
        $received = DocumentCustody::record(
            Uuid::generate(),
            $documentId,
            self::FIRST_SEQUENCE,
            CustodyEvent::Received,
            $uploaderId,
        );

        $this->transactionManager->transactional(function () use ($document, $received): void {
            $this->documents->add($document, $received);
        });

        return $document;
    }
}
