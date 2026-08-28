<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Subject erasure decides an aggregate's fate across two effects that cannot be replayed independently: the
 * live record goes, and the scope's data-encryption key is destroyed. Removing the record captures a final
 * change row whose PII is sealed under that key, so the capture must happen while the key is still live.
 *
 * An unlocked read leaves that ordering open to a second erasure of the same subject. Under `READ
 * COMMITTED` the competitor can commit its own delete and its own key tombstone between this transaction's
 * read and its flush, and the capture then meets a scope that no longer has a key — so the caller is told
 * the erasure failed, where the honest answer is that the subject was already erased. It fails safe
 * (nothing stays legible) and diagnoses wrongly, which is the worst combination for an operator running a
 * compliance obligation by hand.
 *
 * **What this drives is contention between two transactions, not a second call to the same use case.** The
 * erasure runs on the kernel's connection; the competitor is a second connection issuing the statements a
 * concurrent erasure issues, in the window {@see ProbingBankAccountRepository} opens. Sequencing it in one
 * process needs no `pcntl` — which this image does not carry — because a contender only has to be BLOCKED
 * while the first transaction holds the row: the lock makes the outcome deterministic rather than hopeful,
 * and `lock_timeout` is what turns "blocked" into an outcome instead of a hung suite.
 *
 * The competitor is raw SQL rather than a second `EraseBankAccountSubject`: what is under test is this
 * transaction's read, and a contender driven through the use case would drag its own locking into the
 * measurement.
 *
 * The fixture is COMMITTED, because a second connection has to see it, and it is removed by hand in
 * `tearDown()` however the test ends — including the trail rows and the keystore entry, so the shared
 * database is left exactly as it was found.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(EraseBankAccountSubject::class)]
final class BankAccountSubjectErasureRaceFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private ?Connection $outside = null;

    private string $bankId;

    private string $accountId;

    private string $scope;

    /** Whether the competitor's delete committed, read back on its own connection at the instant it ran. */
    private bool $contenderCommitted = false;

    /** Whether the competitor was refused the row lock instead, which is this erasure holding it. */
    private bool $contenderBlocked = false;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->bankId = Uuid::generate();
        $this->accountId = Uuid::generate();
        $this->scope = 'BankAccount:' . $this->accountId;
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->removeCommittedFixture();
        }

        parent::tearDown();
    }

    #[Test]
    public function aCompetingErasureIsRefusedTheSubjectRowWhileThisOneHoldsIt(): void
    {
        $this->seedCommittedSubject();

        // Anti-vacuity on the fixture itself: the competitor's key tombstone is the irreversible half of a
        // concurrent erasure, and it is a no-op against a subject that never had a key. Without this the
        // whole case degrades into "two deletes of one row" and stops describing the defect.
        $this->assertSame(1, $this->liveKeysForTheSubject(), 'the fixture has a live key to lose');

        $result = $this->erasureOvertakenByACompetingErasure()->execute($this->accountId);

        // Anti-vacuity: the competitor must have reached the window and done ONE of the two things. Neither
        // flag set means it never ran, and every assertion below would be about nothing.
        $this->assertNotSame(
            $this->contenderBlocked,
            $this->contenderCommitted,
            'the competitor neither committed its delete nor was refused the lock, so this case proves nothing',
        );

        $this->assertTrue(
            $this->contenderBlocked,
            'A competing erasure committed its own delete and key tombstone while this transaction was '
            . 'still deciding the same subject: the read took no lock, so the two never serialise. What '
            . 'follows is this transaction sealing its deletion capture under a scope that has no key left.',
        );
        $this->assertTrue($result->liveRecordErased, 'the erasure that held the lock removed the record');
        $this->assertTrue($result->keyDestroyed, 'the erasure that held the lock destroyed the scope key');

        // The other half of the sequence, on the connection that was refused: once the holder commits, the
        // competitor sees the absence rather than a row it must decide about.
        $this->assertSame(
            0,
            $this->deleteTheSubjectRowFromTheSecondConnection(),
            'the refused competitor retried after the commit and still found a row to delete',
        );
    }

    /**
     * The production use case, with the one seam the interleaving needs. Every other collaborator is real,
     * including the audit logger — the evidence row and the destroyed key are a pair, and substituting a
     * recording double would leave the shared database holding half of it.
     */
    private function erasureOvertakenByACompetingErasure(): EraseBankAccountSubject
    {
        return new EraseBankAccountSubject(
            new ProbingBankAccountRepository(
                $this->service(BankAccountRepository::class),
                $this->competingErasureFromASecondConnection(...),
            ),
            $this->service(EnvelopeEncryptor::class),
            $this->service(AuditLogger::class),
            $this->service(TransactionManager::class),
        );
    }

    /**
     * The statements a concurrent erasure issues, in the order it issues them: the row first, so a refusal
     * happens before anything irreversible. Reversing them would tombstone the key even when the lock holds.
     */
    private function competingErasureFromASecondConnection(): void
    {
        // Without a `lock_timeout` this method simply never returns once the erasure holds the row — which
        // is the serialisation working, reported as a hang nobody can assert on.
        $this->outsideConnection()->executeStatement("SET lock_timeout = '2s'");

        try {
            $deleted = $this->deleteTheSubjectRowFromTheSecondConnection();
        } catch (DriverException $driverException) {
            // 55P03 `lock_not_available`: the erasure holds the subject's row, which is the whole point.
            $this->assertSame(
                '55P03',
                $driverException->getSQLState(),
                'the competitor failed for a reason other than the lock',
            );
            $this->contenderBlocked = true;

            return;
        }

        $this->outsideConnection()->executeStatement(
            'UPDATE dek_keystore SET destroyed_at = NOW(), wrapped_dek = NULL '
            . 'WHERE encryption_scope_id = :scope AND destroyed_at IS NULL',
            ['scope' => $this->scope],
        );

        $this->contenderCommitted = $deleted > 0;
    }

    private function deleteTheSubjectRowFromTheSecondConnection(): int
    {
        return (int) $this->outsideConnection()->executeStatement(
            'DELETE FROM bank_account WHERE id = :id',
            ['id' => $this->accountId],
        );
    }

    /**
     * The seed runs inside an explicit transaction, and that is load-bearing rather than tidy: the change
     * capture returns early when no transaction is active, and Doctrine dispatches `onFlush` BEFORE opening
     * the one `flush()` owns. A bare `persist` + `flush` therefore captures nothing and mints no key, and
     * the competitor's tombstone below would silently match zero rows.
     */
    private function seedCommittedSubject(): void
    {
        $entityManager = $this->entityManager;
        $connection = $entityManager->getConnection();
        $token = \strtoupper(\substr(\str_replace('-', '', $this->bankId), 0, 8));

        $connection->beginTransaction();

        try {
            $entityManager->persist(Bank::create($this->bankId, 'Bank ' . $this->bankId, 'BNK' . $token));
            $entityManager->flush();

            $entityManager->persist(
                BankAccount::create($this->accountId, $this->bankId, 'Juan Pérez', $this->unusedIban()),
            );
            $entityManager->flush();
            $connection->commit();
        } catch (Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        // The aggregate must be re-hydrated by the locking read rather than served from the identity map,
        // or the lock is taken on a row whose state this process already holds.
        $entityManager->clear();
    }

    private function liveKeysForTheSubject(): int
    {
        $count = $this->outsideConnection()->fetchOne(
            'SELECT COUNT(*) FROM dek_keystore WHERE encryption_scope_id = :scope AND destroyed_at IS NULL',
            ['scope' => $this->scope],
        );

        // COUNT(*) is scalar by construction; asserting it rather than casting keeps a driver returning
        // something else a failure of this test instead of a silent zero.
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * A distinct IBAN per run. The column is unique and this fixture COMMITS, so reusing the constant the
     * rolled-back functional tests share would block against whichever of them holds it right now.
     */
    private function unusedIban(): string
    {
        $bban = \str_pad((string) \random_int(0, 999999999), 10, '0', STR_PAD_LEFT)
            . \str_pad((string) \random_int(0, 999999999), 10, '0', STR_PAD_LEFT);

        // ISO 13616: move the country code and the placeholder check digits to the end, letters as their
        // 1-based alphabet position plus 9 (E = 14, S = 28), then the check digits are 98 minus mod 97.
        $check = 98 - $this->mod97($bban . '142800');

        return 'ES' . \str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $bban;
    }

    private function mod97(string $digits): int
    {
        $remainder = 0;

        foreach (\str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }

    /**
     * The committed fixture and everything the run wrote about it. The trail is append-only in production,
     * and these rows are removed only because they describe a subject that never existed outside this test
     * — leaving them behind would hand the erasure reconciliations a permanent phantom to report.
     */
    private function removeCommittedFixture(): void
    {
        $connection = $this->outsideConnection();

        $connection->executeStatement('DELETE FROM bank_account WHERE id = :id', ['id' => $this->accountId]);
        $connection->executeStatement('DELETE FROM bank WHERE id = :id', ['id' => $this->bankId]);
        $connection->executeStatement(
            'DELETE FROM dek_keystore WHERE encryption_scope_id IN (:scope, :bankScope)',
            ['scope' => $this->scope, 'bankScope' => 'Bank:' . $this->bankId],
        );
        $connection->executeStatement(
            'DELETE FROM audit_log WHERE resource_id IN (:id, :bankId) '
            . "OR metadata->>'encryption_scope_id' = :scope",
            ['id' => $this->accountId, 'bankId' => $this->bankId, 'scope' => $this->scope],
        );
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        }

        return $this->outside;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        $this->assertInstanceOf($id, $service);

        return $service;
    }
}
