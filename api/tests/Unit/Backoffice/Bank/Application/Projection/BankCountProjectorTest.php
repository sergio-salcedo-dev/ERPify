<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application\Projection;

use Erpify\Backoffice\Bank\Application\Projection\BankCountProjector;
use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Event\Domain\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankCountProjector::class)]
final class BankCountProjectorTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itIsNamedBankCountAndSubscribesToBankCreationAndDeletion(): void
    {
        $projector = new BankCountProjector($this->createStub(BankCountReadModel::class));

        $this->assertSame('bank_count', $projector->name());
        $this->assertSame([
            'erpify.backoffice.bank.created',
            'erpify.backoffice.bank.deleted',
        ], $projector->subscribedTo());
    }

    #[Test]
    public function itIncrementsTheTotalOnBankCreation(): void
    {
        $readModel = $this->readModel();
        $readModel->expects($this->once())->method('increment');
        $readModel->expects($this->never())->method('decrement');

        (new BankCountProjector($readModel))->project($this->createdEvent());
    }

    #[Test]
    public function itDecrementsTheTotalOnBankDeletion(): void
    {
        $readModel = $this->readModel();
        $readModel->expects($this->once())->method('decrement');
        $readModel->expects($this->never())->method('increment');

        (new BankCountProjector($readModel))->project(new BankDeletedDomainEvent(self::BANK_ID));
    }

    #[Test]
    public function itIgnoresAnEventItDoesNotProject(): void
    {
        $readModel = $this->readModel();
        $readModel->expects($this->never())->method('increment');
        $readModel->expects($this->never())->method('decrement');

        (new BankCountProjector($readModel))->project($this->createStub(DomainEvent::class));
    }

    #[Test]
    public function truncateEmptiesTheReadModel(): void
    {
        $readModel = $this->readModel();
        $readModel->expects($this->once())->method('truncate');

        (new BankCountProjector($readModel))->truncate();
    }

    private function readModel(): BankCountReadModel&MockObject
    {
        return $this->createMock(BankCountReadModel::class);
    }

    private function createdEvent(): BankCreatedDomainEvent
    {
        return new BankCreatedDomainEvent(
            self::BANK_ID,
            new BankSnapshot('Acme', 'ACME', '2026-06-06T00:00:00+00:00', '2026-06-06T00:00:00+00:00'),
        );
    }
}
