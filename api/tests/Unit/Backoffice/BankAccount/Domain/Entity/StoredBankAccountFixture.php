<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Symfony\Component\Clock\MockClock;

/**
 * An account stored at one instant and edited at a later one, with the two verdicts an edit can carry.
 * The clock moves between the two, which is what makes an `updatedAt` that should not have moved
 * observable: a guard that skips the event but still stamps the timestamp alters the persistable state
 * and would pass a check that only counted events.
 */
trait StoredBankAccountFixture
{
    private const string STORED_AT = '2026-06-14T09:30:00+00:00';

    private const string EDITED_AT = '2026-06-15T11:00:00+00:00';

    private const string HOLDER_NAME = 'Globex Corporation';

    private const string IBAN = 'DE89370400440532013000';

    private const string BIC = 'DEUTDEFFXXX';

    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    private function storedAccount(?string $bic = null, ?string $alias = null): BankAccount
    {
        SystemClock::set(new SymfonyClock(new MockClock(self::STORED_AT)));

        $account = BankAccountMother::drained(
            holderName: self::HOLDER_NAME,
            iban: self::IBAN,
            bic: $bic,
            alias: $alias,
        );

        SystemClock::set(new SymfonyClock(new MockClock(self::EDITED_AT)));

        return $account;
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
