<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\RecoverySecretAlreadyExists;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineRecoverySecretRepository;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The adapter against REAL Postgres: what round-trips, what the one-row-per-identity constraint answers with,
 * what a malformed selector does before it reaches the database, and what the GDPR delete reports.
 *
 * Its locking twins are proved elsewhere — {@see RecoverySecretLockOrderFunctionalTest} drives the two
 * `ForUpdate` finders against real NOWAIT probes, which is the claim only a concurrent session can make. What
 * is left here is everything a single session can decide, and the malformed-selector guard is the one worth
 * naming: it exists so a hostile value collapses into the caller's opaque wall instead of reaching Postgres
 * as a uuid cast error, which would be a 500 that also tells the caller their selector was merely malformed
 * rather than unknown.
 *
 * Each case runs inside a rolled-back transaction that truncates the shared dev table.
 *
 * @internal
 */
#[CoversClass(DoctrineRecoverySecretRepository::class)]
final class DoctrineRecoverySecretRepositoryTest extends KernelTestCase
{
    private const string USER_A = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string USER_B = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    private const string NOW = '2026-08-28T12:00:00+00:00';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineRecoverySecretRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->repository = new DoctrineRecoverySecretRepository($entityManager);
    }

    #[Test]
    public function aSavedSecretRoundTripsAndStillVerifies(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable(self::NOW);
            $generated = RecoverySecret::mint(self::USER_A, $now);

            $this->repository->save($generated->secret);
            $this->entityManager->clear();

            $found = $this->repository->findBySelector((string) $generated->secret->getId());

            $this->assertInstanceOf(RecoverySecret::class, $found);
            $this->assertSame(self::USER_A, $found->userId());
            // The digest and the expiry both survived the round trip, which is what a verify needs; asserting
            // the row is merely present would pass over a secret nobody can spend.
            $this->assertTrue($found->verify($this->presentedSecretOf($generated->plaintext()), $now));
        });
    }

    #[Test]
    public function itIsFoundByItsOwnerToo(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $generated = RecoverySecret::mint(self::USER_A, new DateTimeImmutable(self::NOW));
            $this->repository->save($generated->secret);
            $this->entityManager->clear();

            $found = $this->repository->findByUserId(self::USER_A);

            $this->assertInstanceOf(RecoverySecret::class, $found);
            $this->assertSame((string) $generated->secret->getId(), (string) $found->getId());
            $this->assertNotInstanceOf(
                RecoverySecret::class,
                $this->repository->findByUserId(self::USER_B),
                'the lookup crossed identities',
            );
        });
    }

    #[Test]
    public function aSecondSecretForOneIdentityIsRefusedByTheSchema(): void
    {
        // One row per identity is a schema invariant, not a check the use case can be trusted to have made:
        // two mints racing past the same locked read both reach this INSERT, and only one may land.
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable(self::NOW);
            $this->repository->save(RecoverySecret::mint(self::USER_A, $now)->secret);

            $this->expectException(RecoverySecretAlreadyExists::class);

            $this->repository->save(RecoverySecret::mint(self::USER_A, $now)->secret);
        });
    }

    #[Test]
    public function removingRetiresTheRow(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $generated = RecoverySecret::mint(self::USER_A, new DateTimeImmutable(self::NOW));
            $this->repository->save($generated->secret);

            $this->repository->remove($generated->secret);

            $this->entityManager->clear();

            $this->assertNotInstanceOf(
                RecoverySecret::class,
                $this->repository->findBySelector((string) $generated->secret->getId()),
            );
        });
    }

    #[Test]
    public function theLockedFindersReturnTheSameRowTheUnlockedOnesDo(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $generated = RecoverySecret::mint(self::USER_A, new DateTimeImmutable(self::NOW));
            $this->repository->save($generated->secret);
            $this->entityManager->clear();

            $bySelector = $this->repository->findBySelectorForUpdate((string) $generated->secret->getId());
            $byUser = $this->repository->findByUserIdForUpdate(self::USER_A);

            $this->assertInstanceOf(RecoverySecret::class, $bySelector);
            $this->assertInstanceOf(RecoverySecret::class, $byUser);
            $this->assertSame((string) $bySelector->getId(), (string) $byUser->getId());
            // That the lock EXCLUDES a rival is the one claim a single session cannot make; it is proved
            // against real NOWAIT probes in `RecoverySecretLockOrderFunctionalTest`.
            $this->assertNotInstanceOf(RecoverySecret::class, $this->repository->findByUserIdForUpdate(self::USER_B));
        });
    }

    #[Test]
    public function theLockedReadReHydratesRatherThanAnsweringFromTheUnitOfWork(): void
    {
        // The reason both locked finders carry `HINT_REFRESH`, and until now a claim only the docblock made.
        // Doctrine answers a second load of an already-managed entity from the identity map, so without the
        // hint the row would arrive locked and the HYDRATED STATE would still be the pre-lock snapshot — the
        // verify these methods exist to serialise would then run against exactly the stale row the lock was
        // taken to rule out.
        $this->inRolledBackTransaction(function (): void {
            $generated = RecoverySecret::mint(self::USER_A, new DateTimeImmutable(self::NOW));
            $this->repository->save($generated->secret);

            // A rival's committed write, applied behind Doctrine's back so the identity map keeps the old
            // value — which is what a concurrent transaction looks like from this session's side.
            $movedTo = '2099-01-01 00:00:00';
            $this->connection->executeStatement(
                'UPDATE identity_recovery_secret SET expires_at = :moved WHERE id = :id',
                ['moved' => $movedTo, 'id' => (string) $generated->secret->getId()],
            );

            $locked = $this->repository->findBySelectorForUpdate((string) $generated->secret->getId());

            $this->assertInstanceOf(RecoverySecret::class, $locked);
            $this->assertSame(
                '2099',
                $locked->expiresAt()->format('Y'),
                'the locked read answered from the pre-lock snapshot, so the lock decided nothing',
            );
        });
    }

    #[Test]
    #[DataProvider('provideASelectorThatCanKeyNothingReadsAsAnAbsentRowCases')]
    public function aSelectorThatCanKeyNothingReadsAsAnAbsentRow(string $selector): void
    {
        // Guarded BEFORE the database, so a hostile value cannot reach Postgres as a uuid cast error. That
        // error would be a 500, and a 500 here is an oracle: it separates "malformed" from "unknown" on the
        // one endpoint whose entire contract is that those two are indistinguishable.
        $this->inRolledBackTransaction(function () use ($selector): void {
            $this->assertNotInstanceOf(RecoverySecret::class, $this->repository->findBySelector($selector));
            $this->assertNotInstanceOf(RecoverySecret::class, $this->repository->findBySelectorForUpdate($selector));
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideASelectorThatCanKeyNothingReadsAsAnAbsentRowCases(): iterable
    {
        yield 'not a uuid at all' => ['not-a-uuid'];
        yield 'empty' => [''];
        yield 'sql-ish' => ["' OR 1=1 --"];
        yield 'uuid with a trailing character' => ['0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5bx'];
    }

    #[Test]
    public function theGdprDeleteReportsWhatItRemovedAndTouchesNobodyElse(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable(self::NOW);
            $this->repository->save(RecoverySecret::mint(self::USER_A, $now)->secret);
            $this->repository->save(RecoverySecret::mint(self::USER_B, $now)->secret);

            $this->entityManager->clear();

            $removed = $this->repository->deleteAllForUser(self::USER_A);

            // The COUNT is the erasure path's evidence, not a convenience: a silent zero there reports a
            // successful erasure of nothing while the row stays.
            $this->assertSame(1, $removed);
            $this->assertNotInstanceOf(RecoverySecret::class, $this->repository->findByUserId(self::USER_A));
            $this->assertInstanceOf(RecoverySecret::class, $this->repository->findByUserId(self::USER_B));
        });
    }

    #[Test]
    public function theGdprDeleteReportsZeroForAnIdentityHoldingNothing(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->assertSame(0, $this->repository->deleteAllForUser(self::USER_A));
        });
    }

    /** The half of a `<selector>.<secret>` presentation the digest is taken over. */
    private function presentedSecretOf(string $plaintext): string
    {
        $halves = \explode('.', $plaintext, 2);
        $this->assertCount(2, $halves, 'the mint produced no `<selector>.<secret>` presentation');

        return $halves[1];
    }

    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE identity_recovery_secret RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
