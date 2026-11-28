<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Command;

use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input of {@see \Erpify\Backoffice\BankAccount\Application\Evidence\AttachBankAccountEvidence}: the document an
 * earlier upload filed, by id, and what it proves. No retention terms: they belong to the kind, and the attach checks
 * on the server that the document was filed under them.
 */
final readonly class AttachBankAccountEvidenceCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'The documentId field is required.')]
        #[Assert\Uuid(message: 'The documentId must be a UUID.', strict: true)]
        public ?string $documentId = null,
        #[Assert\NotNull(message: 'The kind field is required.')]
        public ?BankAccountEvidenceKind $kind = null,
    ) {
    }
}
