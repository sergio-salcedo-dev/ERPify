<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Doctrine\DBAL\Exception\DriverException;
use Erpify\Backoffice\Bank\Application\BankDeleter;
use Erpify\Backoffice\Bank\Application\BankLogoHolder;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\Backoffice\Bank\Fixtures\BankLogoRig;
use Erpify\Tests\Functional\Backoffice\Bank\Fixtures\BeforeNthUnitTransactionManager;
use Erpify\Tests\Functional\Backoffice\Bank\Fixtures\HookedBankRepository;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Functional\Shared\Images\Fixtures\FailingAfterWorkTransactionManager;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A bank as the owner of its logo, over the REAL substrates: the ORM holder, the transaction manager, Postgres, the
 * publish guard and the Doctrine transport. What the unit tests prove in order, this proves in effect, and above all
 * what only a second connection can show — what committed, what a rollback took back, what a lock made wait.
 *
 * **Races are driven by placing one writer's whole transaction in the window between two steps of the other**, which
 * needs no second process: a hook before the attach unit of a replace, or after the unlocked read of a delete. The
 * outcome that follows is the one the bank row lock decides, and it is read from the persisted owner row and from the
 * ids queued for release, never from a realtime row.
 *
 * Every case commits, so the fixtures are removed by hand in `tearDown()` however the case ends.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(BankLogoHolder::class)]
#[CoversClass(BankDeleter::class)]
final class BankLogoFunctionalTest extends KernelTestCase
{
    use BankLogoRig;
    use ResolvesContainerServices;

    #[Override]
    protected function setUp(): void
    {
        $this->bootRig();
    }

    protected function tearDown(): void
    {
        $this->tearDownRig();

        parent::tearDown();
    }

