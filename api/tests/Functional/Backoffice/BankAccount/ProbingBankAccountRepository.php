<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Closure;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Override;

/**
 * The real {@see BankAccountRepository}, with a hook in the window between the aggregate read and whatever
 * the caller does with the answer.
 *
 * The hook fires after BOTH read methods on purpose. Which one a use case calls is precisely the property
 * under test, so a probe wired to only one of them would move with the code and stop observing the window
 * the moment the call site changed — the contender would simply never run, and the assertions would be
 * about nothing.
 *
 * It wraps the production adapter rather than replacing it, so the read that takes the lock is the real
 * statement and the contender meets a genuine row lock.
 *
 * @internal
 */
final readonly class ProbingBankAccountRepository implements BankAccountRepository
{
    /**
     * @param Closure(): void $afterTheAggregateRead runs once the read has answered, before the caller acts
     */
    public function __construct(
        private BankAccountRepository $inner,
        private Closure $afterTheAggregateRead,
    ) {
    }

    #[Override]
    public function save(BankAccount $account): void
    {
        $this->inner->save($account);
    }

    #[Override]
    public function remove(BankAccount $account): void
    {
        $this->inner->remove($account);
    }

    #[Override]
    public function findById(string $id): ?BankAccount
    {
        $account = $this->inner->findById($id);

        ($this->afterTheAggregateRead)();

        return $account;
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?BankAccount
    {
        $account = $this->inner->findByIdForUpdate($id);

        // After the answer, never before: the lock this stands in for is taken by the statement above, so a
        // contender running ahead of it would find the row free and prove nothing about the lock at all.
        ($this->afterTheAggregateRead)();

        return $account;
    }

    #[Override]
    public function countByBankId(string $bankId): int
    {
        return $this->inner->countByBankId($bankId);
    }
}
