<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;

/**
 * An account stored at one instant and edited at a later one, with the two verdicts an edit can carry.
 * The edit is applied at the later instant, which is what makes an `updatedAt` that should not have moved
 * observable: a guard that skips the event but still stamps the timestamp alters the persistable state
 * and would pass a check that only counted events.
 */
trait StoredBankAccountFixture
{
    private const string STORED_AT = '2026-06-14T09:30:00+00:00';

    private const string EDITED_AT = '2026-06-15T11:00:00+00:00';

    private const string HOLDER_NAME = 'Globex Corporation';

    private const string IBAN = 'DE89370400440532013000';

    private static function editedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::EDITED_AT);
    }

    private function storedAccount(?string $bic = null, ?string $alias = null): BankAccount
    {
        return BankAccountMother::drained(
            holderName: self::HOLDER_NAME,
            iban: self::IBAN,
            bic: $bic,
            alias: $alias,
            now: new DateTimeImmutable(self::STORED_AT),
        );
    }

    private function assertNoOp(BankAccount $account): void
    {
        $this->assertSame(self::HOLDER_NAME, $account->getHolderName());
        $this->assertSame(self::STORED_AT, $account->getUpdatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame([], $account->pullDomainEvents());
    }

    private function assertMutated(BankAccount $account): void
    {
        $this->assertSame(self::EDITED_AT, $account->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountUpdatedDomainEvent::class, $events[0]);
        $this->assertSame(BankAccountMother::DEFAULT_ID, $events[0]->aggregateId());
    }
}
