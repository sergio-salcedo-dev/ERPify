<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankExistenceChecker;
use Erpify\Backoffice\BankAccount\Application\Command\CreateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Application\Validator;

final readonly class BankAccountCreator
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private BankExistenceChecker $bankExistence,
        private EventBus $eventBus,
        private Validator $validator,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * Guards the owning bank's existence first (a malformed bank id is rejected as 422 by the command's
     * #[Assert\Uuid] at mapping time; a well-formed-but-absent bank is a clean 404 here, before any
     * INSERT touches the FK). The server mints the account id (UUID v7); IBAN uniqueness is enforced by
     * the entity's #[UniqueEntity] via Validator::ensure() (422).
     *
     * @throws InvalidUuidException when the bank id is not a well-formed UUID
     */
    public function create(CreateBankAccountCommand $command): BankAccount
    {
        $this->bankExistence->ensureExists($command->bankId);

        $account = BankAccount::create(
            Uuid::generate(),
            $command->bankId,
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
