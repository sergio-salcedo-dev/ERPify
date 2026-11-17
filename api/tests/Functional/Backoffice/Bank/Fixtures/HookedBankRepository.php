<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Fixtures;

use Closure;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Override;

/**
 * The real {@see BankRepository} with a hook that fires once, right after the first UNLOCKED read answers — the
 * window between a use case's fast-fail load and the transaction that acts on it, where a competing writer commits.
 *
 * @internal
 */
final class HookedBankRepository implements BankRepository
{
    private bool $fired = false;

    /**
     * @param Closure(): void $afterTheUnlockedRead
     */
    public function __construct(private readonly BankRepository $inner, private readonly Closure $afterTheUnlockedRead)
    {
    }

    #[Override]
    public function save(Bank $bank): void
    {
        $this->inner->save($bank);
    }

    #[Override]
    public function remove(Bank $bank): void
    {
        $this->inner->remove($bank);
    }

    #[Override]
    public function findById(string $id): ?Bank
    {
        $bank = $this->inner->findById($id);

        if (!$this->fired) {
            $this->fired = true;
            ($this->afterTheUnlockedRead)();
        }

        return $bank;
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?Bank
    {
        return $this->inner->findByIdForUpdate($id);
    }
}
