<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Command;

use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of an attach: the id of a document already filed through the Documents upload, and what it proves. The
 * retention terms are not here: the kind fixes them, and the server checks the document against them.
 */
final readonly class AttachBankAccountEvidenceCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'The documentId field is required.')]
        #[Assert\Uuid(message: 'The documentId must be a valid UUID.')]
        public string $documentId = '',
        #[Assert\NotNull(message: 'The kind field is required.')]
        public ?EvidenceKind $kind = null,
    ) {
    }
}