    /**
     * The attach unit fails after its work: the bank and the queued release of the old id are rolled back, Doctrine
     * closes the manager, the transaction manager resets it, and the compensation then reads the bank through the
     * ORM holder on the NEW manager — the path a double cannot take. The new id is released once and the old one is
     * not.
     */
    #[Test]
    public function aFailureAfterTheMintRollsBackAndTheOrmHolderStillReleasesTheNewIdAfterTheManagerReset(): void
    {
        $old = Uuid::generate();
        $bankId = $this->seedBank($old);
        $unitOfWorkBefore = $this->entityManager->getUnitOfWork();
        $manager = new FailingAfterWorkTransactionManager($this->transactions(), 2);

        try {
            $this->attachment($manager)->replace($this->holder(), $bankId, $this->png());
            $this->fail('the failure after the work must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('after its work', $runtimeException->getMessage());
        }

        $this->assertNotSame($unitOfWorkBefore, $this->entityManager->getUnitOfWork(), 'the manager was really reset');
        $this->assertTrue($this->entityManager->isOpen());

        $minted = $this->mintedImageIds();
        $this->assertCount(1, $minted);
        $this->assertSame($old, $this->heldBy($bankId), 'the bank row is as it was');
        $this->assertSame(0, $this->queuedFor($old), 'the old id was never released');
        $this->assertSame(1, $this->queuedFor($minted[0]), 'the new id is, once');
    }

    /**
     * Delete takes the bank lock first: the replace had minted its image but not yet attached it, so the attach unit
     * finds the bank gone — the bank's own 404 — and the compensation releases the NEW id. The old one was released by
     * the delete that won.
     */
    #[Test]
    public function aReplaceThatLosesToADeleteIsNotFoundAndReleasesItsNewIdWhileTheDeleteReleasesTheOldOne(): void
    {
        $old = Uuid::generate();
        $bankId = $this->seedBank($old);
        $manager = new BeforeNthUnitTransactionManager($this->transactions(), 2, function () use ($bankId): void {
            $this->deleter()->delete($bankId);
        });

        try {
            $this->attachment($manager)->replace($this->holder(), $bankId, $this->png());
            $this->fail('the bank was deleted first, so the replace is a 404');
        } catch (BankNotFoundException $bankNotFoundException) {
            $this->assertSame('bank-not-found', $bankNotFoundException->type());
        }

        $minted = $this->mintedImageIds();
        $this->assertCount(1, $minted);
        $this->assertNull($this->heldBy($bankId));
        $this->assertSame(0, $this->bankRows($bankId), 'the bank is gone');
        $this->assertSame(1, $this->queuedFor($old), 'the delete released what the bank held');
        $this->assertSame(1, $this->queuedFor($minted[0]), 'the lost replace released its own image');
        $this->assertSame(2, $this->queuedTotal());
    }

    /**
     * The DELETE half of "replace takes the bank lock first", with a genuinely stale managed copy: another connection
     * moves the logo between the delete's unlocked read and its locked reload, so only a refreshing, locking read sees
     * the id the row holds NOW. The writer is a raw `UPDATE`, so it produces no release of its own — the replace's half
     * (one release per id, in this order) is {@see aDeleteThatFollowsARealReplaceReleasesEachIdOnce()}.
     */
    #[Test]
    public function aDeleteReleasesTheIdTheLockedRowHoldsNotTheOneItsFirstReadSaw(): void
    {
        $seen = Uuid::generate();
        $replacement = Uuid::generate();
        $bankId = $this->seedBank($seen);
        $repository = new HookedBankRepository(
            $this->service(BankRepository::class),
            function () use ($bankId, $replacement): void {
                $this->outside->executeStatement(
                    'UPDATE bank SET logo_image_id = ? WHERE id = ?',
                    [$replacement, $bankId],
                );
            },
        );

        $this->deleter($repository)->delete($bankId);

        $this->assertSame(0, $this->bankRows($bankId));
        $this->assertSame(1, $this->queuedFor($replacement), 'the id the locked row held is released');
        $this->assertSame(0, $this->queuedFor($seen), 'the id the unlocked read saw is not');
        $this->assertSame(1, $this->queuedTotal());
    }

    /**
     * Replace first, for real: a whole `ImageAttachment::replace` commits between the delete's unlocked read and its
     * locked reload. The replace releases the id the bank held, the delete then releases the id the replace attached,
     * and each is queued exactly once — nothing is released twice and the held id is never destroyed under the bank.
     */
    #[Test]
    public function aDeleteThatFollowsARealReplaceReleasesEachIdOnce(): void
    {
        $original = Uuid::generate();
        $bankId = $this->seedBank($original);
        $attached = null;
        $repository = new HookedBankRepository(
            $this->service(BankRepository::class),
            function () use ($bankId, &$attached): void {
                $attached = $this->attachment()->replace($this->holder(), $bankId, $this->png())->toString();
            },
        );

        $this->deleter($repository)->delete($bankId);

        $this->assertIsString($attached, 'the replace ran in the window');
        $this->assertSame(0, $this->bankRows($bankId));
        $this->assertSame(1, $this->queuedFor($original), 'the replace released what the bank held');
        $this->assertSame(1, $this->queuedFor($attached), 'the delete released what the replace attached');
        $this->assertSame(2, $this->queuedTotal());
    }

    /**
     * Two replaces of one bank, the second whole inside the window of the first: one live image, one release per
     * replaced id. The loser of the lock attaches over the winner's id and releases it; nobody releases the id the
     * bank ends up holding.
     */
    #[Test]
    public function twoReplacesRacingLeaveOneLiveImageAndOneReleasePerReplacedId(): void
    {
        $old = Uuid::generate();
        $bankId = $this->seedBank($old);
        $winner = null;
        $racer = function () use ($bankId, &$winner): void {
            $winner = $this->attachment()->replace($this->holder(), $bankId, $this->png())->toString();
        };
        $manager = new BeforeNthUnitTransactionManager($this->transactions(), 2, $racer);

        $last = $this->attachment($manager)->replace($this->holder(), $bankId, $this->png())->toString();

        $this->assertIsString($winner);
        $this->assertNotSame($winner, $last);
        $this->assertSame($last, $this->heldBy($bankId), 'one live image: the one the bank holds');
        $this->assertSame(1, $this->queuedFor($old), 'the original was released once');
        $this->assertSame(1, $this->queuedFor($winner), 'the winner was replaced, so released once');
        $this->assertSame(0, $this->queuedFor($last), 'the held one is not released');
        $this->assertSame(2, $this->queuedTotal());
    }

    /**
     * The lock the whole protocol rests on is a real row lock: while one transaction holds the bank through
     * `findByIdForUpdate()`, a second connection cannot take it, and once that transaction ends it can. The races
     * above run one writer inside the other's window on one connection, so they prove the refresh, never the lock.
     */
    #[Test]
    public function theLockingReadHoldsTheBankRowAgainstASecondConnectionUntilItsTransactionEnds(): void
    {
        $bankId = $this->seedBank(null);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->assertInstanceOf(Bank::class, $this->service(BankRepository::class)->findByIdForUpdate($bankId));
            $this->assertSame('55P03', $this->lockStateFromOutside($bankId), 'the locking read took no row lock');
        } finally {
            $connection->rollBack();
        }

        $this->assertNull($this->lockStateFromOutside($bankId), 'the row stayed locked after the transaction ended');
    }

