<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Override;

/**
 * In-memory {@see BankAccountRepository} returning a fixed associated-account count,
 * so a test can drive both the in-use and the free branches of the deleter.
 *
 * When `$recount` is given, the second and later calls return it instead — this lets a
 * test drive the TOCTOU branch (first count 0, FK violation on flush, recount > 0).
 *
 * @internal
 */
final class FakeBankAccountRepository implements BankAccountRepository
{
    private bool $firstCallDone = false;

    public function __construct(
        private readonly int $count,
        private readonly ?int $recount = null,
    ) {
    }

    #[Override]
    public function countByBankId(string $bankId): int
    {
        if (!$this->firstCallDone) {
            $this->firstCallDone = true;

            return $this->count;
        }

        return $this->recount ?? $this->count;
    }
}
