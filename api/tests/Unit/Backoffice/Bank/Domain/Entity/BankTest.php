<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankTest extends TestCase
{
    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    public function testCreateRecordsCreatedEventWhoseAggregateIdEqualsTheEntityId(): void
    {
        // Domain-level invariant: the create event's aggregate id is the entity id. Persist-time id
        // stability is locked separately by IdentifiableAssignedIdentifierTest, which asserts Doctrine
        // does not overwrite this id at flush.
        $bank = BankMother::create();

        $this->assertSame(BankMother::DEFAULT_ID, $bank->getId());

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);
        $this->assertSame($bank->getId(), $event->aggregateId());
    }

    public function testRenameRecordsUpdatedEventWhoseAggregateIdEqualsTheEntityId(): void
    {
        $bank = BankMother::drained();

        $bank->rename('Acme Renamed', 'ACME');

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $event);
        $this->assertSame(BankMother::DEFAULT_ID, $event->aggregateId());
    }

    public function testCreateStampsTimestampsAndEventOccurredOnFromTheAmbientClock(): void
    {
        $instant = '2026-06-14T09:30:00+00:00';
        SystemClock::set(new SymfonyClock(new MockClock($instant)));

        $bank = BankMother::create();

        $this->assertSame($instant, $bank->getCreatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame($instant, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankCreatedDomainEvent::class, $event);
        $this->assertSame($instant, $event->occurredOn()->format(DateTimeInterface::ATOM));
    }

    public function testRenameStampsUpdatedAtAndEventOccurredOnFromTheAmbientClock(): void
    {
        $createdAt = '2026-06-14T09:30:00+00:00';
        $renamedAt = '2026-06-15T11:00:00+00:00';

        SystemClock::set(new SymfonyClock(new MockClock($createdAt)));
        $bank = BankMother::drained();

        SystemClock::set(new SymfonyClock(new MockClock($renamedAt)));
        $bank->rename('Acme Renamed', 'ACME');

        $this->assertSame($createdAt, $bank->getCreatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame($renamedAt, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(BankUpdatedDomainEvent::class, $event);
        $this->assertSame($renamedAt, $event->occurredOn()->format(DateTimeInterface::ATOM));
    }

    public function testGettersExposeTheDisplayCanonicalAndNormalizedForms(): void
    {
        $bank = BankMother::create(name: 'Café Society', shortName: 'café');

        $this->assertSame('Café Society', $bank->getName());
        $this->assertSame('cafe society', $bank->getNameNormalized());
        $this->assertSame('CAFE', $bank->getShortName());
    }

    public function testDeleteRecordsADeletedEventForTheAggregate(): void
    {
        $bank = BankMother::drained();

        $bank->delete();

        $events = $bank->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BankDeletedDomainEvent::class, $events[0]);
        $this->assertSame(BankMother::DEFAULT_ID, $events[0]->aggregateId());
    }

    public function testValidateNormalizedNameLengthIsSilentWhenTheRawNameAlreadyExceedsTheLimit(): void
    {
        $bank = BankMother::create(name: \str_repeat('A', 256));

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $bank->validateNormalizedNameLength($context);
    }

    public function testValidateNormalizedNameLengthFlagsAnOverLongNormalizedTwinOnThePathName(): void
    {
        // 200 "ß" stays within the 255-char raw limit yet folds to "ss" × 200 = 400 normalized chars,
        // which would overflow the unique column — the entity rejects it as a clean violation on `name`.
        $bank = BankMother::create(name: \str_repeat('ß', 200));

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('setParameter')->willReturnSelf();
        $builder->expects($this->once())->method('atPath')->with('name')->willReturnSelf();
        $builder->expects($this->once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('The name must not exceed {{ limit }} characters.')
            ->willReturn($builder)
        ;

        $bank->validateNormalizedNameLength($context);
    }
}
