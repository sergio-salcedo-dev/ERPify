<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use LogicException;
use Override;
use RuntimeException;
use Throwable;

/**
 * In-memory {@see BankAccountRepository} returning a fixed associated-account count,
 * so a test can drive both the in-use and the free branches of the deleter.
 *
 * When `$recount` is given, the second and later calls return it instead — this lets a
 * test drive the TOCTOU branch (first count 0, FK violation on flush, recount > 0). A recount that
 * FAILS is its own scenario ({@see self::withFailingRecount()}): it runs right after a boundary that
 * just collapsed, which is exactly when a connection is least likely to answer.
 *
 * Only the referential-integrity count is exercised by {@see \Erpify\Backoffice\Bank\Application\BankDeleter};
 * the aggregate write surface is irrelevant here and throws if ever touched.
 *
 * @internal
 */
final class InMemoryBankAccountRepository implements BankAccountRepository
{
    private bool $firstCallDone = false;

    public function __construct(
        private readonly int $count,
        private readonly ?int $recount = null,
        private readonly ?Throwable $recountFailure = null,
    ) {
    }

    /** The guard passes, the flush is rejected, and the recount that follows cannot reach the database. */
    public static function withFailingRecount(int $count): self
    {
        return new self($count, recountFailure: new RuntimeException('the connection is gone'));
    }

    #[Override]
    public function save(BankAccount $account): void
    {
        throw new LogicException('BankDeleter never writes accounts.');
    }

    #[Override]
    public function remove(BankAccount $account): void
    {
        throw new LogicException('BankDeleter never writes accounts.');
    }

    #[Override]
    public function findById(string $id): ?BankAccount
    {
        throw new LogicException('BankDeleter never reads single accounts.');
    }

    #[Override]
    public function countByBankId(string $bankId): int
    {
        if (!$this->firstCallDone) {
            $this->firstCallDone = true;

            return $this->count;
        }

        if ($this->recountFailure instanceof Throwable) {
            throw $this->recountFailure;
        }

        return $this->recount ?? $this->count;
    }
}
