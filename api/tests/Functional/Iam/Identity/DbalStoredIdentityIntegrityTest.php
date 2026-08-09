<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\StoredIdentityIntegrity;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalStoredIdentityIntegrity;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the integrity probes against REAL Postgres, which is the only place they can be proven: every shape
 * they exist to detect is one the aggregate refuses to build, so a test over hydrated objects would be
 * asserting behaviour on states that cannot reach the reads.
 *
 * The two SQL clauses that carry the whole control were both wrong before this file existed, and neither
 * failure was a crash. A JSON `null` element expands to SQL NULL and `NULL NOT IN (…)` is NULL rather than
 * TRUE, so the row was dropped by the very predicate written to catch it — the control certifying the
 * corruption it exists to find. And `json_array_elements_text` raises on a non-array, which aborted the whole
 * read: the command then answered "no verdict, do not repair anything" over the one row that needed
 * repairing, and never reached the credential column at all.
 *
 * Counts are asserted as DELTAS around the seeded rows, never as absolutes: these reads span the whole table
 * and the database is shared, so an absolute would pass or fail on whatever else happens to be stored. The
 * orphan value is unique per run and asserted by containment for the same reason. Everything runs inside a
 * rolled-back transaction.
 *
 * @internal
 */
#[CoversClass(DbalStoredIdentityIntegrity::class)]
final class DbalStoredIdentityIntegrityTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private StoredIdentityIntegrity $storedIdentities;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->storedIdentities = new DbalStoredIdentityIntegrity($this->connection);
    }

    #[Test]
    public function itReportsAStoredRoleValueNoEnumCaseBacks(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $orphan = 'GHOST_' . \strtoupper(\substr(\str_replace('-', '', Uuid::generate()), 0, 8));
            $this->seedIdentity(roles: \sprintf('["MANAGER", "%s"]', $orphan));

            $reported = $this->storedIdentities->roleValuesOutsideTheEnum();

            $this->assertContains($orphan, $reported);
            // The live case sits in the very same column: reporting it would make every run a finding, and a
            // control that cries wolf on a clean table is switched off within a week.
            $this->assertNotContains('MANAGER', $reported);
        });
    }

    #[Test]
    public function itReportsANullElementInsteadOfDroppingTheRow(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedIdentity(roles: '["ADMIN", null]');

            // `NULL NOT IN (…)` is NULL, not TRUE. Without the explicit null arm the row is silently dropped
            // and this identity — holding a role element no case backs — is certified clean.
            $this->assertContains('null', $this->storedIdentities->roleValuesOutsideTheEnum());
        });
    }

    #[Test]
    public function itCountsAMalformedRolesColumnWithoutAbortingTheOtherReads(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $malformedBefore = $this->storedIdentities->identitiesWithMalformedRoles();
            $credentialsBefore = $this->storedIdentities->identitiesWithAnUnreadableCredential();
            $orphan = 'GHOST_' . \strtoupper(\substr(\str_replace('-', '', Uuid::generate()), 0, 8));
            $this->seedIdentity(roles: '{"a": "ADMIN"}');
            $this->seedIdentity(roles: \sprintf('["%s"]', $orphan));
            $this->seedIdentity(passwordHash: '');

            $this->assertSame($malformedBefore + 1, $this->storedIdentities->identitiesWithMalformedRoles());
            // The load-bearing half: a set-returning function in FROM is evaluated before any WHERE could
            // exclude the row, so an unguarded expander raises and takes every other finding down with it.
            $this->assertContains($orphan, $this->storedIdentities->roleValuesOutsideTheEnum());
            // And the credential column keeps answering — the command reads it AFTER this one, so an aborting
            // roles probe used to cost the operator a finding in a column that was perfectly readable.
            $this->assertSame(
                $credentialsBefore + 1,
                $this->storedIdentities->identitiesWithAnUnreadableCredential(),
            );
        });
    }

    #[Test]
    public function itCountsACredentialTheValueObjectRefuses(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $before = $this->storedIdentities->identitiesWithAnUnreadableCredential();
            $this->seedIdentity(passwordHash: '');

            $this->assertSame($before + 1, $this->storedIdentities->identitiesWithAnUnreadableCredential());
        });
    }

    #[Test]
    public function itCountsAnAdmittedIdentityHoldingNoCredentialButNotAnInvitedOne(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $before = $this->storedIdentities->admittedIdentitiesWithoutACredential();
            // The INVITED row is the control: for it the null IS the modelled state, and counting it would
            // make the check fire on every installation with an outstanding invitation.
            $this->seedIdentity(passwordHash: null, status: 'INVITED');

            $this->assertSame($before, $this->storedIdentities->admittedIdentitiesWithoutACredential());

            $this->seedIdentity(passwordHash: null);

            $this->assertSame($before + 1, $this->storedIdentities->admittedIdentitiesWithoutACredential());
        });
    }

    private function seedIdentity(
        string $roles = '["MANAGER"]',
        ?string $passwordHash = 'hashed-password-placeholder',
        string $status = 'ACTIVE',
    ): void {
        // Raw DBAL and not the aggregate: every shape under test is one the writer refuses to produce, which
        // is precisely why the reads bypass hydration.
        $this->connection->executeStatement(
            'INSERT INTO identity_user '
            . '(id, email, password_hash, roles, status, failed_attempts, created_at, updated_at) '
            . 'VALUES (CAST(:id AS UUID), :email, :hash, CAST(:roles AS JSON), :status, 0, NOW(), NOW())',
            [
                'id' => Uuid::generate(),
                'email' => \sprintf('integrity-%s@erpify.test', Uuid::generate()),
                'hash' => $passwordHash,
                'roles' => $roles,
                'status' => $status,
            ],
        );
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->entityManager->clear();
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
