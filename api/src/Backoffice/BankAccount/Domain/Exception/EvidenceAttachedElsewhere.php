<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The document is already the evidence of another bank account: a document proves one account, so it is attached to
 * at most one. Names no account.
 */
final class EvidenceAttachedElsewhere extends DomainException implements Conflict
{
    public static function create(): self
    {
        return new self(
            type: 'bank-account-evidence-attached-elsewhere',
            title: 'The document is already the evidence of another bank account.',
        );
    }
}
