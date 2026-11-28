<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The document named in the body cannot serve as evidence: it does not exist, it is erased, or it is quarantined. One
 * answer for the three, because a caller can do nothing different about any of them but file the evidence again.
 */
final class EvidenceDocumentUnavailable extends DomainException implements InvariantViolation
{
    public static function create(): self
    {
        return new self(
            type: 'bank-account-evidence-unavailable',
            title: 'The document does not exist or can no longer serve as evidence.',
        );
    }
}
