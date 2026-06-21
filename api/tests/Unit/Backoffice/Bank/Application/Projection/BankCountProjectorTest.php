<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application\Projection;

use Erpify\Backoffice\Bank\Application\Projection\BankCountProjector;
use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankCreatedDomainEventMother;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankDeletedDomainEventMother;
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

        (new BankCountProjector($readModel))->project(BankCreatedDomainEventMother::create());
    }

    #[Test]
    public function itDecrementsTheTotalOnBankDeletion(): void
    {
        $readModel = $this->readModel();
        $readModel->expects($this->once())->method('decrement');
        $readModel->expects($this->never())->method('increment');

        (new BankCountProjector($readModel))->project(BankDeletedDomainEventMother::create());
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
    public function resetEmptiesTheReadModel(): void
    {
        $readModel = $this->readModel();
        $readModel->expects($this->once())->method('reset');

        (new BankCountProjector($readModel))->reset();
    }

    private function readModel(): BankCountReadModel&MockObject
    {
        return $this->createMock(BankCountReadModel::class);
    }
}
