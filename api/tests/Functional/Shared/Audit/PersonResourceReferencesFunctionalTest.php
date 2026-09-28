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
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalPersonResourceReferences;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AssertsKeysetPagedIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the read side of the resource-axis erasure against REAL Postgres, where both halves of the query
 * live: the `DISTINCT` that keeps a subject named by a hundred rows from being reported a hundred times, and
 * the `resource_erased = FALSE` predicate without which every correct erasure would surface as a divergence.
 *
 * Ids are generated per run and asserted by containment, so the shared dev database's own rows cannot make
 * this pass or fail. Each test runs inside a rolled-back transaction and leaves nothing behind.
 *
 * @internal
 */
#[CoversClass(DbalPersonResourceReferences::class)]
final class PersonResourceReferencesFunctionalTest extends KernelTestCase
{
    use AssertsKeysetPagedIds;

    private const string PERSON_TYPE = 'User';

    #[Test]
    public function itListsEachUnerasedReferenceOfTheTypeOnceAndIgnoresOtherTypes(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $subject = Uuid::generate();
            $otherSubject = Uuid::generate();
            $bankId = Uuid::generate();

            // The same person named twice: an operator must be handed one id, not one per audited action.
            $this->seed($writer, self::PERSON_TYPE, $subject);
            $this->seed($writer, self::PERSON_TYPE, $subject);
            $this->seed($writer, self::PERSON_TYPE, $otherSubject);
            $this->seed($writer, 'Bank', $bankId);

            $ids = (new DbalPersonResourceReferences($connection))->unerasedIdsOfType(self::PERSON_TYPE);

            $this->assertCount(1, \array_keys($ids, $subject, true), 'DISTINCT collapses the repeated rows');
            $this->assertContains($otherSubject, $ids);
            $this->assertNotContains($bankId, $ids, 'another resource type is not this axis');
        });
    }

    #[Test]
    public function itDropsAReferenceOnceItHasBeenAnonymised(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $erased = Uuid::generate();
            $pseudonym = Uuid::generate();
            $this->seed(new DbalAuditLogWriter($connection), self::PERSON_TYPE, $erased);

            (new DbalAuditResourceAnonymiser($connection))
                ->anonymise(AuditResource::of(self::PERSON_TYPE, $erased), $pseudonym)
            ;

            $ids = (new DbalPersonResourceReferences($connection))->unerasedIdsOfType(self::PERSON_TYPE);

            $this->assertNotContains($erased, $ids, 'the real id is gone from the trail');
            $this->assertNotContains($pseudonym, $ids, 'and its pseudonym is not a fresh divergence');
        });
    }

    #[Test]
    public function itPagesThroughTheTypeOneIdAtATimeAndStaysInsideItsScope(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($connection);
            $seeded = [Uuid::generate(), Uuid::generate(), Uuid::generate()];
            $bankId = Uuid::generate();
            $erased = Uuid::generate();

            foreach ($seeded as $subject) {
                $this->seed($writer, self::PERSON_TYPE, $subject);
            }

            $this->seed($writer, self::PERSON_TYPE, $seeded[0]);
            $this->seed($writer, 'Bank', $bankId);
            $this->seed($writer, self::PERSON_TYPE, $erased);
            (new DbalAuditResourceAnonymiser($connection))
                ->anonymise(AuditResource::of(self::PERSON_TYPE, $erased), Uuid::generate())
            ;

            $ids = (new DbalPersonResourceReferences($connection, 1))->unerasedIdsOfType(self::PERSON_TYPE);

            $this->assertKeysetPagedIds($seeded, $ids);
            $this->assertNotContains($bankId, $ids, 'the scope holds on every page, not only the first');
            $this->assertNotContains($erased, $ids, 'an erased reference stays out on every page');
        });
    }

    private function seed(DbalAuditLogWriter $writer, string $resourceType, string $resourceId): void
    {
        $writer->write(AuditLogEntry::create(
            'USER_ROLES_CHANGED',
            AuditLevel::SECURITY,
            ActorContext::forUser(Uuid::generate()),
            Uuid::generate(),
            new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            AuditResource::of($resourceType, $resourceId),
        ));
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
