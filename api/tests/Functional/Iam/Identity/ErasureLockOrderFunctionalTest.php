<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The erasure chain's lock order, measured against REAL Postgres and the real adapters.
 *
 * `ErasureLockOrderTest` pins the order the USE CASES reach for the three tables, over in-memory doubles that
 * record a statement rather than a lock. This one closes the other half: that the adapters those statements
 * resolve to really take the row locks, and really take them in that order. Neither proves the other — a
 * `DELETE` that stopped locking would leave the unit test green, and a reordering would leave a per-adapter
 * lock test green.
 *
 * **Two observation points, three questions.** A lock order is only visible from a contender, and two
 * transactions cannot be made to race inside one PHPUnit process here: the image has no `pcntl` (no fork) and
 * no procedural `pgsql` extension (no asynchronous query). The equivalent claim without concurrency is about
 * the instant of acquisition — "A takes X before Y" is "when A takes Y, A already holds X" — so a second
 * connection is asked, where each lock is taken, what the transaction holds:
 *
 *   - At the `identity_user` row: `iam_invitation` must ALREADY be locked. That is the accept/revoke pair's
 *     order (`iam_invitation` → `identity_user`), which an accept cannot reverse — it arrives holding a token
 *     and learns the identity only from the invitation it has already locked.
 *   - At the same instant: `identity_password_reset_token` must NOT be locked yet. That is the reset pair's
 *     order (`identity_user` → `identity_password_reset_token`), which those paths cannot reverse because
 *     their own correctness needs the identity lock first — it is the supersede mutex, and the status
 *     re-read under it is what walls a reset the admin just suspended.
 *   - At the reset-token delete: `identity_user` must already be locked. **This is not the second question
 *     restated.** Those two fire where `remove()` is CALLED; a `remove()` that stopped flushing would defer
 *     its `DELETE` past the bulk delete — a DQL bulk delete never flushes the unit of work — and leave both
 *     of them true while the real order became invitation, token, identity. Only this one sees that.
 *
 * The negative question is not a weaker assertion than the positive ones. Reverting either statement flips
 * exactly one answer, so each cycle has its own witness, and the adapter that stops locking has a third.
 *
 * `NOWAIT` is what makes the questions askable at all: it turns "somebody else holds it" into an immediate
 * `55P03` instead of a hang, and the probe's own transaction is rolled back either way so it never becomes a
 * contender itself.
 *
 * The audit logger is doubled — it sits past the observation point and outside the three-table graph under
 * test, and the real one would leave `security` rows in a table other functional tests read. Everything that
 * touches the three tables is the container's own service.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
