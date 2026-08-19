<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineActiveAdministratorDirectory;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the guard adapter against REAL Postgres — the `json`→`jsonb` role containment the in-memory double
 * can only approximate. An active administrator is a row whose status is `ACTIVE` and whose `roles` contain
 * `ADMIN`; the query excludes the passed id, so the last active admin never rescues itself, and a SUSPENDED,
 * DEACTIVATED or INVITED admin or an active non-admin never counts. Carrying the role is the second, weaker
 * question — same containment, no status predicate — so the two must not be proven by one fixture.
 *
 * Each test runs inside a rolled-back transaction over a truncated `identity_user`, so the shared dev DB is
 * left untouched.
 *
 * @internal
 */
#[CoversClass(DoctrineActiveAdministratorDirectory::class)]
final class DoctrineActiveAdministratorDirectoryTest extends KernelTestCase
{
    private const string ADMIN_A = '0190f100-0000-7000-8000-0000000000a1';

    private const string ADMIN_B = '0190f100-0000-7000-8000-0000000000a2';

    private const string SUSPENDED_ADMIN = '0190f100-0000-7000-8000-0000000000a3';

    private const string DEACTIVATED_ADMIN = '0190f100-0000-7000-8000-0000000000a4';

    private const string INVITED_ADMIN = '0190f100-0000-7000-8000-0000000000a6';

    private const string ACTIVE_VIEWER = '0190f100-0000-7000-8000-0000000000a5';

    private const string UNKNOWN_ID = '0190f100-0000-7000-8000-0000000000af';

