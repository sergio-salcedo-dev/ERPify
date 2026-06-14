<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankExistenceChecker;
use Override;
use Throwable;

/**
 * In-memory {@see BankExistenceChecker}: passes silently, or throws a preset exception (a 400/404) so
 * a test can drive the searcher's reject branches.
 *
 * @internal
 */
final class InMemoryBankExistenceChecker implements BankExistenceChecker
{
    public bool $called = false;

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    #[Override]
    public function ensureExists(string $bankId): void
    {
        $this->called = true;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
