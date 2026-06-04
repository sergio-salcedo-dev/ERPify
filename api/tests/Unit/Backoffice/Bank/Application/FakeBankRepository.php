<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Override;

/**
 * In-memory {@see BankRepository} that records every mutation, so a test can
 * assert {@see \Erpify\Backoffice\Bank\Application\BankDeleter} mutates
 * nothing when the bank is still in use.
 *
 * When `$removeFailure` is given, `remove()` throws it instead of completing — the DB
 * rejected the DELETE, so `$removeCalled` stays false.
 *
 * @internal
 */
final class FakeBankRepository implements BankRepository
{
    public bool $removeCalled = false;

    /** @var list<Bank> */
    public array $saved = [];

    public function __construct(
        private readonly ?Bank $bank = null,
        private readonly ?ForeignKeyConstraintViolationException $removeFailure = null,
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
        if ($this->removeFailure instanceof ForeignKeyConstraintViolationException) {
            throw $this->removeFailure;
        }

        $this->removeCalled = true;
    }

    #[Override]
    public function findById(string $id): ?Bank
    {
        return $this->bank;
    }
}
