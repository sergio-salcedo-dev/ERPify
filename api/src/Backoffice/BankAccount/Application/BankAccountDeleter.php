<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
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
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private ReleaseBankAccountEvidence $releaseEvidence,
    ) {
    }

    /**
     * Everything happens under the account's row lock — the lock an attach and a detach take — and on the instance that
     * lock loaded: the deletion precondition (`CLOSED`) is a state invariant owned by the aggregate
     * ({@see BankAccount::delete()} throws before any event is recorded), so it is judged against the row as it stands
     * once nobody else can change it. A refused delete mutates nothing and dispatches nothing.
     *
     * The account's evidence links reference it through a foreign key, so they go in the same transaction, and only
     * when every linked document is erased; otherwise the whole delete is refused and nothing changes.
     *
     * @throws InvalidUuidException          when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankAccountNotFoundException
     * @throws BankAccountNotClosedException when the account is not CLOSED (409)
     * @throws BankAccountHasLiveEvidence    when a document attached to it is not erased (409)
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

            $this->releaseEvidence->releaseAll($id);
            $this->bankAccountRepository->remove($account);
            $this->eventBus->publish(...$domainEvents);
        });
    }
}
