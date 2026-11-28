<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The document is already attached to this bank account: a link is unique per (account, document).
 */
final class EvidenceAlreadyAttached extends DomainException implements Conflict
{
    public static function create(): self
    {
        return new self(
            type: 'bank-account-evidence-already-attached',
            title: 'The document is already attached to this bank account.',
        );
    }
}
