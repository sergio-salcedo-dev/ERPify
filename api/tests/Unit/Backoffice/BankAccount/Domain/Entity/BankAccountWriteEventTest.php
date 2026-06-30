<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountStatusChangedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotClosedException;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Update / delete behaviour of the {@see BankAccount} aggregate, split from {@see BankAccountTest} so
 * each test class stays focused (and under the public-method budget). Covers IBAN/BIC canonicalization
 * on edit, the CLOSED-only delete invariant, and the recorded lifecycle events.
 *
 * @internal
 */
#[CoversClass(BankAccount::class)]
final class BankAccountWriteEventTest extends TestCase
{
    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    public function testUpdateRecanonicalizesIbanUppercasesBicAndRecordsUpdatedEvent(): void
    {
        $account = BankAccountMother::drained(bic: 'DEUTDEFFXXX', alias: 'Old Alias');

        $account->update(
            'Globex Renamed',
            'fr14 2004 1010 0505 0001 3M02 606',
            'bnpafrppxxx',
            'New Alias',
            Currency::EUR,
        );

        $this->assertSame('Globex Renamed', $account->getHolderName());
        $this->assertSame('FR1420041010050500013M02606', $account->getIban());
        $this->assertSame('BNPAFRPPXXX', $account->getBic());
        $this->assertSame('New Alias', $account->getAlias());

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountUpdatedDomainEvent::class, $events[0]);
        $this->assertSame(BankAccountMother::DEFAULT_ID, $events[0]->aggregateId());
    }

    public function testUpdateClearsTheOptionalBicAndAliasWhenNullIsProvided(): void
    {
        $account = BankAccountMother::drained(bic: 'DEUTDEFFXXX', alias: 'Treasury');

        $account->update('Holder', 'DE89370400440532013000', null, null, Currency::EUR);

        $this->assertNull($account->getBic());
        $this->assertNull($account->getAlias());
    }

    public function testUpdateBumpsUpdatedAtAndStampsTheEventFromTheAmbientClock(): void
    {
        $createdAt = '2026-06-14T09:30:00+00:00';
        $updatedAt = '2026-06-15T11:00:00+00:00';

        SystemClock::set(new SymfonyClock(new MockClock($createdAt)));
        $account = BankAccountMother::drained();

        SystemClock::set(new SymfonyClock(new MockClock($updatedAt)));
        $account->update('Holder', 'DE89370400440532013000', null, null, Currency::EUR);

        $this->assertSame($createdAt, $account->getCreatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame($updatedAt, $account->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountUpdatedDomainEvent::class, $events[0]);
        $this->assertSame($updatedAt, $events[0]->occurredOn()->format(DateTimeInterface::ATOM));
    }

    public function testDeleteRecordsADeletedEventWhenTheAccountIsClosed(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);

        $account->delete();

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountDeletedDomainEvent::class, $events[0]);
        $this->assertSame(BankAccountMother::DEFAULT_ID, $events[0]->aggregateId());
        $this->assertSame(BankAccountMother::DEFAULT_BANK_ID, $events[0]->bankId());
    }

    public function testDeleteThrowsAndRecordsNothingWhenTheAccountIsNotClosed(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);

        try {
            $account->delete();
            $this->fail('Expected BankAccountNotClosedException to be thrown.');
        } catch (BankAccountNotClosedException $bankAccountNotClosedException) {
            $this->assertSame('bank-account-not-closed', $bankAccountNotClosedException->type());
            $this->assertSame(
                ['bankAccountId' => BankAccountMother::DEFAULT_ID],
                $bankAccountNotClosedException->context(),
            );
        }

        $this->assertSame([], $account->pullDomainEvents());
    }

    public function testChangeStatusRecordsAStatusChangedEventCarryingTheFromAndToPair(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);

        $account->changeStatus(BankAccountStatus::CLOSED);

        $this->assertSame(BankAccountStatus::CLOSED, $account->getStatus());

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountStatusChangedDomainEvent::class, $events[0]);
        $this->assertSame('ACTIVE', $events[0]->fromStatus());
        $this->assertSame('CLOSED', $events[0]->toStatus());
        $this->assertSame(BankAccountMother::DEFAULT_BANK_ID, $events[0]->bankId());
    }

    public function testChangeStatusToTheCurrentStatusIsANoOpAndRecordsNothing(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::ACTIVE);

        $account->changeStatus(BankAccountStatus::ACTIVE);

        $this->assertSame(BankAccountStatus::ACTIVE, $account->getStatus());
        $this->assertSame([], $account->pullDomainEvents());
    }

    public function testChangeStatusAllowsReopeningAClosedAccountSinceTransitionsAreFree(): void
    {
        $account = BankAccountMother::drained(status: BankAccountStatus::CLOSED);

        $account->changeStatus(BankAccountStatus::ACTIVE);

        $this->assertSame(BankAccountStatus::ACTIVE, $account->getStatus());

        $events = $account->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankAccountStatusChangedDomainEvent::class, $events[0]);
        $this->assertSame('CLOSED', $events[0]->fromStatus());
        $this->assertSame('ACTIVE', $events[0]->toStatus());
    }
}
