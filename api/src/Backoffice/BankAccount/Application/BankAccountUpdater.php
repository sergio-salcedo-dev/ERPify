<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\Command\UpdateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Validation\Application\Validator;

final readonly class BankAccountUpdater
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private BankAccountFinder $bankAccountFinder,
        private EventBus $eventBus,
        private Validator $validator,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws InvalidUuidException         when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankAccountNotFoundException
     */
    public function update(string $id, UpdateBankAccountCommand $command): BankAccount
    {
        $account = $this->bankAccountFinder->find($id);

        $account->update(
            $command->holderName,
            $command->iban,
            $command->bic,
            $command->alias,
            $command->currency,
        );

        $this->validator->ensure($account);

        // save + publish in one transaction so the aggregate, its event_store rows and the outbox
        // commit atomically (closes the dual-write window). See docs/adr/event-store-and-projections.md.
        $this->transactionManager->transactional(function () use ($account): void {
            $this->bankAccountRepository->save($account);
            $this->eventBus->publish(...$account->pullDomainEvents());
        });

        return $account;
    }
}
