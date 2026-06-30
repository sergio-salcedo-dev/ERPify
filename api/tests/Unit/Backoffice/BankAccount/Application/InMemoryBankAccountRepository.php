<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Override;

/**
 * In-memory {@see BankAccountRepository} that records every mutation, so a test can assert which
 * account a use case saves/removes and that a rejected write mutates nothing. `findById` returns the
 * single preset account (or null), mirroring the find→mutate→persist flow of the write use cases.
 *
 * @internal
 */
final class InMemoryBankAccountRepository implements BankAccountRepository
{
    public bool $removeCalled = false;

    /** @var list<BankAccount> */
    public array $saved = [];

    public function __construct(
        private readonly ?BankAccount $account = null,
        private readonly int $count = 0,
    ) {
    }

    #[Override]
    public function save(BankAccount $account): void
    {
        $this->saved[] = $account;
    }

    #[Override]
    public function remove(BankAccount $account): void
    {
        $this->removeCalled = true;
    }

    #[Override]
    public function findById(string $id): ?BankAccount
    {
        return $this->account;
    }

    #[Override]
    public function countByBankId(string $bankId): int
    {
        return $this->count;
    }
}
