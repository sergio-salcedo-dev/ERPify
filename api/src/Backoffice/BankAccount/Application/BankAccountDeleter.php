<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotClosedException;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;

final readonly class BankAccountDeleter
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private BankAccountFinder $bankAccountFinder,
        private EventBus $eventBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The deletion precondition (account must be CLOSED) is a state invariant owned by the aggregate:
     * {@see \Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount::delete()} throws before any event
     * is recorded, so a rejected delete mutates nothing and dispatches nothing. No referential-integrity
     * TOCTOU dance is needed here (unlike the bank's FK-guarded delete) — the guard is on the account's
     * own state, not on a child relation.
     *
     * @throws InvalidUuidException          when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankAccountNotFoundException
     * @throws BankAccountNotClosedException when the account is not CLOSED (409)
     */
    public function delete(string $id): void
    {
        $account = $this->bankAccountFinder->find($id);

        $account->delete();

        // Pull events before removal so the aggregate is still intact when captured.
        $domainEvents = $account->pullDomainEvents();

        // remove + publish in one transaction (closes the dual-write window).
        // See docs/adr/event-store-and-projections.md.
        $this->entityManager->wrapInTransaction(function () use ($account, $domainEvents): void {
            $this->bankAccountRepository->remove($account);
            $this->eventBus->publish(...$domainEvents);
        });
    }
}
