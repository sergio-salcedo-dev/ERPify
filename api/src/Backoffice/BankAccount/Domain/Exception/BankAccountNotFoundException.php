<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

final class BankAccountNotFoundException extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'bank-account-not-found',
            title: \sprintf('Bank account with id <%s> not found.', $id),
            context: ['bankAccountId' => $id],
        );
    }

    /**
     * Unlike {@see withId()}, the IBAN itself never appears in the message or the context: an id is an
     * opaque UUID, but an IBAN is classified PII, and this exception's message reaches both the
     * per-error log line and Sentry (see the "validation message may describe the rule, never the
     * value" rule in `api/CLAUDE.md`, which applies here just as much as to a constraint violation).
     */
    public static function withIban(): self
    {
        return new self(
            type: 'bank-account-not-found',
            title: 'Bank account with the given IBAN not found.',
        );
    }
}