#[CoversClass(EraseIdentitySubject::class)]
final class ErasureLockOrderFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4b01';

    private string $subjectId;

    private string $invitationId;

    private string $resetTokenId;

    private ?Connection $outside = null;

    private EntityManagerInterface $entityManager;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->subjectId = Uuid::generate();
        $this->invitationId = Uuid::generate();
        $this->resetTokenId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        // Nothing to reclaim if setUp never got as far as the EntityManager: the ids are unset and
        // `outsideConnection()` would die on an uninitialised typed property, replacing the real diagnosis
        // with its own.
        if (!isset($this->entityManager)) {
            parent::tearDown();

            return;
        }

        // The fixtures are COMMITTED — a row written inside a rolled-back transaction is invisible to the
        // probe's connection, which would report every row unlocked and the test would prove nothing. So they
        // are removed by hand, however the test ends, and by id so a parallel row is never collateral.
        $connection = $this->outsideConnection();
        $connection->executeStatement(
            'DELETE FROM identity_password_reset_token WHERE id = CAST(:id AS UUID)',
            ['id' => $this->resetTokenId],
        );
        $connection->executeStatement(
            'DELETE FROM iam_invitation WHERE id = CAST(:id AS UUID)',
            ['id' => $this->invitationId],
        );
        $connection->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $connection->close();

        $this->outside = null;
        parent::tearDown();
    }

    #[Test]
    public function theInvitationIsAlreadyLockedAndTheResetTokenIsNotWhenTheIdentityRowIsTaken(): void
    {
        $this->seedCommittedSubject();

        $invitationLockedAtIdentityLock = null;
        $resetTokenLockedAtIdentityLock = null;
        $identityGoneAtTokenDelete = null;

        $result = $this->chainProbing(
            function () use (&$invitationLockedAtIdentityLock, &$resetTokenLockedAtIdentityLock): void {
                $invitationLockedAtIdentityLock = $this->isLocked('iam_invitation', $this->invitationId);
                $resetTokenLockedAtIdentityLock = $this->isLocked(
                    'identity_password_reset_token',
                    $this->resetTokenId,
                );
            },
            function () use (&$identityGoneAtTokenDelete): void {
                $identityGoneAtTokenDelete = $this->identityRowIsGoneOnOurOwnConnection();
            },
        )->execute($this->subjectId);

        // Asserted before the order, and not decoration: a statement matching no row takes no lock, so a probe
        // run over an empty store would answer "unlocked" for the honest reason and read as a passing test.
        $this->assertTrue($result->identityErased, 'the identity was never there, so nothing locked its row');
        $this->assertSame(1, $result->invitationsDeleted, 'no invitation row existed for the purge to lock');
        $this->assertSame(1, $result->resetTokensDeleted, 'no reset-token row existed for the delete to lock');

        $this->assertTrue(
            $invitationLockedAtIdentityLock,
            'The chain took the `identity_user` row before it had `iam_invitation`. Accept and revoke take '
            . 'that pair the other way round and cannot reorder, so this is an ABBA deadlock reachable by an '
            . 'invitee clicking their link while the same subject is being erased.',
        );
        $this->assertFalse(
            $resetTokenLockedAtIdentityLock,
            'The chain already held `identity_password_reset_token` when it took the `identity_user` row. '
            . 'Both reset paths take that pair the other way round and cannot reorder, so this is an ABBA '
            . "deadlock reachable by a password reset completing during the same subject's erasure.",
        );
        $this->assertTrue(
            $identityGoneAtTokenDelete,
            'The chain reached the reset-token delete without having issued the `identity_user` DELETE. The '
            . 'two assertions above cannot see this: they fire where `remove()` is CALLED, and a `remove()` '
            . 'that stopped flushing would defer its DELETE past the bulk delete below while leaving both of '
            . 'them true. That is ABBA #2 again, reached by an adapter change instead of by a reordering. '
            . 'This asks whether the row is GONE rather than whether it is LOCKED, because the refusal now '
            . 'locks it before the delete either way — a lock answer would be true whether or not `remove()` '
            . 'flushed, which is the witness going quiet rather than passing.',
        );
    }

    /**
     * Whether the subject's row is already gone from the erasure's OWN view — the uncommitted `DELETE` is
     * visible to the transaction that issued it and to nothing else, which is exactly the property wanted:
     * the second connection cannot answer this, because to it the row is still there until commit.
     */
    private function identityRowIsGoneOnOurOwnConnection(): bool
    {
        $rows = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $this->assertIsNumeric($rows);

        return 0 === (int) $rows;
    }

    /**
     * @param callable(): void $beforeIdentityRowLock  fires where the `identity_user` row lock is taken
     * @param callable(): void $beforeResetTokenDelete fires where the reset-token row locks are taken
     */
    private function chainProbing(
        callable $beforeIdentityRowLock,
        callable $beforeResetTokenDelete,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                new ProbingUserRepository($this->service(UserRepository::class), $beforeIdentityRowLock(...)),
                new ProbingPasswordResetTokenRepository(
                    $this->service(PasswordResetTokenRepository::class),
                    $beforeResetTokenDelete(...),
                ),
                $this->service(TransactionManager::class),
            ),
            $this->service(AuditSubjectTrailErasure::class),
            $this->service(EventStoreSubjectAnonymiser::class),
            $this->service(ActiveAdministratorDirectory::class),
            $this->service(PurgeUserSessions::class),
            $this->service(PurgeUserMembership::class),
            $this->service(PurgeUserInvitations::class),
            new RecordingAuditLogger(),
            $this->service(ActorContextFactory::class),
            $this->service(TransactionManager::class),
        );
    }

    /**
     * Committed through the production adapters, so the rows carry the shape the erasure will meet.
     */
    private function seedCommittedSubject(): void
    {
        $user = User::register(
            $this->subjectId,
            'lock-order-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        // Drained like the other two fixtures: nothing publishes them today, but a fixture whose job is to
        // leave the database as it found it must not be the one carrying a pending event when that changes.
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);

        $generated = SingleUseToken::mint(new DateTimeImmutable('+1 hour'));
        $invitation = Invitation::create(
            $this->invitationId,
            self::ORGANIZATION_ID,
            $this->subjectId,
            $generated->token,
        );
        $invitation->pullDomainEvents();
        $this->service(InvitationRepository::class)->save($invitation);

        $token = PasswordResetToken::issue($this->resetTokenId, $this->subjectId, $generated->token);
        $token->pullDomainEvents();
        $this->service(PasswordResetTokenRepository::class)->save($token);

        // The chain runs on this connection and must not meet the identity map's copies of the rows above.
        $this->entityManager->clear();
    }

    /**
     * Asks the second connection to take the row. `NOWAIT` turns "somebody else holds it" into an immediate
     * `55P03` rather than a hang, which is the only reason this can run in one process.
     */
    private function isLocked(string $table, string $id): bool
    {
        $probe = $this->outsideConnection();
        $probe->beginTransaction();

        try {
            $probe->fetchOne(
                \sprintf('SELECT id FROM %s WHERE id = CAST(:id AS UUID) FOR UPDATE NOWAIT', $table),
                ['id' => $id],
            );

            return false;
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState(), 'the probe failed for another reason');

            return true;
        } finally {
            if ($probe->isTransactionActive()) {
                $probe->rollBack();
            }
        }
    }

    /**
     * A second connection is what makes the question askable at all: the container's own is the one holding
     * the locks, so probing with it would always succeed and prove nothing.
     */
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
