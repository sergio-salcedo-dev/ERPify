<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\ErasureResweepRepository;
use Erpify\Iam\Identity\Domain\Repository\TotpFactorRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalTotpFactorPersonReferences;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Privacy\Application\PersonReferenceErasure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The identity erasure reaching the second factor on a real database: the factor row is deleted, the key that
 * sealed its secret is tombstoned for erasure, the compliance entry carries that scope (what the subject-erasure
 * reconciler anti-joins the tombstone against), and no column of the schema still holds the person's id — the
 * tombstone included, because the scope is the factor's own random id.
 *
 * The first case COMMITS its fixture, because the erasure owns a real transaction, and removes it by hand however
 * the test ends — the tombstone too, since its evidence is written to the recording logger rather than to
 * `audit_log` and a tombstone left behind would read as a divergence to the reconciler. The second case runs the
 * real pipeline, the real audit logger included, inside an outer transaction it rolls back (the erasure's own
 * transaction nests as a savepoint), and asks the real subject-erasure reconciler whether the tombstone is
 * answered — which is the property the evidence scope exists for.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(EraseIdentitySubject::class)]
#[CoversClass(FulfilIdentityErasure::class)]
#[CoversClass(DbalTotpFactorPersonReferences::class)]
final class TotpFactorErasureFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private string $subjectId;

    private string $scopeId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->subjectId = Uuid::generate();
        $this->scopeId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'DELETE FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => $this->scope()],
        );

        $owned = ['identity_totp_factor' => 'user_id', 'identity_erasure_resweep' => 'subject_id'];

        foreach ($owned as $table => $column) {
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s = CAST(:id AS UUID)', $table, $column),
                ['id' => $this->subjectId],
            );
        }

        $connection->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );

        parent::tearDown();
    }

    #[Test]
    public function theFactorLeavesItsKeyIsTombstonedAndTheEvidenceNamesTheScopeNotThePerson(): void
    {
        $this->seedCommittedSubjectWithAFactor();

        $this->assertSame(
            1,
            $this->rowsNamingTheSubject('identity_totp_factor', 'user_id'),
            'the factor was really there',
        );
        $this->assertSame('live', $this->keyState(), 'and so was its key');
        $this->assertContains(
            $this->subjectId,
            (new DbalTotpFactorPersonReferences($this->connection()))->retainedPersonIds(),
            'the detective source reads the column the factor holds the person in',
        );

        $audit = new RecordingAuditLogger();
        $result = $this->chain($audit)->execute($this->subjectId);

        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->totpFactorsDeleted);
        $this->assertSame(0, $this->rowsNamingTheSubject('identity_totp_factor', 'user_id'));
        $this->assertSame('erasure', $this->keyState());
        $this->assertNotContains(
            $this->subjectId,
            (new DbalTotpFactorPersonReferences($this->connection()))->retainedPersonIds(),
        );

        $evidence = \array_values(\array_filter(
            $audit->records,
            static fn (array $record): bool => 'GDPR_SUBJECT_ERASED' === $record['action'],
        ));
        $this->assertCount(1, $evidence);
        $this->assertSame($this->scope(), $evidence[0]['metadata']['encryption_scope_id'] ?? null);
        $this->assertSame(1, $evidence[0]['metadata']['totp_factors_deleted'] ?? null);
        $this->assertStringNotContainsString(
            $this->subjectId,
            \json_encode($evidence[0]['metadata'], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(
            [],
            $this->columnsHoldingTheSubject(),
            "A column still holds the subject. The re-sweep schedule is excluded by name: it is the erasure's own "
            . 'instrument.',
        );
    }

    #[Test]
    public function theRealPipelineLeavesTheTombstoneAnsweredForTheReconciler(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->seedCommittedSubjectWithAFactor();
            $this->assertSame('live', $this->keyState(), 'the key was really there to destroy');

            $result = $this->chain($this->service(AuditLogger::class))->execute($this->subjectId);

            $this->assertSame(1, $result->totpFactorsDeleted);
            $this->assertSame('erasure', $this->keyState());
            $this->assertNotContains(
                $this->scope(),
                $this->service(SubjectErasureReconciler::class)->unreconciledScopes(),
                'the tombstone of the erased factor has no GDPR_SUBJECT_ERASED entry naming its scope',
            );
            $evidence = $connection->fetchOne(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'GDPR_SUBJECT_ERASED' "
                . "AND metadata->>'encryption_scope_id' = :scope",
                ['scope' => $this->scope()],
            );
            $this->assertIsNumeric($evidence);
            $this->assertSame(1, (int) $evidence);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function seedCommittedSubjectWithAFactor(): void
    {
        $user = User::register(
            $this->subjectId,
            'totp-erasure-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);

        $this->service(TotpFactorRepository::class)->save(TotpFactor::enrol(
            Uuid::generate(),
            $this->subjectId,
            $this->scopeId,
            $this->service(TotpSecretVault::class)->seal($this->scopeId, $this->subjectId, \random_bytes(20)),
            SystemClock::now(),
        ));
        $this->service(EntityManagerInterface::class)->clear();
    }

    private function chain(AuditLogger $audit): FulfilIdentityErasure
    {
        return new FulfilIdentityErasure(
            $this->service(EraseIdentitySubject::class),
            $this->service(AuditSubjectTrailErasure::class),
            $this->service(EventStoreSubjectAnonymiser::class),
            $this->service(ActiveAdministratorDirectory::class),
            $this->service(PurgeUserSessions::class),
            $this->service(PurgeUserMembership::class),
            $this->service(PurgeUserInvitations::class),
            $audit,
            $this->service(ActorContextFactory::class),
            $this->service(TransactionManager::class),
            $this->service(ErasureResweepRepository::class),
            $this->service(PersonReferenceErasure::class),
        );
    }

    private function scope(): string
    {
        return TotpSecretVault::scopeOf($this->scopeId)->toString();
    }

    /**
     * `live`, `absent`, or the tombstone's destroy reason.
     */
    private function keyState(): string
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT destroyed_at, destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => $this->scope()],
        );

        if (false === $row) {
            return 'absent';
        }

        if (null === ($row['destroyed_at'] ?? null)) {
            return 'live';
        }

        $reason = $row['destroy_reason'] ?? null;
        $this->assertIsString($reason);

        return $reason;
    }

    private function rowsNamingTheSubject(string $table, string $column): int
    {
        $count = $this->connection()->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE %s = CAST(:id AS UUID)', $table, $column),
            ['id' => $this->subjectId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function columnsHoldingTheSubject(): array
    {
        $found = [];
        $columns = $this->connection()->fetchAllAssociative(
            'SELECT c.table_name, c.column_name FROM information_schema.columns c '
            . 'JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name '
            . "WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE' "
            . "AND c.data_type IN ('uuid', 'character varying', 'text', 'json', 'jsonb') "
            . "AND c.table_name <> 'identity_erasure_resweep' ORDER BY c.table_name, c.column_name",
        );
        $this->assertGreaterThan(50, \count($columns), 'the sweep must cover the schema or it proves nothing');

        foreach ($columns as $column) {
            $table = $column['table_name'] ?? null;
            $name = $column['column_name'] ?? null;
            $this->assertIsString($table);
            $this->assertIsString($name);
            $count = $this->connection()->fetchOne(
                \sprintf('SELECT COUNT(*) FROM "%s" WHERE CAST("%s" AS TEXT) ILIKE :needle', $table, $name),
                ['needle' => '%' . $this->subjectId . '%'],
            );
            $this->assertIsNumeric($count);

            if ((int) $count > 0) {
                $found[] = $table . '.' . $name;
            }
        }

        return $found;
    }

    private function connection(): Connection
    {
        return $this->service(EntityManagerInterface::class)->getConnection();
    }
}
