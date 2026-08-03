<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Invitation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine\DbalInvitationPersonReferences;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the person-reference source against REAL Postgres: the read hits `iam_invitation.invited_user_id`,
 * and its `DISTINCT` is load-bearing because a person can be invited repeatedly.
 *
 * The seeded rows are asserted to exist first; ids are per-run and asserted by containment against a shared
 * dev database; the test runs inside a rolled-back transaction.
 *
 * @internal
 */
#[CoversClass(DbalInvitationPersonReferences::class)]
final class DbalInvitationPersonReferencesTest extends KernelTestCase
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

    public function testItReportsARepeatedlyInvitedPersonOnce(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $invitedUserId = Uuid::generate();
            $this->seedInvitation($invitedUserId);
            $this->seedInvitation($invitedUserId);

            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM iam_invitation WHERE invited_user_id = CAST(:id AS UUID)',
                ['id' => $invitedUserId],
            );
            $this->assertIsNumeric($count);
            $this->assertSame(2, (int) $count, 'both invitations really exist');

            $ids = (new DbalInvitationPersonReferences($this->connection))->retainedPersonIds();

            // An operator must be handed one id to repair, not one per invitation ever addressed.
            $this->assertCount(1, \array_keys($ids, $invitedUserId, true), 'DISTINCT collapses the rows');
        });
    }

    private function seedInvitation(string $invitedUserId): void
    {
        $this->entityManager->persist(Invitation::create(
            Uuid::generate(),
            Uuid::generate(),
            $invitedUserId,
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
