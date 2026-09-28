<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\KeepOnlyCurrentSession;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Application\RevokeCurrentSessionBestEffort;
use Erpify\Iam\Identity\Application\RevokeRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Session\Application\EvictOtherSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The lock order of every flow that holds BOTH `identity_user` and `identity_recovery_secret`, measured
 * against REAL Postgres and the real adapters.
 *
 * The unit suites pin the order each USE CASE reaches for the two tables, over in-memory doubles that record a
 * call rather than a lock. This closes the other half: that the adapters those calls resolve to really take
 * row locks, and really take them in that order. Neither proves the other — a finder that stopped locking
 * leaves the unit tests green, and a reordering leaves a per-adapter lock test green.
 *
 * **Both flows are here because the invariant is a property of the SET, not of either one.** A deadlock cycle
 * needs two transactions acquiring the same pair in opposite orders, so "redemption takes the user first" is
 * worth nothing on its own; what makes it worth something is that nothing else takes the secret first.
 * Redemption could not reverse its order even if it wanted to — it learns which identity to lock only from the
 * row its selector resolves — while revocation reads the identity solely to prove the caller's credential, so
 * its order is a choice, and a choice is what can be got wrong. Minting reaches the same pair through the same
 * two adapter methods revocation does; its own order is pinned in the unit suite.
 *
 * **Two observation points per flow, three questions**, asked from a second connection with `NOWAIT` (which
 * turns "somebody else holds it" into an immediate `55P03` instead of a hang, and whose own transaction is
 * rolled back either way so the probe never becomes a contender itself). Each question has its own witness,
 * and each was measured by mutating the code and watching exactly that one flip:
 *
 *   - ARRIVING at the `identity_recovery_secret` lock: `identity_user` must ALREADY be held. Swapping the two
 *     acquisitions flips this, and it is the question the whole arrangement exists to ask.
 *   - At the same instant: the secret row must NOT be held yet. This one is the probe's own control. Without
 *     it a probe that answered "locked" for every row would satisfy the question above for the wrong reason,
 *     and the whole test would pass over an adapter that locks nothing at all. It turned out to catch a
 *     second thing nobody designed it for, found while falsifying: a DUPLICATED acquisition — a second locked
 *     read added ahead of the real one — reaches this point with the row already held and flips it, while
 *     both other questions stay green.
 *   - LEAVING that lock: the secret row must now be held. This is the question the `before` point is
 *     structurally blind to — it runs first, so it can never observe whether the inner call locked anything.
 *     Dropping `PESSIMISTIC_WRITE` from the adapter behind the locked finder flips this one and only this one;
 *     calling the UNLOCKED finder instead does not, because the hook then never runs and the reading is null
 *     rather than false — which the first question catches, and is why the two mutations are not the same
 *     experiment.
 *
 * Redemption also takes a third table, `iam_session`, last: its eviction of the identity's other sessions is
 * observed here from the same second connection, because the in-memory journal can only say the locked read
 * RAN, never that the adapter held the survivor's row while it decided.
 *
 * The audit logger is doubled: it sits past the observation point and would otherwise leave `security` rows
 * in a table other functional tests read. Everything touching the two tables under test is the container's
 * own service.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RedeemRecoverySecret::class)]
