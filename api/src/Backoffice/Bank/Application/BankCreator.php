<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Application\Validator;

final readonly class BankCreator
{
    public function __construct(
        private BankRepository $bankRepository,
        private EventBus $eventBus,
        private Validator $validator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(CreateBankCommand $bankCommand): Bank
    {
        $newBank = Bank::create(
            Uuid::generate(),
            $bankCommand->name,
            $bankCommand->shortName,
        );

        $this->validator->ensure($newBank);

        // save + publish in one transaction so the aggregate, its event_store rows and the outbox
        // commit atomically (closes the dual-write window). See docs/adr/event-store-and-projections.md.
        $this->entityManager->wrapInTransaction(function () use ($newBank): void {
            $this->bankRepository->save($newBank);
            $this->eventBus->publish(...$newBank->pullDomainEvents());
        });

        return $newBank;
    }
}
