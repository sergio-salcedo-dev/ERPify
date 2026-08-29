<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AdministratorErasureRequiresDemotion;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The administrator refusal is a lock-free read, so it can be overtaken between its own statement and the
 * `DELETE` it is supposed to guard.
 *
 * `FulfilIdentityErasure` refuses a subject that still carries `ADMIN` — the point being that the demotion
 * happens FIRST and is recorded, since a demotion writes its own `USER_ROLES_CHANGED` `SECURITY` row. The
 * predicate is `ActiveAdministratorDirectory::holdsAdministratorRole()`, a `SELECT EXISTS` that takes no lock
 * and carries no status predicate. Under `READ COMMITTED` it sees the snapshot at statement time, and nothing
 * holds a lock on the subject's row between that read and the `DELETE` issued later in the same transaction.
 *
 * **No fork is needed to force this interleaving, and that is the defect stated as a test.** A race normally
 * cannot be driven from one PHPUnit process here — the image carries neither `pcntl` nor the procedural
 * `pgsql` extension — but a contender only has to be *blocked* when the first transaction holds a lock. This
 * one holds none, so the grant commits immediately and the sequence is deterministic rather than hopeful:
 * guard reads, second connection grants `ADMIN` and commits, erasure proceeds.
 *
 * The hook is {@see ProbingActiveAdministratorDirectory}, whose callback fires in the window BETWEEN the two
 * administrator readings — after the unlocked one has answered, before the locked one is asked. That is the
 * window the invitation purge spans in production, and it is the only seam from which the contender can win:
 * a hook at the `identity_user` row lock fires after the locked reading, so the contender is always blocked
 * there and the refusal branch is never reached. It wraps the production adapter, so both readings are real.
 *
 * The fixture is COMMITTED, because the second connection has to see the subject; it is removed by hand in
 * `tearDown()` however the test ends.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
final class AdministratorErasureRaceFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private EntityManagerInterface $entityManager;

    private ?Connection $outside = null;

    private string $subjectId;

    /**
     * Whether the contender's grant was committed AND visible, read back on its own connection at the
     * instant it was made. It cannot be checked afterwards: if the defect is live the row is gone by then,
     * and "no row" would read as "no ADMIN" — a vacuous pass wearing the shape of a real one.
     */
    private bool $grantLanded = false;

    /** Whether the contender was refused the row lock instead, which is the erasure holding it. */
    private bool $grantBlocked = false;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->subjectId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();

            return;
        }

        $this->outsideConnection()->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        parent::tearDown();
    }

    #[Test]
    public function aGrantThatLandsBeforeTheLockedReadingIsRefused(): void
    {
        $this->seedCommittedNonAdministrator();

        $refused = false;

        try {
            $this->erasureOvertakenByAGrant()->execute($this->subjectId);
        } catch (AdministratorErasureRequiresDemotion) {
            $refused = true;
        }

        // Anti-vacuity: the contender must have reached the window and done ONE of the two things. Neither
        // flag set means it never ran and every assertion below would be about nothing.
        $this->assertNotSame(
            $this->grantBlocked,
            $this->grantLanded,
            'the contender neither committed a grant nor was refused the lock, so this case proves nothing',
        );

        // The contender wins this window by construction — the erasure holds no lock here yet — and that is
        // the point: it puts the DECIDING branch under test rather than the blocking one. A hook at the row
        // lock instead makes the contender always lose, and then the refusal is never reached and its
        // deletion goes unnoticed. So the win is asserted first, and separately: if some future seam makes
        // the contender lose, this says so instead of passing over a branch it stopped exercising.
        $this->assertTrue($this->grantLanded, 'the contender lost a window it cannot lose; the seam moved');
        $this->assertTrue(
            $refused,
            'An identity carrying ADMIN was erased without ever being demoted. The refusal read the role '
            . 'without locking the row, so a grant committed between that read and the DELETE it guards — '
            . 'and the audit trail now shows an erasure with no USER_ROLES_CHANGED preceding it.',
        );
    }

    /**
     * The production erasure, with the one seam the interleaving needs. Every other collaborator is real.
     */
    private function erasureOvertakenByAGrant(): FulfilIdentityErasure
    {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                $this->service(UserRepository::class),
                $this->service(PasswordResetTokenRepository::class),
                $this->service(TransactionManager::class),
            ),
            $this->service(AuditSubjectTrailErasure::class),
            $this->service(EventStoreSubjectAnonymiser::class),
            new ProbingActiveAdministratorDirectory(
                $this->service(ActiveAdministratorDirectory::class),
                $this->grantAdministratorFromASecondConnection(...),
            ),
            $this->service(PurgeUserSessions::class),
            $this->service(PurgeUserMembership::class),
            $this->service(PurgeUserInvitations::class),
            new RecordingAuditLogger(),
            $this->service(ActorContextFactory::class),
            $this->service(TransactionManager::class),
        );
    }

    private function carriesAdministratorRole(): bool
    {
        return (bool) $this->outsideConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM identity_user WHERE id = CAST(:id AS UUID) '
            . "AND roles::jsonb @> '[\"ADMIN\"]'::jsonb)",
            ['id' => $this->subjectId],
        );
    }

    /**
     * Grants `ADMIN` to the subject on a second connection and commits, in the window between the refusal
     * and the delete. Raw SQL rather than the role use case: what is under test is the erasure's guard, and
     * a contender driven through `ChangeUserRoles` would drag its own locking into the measurement.
     */
    private function grantAdministratorFromASecondConnection(): void
    {
        // A `lock_timeout` is what makes "blocked" an OUTCOME rather than a hung suite. Without it this
        // method simply never returns once the erasure holds the row — which is the fix working, reported as
        // a timeout nobody can assert on.
        $this->outsideConnection()->executeStatement("SET lock_timeout = '2s'");

        try {
            $this->outsideConnection()->executeStatement(
                'UPDATE identity_user SET roles = :roles WHERE id = CAST(:id AS UUID)',
                ['roles' => \json_encode([Role::ADMIN->value], JSON_THROW_ON_ERROR), 'id' => $this->subjectId],
            );
        } catch (DriverException $driverException) {
            // 55P03 `lock_not_available`: the erasure holds the subject's row, which is the whole point.
            $this->assertSame('55P03', $driverException->getSQLState(), 'the contender failed for an unrelated reason');
            $this->grantBlocked = true;

            return;
        }

        $this->grantLanded = $this->carriesAdministratorRole();
    }

    private function seedCommittedNonAdministrator(): void
    {
        $user = User::register(
            $this->subjectId,
            'admin-race-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        // `save()` flushes; only the identity map needs clearing so the erasure re-hydrates from the row.
        $this->service(UserRepository::class)->save($user);
        $this->entityManager->clear();
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection(
                $this->entityManager->getConnection()->getParams(),
            );
        }

        return $this->outside;
    }
}
