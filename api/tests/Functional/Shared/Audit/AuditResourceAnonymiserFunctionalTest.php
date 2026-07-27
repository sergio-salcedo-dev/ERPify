<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditResourceAnonymiser;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the resource-axis erasure against REAL Postgres — the `resource_type`/`resource_id` pair matching
 * and the `CAST(… AS UUID)` an in-memory double cannot model.
 *
 * The load-bearing assertion is the **column asymmetry**: a row naming the subject as a resource was very
 * often written by somebody else (an administrator acting on them), so the actor half of that row —
 * `actor_id`, `ip`, `user_agent`, `actor_erased` — must come out untouched. Redacting it would destroy a
 * third party's evidence and falsely flag that third party as an erased actor.
 *
 * Each test runs inside a rolled-back transaction, so the shared dev DB is left as it was found.
 *
 * @internal
 */
#[CoversClass(DbalAuditResourceAnonymiser::class)]
final class AuditResourceAnonymiserFunctionalTest extends KernelTestCase
{
    private const string SUBJECT_ID = '0190f300-0000-7000-8000-0000000000b1';

    private const string OTHER_SUBJECT_ID = '0190f300-0000-7000-8000-0000000000b2';

    private const string ADMIN_ID = '0190f300-0000-7000-8000-0000000000b3';

    private const string BANK_ID = '0190f300-0000-7000-8000-0000000000b4';

    private const string CLIENT_IP = '203.0.113.7';

    private const string PERSON_TYPE = 'User';

    #[Test]
    public function itRewritesOnlyTheMatchingResourceAndLeavesEveryActorColumnAlone(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $pseudonym = Uuid::generate();

            // The crosswalk row: the subject acted on themselves, so both columns name the same person.
            $subject = ActorContext::forUser(self::SUBJECT_ID);
            $admin = ActorContext::forUser(self::ADMIN_ID);
            $selfActed = $this->seed($writer, $subject, self::PERSON_TYPE, self::SUBJECT_ID);
            // An administrator acted on the subject — the subject is the resource, the admin is the actor.
            $adminActed = $this->seed($writer, $admin, self::PERSON_TYPE, self::SUBJECT_ID);
            // Same subject as actor, but a non-person resource: out of this axis' reach.
            $bankRow = $this->seed($writer, $subject, 'Bank', self::BANK_ID);
            // A different person entirely.
            $otherRow = $this->seed($writer, $admin, self::PERSON_TYPE, self::OTHER_SUBJECT_ID);

            $anonymiser = new DbalAuditResourceAnonymiser($connection);
            $affected = $anonymiser->anonymise(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID), $pseudonym);

            $this->assertSame(2, $affected, 'both rows naming the subject, and only those');

            foreach ([$selfActed, $adminActed] as $id) {
                $row = $this->rowById($connection, $id);
                $this->assertSame($pseudonym, $row['resource_id']);
                $this->assertTrue($this->isFlagSet($row['resource_erased']));
            }

            // The admin's own attribution survives intact — this is the asymmetry the two columns exist for.
            $admin = $this->rowById($connection, $adminActed);
            $this->assertSame(self::ADMIN_ID, $admin['actor_id']);
            $this->assertFalse($this->isFlagSet($admin['actor_erased']), 'the acting admin is not an erased actor');
            $this->assertSame(self::CLIENT_IP, $admin['ip'], "a third party's ip is not collateral");

            foreach ([$bankRow, $otherRow] as $id) {
                $untouched = $this->rowById($connection, $id);
                $this->assertNotSame($pseudonym, $untouched['resource_id']);
                $this->assertFalse($this->isFlagSet($untouched['resource_erased']));
            }
        });
    }

    #[Test]
    public function itIsIdempotentBecauseTheOriginalIdNoLongerMatches(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $this->seed($writer, ActorContext::forUser(self::SUBJECT_ID), self::PERSON_TYPE, self::SUBJECT_ID);

            $anonymiser = new DbalAuditResourceAnonymiser($connection);
            $resource = AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID);

            $this->assertSame(1, $anonymiser->anonymise($resource, Uuid::generate()));
            $this->assertSame(0, $anonymiser->anonymise($resource, Uuid::generate()), 'a re-run matches nothing');
        });
    }

    #[Test]
    public function itRefusesAMalformedPseudonymBeforeReachingTheDriver(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $this->expectException(InvalidUuidException::class);

            (new DbalAuditResourceAnonymiser($connection))
                ->anonymise(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID), 'not-a-uuid')
            ;
        });
    }

    private function seed(
        DbalAuditLogWriter $writer,
        ActorContext $actor,
        string $resourceType,
        string $resourceId,
    ): string {
        $entry = AuditLogEntry::create(
            'USER_ROLES_CHANGED',
            AuditLevel::SECURITY,
            $actor,
            Uuid::generate(),
            new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            AuditResource::of($resourceType, $resourceId),
            [],
            self::CLIENT_IP,
            'Mozilla/5.0',
        );
        $writer->write($entry);

        return $entry->id;
    }

    /**
     * @return array{actor_id: mixed, ip: mixed, actor_erased: mixed, resource_id: mixed, resource_erased: mixed}
     */
    private function rowById(Connection $connection, string $id): array
    {
        $row = $connection->fetchAssociative(
            'SELECT actor_id, ip, actor_erased, resource_id, resource_erased '
            . 'FROM audit_log WHERE id = :id',
            ['id' => $id],
        );
        $this->assertIsArray($row);
        /** @phpstan-var array{actor_id: mixed, ip: mixed, actor_erased: mixed, resource_id: mixed, resource_erased: mixed} $row */

        return $row;
    }

    /**
     * The driver surfaces a Postgres boolean as a native bool or as `'t'`/`'f'` depending on the platform.
     */
    private function isFlagSet(mixed $value): bool
    {
        return true === $value || 't' === $value || '1' === $value || 1 === $value;
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
