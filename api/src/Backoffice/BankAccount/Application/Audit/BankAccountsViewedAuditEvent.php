<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Audit;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\SystemClock;

/**
 * Audit / observability message: a bank's accounts (which carry the PII IBAN) were read. Deliberately
 * NOT a {@see \Erpify\Shared\Event\Domain\DomainEvent} — no business fact occurred, so it stays out of
 * the domain language and out of the event store. It carries only WHAT (bankId) and WHEN; never
 * the IBAN or any account value. The actor (WHO) is deferred until authentication exists.
 */
final readonly class BankAccountsViewedAuditEvent
{
    public function __construct(
        public string $bankId,
        public DateTimeImmutable $occurredOn,
    ) {
    }

    public static function record(string $bankId): self
    {
        return new self($bankId, SystemClock::now());
    }
}
