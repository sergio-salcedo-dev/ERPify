<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalRecoverySecretPersonReferences;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the person-reference source against REAL Postgres: the read hits `identity_recovery_secret.user_id`
 * and reports a holder that no live identity backs.
 *
 * The unit gate proves this source declares a key the registry carries; only a real read proves the query
 * points at the right table and the right column. A source whose SQL named some other table would satisfy
 * every static check and report an empty axis for ever — which on this axis is the worst possible silence,
 * because a recovery secret has a ten-year TTL and no sweep, so nothing else would ever surface the row.
 *
 * The seeded row is asserted to exist first, so a `SELECT` that matched nothing cannot pass as a clean read.
 * Ids are per-run and asserted by containment, because the suite shares a dirty dev database whose own rows
 * must be unable to decide this either way; the whole test runs inside a rolled-back transaction.
 *
 * @internal
 */
#[CoversClass(DbalRecoverySecretPersonReferences::class)]
final class DbalRecoverySecretPersonReferencesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    public function testItReadsTheHolderOfEveryRetainedRecoverySecret(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $this->seedRecoverySecret($userId);

            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM identity_recovery_secret WHERE user_id = CAST(:id AS UUID)',
                ['id' => $userId],
            );
            $this->assertIsNumeric($count);
            $this->assertSame(1, (int) $count, 'the recovery secret really exists');

            $ids = (new DbalRecoverySecretPersonReferences($this->connection))->retainedPersonIds();

            $this->assertContains($userId, $ids);
        });
    }

    public function testTheUniqueIndexIsWhatMakesTheAbsentDistinctSafe(): void
    {
        // The sibling source over `identity_password_reset_token` needs a DISTINCT because a person can hold
        // several pending resets. This one deliberately has none, and that omission is only correct while one
        // identity cannot hold two secrets — so the constraint is asserted rather than assumed. Losing the
        // unique index would not fail any other test here; it would quietly start double-reporting a holder.
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $this->seedRecoverySecret($userId);

            $this->expectExceptionMessageMatches('/uniq_identity_recovery_secret_user_id/');

            $this->seedRecoverySecret($userId);
        });
    }

    private function seedRecoverySecret(string $userId): void
    {
        $this->entityManager->persist(
            RecoverySecret::mint($userId, new DateTimeImmutable('2026-08-28T00:00:00+00:00'))->secret,
        );
        $this->entityManager->flush();
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