#[CoversClass(RevokeRecoverySecret::class)]
final class RecoverySecretLockOrderFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private ?Connection $outside = null;

    /**
     * The request's session correlation, which the session seam writes and the eviction reads back — the
     * double of the Symfony session bag a kernel test has no request to hold.
     */
    private RecordingCurrentSessionReference $correlation;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->correlation = new RecordingCurrentSessionReference();
    }

    protected function tearDown(): void
    {
        $this->outside?->close();
        $this->outside = null;
        parent::tearDown();
    }

    #[Test]
    public function redeemingHoldsTheIdentityRowWhenItTakesTheSecretRow(): void
    {
        [$userId, $plaintext, $selector] = $this->seedIdentityHoldingASecret();

        $identityLockedOnArrival = null;
        $secretLockedOnArrival = null;
        $secretLockedOnLeaving = null;

        $redeem = $this->redeemWithProbe(
            function () use ($userId, $selector, &$identityLockedOnArrival, &$secretLockedOnArrival): void {
                $identityLockedOnArrival = $this->isLocked('identity_user', $userId);
                $secretLockedOnArrival = $this->isLocked('identity_recovery_secret', $selector);
            },
            function () use ($selector, &$secretLockedOnLeaving): void {
                $secretLockedOnLeaving = $this->isLocked('identity_recovery_secret', $selector);
            },
        );

        $signedIn = [];
        // The seam records rather than ignores: this test is about locks, but a redemption that never
        // reached its login would take those locks and prove nothing about the flow they belong to.
        $redeem->redeem($plaintext, $this->sessionSeam($userId, $signedIn));

        $this->assertCount(1, $signedIn, 'the flow never reached the session seam');

        $this->assertTrue(
            $identityLockedOnArrival,
            'the secret row was taken before the identity row — that is the ABBA cycle I-B exists to forbid',
        );
        $this->assertFalse(
            $secretLockedOnArrival,
            'the secret row was already held at its own acquisition, so the probe cannot tell a lock from none',
        );
        $this->assertTrue(
            $secretLockedOnLeaving,
            'the resolving read took no row lock, so the verify and the consume are not serialised at all',
        );

        // The redemption really happened; a probe over a flow that did nothing proves nothing about it.
        $this->assertSame(
            0,
            $this->countSecretsFor($userId),
            'the secret survived a redemption that reported success',
        );
    }

    #[Test]
    public function revokingHoldsTheIdentityRowWhenItTakesTheSecretRow(): void
    {
        [$userId, , $selector] = $this->seedIdentityHoldingASecret();

        $identityLockedOnArrival = null;
        $secretLockedOnArrival = null;
        $secretLockedOnLeaving = null;

        $revoke = $this->revokeWithProbe(
            function () use ($userId, $selector, &$identityLockedOnArrival, &$secretLockedOnArrival): void {
                $identityLockedOnArrival = $this->isLocked('identity_user', $userId);
                $secretLockedOnArrival = $this->isLocked('identity_recovery_secret', $selector);
            },
            function () use ($selector, &$secretLockedOnLeaving): void {
                $secretLockedOnLeaving = $this->isLocked('identity_recovery_secret', $selector);
            },
        );

        // The seam READS the stored credential rather than ignoring it, which is the shape the real closure
        // has: one that answered true without looking would prove nothing about a flow that stopped handing
        // the credential over — and it is only because this flow proves a credential that it reads the
        // identity row at all, which is the whole reason it holds two locks to order.
        $revoke->revoke($userId, static fn (HashedPassword $stored): bool => '' !== $stored->toString());

        $this->assertTrue(
            $identityLockedOnArrival,
            'the secret row was taken before the identity row — the ABBA cycle against minting and redemption',
        );
        $this->assertFalse(
            $secretLockedOnArrival,
            'the secret row was already held at its own acquisition, so the probe cannot tell a lock from none',
        );
        $this->assertTrue(
            $secretLockedOnLeaving,
            'the read took no row lock, so a redemption in flight is not serialised against this revocation',
        );

        // The revocation really happened; a probe over a flow that did nothing proves nothing about it.
        $this->assertSame(
            0,
            $this->countSecretsFor($userId),
            'the secret survived a revocation that reported success',
        );
    }

    /**
     * The eviction against the real adapter: every other session of the identity is revoked in the same
     * transaction as the consumption, the redeemed one survives, and — the half no in-memory double can show —
     * the survivor's row is really held under a row lock while the decision is taken. The UPDATE locks the rows
     * it flips on its own; the survivor is locked ONLY by the ordered locked read, so a probe finding it free is
     * a read that stopped locking, which would reopen the race with a concurrent "sign out my other devices".
     */
    #[Test]
    public function redeemingEvictsEveryOtherSessionUnderTheLockAndKeepsTheRedeemedOne(): void
    {
        [$userId, $plaintext] = $this->seedIdentityHoldingASecret();
        $stolen = $this->persistActiveSession($userId);

        $survivorLockedWhileEvicting = null;
        $redeem = $this->redeemWithProbe(
            static function (): void {
            },
            static function (): void {
            },
            function () use (&$survivorLockedWhileEvicting): void {
                $survivor = $this->correlation->get();
                $this->assertInstanceOf(SessionId::class, $survivor);
                $survivorLockedWhileEvicting = $this->isLocked('iam_session', $survivor->toString());
            },
        );

        $signedIn = [];
        $redeem->redeem($plaintext, $this->sessionSeam($userId, $signedIn));

        $redeemed = $this->correlation->get();
        $this->assertInstanceOf(SessionId::class, $redeemed);
        $this->assertSame('ACTIVE', $this->sessionStatus($redeemed->toString()), 'the redemption evicted its own');
        $this->assertSame('REVOKED', $this->sessionStatus($stolen), 'a session predating the redemption survived');
        $this->assertTrue(
            $survivorLockedWhileEvicting,
            'the survivor was not held while the others were revoked, so a concurrent revoke can still take it',
        );
        $this->assertSame(0, $this->countSecretsFor($userId), 'the secret survived a redemption that succeeded');
    }

    /**
     * An `ACTIVE`, credentialed identity holding one recovery secret — the starting state of both flows.
     *
     * @return array{string, string, string} user id, the `<selector>.<secret>` plaintext, and the selector
     */
    private function seedIdentityHoldingASecret(): array
    {
        $userId = Uuid::generate();
        $user = User::register($userId, \sprintf('holder-%s@erpify.test', $userId), HashedPassword::fromHash(
            '$2y$04$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ012',
        ), Role::AUDIT_READER);
        $user->pullDomainEvents();

        // Minted on the clock the redemption reads; a bare `new DateTimeImmutable()` here would make the
        // secret's liveness depend on the wall clock and the container's clock agreeing.
        $mintClock = self::getContainer()->get(Clock::class);
        $this->assertInstanceOf(Clock::class, $mintClock);
        $generated = RecoverySecret::mint($userId, $mintClock->now());
        $generated->secret->pullDomainEvents();

        $this->entityManager->persist($user);
        $this->entityManager->persist($generated->secret);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $selector = $generated->secret->getId();
        $this->assertNotNull($selector);

        return [$userId, $generated->plaintext(), $selector];
    }

    /**
     * What `SessionMintingSuccessListener` does on a real login — insert the `iam_session` row and stash its
     * id as the request's correlation — against the real table, so the eviction has a real survivor to keep.
     *
     * @param list<string> $signedIn
     *
     * @return Closure(string): void
     */
    private function sessionSeam(string $userId, array &$signedIn): Closure
    {
        return function (string $email) use ($userId, &$signedIn): void {
            $signedIn[] = $email;
            $this->correlation->set(SessionId::fromString($this->persistActiveSession($userId)));
        };
    }

    private function persistActiveSession(string $userId): string
    {
        $clock = self::getContainer()->get(Clock::class);
        $this->assertInstanceOf(Clock::class, $clock);

        $id = SessionId::generate()->toString();
        $session = Session::start($id, $userId, Uuid::generate(), 'test-device', null, $clock->now()->modify('+1 day'));
        $session->pullDomainEvents();

        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $id;
    }

    private function sessionStatus(string $sessionId): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM iam_session WHERE id = CAST(:id AS UUID)',
            ['id' => $sessionId],
        );
        $this->assertIsString($status);

        return $status;
    }

    /**
     * @param callable(): void      $onArrival
     * @param callable(): void      $onLeaving
     * @param callable(): void|null $onEvicting runs when the eviction publishes its fact — after the locked read
     *                                          and the UPDATE, before the consuming transaction commits
     */
    private function redeemWithProbe(
        callable $onArrival,
        callable $onLeaving,
        ?callable $onEvicting = null,
    ): RedeemRecoverySecret {
        $users = self::getContainer()->get(UserRepository::class);
        $secrets = self::getContainer()->get(RecoverySecretRepository::class);
        $transactions = self::getContainer()->get(TransactionManager::class);
        $revokeSessions = self::getContainer()->get(RevokeCurrentSessionBestEffort::class);
        $eventBus = self::getContainer()->get(EventBus::class);
        $clock = self::getContainer()->get(Clock::class);

        $this->assertInstanceOf(UserRepository::class, $users);
        $this->assertInstanceOf(RecoverySecretRepository::class, $secrets);
        $this->assertInstanceOf(TransactionManager::class, $transactions);
        $this->assertInstanceOf(RevokeCurrentSessionBestEffort::class, $revokeSessions);
        $this->assertInstanceOf(EventBus::class, $eventBus);
        $this->assertInstanceOf(Clock::class, $clock);

        $sessions = self::getContainer()->get(SessionRepository::class);
        $this->assertInstanceOf(SessionRepository::class, $sessions);

        return new RedeemRecoverySecret(
            $users,
            ProbingRecoverySecretRepository::aroundSelectorLock($secrets, $onArrival(...), $onLeaving(...)),
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            $revokeSessions,
            new KeepOnlyCurrentSession(
                $this->correlation,
                new EvictOtherSessions(
                    $sessions,
                    new ProbingEventBus($eventBus, ($onEvicting ?? static function (): void {
                    })(...)),
                    $clock,
                ),
            ),
            $eventBus,
            $transactions,
            $clock,
        );
    }

    /**
     * @param callable(): void $onArrival
     * @param callable(): void $onLeaving
     */
    private function revokeWithProbe(callable $onArrival, callable $onLeaving): RevokeRecoverySecret
    {
        $users = self::getContainer()->get(UserRepository::class);
        $secrets = self::getContainer()->get(RecoverySecretRepository::class);
        $transactions = self::getContainer()->get(TransactionManager::class);
        $eventBus = self::getContainer()->get(EventBus::class);

        $this->assertInstanceOf(UserRepository::class, $users);
        $this->assertInstanceOf(RecoverySecretRepository::class, $secrets);
        $this->assertInstanceOf(TransactionManager::class, $transactions);
        $this->assertInstanceOf(EventBus::class, $eventBus);

        return new RevokeRecoverySecret(
            $users,
            ProbingRecoverySecretRepository::aroundUserIdLock($secrets, $onArrival(...), $onLeaving(...)),
            new ProveCurrentPassword(),
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            $eventBus,
            $transactions,
        );
    }

    private function countSecretsFor(string $userId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM identity_recovery_secret WHERE user_id = CAST(:id AS UUID)',
            ['id' => $userId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

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

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->connection->getParams());
        }

        return $this->outside;
    }
}
