<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;

/**
 * One piece of a bank account's evidence as the account screen shows it: the link (its id, the kind, when it was
 * attached) composed with what the Documents seam says of the document (its opened name, its state, the digest and
 * size the server computed). Never serialised: the resource mapper picks the fields.
 */
final readonly class ListedEvidence
{
    public function __construct(
        public string $id,
        public string $documentId,
        public BankAccountEvidenceKind $kind,
        public DateTimeImmutable $attachedAt,
        public ?string $name,
        public string $state,
        public string $digest,
        public int $sizeBytes,
    ) {
    }
}
