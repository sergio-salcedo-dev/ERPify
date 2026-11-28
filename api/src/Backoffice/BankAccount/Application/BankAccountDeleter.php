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

final readonly class BankAccountDeleter
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private BankAccountFinder $bankAccountFinder,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private ReleaseBankAccountEvidence $releaseEvidence,
    ) {
    }

    /**
     * The deletion precondition (account must be CLOSED) is a state invariant owned by the aggregate:
     * {@see BankAccount::delete()} throws before any event is recorded, so a rejected delete mutates nothing,
     * dispatches nothing and opens no transaction.
     *
     * The account's evidence links reference it through a foreign key, so they go in the same transaction, after the
     * account's row is locked — the lock an attach takes — and only when every linked document is erased; otherwise
     * the whole delete is refused and nothing changes.
     *
     * @throws InvalidUuidException          when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankAccountNotFoundException
     * @throws BankAccountNotClosedException when the account is not CLOSED (409)
     * @throws BankAccountHasLiveEvidence    when a document attached to it is not erased (409)
     */
    public function delete(string $id): void
    {
        $account = $this->bankAccountFinder->find($id);

        $account->delete();

        // Pull events before removal so the aggregate is still intact when captured.
        $domainEvents = $account->pullDomainEvents();

        // remove + publish in one transaction (closes the dual-write window).
        // See docs/adr/event-store-and-projections.md.
        $this->transactionManager->transactional(function () use ($id, $account, $domainEvents): void {
            if (!$this->bankAccountRepository->findByIdForUpdate($id) instanceof BankAccount) {
                throw BankAccountNotFoundException::withId($id);
            }

            $this->releaseEvidence->releaseAll($id);
            $this->bankAccountRepository->remove($account);
            $this->eventBus->publish(...$domainEvents);
        });
    }
}
