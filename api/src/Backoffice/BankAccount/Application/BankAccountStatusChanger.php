<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\Command\ChangeBankAccountStatusCommand;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Validation\Application\Validator;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class BankAccountStatusChanger
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
     * Applies a lifecycle transition. A transition to the current status is a no-op (no event), so a
     * redundant PATCH is idempotent.
     *
     * @throws InvalidUuidException         when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankAccountNotFoundException
     * @throws ValidationFailedException    when the account fails validation (422)
     */
    public function change(string $id, ChangeBankAccountStatusCommand $command): BankAccount
    {
        $account = $this->bankAccountFinder->find($id);

        $account->changeStatus($command->status);

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
