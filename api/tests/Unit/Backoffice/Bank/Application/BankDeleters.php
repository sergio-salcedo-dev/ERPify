<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * A {@see BankDeleter} over in-memory collaborators, so a case names only what it is about — the bank, how many
 * accounts
 * reference it, the bus and the unit of work — and not the four doubles around them.
 *
 * @internal
 */
final class BankDeleters
{
    private function __construct()
    {
    }

    public static function over(
        InMemoryBankRepository $bankRepository,
        int $accountCount,
        EventBus $eventBus,
        TransactionManager $transactions,
        ?int $recount = null,
    ): BankDeleter {
        return new BankDeleter(
            $bankRepository,
            new BankFinder($bankRepository),
            new InMemoryBankAccountRepository($accountCount, $recount),
            $eventBus,
            $transactions,
            BankImageAttachments::over($eventBus, $transactions),
        );
    }
}
