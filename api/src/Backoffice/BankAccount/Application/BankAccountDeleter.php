<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\Evidence\LiveEvidenceGuard;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountHasLiveEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotClosedException;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

final readonly class BankAccountDeleter
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private LiveEvidenceGuard $liveEvidence,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * The deletion precondition (account must be CLOSED) is a state invariant owned by the aggregate:
     * {@see \Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount::delete()} throws before any event
     * is recorded, so a rejected delete mutates nothing and dispatches nothing.
     *
     * The account is locked `FOR UPDATE` before its evidence links are read and released: an attach takes the same
     * lock, so no link can land between the guard's read and the removal, and the evidence foreign key never has to
     * refuse the `DELETE`. A link whose document is not erased refuses the whole deletion (409), and nothing is
     * removed.
     *
     * @throws InvalidUuidException          when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankAccountNotFoundException
     * @throws BankAccountNotClosedException when the account is not CLOSED (409)
     * @throws BankAccountHasLiveEvidence    when a linked document is not erased (409)
     */
    public function delete(string $id): void
    {
        Uuid::ensure($id);

        // remove + publish in one transaction (closes the dual-write window).
        // See docs/adr/event-store-and-projections.md.
        $this->transactionManager->transactional(function () use ($id): void {
            $account = $this->bankAccountRepository->findByIdForUpdate($id)
                ?? throw BankAccountNotFoundException::withId($id);

            $account->delete();

            // Pull events before removal so the aggregate is still intact when captured.
            $domainEvents = $account->pullDomainEvents();

            $this->liveEvidence->releaseAll($id);
            $this->bankAccountRepository->remove($account);
            $this->eventBus->publish(...$domainEvents);
        });
    }
}
