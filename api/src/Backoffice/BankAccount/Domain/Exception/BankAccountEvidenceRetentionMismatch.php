<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The document was filed under retention terms other than the ones its kind of evidence fixes. The terms are the
 * consumer's, never the caller's: re-file the original under the kind's terms rather than attach this one.
 */
final class BankAccountEvidenceRetentionMismatch extends DomainException implements InvariantViolation
{
    public static function of(string $documentId, string $kind): self
    {
        return new self(
            type: 'bank-account-evidence-retention-mismatch',
            title: 'The document was not filed under the retention terms of this kind of evidence.',
            context: ['documentId' => $documentId, 'kind' => $kind],
        );
    }
}
