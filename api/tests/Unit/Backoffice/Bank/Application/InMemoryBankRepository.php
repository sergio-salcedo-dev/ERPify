<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Override;

/**
 * In-memory {@see BankRepository} that records every mutation, so a test can
 * assert {@see \Erpify\Backoffice\Bank\Application\BankDeleter} mutates
 * nothing when the bank is still in use.
 *
 * @internal
 */
final class InMemoryBankRepository implements BankRepository
{
    public bool $removeCalled = false;

    /** @var list<Bank> */
    public array $saved = [];

    /** How many times a caller asked for the bank with its row lock. */
    public int $lockedLoads = 0;

    /** The bank is gone by the time the lock is asked for, whatever {@see findById()} answered before. */
    public bool $vanishesBeforeTheLock = false;

    public function __construct(
        private readonly ?Bank $bank = null,
    ) {
    }

    #[Override]
    public function save(Bank $bank): void
    {
        $this->saved[] = $bank;
    }

    #[Override]
    public function remove(Bank $bank): void
    {
        $this->removeCalled = true;
    }

    #[Override]
    public function findById(string $id): ?Bank
    {
        return $this->bank;
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?Bank
    {
        ++$this->lockedLoads;

        return $this->vanishesBeforeTheLock ? null : $this->bank;
    }
}
