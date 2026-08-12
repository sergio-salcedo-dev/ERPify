<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Application\Validator;

final readonly class BankCreator
{
    public function __construct(
        private BankRepository $bankRepository,
        private EventBus $eventBus,
        private Validator $validator,
        private TransactionManager $transactionManager,
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
        $this->transactionManager->transactional(function () use ($newBank): void {
            $this->bankRepository->save($newBank);
            $this->eventBus->publish(...$newBank->pullDomainEvents());
        });

        return $newBank;
    }
}
