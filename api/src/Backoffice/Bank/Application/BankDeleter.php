<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;

final readonly class BankDeleter
{
    public function __construct(
        private BankRepository $bankRepository,
        private BankFinder $bankFinder,
        private BankAccountRepository $bankAccountRepository,
        private EventBus $eventBus,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * @throws InvalidUuidException  when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankNotFoundException
     * @throws BankInUseException
     */
    public function delete(string $id): void
    {
        $bank = $this->bankFinder->find($id);

        $accountCount = $this->bankAccountRepository->countByBankId($id);

        if ($accountCount > 0) {
            throw BankInUseException::withAccountCount($id, $accountCount);
        }

        $bank->delete();

        // Pull events before removal so the aggregate is still intact when captured.
        $domainEvents = $bank->pullDomainEvents();

        try {
            // remove + publish in one transaction (closes the dual-write window).
            // See docs/adr/event-store-and-projections.md.
            $this->entityManager->wrapInTransaction(function () use ($bank, $domainEvents): void {
                $this->bankRepository->remove($bank);
                $this->eventBus->publish(...$domainEvents);
            });
        } catch (ForeignKeyConstraintViolationException) {
            // TOCTOU: an account was inserted between the count guard above and this flush, so the
            // bank_account FK (NOT DEFERRABLE) rejected the DELETE. wrapInTransaction has rolled back
            // AND closed the EntityManager, so the recount needs a fresh one — resetManager() re-opens
            // the shared manager the count repository reads through. The FK violation proves >= 1
            // account existed at flush time; max(1, …) covers the reverse double-race where the
            // recount also lands on zero.
            $this->managerRegistry->resetManager();

            $recount = $this->bankAccountRepository->countByBankId($id);

            throw BankInUseException::withAccountCount($id, \max(1, $recount));
        }
    }
}
