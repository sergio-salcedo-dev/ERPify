<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalSubjectErasureReconciler;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the GDPR subject-erasure reconciliation against a real Postgres: a destroyed key
 * without a `GDPR_SUBJECT_ERASED` entry surfaces as an integrity divergence, while a destroyed key with its
 * evidence — and a still-live key — do not.
 *
 * Runs inside a transaction that is always rolled back, so nothing escapes the shared dev database; scopes
 * are asserted by containment, immune to any other rows in the shared tables.
 *
 * @internal
 */
#[CoversClass(DbalSubjectErasureReconciler::class)]
final class SubjectErasureReconcilerFunctionalTest extends KernelTestCase
{
    public function testItReportsOnlyDestroyedKeysThatLackErasureEvidence(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $keystore = new DbalKeystore($connection);

            $orphan = EncryptionScopeId::forBankAccount(Uuid::generate());  // destroyed, no evidence
            $reconciled = EncryptionScopeId::forBankAccount(Uuid::generate()); // destroyed, with evidence
            $live = EncryptionScopeId::forBankAccount(Uuid::generate());     // never destroyed

            foreach ([$orphan, $reconciled, $live] as $scope) {
                $keystore->store($scope, new WrappedDek('wrapped', 1));
            }

            $keystore->destroy($orphan);
            $keystore->destroy($reconciled);
            $this->seedErasureEvidence($connection, $reconciled);

            $unreconciled = (new DbalSubjectErasureReconciler($connection))->unreconciledScopes();

            $this->assertContains($orphan->toString(), $unreconciled, 'a destroyed key with no evidence diverges');
            $this->assertNotContains($reconciled->toString(), $unreconciled, 'a destroyed key with evidence is clean');
            $this->assertNotContains($live->toString(), $unreconciled, 'a live key is not an erasure at all');
        });
    }

    private function seedErasureEvidence(Connection $connection, EncryptionScopeId $scope): void
    {
        $connection->executeStatement(
            'INSERT INTO audit_log '
            . '(id, level, action, actor_type, correlation_id, metadata, actor_erased, occurred_on) '
            . "VALUES (CAST(:id AS UUID), 'security', 'GDPR_SUBJECT_ERASED', 'system', "
            . 'CAST(:correlationId AS UUID), CAST(:metadata AS JSONB), FALSE, NOW())',
            [
                'id' => Uuid::generate(),
                'correlationId' => Uuid::generate(),
                'metadata' => \json_encode(['encryption_scope_id' => $scope->toString()], JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function inRolledBackTransaction(callable $work): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $work($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
