<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The document was filed under retention terms other than the ones its kind of evidence fixes. Carries the expected
 * terms, never the ones the document holds, so the client can upload again under the right ones.
 */
final class EvidenceRetentionMismatch extends DomainException implements InvariantViolation
{
    public static function forKind(EvidenceKind $kind): self
    {
        return new self(
            type: 'bank-account-evidence-retention-mismatch',
            title: 'The document was not filed under the retention terms its kind of evidence requires.',
            context: [
                'kind' => $kind->value,
                'retentionReason' => $kind->retentionReason(),
                'legalBasis' => $kind->legalBasis(),
            ],
        );
    }
}