    /**
     * The locking read reaches the database through a refresh: a bank already managed with one logo, changed by another
     * connection, is removed from under the id the database holds, not the stale one in the identity map.
     */
    #[Test]
    public function aLockedReadSeesWhatAnotherConnectionCommittedNotTheManagedCopy(): void
    {
        $stale = Uuid::generate();
        $current = Uuid::generate();
        $bankId = $this->seedBank($stale);
        $managed = $this->entityManager->find(Bank::class, $bankId);
        $this->assertInstanceOf(Bank::class, $managed);
        $this->assertSame($stale, $managed->getLogoImageId());
        $this->outside->executeStatement('UPDATE bank SET logo_image_id = ? WHERE id = ?', [$current, $bankId]);

        $this->attachment()->remove($this->holder(), $bankId);

        $this->assertNull($this->heldBy($bankId));
        $this->assertSame(1, $this->queuedFor($current));
        $this->assertSame(0, $this->queuedFor($stale));
    }

    #[Test]
    public function deletingABankQueuesOneReleaseOfItsLogoAndABankWithoutOneQueuesNothing(): void
    {
        $logo = Uuid::generate();
        $withLogo = $this->seedBank($logo);
        $without = $this->seedBank(null);

        $this->deleter()->delete($withLogo);
        $this->assertSame(0, $this->bankRows($withLogo));
        $this->assertSame(1, $this->queuedFor($logo));

        $this->deleter()->delete($without);
        $this->assertSame(0, $this->bankRows($without));
        $this->assertSame(1, $this->queuedTotal(), 'a bank without a logo queues no release');
    }

    #[Test]
    public function removingARemovedLogoIsIdempotentAndQueuesOnce(): void
    {
        $logo = Uuid::generate();
        $bankId = $this->seedBank($logo);
        $attachment = $this->attachment();
        $holder = $this->holder();

        $attachment->remove($holder, $bankId);
        $attachment->remove($holder, $bankId);

        $this->assertNull($this->heldBy($bankId));
        $this->assertSame(1, $this->queuedFor($logo));
        $this->assertSame(1, $this->queuedTotal());
    }

    /**
     * @return string|null the SQLSTATE a non-waiting lock attempt from the second connection failed with, or null
     *                     when it took the lock
     */
    private function lockStateFromOutside(string $bankId): ?string
    {
        try {
            $this->outside->fetchOne('SELECT 1 FROM bank WHERE id = ? FOR UPDATE NOWAIT', [$bankId]);
        } catch (DriverException $exception) {
            return $exception->getSQLState();
        }

        return null;
    }
}
