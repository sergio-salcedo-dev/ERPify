<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalPasswordResetTokenPersonReferences;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the person-reference source against REAL Postgres: the read hits
 * `identity_password_reset_token.user_id`, and its `DISTINCT` holds when a person has requested more than
 * one reset.
 *
 * This is the axis whose column never crosses a bounded context — the identity context owns both the person
 * and the table — and it is in the control for exactly that reason: the defect is a partial erasure, and
 * "the use case that owns it is one class away" is not a property anything enforces.
 *
 * The seeded rows are asserted to exist first; ids are per-run and asserted by containment against a shared
 * dev database; the test runs inside a rolled-back transaction.
 *
 * @internal
 */
#[CoversClass(DbalPasswordResetTokenPersonReferences::class)]
final class DbalPasswordResetTokenPersonReferencesTest extends KernelTestCase
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

    public function testItReportsARepeatedRequesterOnce(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $this->seedResetToken($userId);
            $this->seedResetToken($userId);

            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM identity_password_reset_token WHERE user_id = CAST(:id AS UUID)',
                ['id' => $userId],
            );
            $this->assertIsNumeric($count);
            $this->assertSame(2, (int) $count, 'both reset tokens really exist');

            $ids = (new DbalPasswordResetTokenPersonReferences($this->connection))->retainedPersonIds();

            $this->assertCount(1, \array_keys($ids, $userId, true), 'DISTINCT collapses the rows');
        });
    }

    private function seedResetToken(string $userId): void
    {
        $this->entityManager->persist(PasswordResetToken::issue(
            Uuid::generate(),
            $userId,
            SingleUseToken::fromHash(
                \hash('sha256', Uuid::generate()),
                new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            ),
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
