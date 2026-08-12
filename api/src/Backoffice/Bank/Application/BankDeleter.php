<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Throwable;

final readonly class BankDeleter
{
    public function __construct(
        private BankRepository $bankRepository,
        private BankFinder $bankFinder,
        private BankAccountRepository $bankAccountRepository,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
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
            $this->transactionManager->transactional(function () use ($bank, $domainEvents): void {
                $this->bankRepository->remove($bank);
                $this->eventBus->publish(...$domainEvents);
            });
        } catch (ReferentialIntegrityViolation $referentialIntegrityViolation) {
            // TOCTOU: an account was inserted between the count guard above and this flush, so the
            // bank_account FK (NOT DEFERRABLE) rejected the DELETE. The generic conflict is turned into
            // the one this use case can name — the violation proves >= 1 row referenced this bank at flush
            // time, and max(1, …) covers the reverse double-race where the recount also lands on zero.
            //
            // The recount is best-effort by construction: it runs after a boundary that has just failed,
            // and letting it throw would replace a 409 the caller can act on with a 500. The violation is
            // kept as `previous` so the driver's SQLSTATE and constraint name survive into the debug chain
            // — without it a conflict raised by some other foreign key would be indistinguishable here.
            try {
                $recount = $this->bankAccountRepository->countByBankId($id);
            } catch (Throwable) {
                $recount = 0;
            }

            throw BankInUseException::withAccountCount($id, \max(1, $recount), $referentialIntegrityViolation);
        }
    }
}
