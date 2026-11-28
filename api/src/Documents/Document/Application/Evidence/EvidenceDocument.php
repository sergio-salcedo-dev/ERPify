<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Evidence;

use DateTimeImmutable;

/**
 * One document as a consumer context sees the evidence it references: the lifecycle state and the retention terms as
 * their wire strings, the opened name (NULL when there is none to give), the size and when it was filed. Strings rather
 * than the Documents enums because a consumer may not import another context's `Domain`; the vocabulary is the one
 * `RetentionReason`, `LegalBasis` and `DocumentState` publish on the wire.
 *
 * Carries no uploader, digest, scope or ciphertext: a consumer needs to show the document and to check its retention,
 * nothing else.
 */
final readonly class EvidenceDocument
{
    public function __construct(
        public string $documentId,
        public string $state,
        public ?string $name,
        public int $sizeBytes,
        public ?string $retentionReason,
        public ?string $legalBasis,
        public ?DateTimeImmutable $reviewAt,
        public DateTimeImmutable $createdAt,
        public bool $erased,
    ) {
    }

    /**
     * Whether the document was filed under exactly these retention terms. A consumer fixes the terms per kind of
     * evidence and refuses a document filed under any other (the caller of the upload, not the consumer, chose them).
     */
    public function isRetainedAs(string $retentionReason, string $legalBasis, ?DateTimeImmutable $reviewAt): bool
    {
        return $this->retentionReason === $retentionReason
            && $this->legalBasis === $legalBasis
            && $this->reviewAt?->getTimestamp() === $reviewAt?->getTimestamp();
    }
}
