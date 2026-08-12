<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Command\UpdateBankCommand;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Validation\Application\Validator;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class BankUpdater
{
    public function __construct(
        private BankRepository $bankRepository,
        private BankFinder $bankFinder,
        private EventBus $eventBus,
        private Validator $validator,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws InvalidUuidException      when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankNotFoundException
     * @throws ValidationFailedException when the updated entity fails validation (422)
     */
    public function update(string $id, UpdateBankCommand $bankCommand): Bank
    {
        $bank = $this->bankFinder->find($id);

        $bank->rename($bankCommand->name, $bankCommand->shortName);

        $this->validator->ensure($bank);

        // save + publish in one transaction so the aggregate, its event_store rows and the outbox
        // commit atomically (closes the dual-write window). See docs/adr/event-store-and-projections.md.
        $this->transactionManager->transactional(function () use ($bank): void {
            $this->bankRepository->save($bank);
            $this->eventBus->publish(...$bank->pullDomainEvents());
        });

        return $bank;
    }
}
