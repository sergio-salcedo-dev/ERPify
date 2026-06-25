<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditActorAnonymiser;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the GDPR erasure against a real Postgres: a subject's rows are all rewritten with a
 * single fresh actor id and redacted `ip`/`user_agent`, every other actor's rows are untouched, no row is
 * deleted, and a re-run with the original id is a no-op (idempotent).
 *
 * Runs inside a transaction that is always rolled back, so the UPDATEs never escape the test; rows are
 * addressed by their minted id, immune to other rows in the shared table.
 *
 * @internal
 */
#[CoversClass(DbalAuditActorAnonymiser::class)]
#[CoversClass(ActorAnonymisationResult::class)]
final class AuditActorAnonymiserFunctionalTest extends KernelTestCase
{
    private const string CLIENT_IP = '203.0.113.7';

    private const string SENTINEL = '[REDACTED]';

    public function testItAnonymisesOneSubjectAndLeavesEveryOtherRowIntact(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $occurredOn = new DateTimeImmutable('2026-06-25T00:00:00+00:00');

            $subjectId = Uuid::generate();
            $subjectRowA = $this->seedForActor($writer, ActorContext::forUser($subjectId), $occurredOn);
            $subjectRowB = $this->seedForActor($writer, ActorContext::forUser($subjectId), $occurredOn);
            $otherId = Uuid::generate();
            $otherRow = $this->seedForActor($writer, ActorContext::forUser($otherId), $occurredOn);
            $anonymousRow = $this->seedForActor($writer, ActorContext::anonymous(), $occurredOn);

            $anonymiser = new DbalAuditActorAnonymiser($connection);

            $this->assertSame(2, $anonymiser->countFor($subjectId), 'two rows attributed to the subject');

            $result = $anonymiser->anonymise($subjectId);

            $this->assertSame(2, $result->affectedRows);
            $this->assertTrue(Uuid::isValid($result->pseudonym));
            $this->assertNotSame($subjectId, $result->pseudonym, 'the original id is gone');

            // Both subject rows now carry the SAME new pseudonym (trail stays correlated) and redacted PII.
            foreach ([$subjectRowA, $subjectRowB] as $id) {
                $row = $this->rowById($connection, $id);
                $this->assertSame($result->pseudonym, $row['actor_id'], 'rewritten to the shared pseudonym');
                $this->assertSame(self::SENTINEL, $row['ip']);
                $this->assertSame(self::SENTINEL, $row['user_agent']);
            }

            // Other actor untouched, anonymous row still anonymous, and nothing was deleted.
            $other = $this->rowById($connection, $otherRow);
            $this->assertSame($otherId, $other['actor_id']);
            $this->assertSame(self::CLIENT_IP, $other['ip']);

            $anonymous = $this->rowById($connection, $anonymousRow);
            $this->assertNull($anonymous['actor_id'], 'anonymous row untouched');

            // Idempotent: a re-run with the original id matches nothing.
            $this->assertSame(0, $anonymiser->anonymise($subjectId)->affectedRows);
        });
    }

    private function seedForActor(
        DbalAuditLogWriter $writer,
        ActorContext $actor,
        DateTimeImmutable $occurredOn,
    ): string {
        $entry = AuditLogEntry::create(
            'BANK_ACCOUNTS_VIEWED',
            AuditLevel::ACTIVITY,
            $actor,
            Uuid::generate(),
            $occurredOn,
            null,
            [],
            self::CLIENT_IP,
            'Mozilla/5.0',
        );
        $writer->write($entry);

        return $entry->id;
    }

    /**
     * @return array{actor_id: mixed, ip: mixed, user_agent: mixed}
     */
    private function rowById(Connection $connection, string $id): array
    {
        $row = $connection->fetchAssociative(
            'SELECT actor_id, ip, user_agent FROM audit_log WHERE id = :id',
            ['id' => $id],
        );
        $this->assertIsArray($row);

        return [
            'actor_id' => $row['actor_id'] ?? null,
            'ip' => $row['ip'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
        ];
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
