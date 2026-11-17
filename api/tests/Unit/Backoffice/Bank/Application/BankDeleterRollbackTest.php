<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RollbackingTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\TransactionalEventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A delete the foreign key refuses AFTER its work: the removal, the deleted event and the release of the logo were all
 * issued inside the unit of work, and what the caller is left with is what the rollback left.
 *
 * @internal
 */
#[CoversClass(BankDeleter::class)]
final class BankDeleterRollbackTest extends TestCase
{
    private const string LOGO = '0190b1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    /**
     * The foreign key refuses the delete AFTER the work: the removal, the deleted event and the release were all
     * issued, and what survives is what the rollback left — nothing queued — while the caller is told the bank is in
     * use, with the accounts recounted.
     */
    public function testARolledBackDeleteLeavesNoEventAndNoReleaseBehindAndRecountsTheAccounts(): void
    {
        $transactions = new RollbackingTransactionManager();
        $eventBus = new TransactionalEventBus($transactions);
        $bankDeleter = BankDeleters::over(
            new InMemoryBankRepository(BankMother::withLogo(self::LOGO)),
            0,
            $eventBus,
            new RollbackAfterWorkTransactionManager($transactions),
            recount: 2,
        );

        try {
            $bankDeleter->delete(BankMother::DEFAULT_ID);
            $this->fail('Expected BankInUseException to be thrown.');
        } catch (BankInUseException $bankInUseException) {
            $expected = ['bankId' => BankMother::DEFAULT_ID, 'accountCount' => 2];
            $this->assertSame($expected, $bankInUseException->context());
        }

        $this->assertSame([], $eventBus->queuedIds(), 'no release and no deleted event outlives the rollback');
        $this->assertSame(1, $transactions->started);
    }
}