    private const string ORPHAN_MEMBERSHIP = '0190f100-0000-7000-8000-0000000000b1';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineActiveAdministratorDirectory $directory;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->directory = new DoctrineActiveAdministratorDirectory($this->connection);
    }

    public function testAnotherActiveAdministratorKeepsTheDirectorySatisfied(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::ADMIN_B, 'admin-b@erpify.test', [Role::ADMIN->value]);

            $this->assertTrue($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
        });
    }

    public function testTheSoleActiveAdministratorDoesNotCountItself(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);

            // Excluded → nothing remains; not excluded → it is the surviving admin.
            $this->assertFalse($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
            $this->assertTrue($this->directory->keepsAnActiveAdminWithout(self::UNKNOWN_ID));
        });
    }

    public function testANonActiveAdministratorDoesNotCount(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::SUSPENDED_ADMIN, 'suspended-admin@erpify.test', [Role::ADMIN->value], 'SUSPENDED');
            $this->seed(self::DEACTIVATED_ADMIN, 'deactivated-admin@erpify.test', [Role::ADMIN->value], 'DEACTIVATED');
            // An INVITED admin holds the role but has never activated, so it must not rescue the last ACTIVE
            // admin any more than a SUSPENDED or DEACTIVATED one does — the guard filters on `status = ACTIVE`.
            $this->seed(self::INVITED_ADMIN, 'invited-admin@erpify.test', [Role::ADMIN->value], 'INVITED');

            $this->assertFalse($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
        });
    }

    public function testAnActiveNonAdministratorDoesNotCount(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::ACTIVE_VIEWER, 'viewer@erpify.test', [Role::VIEWER->value]);

            $this->assertFalse($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
        });
    }

    public function testTheGuardLocksTheActiveAdminSetToSerializeConcurrentTransitions(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::ADMIN_B, 'admin-b@erpify.test', [Role::ADMIN->value]);

            $this->directory->keepsAnActiveAdminWithout(self::ADMIN_A);

            // `SELECT ... FOR UPDATE` takes a RowShareLock on identity_user held until commit; a plain read
            // would only take AccessShareLock. Its presence proves the guard locks the admin set — the lock
            // that makes two concurrent last-two-admin transitions serialize (the loser re-reads the committed
            // state and is rejected) instead of both draining every administrator from a stale snapshot.
            $this->assertGreaterThan(0, $this->rowShareLocksHeldOnIdentityUser());
        });
    }

    public function testTakingTheLockAloneAcquiresTheSameLockTheGuardDoes(): void
    {
        // The lock-only member exists so a caller can take the set BEFORE the single row it is about to
        // change; degraded to a plain read it would order nothing while every other gate stayed green.
        //
        // What this proves is narrow, and narrower than the name suggests: RowShareLock is a RELATION-level
        // mode that any `FOR UPDATE` against identity_user takes, whatever its predicate, its ordering or the
        // number of rows it matches. So a one-row `FOR UPDATE` on a nonexistent id would satisfy it. What it
        // does establish is that the void member issues a row-locking statement at all rather than a plain
        // read — the predicate and the ordering are pinned by AdministratorSetLockStatementGateTest, which is
        // the only thing in the suite that goes red when `ORDER BY id` is deleted.
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::ADMIN_B, 'admin-b@erpify.test', [Role::ADMIN->value]);

            $this->directory->lockActiveAdministrators();

            $this->assertGreaterThan(0, $this->rowShareLocksHeldOnIdentityUser());
        });
    }

    public function testCarryingTheAdministratorRoleIsIndependentOfStatus(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::SUSPENDED_ADMIN, 'suspended-admin@erpify.test', [Role::ADMIN->value], 'SUSPENDED');
            $this->seed(self::INVITED_ADMIN, 'invited-admin@erpify.test', [Role::ADMIN->value], 'INVITED');
            $this->seed(self::ACTIVE_VIEWER, 'viewer@erpify.test', [Role::VIEWER->value]);

            // The erasure refusal asks about the role, not the activity: suspending or never activating an
            // administrator must not become a way to erase them without recording the demotion first.
            $this->assertTrue($this->directory->holdsAdministratorRole(self::ADMIN_A));
            $this->assertTrue($this->directory->holdsAdministratorRole(self::SUSPENDED_ADMIN));
            $this->assertTrue($this->directory->holdsAdministratorRole(self::INVITED_ADMIN));

            $this->assertFalse($this->directory->holdsAdministratorRole(self::ACTIVE_VIEWER));
            $this->assertFalse($this->directory->holdsAdministratorRole(self::UNKNOWN_ID));
        });
    }

    public function testAnAdministratorMembershipWhoseIdentityIsGoneCannotKeepTheDirectorySatisfied(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            // A membership whose user is not a live identity — what an erasure interrupted between the two
            // contexts leaves behind. This pins WHICH source answers the invariant: the directory reads
            // `identity_user.roles` and never joins membership, so belonging alone is not authority and a row
            // here can never rescue a drained administrator pool. If authorization is ever re-pointed at
            // membership, this test is the one that has to be revisited deliberately rather than discovered
            // in production.
            $seeded = $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO membership (id, user_id, organization_id, created_at, updated_at)
                    VALUES (:id, :userId, :organizationId, NOW(), NOW())
                    SQL,
                [
                    'id' => self::ORPHAN_MEMBERSHIP,
                    'userId' => self::UNKNOWN_ID,
                    'organizationId' => $this->provisionOrganization(),
                ],
            );

            // Without this the whole test is vacuous: both assertions below already hold on the ADMIN_A-only
            // seed, so a membership that failed to insert would read exactly like one the directory ignores.
            // Cast because `Connection::executeStatement()` is the one API here typed `int|string`: a driver
            // reporting the count as a numeric string would turn the anti-vacuity guard into a spurious red.
            $this->assertSame(
                1,
                (int) $seeded,
                'The orphan membership was not seeded — nothing below proves anything.',
            );
            $this->assertFalse($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
            $this->assertFalse($this->directory->holdsAdministratorRole(self::UNKNOWN_ID));
        });
    }

    public function testCarryingTheAdministratorRoleIsMatchedUnderAnyUuidCasing(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);

            // `id` is a `uuid` column, so the cast compares canonically — an upper-cased id must not slip past
            // the refusal the way a naive string `=` would let it.
            $this->assertTrue($this->directory->holdsAdministratorRole(\strtoupper(self::ADMIN_A)));
        });
    }

    /**
     * `SELECT … FOR UPDATE` takes a RowShareLock on `identity_user` held until commit; a plain read would only
     * take AccessShareLock. Counting it is how both locking members are shown to lock rather than merely read.
     */
    private function rowShareLocksHeldOnIdentityUser(): int
    {
        $rowShareLocks = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*)
                FROM pg_locks
                WHERE relation = 'identity_user'::regclass
                  AND mode = 'RowShareLock'
                  AND pid = pg_backend_pid()
                SQL,
        );

        // pg_locks count(*) surfaces as an int or a numeric string depending on the driver.
        return \is_numeric($rowShareLocks) ? (int) $rowShareLocks : 0;
    }

    /**
     * @param list<string> $roleValues
     */
    private function seed(string $id, string $email, array $roleValues, string $status = 'ACTIVE'): void
    {
        $this->entityManager->persist(
            UserFixtureFactory::create($id, $email, 'seed-password', $roleValues, $status),
        );
        $this->entityManager->flush();
    }

    /**
     * The organization a membership must point at — `membership.organization_id` carries a real foreign key,
     * unlike `user_id`. Created by the test rather than taken from ambient data: the CI database is migrated
     * and never provisioned, so a seed that depended on an organization already existing would insert nothing
     * there and pass while proving nothing.
     */
    private function provisionOrganization(): string
    {
        $organizationId = Uuid::generate();
        $this->connection->executeStatement(
            'INSERT INTO organization (id, name, created_at, updated_at) VALUES (:id, :name, NOW(), NOW())',
            ['id' => $organizationId, 'name' => 'ACME Corp'],
        );

        return $organizationId;
    }

    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            // TRUNCATE is transactional in Postgres, so the rollback below undoes the seeded rows.
            $this->connection->executeStatement(
                'TRUNCATE identity_user, membership, organization RESTART IDENTITY CASCADE',
            );
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
