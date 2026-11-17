<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Images\Application\ImageAttachment;
use Erpify\Shared\Images\Domain\ImageId;
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
        private ImageAttachment $imageAttachment,
    ) {
    }

    /**
     * Fails fast on the bank and its accounts outside any lock, then does the whole removal in ONE transaction that
     * starts by re-loading the bank `FOR UPDATE`. Everything that depends on what the bank holds is read from that
     * locked instance — the events it records and the logo it points at — so a concurrent replace of the logo
     * either committed before the lock was granted (and its id is the one released here) or waits behind this
     * transaction and finds the bank gone. The release is queued in the same transaction as the removal, so the row
     * and the request to destroy the logo commit or roll back together.
     *
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

        $bankId = (string) $bank->getId();

        try {
            // remove + publish in one transaction (closes the dual-write window).
            // See docs/adr/event-store-and-projections.md.
            $this->transactionManager->transactional(function () use ($bankId): void {
                $locked = $this->bankFinder->findForUpdate($bankId);

                $locked->delete();

                // Pull events before removal so the aggregate is still intact when captured.
                $domainEvents = $locked->pullDomainEvents();
                $logoImageId = $locked->getLogoImageId();

                $this->bankRepository->remove($locked);

                if (null !== $logoImageId) {
                    $this->imageAttachment->release(ImageId::fromString($logoImageId));
                }

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
