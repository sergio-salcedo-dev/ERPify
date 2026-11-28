<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Resource;

/**
 * One piece of evidence of a bank account on the wire: the link (its id, the document's id, the kind, when it was
 * attached) and what Documents says about the document — its state, its name and its size, each NULL when Documents
 * has nothing to give (a name that does not open, an erased document's name). The client reads the bytes through the
 * Documents read route by `documentId`; no URL is composed here.
 */
final readonly class BankAccountEvidenceResource
{
    public function __construct(
        public string $id,
        public string $documentId,
        public string $kind,
        public string $attachedAt,
        public ?string $documentState,
        public ?string $documentName,
        public ?int $documentSizeBytes,
    ) {
    }
}
