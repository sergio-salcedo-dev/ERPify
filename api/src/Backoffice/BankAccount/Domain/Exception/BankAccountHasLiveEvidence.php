<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The bank account still holds evidence whose document is not erased, so neither its deletion nor its subject's
 * erasure may proceed: each document is erased first, through its own route, by a decision per document (whether a
 * piece of evidence must outlive its subject is not a cascade). Names how many, never which.
 */
final class BankAccountHasLiveEvidence extends DomainException implements Conflict
{
    public static function withCount(int $liveDocuments): self
    {
        return new self(
            type: 'bank-account-has-live-evidence',
            title: 'The bank account still holds evidence documents that are not erased.',
            context: ['liveDocuments' => $liveDocuments],
        );
    }
}
