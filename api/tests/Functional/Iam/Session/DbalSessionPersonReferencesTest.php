<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DbalSessionPersonReferences;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the person-reference source against REAL Postgres, where the two claims its docblock makes are
 * decided: that it reads `iam_session.user_id`, and that it applies NO temporal-validity predicate.
 *
 * The second is the one worth a test of its own. Expiry here is a read-side predicate rather than a
 * persisted transition and nothing reaps the table, so the lapsed rows are precisely the ones that keep a
 * person's `user_id`, `ip` and `device` indefinitely — the seed is therefore an EXPIRED session, which every
 * other read of this table hides. A source that copied the `status = ACTIVE AND expires_at > now` filter
 * would be blind to the majority of what this control exists to find, and would still pass every static
 * check.
 *
 * The seeded row is asserted to exist first; ids are per-run and asserted by containment against a shared
 * dev database; the test runs inside a rolled-back transaction.
 *
 * @internal
 */
#[CoversClass(DbalSessionPersonReferences::class)]
final class DbalSessionPersonReferencesTest extends KernelTestCase
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

    public function testItSeesAnExpiredSessionAndReportsARepeatedUserOnce(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $this->seedExpiredSession($userId);
            $this->seedExpiredSession($userId);

            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM iam_session WHERE user_id = CAST(:id AS UUID)',
                ['id' => $userId],
            );
            $this->assertIsNumeric($count);
            $this->assertSame(2, (int) $count, 'both expired sessions really exist');

            $ids = (new DbalSessionPersonReferences($this->connection))->retainedPersonIds();

            // One person owns many sessions over time; an operator must be handed one id to repair.
            $this->assertCount(1, \array_keys($ids, $userId, true), 'DISTINCT collapses the repeated rows');
        });
    }

    private function seedExpiredSession(string $userId): void
    {
        $this->entityManager->persist(Session::start(
            Uuid::generate(),
            $userId,
            Uuid::generate(),
            'functional-test',
            '127.0.0.1',
            new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        ));
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
