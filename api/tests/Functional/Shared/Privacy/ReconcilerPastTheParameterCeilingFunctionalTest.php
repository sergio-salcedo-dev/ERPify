<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drives the container-wired control over more distinct person ids than one PostgreSQL statement can bind
 * (65535), at the production page and chunk sizes. The adapters' own tests prove the paging and the chunking
 * with the sizes they choose; only this one proves the assembled control, as deployed, reaches a verdict
 * instead of a driver error once the installation has referenced that many people.
 *
 * The rows are seeded in one `INSERT … SELECT … generate_series` inside a rolled-back transaction, tagged by
 * a correlation id of their own so the sample asserted on is theirs. Assertions are by containment and floor,
 * because the shared test database may hold other rows of the same type.
 *
 * @internal
 */
#[CoversNothing]
final class ReconcilerPastTheParameterCeilingFunctionalTest extends KernelTestCase
{
    private const int SEEDED = 70_000;

    private const string AUDIT_RESOURCE_AXIS = 'audit_log.resource_id';

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
    }

    public function testTheWiredControlReachesAVerdictPastTheCeiling(): void
    {
        $this->connection->beginTransaction();

        try {
            $correlationId = Uuid::generate();
            $seeded = $this->connection->executeStatement(
                'INSERT INTO audit_log (id, level, action, actor_type, correlation_id, resource_type, resource_id, '
                . 'metadata, resource_erased, occurred_on) '
                . "SELECT gen_random_uuid(), 'security', 'USER_ROLES_CHANGED', 'system', "
                . "CAST(:correlation AS UUID), :type, gen_random_uuid(), '{}'::jsonb, FALSE, "
                . "'2026-07-01T10:00:00+00:00' "
                . 'FROM generate_series(1, CAST(:rows AS INT))',
                [
                    'correlation' => $correlationId,
                    'type' => FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE,
                    'rows' => self::SEEDED,
                ],
            );
            $this->assertSame(self::SEEDED, (int) $seeded, 'the seed really inserted every row');

            $sample = $this->connection->fetchFirstColumn(
                '(SELECT resource_id FROM audit_log WHERE correlation_id = :correlation ORDER BY resource_id LIMIT 1) '
                . 'UNION ALL '
                . '(SELECT resource_id FROM audit_log WHERE correlation_id = :correlation '
                . 'ORDER BY resource_id DESC LIMIT 1)',
                ['correlation' => $correlationId],
            );
            $this->assertCount(2, $sample);

            $reconciler = self::getContainer()->get(ReconcileErasedSubjectReferences::class);
            $this->assertInstanceOf(ReconcileErasedSubjectReferences::class, $reconciler);

            $reported = [];

            foreach ($reconciler->unreconciledReferences()->findings() as $personReferenceFinding) {
                $reported[$personReferenceFinding->axis->key()] = $personReferenceFinding->subjectIds;
            }

            $this->assertArrayHasKey(self::AUDIT_RESOURCE_AXIS, $reported);
            $this->assertGreaterThanOrEqual(self::SEEDED, \count($reported[self::AUDIT_RESOURCE_AXIS]));

            foreach ($sample as $id) {
                $this->assertContains($id, $reported[self::AUDIT_RESOURCE_AXIS]);
            }
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
