<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/** The account holds no link with that id — none at all, or one that belongs to another account. */
final class BankAccountEvidenceNotFound extends DomainException implements NotFound
{
    public static function withId(string $linkId): self
    {
        return new self(
            type: 'bank-account-evidence-not-found',
            title: 'The bank account holds no such evidence.',
            context: ['evidenceId' => $linkId],
        );
    }
}
