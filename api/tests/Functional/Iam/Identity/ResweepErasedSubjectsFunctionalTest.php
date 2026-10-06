<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\ResweepErasedSubjects;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineErasureResweepRepository;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock as SymfonyClockFacade;
use Symfony\Component\Clock\MockClock;

/**
 * The re-sweep over the real adapters: a committed erasure, then rows a late writer commits naming the
 * subject on every surface the passes cover — both audit axes, `event_store` and `iam_session` — then a tick.
 *
 * The late rows are inserted directly rather than through the writers that produce them in production, and
 * that is the point: the mechanism is meant to hold whoever wrote the row, so the test must not depend on
 * which writer it was. The erasure is the real use case, because what is under test is that it schedules the
 * re-sweep in the transaction it commits.
 *
 * Everything runs inside one rolled-back transaction; ids are per run, so the shared test database's own rows
 * can decide nothing either way.
 *
 * Its object coupling is the re-sweep's own: building it names every pass the erasure runs, and a double or
 * an adapter for each.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ResweepErasedSubjects::class)]
#[CoversClass(DoctrineErasureResweepRepository::class)]
final class ResweepErasedSubjectsFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private Connection $connection;

    private string $subjectId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();

        $this->subjectId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        FreezeSystemClockExtension::pin();

        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function aRowCommittedAfterTheErasureIsRewrittenOnTheNextTickWhoeverWroteIt(): void
    {
        $this->eraseALiveSubject();
        $scheduled = $this->rowsNamingTheSubject('identity_erasure_resweep', 'subject_id');
        $this->assertSame(1, $scheduled, 'the erasure scheduled it');

        $this->insertLateActorRow();
        $this->insertLateResourceRow();
        $this->insertLateEvent();
        $this->insertLateSession();

        $rewritten = $this->resweep()->resweep();

        $this->assertSame(4, $rewritten);
        $this->assertSame(0, $this->rowsNamingTheSubject('audit_log', 'actor_id'), 'actor axis');
        $this->assertSame(0, $this->rowsNamingTheSubject('audit_log', 'resource_id'), 'resource axis');
        $this->assertSame(0, $this->rowsNamingTheSubject('event_store', 'aggregate_id'), 'business log');
        $this->assertSame(0, $this->rowsNamingTheSubject('iam_session', 'user_id'), 'session store');

        // One pseudonym for the whole tick, and a compliance entry carrying it (D4.1) — never the subject.
        $pseudonym = $this->connection->fetchOne(
            "SELECT metadata->>'anonymized_actor_id' FROM audit_log "
            . "WHERE action = 'GDPR_ERASURE_EXECUTED' AND metadata->>'resweep' = 'true' "
            . 'AND actor_type = :system',
            ['system' => 'system'],
        );
        $this->assertIsString($pseudonym);
        $this->assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM audit_log WHERE actor_id = CAST(:p AS UUID) AND actor_erased',
                ['p' => $pseudonym],
            ),
        );
        $this->assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM event_store WHERE aggregate_id = CAST(:p AS UUID)',
                ['p' => $pseudonym],
            ),
        );

        // The window is still open, so the subject is kept for the next tick.
        $this->assertSame(1, $this->rowsNamingTheSubject('identity_erasure_resweep', 'subject_id'));
    }

    #[Test]
    public function aSecondTickOverCleanTablesRewritesNothingAndWritesNoEvidence(): void
    {
        $this->eraseALiveSubject();
        $this->insertLateActorRow();
        $this->resweep()->resweep();
        $evidenceBefore = $this->resweepEvidenceRows();

        $this->assertSame(0, $this->resweep()->resweep());
        $this->assertSame($evidenceBefore, $this->resweepEvidenceRows());
    }

    #[Test]
    public function theTickAfterTheWindowClosesSweepsOnceMoreAndForgetsTheSubject(): void
    {
        $this->eraseALiveSubject();
        $this->travel('PT1H1S');
        $this->insertLateActorRow();

        $this->assertSame(1, $this->resweep()->resweep(), 'the closing tick still sweeps');
        $this->assertSame(0, $this->rowsNamingTheSubject('audit_log', 'actor_id'));
        $this->assertSame(0, $this->rowsNamingTheSubject('identity_erasure_resweep', 'subject_id'), 'and then forgets');
    }

    #[Test]
    public function aSubjectNobodyScheduledIsNeverTouched(): void
    {
        // The re-sweep matches by value across every aggregate, so the only ids it may be handed are the ones
        // an erasure proved to be people. A row naming an id that was never erased stays exactly as written.
        $this->insertLateActorRow();
        $this->insertLateEvent();

        $this->resweep()->resweep();

        $this->assertSame(1, $this->rowsNamingTheSubject('audit_log', 'actor_id'));
        $this->assertSame(1, $this->rowsNamingTheSubject('event_store', 'aggregate_id'));
    }

    private function eraseALiveSubject(): void
    {
        $entityManager = $this->service(EntityManagerInterface::class);
        $entityManager->persist(UserFixtureFactory::create(
            $this->subjectId,
            \sprintf('resweep-%s@erpify.test', $this->subjectId),
            'resweep-password',
            [Role::VIEWER->value],
        ));
        $entityManager->flush();
        $entityManager->clear();

        $erasure = $this->service(FulfilIdentityErasure::class)->execute($this->subjectId);
        $this->assertTrue($erasure->identityErased);
        $entityManager->clear();
    }

    private function resweep(): ResweepErasedSubjects
    {
        return $this->service(ResweepErasedSubjects::class);
    }

    /**
     * Both time sources, since the container's clock reads Symfony's global one and the aggregate stamp reads
     * the ambient one; `tearDown()` restores the suite pin.
     */
    private function travel(string $interval): void
    {
        $later = SystemClock::now()->add(new DateInterval($interval));

        SystemClock::set(new FixedClock($later));
        SymfonyClockFacade::set(new MockClock($later));
    }

    private function insertLateActorRow(): void
    {
        $this->insertAuditRow(actorId: $this->subjectId, resourceType: 'Bank', resourceId: Uuid::generate());
    }

    private function insertLateResourceRow(): void
    {
        $this->insertAuditRow(
            actorId: null,
            resourceType: FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE,
            resourceId: $this->subjectId,
        );
    }

    private function insertAuditRow(?string $actorId, string $resourceType, string $resourceId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO audit_log '
            . '(id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, '
            . 'metadata, actor_erased, resource_erased, occurred_on) '
            . 'VALUES (CAST(:id AS UUID), :level, :action, :actorType, CAST(:actorId AS UUID), :correlationId, '
            . ':resourceType, :resourceId, :metadata, FALSE, FALSE, :occurredOn)',
            [
                'id' => Uuid::generate(),
                'level' => 'activity',
                'action' => 'API_REQUEST',
                'actorType' => null === $actorId ? 'anonymous' : 'user',
                'actorId' => $actorId,
                'correlationId' => Uuid::generate(),
                'resourceType' => $resourceType,
                'resourceId' => $resourceId,
                'metadata' => '{}',
                'occurredOn' => SystemClock::now()->format('Y-m-d H:i:sP'),
            ],
        );
    }

    private function insertLateEvent(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
            . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on) '
            . 'VALUES (CAST(:event_id AS UUID), CAST(:aggregate_id AS UUID), :aggregate_type, 1, :event_name, '
            . "1, CAST('{}' AS JSONB), CAST('{}' AS JSONB), NULL, :occurred_on, :occurred_on)",
            [
                'event_id' => Uuid::generate(),
                'aggregate_id' => $this->subjectId,
                'aggregate_type' => 'Iam.Session',
                'event_name' => 'erpify.session.revoked',
                'occurred_on' => SystemClock::now()->format('Y-m-d H:i:sP'),
            ],
        );
    }

    private function insertLateSession(): void
    {
        $session = Session::start(
            SessionId::generate()->toString(),
            $this->subjectId,
            Uuid::generate(),
            'Late device',
            '203.0.113.7',
            SystemClock::now()->add(new DateInterval('P1D')),
        );
        $session->pullDomainEvents();

        $this->service(SessionRepository::class)->save($session);
    }

    private function rowsNamingTheSubject(string $table, string $column): int
    {
        return $this->rowCount(
            \sprintf('SELECT COUNT(*) FROM %s WHERE %s = CAST(:id AS UUID)', $table, $column),
            ['id' => $this->subjectId],
        );
    }

    private function resweepEvidenceRows(): int
    {
        return $this->rowCount(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'GDPR_ERASURE_EXECUTED' AND metadata->>'resweep' = 'true'",
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function rowCount(string $sql, array $parameters = []): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
