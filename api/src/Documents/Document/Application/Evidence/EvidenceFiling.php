<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Evidence;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\DocumentState;

/**
 * The terms a document was filed under and where it stands in its lifecycle, as {@see EvidenceDocuments} hands them
 * to a consumer. Strings rather than the Documents enums: the consumer compares them and never needs the types.
 */
final readonly class EvidenceFiling
{
    public function __construct(
        public string $documentId,
        public string $state,
        public ?string $retentionReason,
        public ?string $legalBasis,
        public ?DateTimeImmutable $reviewAt,
    ) {
    }

    /** `Erased` is terminal: the key is destroyed and only the tombstone remains. */
    public function isErased(): bool
    {
        return DocumentState::Erased->value === $this->state;
    }

    /**
     * Whether the document was filed under exactly these terms. The consumer fixes the terms per kind of evidence while
     * the caller's declare chose them, so this comparison is what keeps an original filed under other terms from being
     * attached. A review date is part of the terms: a kind that carries none matches only a document filed without one.
     */
    public function isFiledUnder(string $retentionReason, string $legalBasis, ?DateTimeImmutable $reviewAt): bool
    {
        return $this->retentionReason === $retentionReason
            && $this->legalBasis === $legalBasis
            && $this->reviewAt?->getTimestamp() === $reviewAt?->getTimestamp();
    }
}
